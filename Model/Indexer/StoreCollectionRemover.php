<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Psr\Log\LoggerInterface;

/**
 * Removes a deleted store view's main and public collections, aliases and synonym set.
 *
 * Names come from {@see IndexNameResolver} only: the store row is already gone. Errors are logged, never thrown.
 */
class StoreCollectionRemover
{
    /**
     * @param IndexNameResolver $nameResolver
     * @param IndexStructure $indexStructure drops each alias and its target
     * @param OrphanCollectionPruner $pruner drops versioned leftovers no alias points at
     * @param SynonymSync $synonymSync
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly IndexNameResolver $nameResolver,
        private readonly IndexStructure $indexStructure,
        private readonly OrphanCollectionPruner $pruner,
        private readonly SynonymSync $synonymSync,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Remove everything Typesense holds for the deleted store view.
     *
     * @param int $storeId
     */
    public function remove(int $storeId): void
    {
        $aliases = [
            $this->nameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId),
            $this->nameResolver->getPublicAliasName(Fulltext::INDEXER_ID, $storeId),
        ];
        foreach ($aliases as $alias) {
            try {
                $this->indexStructure->dropAlias($alias);
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf('Typesense indexer: could not remove alias "%s" of deleted store %d.', $alias, $storeId),
                    ['exception' => $e]
                );
            }
            $this->pruner->prune($alias, '');
        }

        try {
            $this->synonymSync->deleteStore($storeId);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Typesense indexer: could not delete the synonym set of deleted store %d.', $storeId),
                ['exception' => $e]
            );
        }
    }
}
