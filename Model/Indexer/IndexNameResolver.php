<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use DmLab\TypesenseIndexer\Api\IndexNameResolverInterface;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;

/**
 * Derives the stable alias name for an indexer in a store view: `<prefix>_<indexerId>_<storeId>`.
 *
 * The alias is what consumers query; the physical collection behind it is versioned and swapped
 * (see core's {@see \DmLab\TypesenseCore\Model\Collection\AliasManager}). This mirrors ES's
 * `IndexNameResolver::getIndexNameForAlias()` so per-store scoping and the reindex-and-swap model
 * line up with Magento's own conventions. Shared by {@see IndexStructure}, the indexer handler and
 * the attribute-save schema-PATCH plugin so they all address the same alias.
 *
 * The prefix comes from the Catalog Search index-prefix setting (mirrors OpenSearch's index prefix),
 * read through indexer's {@see ConnectionSettings::getIndexPrefix()}.
 *
 * @api
 */
class IndexNameResolver implements IndexNameResolverInterface
{
    /**
     * @param ConnectionSettings $connectionSettings supplies the configured collection-name prefix
     */
    public function __construct(
        private readonly ConnectionSettings $connectionSettings
    ) {
    }

    /**
     * The alias name for one indexer in one store view.
     *
     * @param string $indexerId e.g. `catalogsearch_fulltext`
     * @param int $storeId
     */
    public function getAliasName(string $indexerId, int $storeId): string
    {
        return $this->connectionSettings->getIndexPrefix() . '_' . $indexerId . '_' . $storeId;
    }
}
