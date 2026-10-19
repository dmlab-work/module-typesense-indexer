<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexStructureInterface;
use Psr\Log\LoggerInterface;

/**
 * Owns the collection schema for one indexer per store view — the `IndexStructureInterface`
 * half of the indexer contract, driven by `cleanIndex` (`create()` only; the previous collection
 * keeps serving until the alias swap at the end of `saveIndex` retires it).
 *
 * `create()` stands up a **fresh physical collection** for the store's alias and computes the
 * desired schema itself: Magento calls it as `create($indexerId, [], $dimensions)`, so the
 * `$fields` argument is always empty and is deliberately ignored — the schema is `base + Σ(providers)`
 * ({@see DesiredSchemaBuilder}), never the caller's. The new collection is registered in
 * {@see PendingCollectionRegistry} so the handler writes documents into it and swaps the alias onto
 * it as the final step of `saveIndex`.
 *
 * The public storefront collection (`<alias>_public`, {@see PublicSchemaBuilder}) is created and
 * registered alongside, so both are rebuilt and swapped together.
 *
 * Creation uses core's {@see CollectionManager::create()}, **not** the reconciler: the reconciler
 * first `get()`s the collection and so assumes it already exists, which in the `cleanIndex → create`
 * path it does not. Reconcile is for drift on a live collection (the attribute-save PATCH), not for
 * standing a new one up.
 *
 * @api
 */
class IndexStructure implements IndexStructureInterface
{
    /**
     * @param StoreScopeResolver $storeScopeResolver resolves the store id carried by the dimension
     * @param DesiredSchemaBuilder $schemaBuilder computes the per-store `FieldSpec[]`
     * @param AliasManager $aliasManager alias name → target, and versioned physical names
     * @param CollectionManager $collectionManager collection create/drop
     * @param IndexNameResolver $nameResolver derives the alias name for the scope
     * @param PendingCollectionRegistry $pendingCollections hands the new collection to the handler
     * @param PublicSchemaBuilder $publicSchemaBuilder computes the public collection `FieldSpec[]`
     * @param LoggerInterface $logger surfaces a failed rollback of the main collection
     */
    public function __construct(
        private readonly StoreScopeResolver $storeScopeResolver,
        private readonly DesiredSchemaBuilder $schemaBuilder,
        private readonly AliasManager $aliasManager,
        private readonly CollectionManager $collectionManager,
        private readonly IndexNameResolver $nameResolver,
        private readonly PendingCollectionRegistry $pendingCollections,
        private readonly PublicSchemaBuilder $publicSchemaBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Create fresh main and public physical collections for the scope and register both for the handler.
     *
     * The `$fields` argument is ignored: `cleanIndex` passes an empty array and the schema is
     * computed here from the registered providers.
     *
     * @param string $index the indexer id, e.g. `catalogsearch_fulltext`
     * @param array<mixed> $fields ignored (always empty from `cleanIndex`)
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @return void
     * @throws LocalizedException when no scope dimension is present
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException
     */
    public function create($index, array $fields, array $dimensions = [])
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName((string)$index, $storeId);

        $physical = $this->aliasManager->generateCollectionName($alias);
        $this->collectionManager->create($physical, $this->schemaBuilder->build($storeId));
        $this->pendingCollections->set($alias, $physical);

        $publicAlias = $this->nameResolver->getPublicAliasName((string)$index, $storeId);
        try {
            $publicPhysical = $this->aliasManager->generateCollectionName($publicAlias);
            $this->collectionManager->create($publicPhysical, $this->publicSchemaBuilder->build($storeId));
        } catch (\Exception $e) {
            // Without its public twin the main build would swap alone; roll it back instead.
            $this->rollBack($alias, $physical);
            throw $e;
        }
        $this->pendingCollections->set($publicAlias, $publicPhysical);
    }

    /**
     * Drop the collections the scope's main and public aliases target, and both aliases.
     *
     * @param string $index the indexer id
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @return void
     * @throws LocalizedException when no scope dimension is present
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException
     */
    public function delete($index, array $dimensions = [])
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName((string)$index, $storeId);

        $this->dropAlias($alias);
        $this->dropAlias($this->nameResolver->getPublicAliasName((string)$index, $storeId));
    }

    /**
     * Drop the collection an alias targets and the alias; drop nothing if the alias is unset.
     *
     * @param string $alias
     * @throws \DmLab\TypesenseCore\Exception\TypesenseException on any failure other than a 404
     */
    public function dropAlias(string $alias): void
    {
        $target = $this->aliasManager->resolve($alias);
        if ($target === null) {
            return;
        }

        // A 404 means the target is already gone — still fall through to drop the alias,
        // or it dangles at a now-deleted collection and later resolves to it.
        try {
            $this->collectionManager->drop($target);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }

        try {
            $this->aliasManager->delete($alias);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
    }

    /**
     * Drop a just-created main collection and forget it — never masks the original failure.
     *
     * @param string $alias
     * @param string $collection
     */
    private function rollBack(string $alias, string $collection): void
    {
        $this->pendingCollections->clear($alias);
        try {
            $this->collectionManager->drop($collection);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Typesense indexer: could not drop collection "%s" after a failed create.', $collection),
                ['exception' => $e]
            );
        }
    }
}
