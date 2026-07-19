<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Api;

/**
 * Published authority for the alias name of an indexer in a store view.
 *
 * The alias (`<prefix>_<indexerId>_<storeId>`) is what consumers query; the physical
 * collection behind it is versioned and swapped on reindex. `typesense-search` resolves
 * the alias through this contract so it never rebuilds the naming rule the indexer owns.
 *
 * @api
 */
interface IndexNameResolverInterface
{
    /**
     * The alias name for one indexer in one store view.
     *
     * @param string $indexerId e.g. `catalogsearch_fulltext`
     * @param int $storeId
     */
    public function getAliasName(string $indexerId, int $storeId): string;
}
