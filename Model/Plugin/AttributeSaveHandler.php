<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin;

use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseIndexer\Model\Config;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use DmLab\TypesenseIndexer\Model\Schema\CollectionSchemaSync;
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
 * attribute save or delete do to the schema.
 *
 * Flag changes, new attributes and deletions are **schema-only** events — values are already in the
 * FAT documents (a new attribute has none yet) — so each store's main and public collections are
 * reconciled in place (a PATCH that rebuilds the in-memory index from the stored documents, no
 * reimport). Only when core answers `needs-rebuild` (past the size threshold, an uncoercible type
 * change, or a transport failure) do we fall back to a native invalidation.
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class AttributeSaveHandler
{
    /**
     * @param EngineResolverInterface $engineResolver the currently configured search engine
     * @param Config $config the escape-hatch toggle
     * @param AliasManager $aliasManager resolves an alias to its live physical collection
     * @param CollectionSchemaSync $schemaSync reconciles a collection toward the store's desired schema
     * @param IndexNameResolver $nameResolver alias name for a scope
     * @param StoreManagerInterface $storeManager the store views whose collections to reconcile
     * @param IndexerRegistry $indexerRegistry the fulltext indexer, for the invalidation fallback
     * @param SearchRequestConfig $searchRequestConfig cached search-request config to reset
     * @param EavConfig $eavConfig product entity type, to flag the searchable list stale
     * @param LoggerInterface $logger surfaces rebuild fallbacks and per-store failures
     * @param ReindexInvalidationMarker $invalidationMarker keeps the invalidation past a running full reindex
     */
    public function __construct(
        private readonly EngineResolverInterface $engineResolver,
        private readonly Config $config,
        private readonly AliasManager $aliasManager,
        private readonly CollectionSchemaSync $schemaSync,
        private readonly IndexNameResolver $nameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly SearchRequestConfig $searchRequestConfig,
        private readonly EavConfig $eavConfig,
        private readonly LoggerInterface $logger,
        private readonly ReindexInvalidationMarker $invalidationMarker
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
     * Handle an attribute save that touches the schema (a new flagged attribute or a flag change).
     *
     * Refresh the request config, then reconcile every store's collections in place, falling back to
     * a full invalidation if any store needs a rebuild.
     */
    public function handleAttributeSave(): void
    {
        $this->refreshSearchConfig();

        if ($this->reconcileNeedsRebuild()) {
            $this->invalidate();
        }
    }

    /**
     * Handle an attribute delete: the PATCH drops its fields; invalidate only if a store needs a rebuild.
     */
    public function handleAttributeDelete(): void
    {
        $this->handleAttributeSave();
    }

    /**
     * Handle an option attribute that just became storefront-exposed: the public documents lack its labels.
     *
     * Only a reindex adds them, so the fulltext index is marked invalid (no inline reindex) when Typesense
     * is the engine. Toggling between exposed states needs nothing beyond the usual schema PATCH.
     */
    public function handleLabelsExposed(): void
    {
        if ($this->engineResolver->getCurrentSearchEngine() !== EngineCode::ENGINE) {
            return;
        }

        $this->logger->info('Typesense indexer: option attribute became storefront-exposed; reindex for its labels.');
        $this->invalidate();
    }

    /**
     * Reconcile each store view's live main and public collections toward their desired schemas.
     *
     * @return bool whether any store needs a full rebuild
     */
    private function reconcileNeedsRebuild(): bool
    {
        $needsRebuild = false;
        $policy = $this->config->getReconcilePolicy();

        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            $mainAlias = $this->nameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId);
            $publicAlias = $this->nameResolver->getPublicAliasName(Fulltext::INDEXER_ID, $storeId);

            $main = fn (string $collection) => $this->schemaSync->reconcileMain($collection, $storeId, $policy);
            if ($this->aliasNeedsRebuild($mainAlias, $main)) {
                $needsRebuild = true;
            }
            $public = fn (string $collection) => $this->schemaSync->reconcilePublic($collection, $storeId, $policy);
            if ($this->aliasNeedsRebuild($publicAlias, $public)) {
                $needsRebuild = true;
            }
        }

        return $needsRebuild;
    }

    /**
     * Reconcile one alias's live collection toward its desired schema.
     *
     * An alias with no live collection yet (never reindexed) is skipped — there is nothing to PATCH.
     * A `needs-rebuild` decision, or a failure that leaves the in-place outcome unknown, is reported
     * so the caller can invalidate rather than silently leave the schema stale.
     *
     * @param string $alias
     * @param callable $reconcile live collection name ⇒ core `Decision`
     * @return bool whether the alias needs a full rebuild
     */
    private function aliasNeedsRebuild(string $alias, callable $reconcile): bool
    {
        try {
            $collection = $this->aliasManager->resolve($alias);
            if ($collection === null) {
                return false;
            }

            $decision = $reconcile($collection);
            if ($decision->needsRebuild()) {
                $this->logger->info(
                    sprintf(
                        'Typesense indexer: schema change on "%s" needs a rebuild — %s',
                        $alias,
                        (string)$decision->getReason()
                    )
                );

                return true;
            }
        } catch (\Exception $e) {
            // Could not apply in place (transport failure, bad decision config, …); fall back to a
            // rebuild rather than leave the schema stale or fatal the admin save.
            $this->logger->error(
                sprintf('Typesense indexer: could not reconcile schema for "%s": %s', $alias, $e->getMessage()),
                ['exception' => $e]
            );

            return true;
        }

        return false;
    }

    /**
     * Fall back to Magento's native invalidation of the fulltext index.
     */
    private function invalidate(): void
    {
        $this->invalidationMarker->mark();
        $this->indexerRegistry->get(Fulltext::INDEXER_ID)->invalidate();
    }
}
