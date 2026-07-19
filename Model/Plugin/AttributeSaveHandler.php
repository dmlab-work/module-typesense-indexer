<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Plugin;

use MageDevGroup\TypesenseCore\Model\Collection\AliasManager;
use MageDevGroup\TypesenseCore\Model\Schema\Reconciler;
use MageDevGroup\TypesenseIndexer\Model\Config;
use MageDevGroup\TypesenseIndexer\Api\EngineCode;
use MageDevGroup\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use MageDevGroup\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use Magento\Catalog\Model\Product;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\Search\Request\Config as SearchRequestConfig;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The logic behind the attribute-save plugin: is the Typesense flow active, and what does an
 * attribute save do to the schema.
 *
 * A flag change on an existing attribute is a **schema-only** event — its value is already in the
 * FAT documents — so it is reconciled in place (a PATCH that rebuilds the in-memory index from the
 * stored documents, no reimport). Only when core answers `needs-rebuild` (past the size threshold,
 * an uncoercible type change, or a transport failure) do we fall back to a native invalidation.
 *
 * A **new** attribute is different: its values are in no document yet, so its column has to be
 * written before it can be indexed. There we invalidate unconditionally — deliberately more
 * expensive than ES's in-place mapping add, and correct for a FAT store.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttributeSaveHandler
{
    /**
     * @param EngineResolverInterface $engineResolver the currently configured search engine
     * @param Config $config the escape-hatch toggle
     * @param Reconciler $reconciler core's in-place-or-rebuild schema decision
     * @param AliasManager $aliasManager resolves an alias to its live physical collection
     * @param DesiredSchemaBuilder $schemaBuilder the per-store desired `FieldSpec[]`
     * @param IndexNameResolver $nameResolver alias name for a scope
     * @param StoreManagerInterface $storeManager the store views whose collections to reconcile
     * @param IndexerRegistry $indexerRegistry the fulltext indexer, for the invalidation fallback
     * @param SearchRequestConfig $searchRequestConfig cached search-request config to reset
     * @param EavConfig $eavConfig product entity type, to flag the searchable list stale
     * @param LoggerInterface $logger surfaces rebuild fallbacks and per-store failures
     */
    public function __construct(
        private readonly EngineResolverInterface $engineResolver,
        private readonly Config $config,
        private readonly Reconciler $reconciler,
        private readonly AliasManager $aliasManager,
        private readonly DesiredSchemaBuilder $schemaBuilder,
        private readonly IndexNameResolver $nameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly SearchRequestConfig $searchRequestConfig,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether attribute saves should be handled by the Typesense flow.
     *
     * False when Typesense is not the active engine (leave the native plugins alone) or when the
     * escape hatch restores native reindex-on-change semantics.
     */
    public function isActive(): bool
    {
        return $this->engineResolver->getCurrentSearchEngine() === EngineCode::ENGINE
            && !$this->config->usesNativeInvalidation();
    }

    /**
     * Reproduce the native plugins' non-invalidation side effects.
     *
     * Drops the cached search-request config and marks the searchable-attributes list stale so the
     * next build re-reads it.
     */
    public function refreshSearchConfig(): void
    {
        $this->searchRequestConfig->reset();
        $this->eavConfig->getEntityType(Product::ENTITY)->setNeedRefreshSearchAttributesList(true);
    }

    /**
     * Handle a save of an existing attribute.
     *
     * Refresh the request config, then reconcile every store's collection in place, falling back to
     * a full invalidation if any store needs a rebuild.
     */
    public function handleExistingAttributeSave(): void
    {
        $this->refreshSearchConfig();

        if ($this->reconcileNeedsRebuild()) {
            $this->invalidate();
        }
    }

    /**
     * Handle a save of a new attribute.
     *
     * Its column is in no document, so refresh the config and invalidate: a reindex writes the
     * column and keeps later flag toggles a cheap PATCH.
     */
    public function handleNewAttributeSave(): void
    {
        $this->refreshSearchConfig();
        $this->invalidate();
    }

    /**
     * Reconcile each store view's live collection toward its desired schema.
     *
     * A store with no live collection yet (never reindexed) is skipped — there is nothing to PATCH.
     * A `needs-rebuild` decision, or a failure that leaves the in-place outcome unknown, is reported
     * so the caller can invalidate rather than silently leave the schema stale.
     *
     * @return bool whether any store needs a full rebuild
     */
    private function reconcileNeedsRebuild(): bool
    {
        $needsRebuild = false;

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $alias = $this->nameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);

            try {
                $collection = $this->aliasManager->resolve($alias);
                if ($collection === null) {
                    continue;
                }

                $decision = $this->reconciler->reconcile(
                    $collection,
                    $this->schemaBuilder->build($storeId),
                    $this->config->getReconcilePolicy()
                );
                if ($decision->needsRebuild()) {
                    $needsRebuild = true;
                    $this->logger->info(
                        sprintf(
                            'Typesense indexer: schema change on "%s" needs a rebuild — %s',
                            $alias,
                            (string)$decision->getReason()
                        )
                    );
                }
            } catch (\Exception $e) {
                // Could not apply in place (transport failure, bad decision config, …); fall back to a
                // rebuild rather than leave the schema stale or fatal the admin save.
                $needsRebuild = true;
                $this->logger->error(
                    sprintf('Typesense indexer: could not reconcile schema for "%s": %s', $alias, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }

        return $needsRebuild;
    }

    /**
     * Fall back to Magento's native invalidation of the fulltext index.
     */
    private function invalidate(): void
    {
        $this->indexerRegistry->get(Fulltext::INDEXER_ID)->invalidate();
    }
}
