<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Synonym;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mirrors native synonym groups into one Typesense synonym set per store, `<mainAlias>_synonyms`.
 *
 * The set is replaced as a whole, so an empty one is still written: searches can always reference it.
 */
class SynonymSync
{
    private const SET_SUFFIX = '_synonyms';

    /**
     * @param SynonymGroupReader $reader
     * @param SynonymSetManager $setManager
     * @param IndexNameResolver $nameResolver
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SynonymGroupReader $reader,
        private readonly SynonymSetManager $setManager,
        private readonly IndexNameResolver $nameResolver,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Replace the store's synonym set with its current groups.
     *
     * @param int $storeId
     * @throws NoSuchEntityException when the store does not exist
     * @throws TypesenseException
     */
    public function syncStore(int $storeId): void
    {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();

        $items = [];
        foreach ($this->reader->getForStore($storeId, $websiteId) as $groupId => $phrases) {
            $items[] = ['id' => 'group_' . $groupId, 'synonyms' => $phrases];
        }

        $this->setManager->upsert($this->getSetName($storeId), $items);
    }

    /**
     * Resync every store view (a group save may merge or delete other groups).
     *
     * Each store is synced independently: a failure is logged and the remaining stores still sync.
     */
    public function syncAllStores(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();
            try {
                $this->syncStore($storeId);
            } catch (\Exception $e) {
                $this->logger->error(
                    sprintf('Typesense indexer: synonym sync failed for store %d — %s', $storeId, $e->getMessage()),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Remove the store's synonym set; a missing set is not an error.
     *
     * @param int $storeId
     * @throws TypesenseException
     */
    public function deleteStore(int $storeId): void
    {
        $this->setManager->delete($this->getSetName($storeId));
    }

    /**
     * Synonym set name of a store: `<mainAlias>_synonyms`.
     *
     * @param int $storeId
     */
    public function getSetName(int $storeId): string
    {
        return $this->nameResolver->getAliasName(Fulltext::INDEXER_ID, $storeId) . self::SET_SUFFIX;
    }
}
