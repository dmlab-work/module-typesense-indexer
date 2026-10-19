<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Merges the option values of a composite product's children into the parent document, so a
 * configurable carries its children's `color`/`size` for layered navigation.
 *
 * Children come from `catalog_product_relation` and only the enabled ones assigned to the store's
 * website count; out-of-stock ones are skipped unless "Display Out of Stock Products" is on (native
 * `StockedProductsFilterPlugin`). Each option attribute becomes the unique int union of the parent's own
 * values and the children's. Mirrors `CatalogSearch\...\Fulltext\Action\DataProvider::prepareProductIndex()`.
 */
class ChildAttributeDataProvider implements DocumentDataProviderInterface
{
    /**
     * @param ResourceConnection $resource
     * @param CollectionFactory $collectionFactory loads the children
     * @param OptionAttributeList $optionAttributes the attributes whose values are merged
     * @param StoreManagerInterface $storeManager resolves the store's website
     * @param StockConfigurationInterface $stockConfiguration "Display Out of Stock Products"
     * @param StockDataProvider $stockDataProvider the children's `is_out_of_stock` (MSI-aware)
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly OptionAttributeList $optionAttributes,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly StockDataProvider $stockDataProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addData(array $documents, int $storeId): array
    {
        $ids = array_keys($documents);
        if ($ids === []) {
            return $documents;
        }

        $parentsByChild = $this->relations($ids);
        if ($parentsByChild === []) {
            return $documents;
        }
        $codes = array_keys($this->optionAttributes->get());
        if ($codes === []) {
            return $documents;
        }
        $childIds = array_keys($parentsByChild);
        if (!$this->stockConfiguration->isShowOutOfStock($storeId)) {
            $childIds = $this->inStock($childIds, $storeId);
            if ($childIds === []) {
                return $documents;
            }
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addIdFilter($childIds);
        $collection->addWebsiteFilter((int)$this->storeManager->getStore($storeId)->getWebsiteId());
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);
        $collection->addAttributeToSelect($codes);

        $childValues = [];
        foreach ($collection->getItems() as $child) {
            foreach ($parentsByChild[(string)$child->getId()] ?? [] as $parentId) {
                foreach ($codes as $code) {
                    foreach ($this->toInts($child->getData($code)) as $value) {
                        $childValues[$parentId][$code][$value] = $value;
                    }
                }
            }
        }

        foreach ($childValues as $parentId => $byCode) {
            foreach ($byCode as $code => $values) {
                $own = $this->toInts($documents[$parentId][$code] ?? null);
                $documents[$parentId][$code] = array_values(array_combine($own, $own) + $values);
            }
        }

        return $documents;
    }

    /**
     * Child id ⇒ the batch parents it belongs to.
     *
     * @param array<int,int|string> $parentIds
     * @return array<string,string[]>
     */
    private function relations(array $parentIds): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_product_relation'), ['parent_id', 'child_id'])
            ->where('parent_id IN (?)', $parentIds);

        $parentsByChild = [];
        foreach ($connection->fetchAll($select) as $row) {
            $parentsByChild[(string)$row['child_id']][] = (string)$row['parent_id'];
        }

        return $parentsByChild;
    }

    /**
     * An option value (list, scalar id or comma-joined ids) as a list of ints.
     *
     * @param mixed $value
     * @return int[]
     */
    private function toInts(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        $list = is_array($value) ? $value : explode(',', (string)$value);
        $list = array_filter(
            array_map(static fn ($v): string => trim((string)$v), $list),
            static fn (string $v): bool => $v !== ''
        );

        return array_values(array_map('intval', $list));
    }

    /**
     * The ids among `$ids` that are in stock for the store's website.
     *
     * @param array<int,int|string> $ids
     * @param int $storeId
     * @return int[]
     */
    private function inStock(array $ids, int $storeId): array
    {
        $stock = $this->stockDataProvider->addData(array_fill_keys($ids, []), $storeId);

        return array_keys(array_filter($stock, static fn (array $row): bool => ($row['is_out_of_stock'] ?? 1) === 0));
    }
}
