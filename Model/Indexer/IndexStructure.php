<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Indexer;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Collection\AliasManager;
use MageDevGroup\TypesenseCore\Model\Collection\CollectionManager;
use MageDevGroup\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\IndexStructureInterface;

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
     */
    public function __construct(
        private readonly StoreScopeResolver $storeScopeResolver,
        private readonly DesiredSchemaBuilder $schemaBuilder,
        private readonly AliasManager $aliasManager,
        private readonly CollectionManager $collectionManager,
        private readonly IndexNameResolver $nameResolver,
        private readonly PendingCollectionRegistry $pendingCollections
    ) {
    }

    /**
     * Create a fresh physical collection for the scope and register it for the handler.
     *
     * The `$fields` argument is ignored: `cleanIndex` passes an empty array and the schema is
     * computed here from the registered providers.
     *
     * @param string $index the indexer id, e.g. `catalogsearch_fulltext`
     * @param array<mixed> $fields ignored (always empty from `cleanIndex`)
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @return void
     * @throws LocalizedException when no scope dimension is present
     * @throws \MageDevGroup\TypesenseCore\Exception\TypesenseException
     */
    public function create($index, array $fields, array $dimensions = [])
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName((string)$index, $storeId);

        $physical = $this->aliasManager->generateCollectionName($alias);
        $this->collectionManager->create($physical, $this->schemaBuilder->build($storeId));

        $this->pendingCollections->set($alias, $physical);
    }

    /**
     * Drop the collection the scope's alias targets and the alias; drop nothing if the alias is unset.
     *
     * @param string $index the indexer id
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @return void
     * @throws LocalizedException when no scope dimension is present
     * @throws \MageDevGroup\TypesenseCore\Exception\TypesenseException
     */
    public function delete($index, array $dimensions = [])
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName((string)$index, $storeId);

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
}
