<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\Indexer\Product\Price\DimensionCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Search\Request\IndexScopeResolverInterface;
use Magento\Store\Model\Indexer\WebsiteDimensionProvider;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Final price per customer group for the store view's website (`price_<group>_<website>`),
 * read from the price index — the same field names the
 * {@see \DmLab\TypesenseIndexer\Model\Schema\Provider\PriceFieldProvider} declares.
 *
 * The base `catalog_product_index_price` table is only maintained when the price indexer runs in the
 * default (`none`) dimensions mode; under a `website` / `customer_group` mode Magento keeps the data
 * in per-dimension tables instead. So the table is resolved through the dimension-aware table resolver
 * and UNIONed across the configured dimensions (filtered to the store's website), mirroring Magento's
 * own `AdvancedSearch\Model\ResourceModel\Index::_getCatalogProductPriceData`.
 */
class PriceDataProvider implements DocumentDataProviderInterface
{
    /**
     * The logical price index table the dimension resolver maps to a concrete per-dimension table.
     */
    private const PRICE_INDEX_TABLE = 'catalog_product_index_price';

    /**
     * @param ResourceConnection $resource
     * @param StoreManagerInterface $storeManager
     * @param FieldNameResolver $fieldNameResolver
     * @param IndexScopeResolverInterface $tableResolver resolves the per-dimension price index table
     * @param DimensionCollectionFactory $dimensionCollectionFactory the configured price dimensions
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StoreManagerInterface $storeManager,
        private readonly FieldNameResolver $fieldNameResolver,
        private readonly IndexScopeResolverInterface $tableResolver,
        private readonly DimensionCollectionFactory $dimensionCollectionFactory
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

        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $connection = $this->resource->getConnection();

        $selects = [];
        foreach ($this->dimensionCollectionFactory->create() as $dimensions) {
            // Skip dimension sets scoped to another website; a website-agnostic set (none mode) stays.
            $websiteDimension = $dimensions[WebsiteDimensionProvider::DIMENSION_NAME] ?? null;
            if ($websiteDimension !== null && (int)$websiteDimension->getValue() !== $websiteId) {
                continue;
            }

            $selects[] = $connection->select()
                ->from(
                    $this->tableResolver->resolve(self::PRICE_INDEX_TABLE, $dimensions),
                    ['entity_id', 'customer_group_id', 'final_price']
                )
                ->where('entity_id IN (?)', $ids)
                ->where('website_id = ?', $websiteId);
        }

        if ($selects === []) {
            return $documents;
        }

        foreach ($connection->fetchAll($connection->select()->union($selects)) as $row) {
            $productId = (string)$row['entity_id'];
            if (!isset($documents[$productId])) {
                continue;
            }
            $name = $this->fieldNameResolver->resolve(
                'price',
                ['customerGroupId' => (int)$row['customer_group_id'], 'websiteId' => $websiteId]
            );
            $documents[$productId][$name] = (float)$row['final_price'];
        }

        return $documents;
    }
}
