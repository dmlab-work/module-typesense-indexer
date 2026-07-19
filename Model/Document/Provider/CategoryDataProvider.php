<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Document\Provider;

use MageDevGroup\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\Indexer\Category\Product\AbstractAction;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Search\Request\IndexScopeResolverInterface;
use Magento\Store\Model\Store;

/**
 * Category membership (`category_ids`) and per-category sort position
 * (`position_category_<id>`), matching the fields the `CategoryFieldProvider` declares so
 * faceting and sorting have data to work on.
 *
 * Data is read from the store-scoped category-product **index** (`catalog_category_product_index`,
 * resolved per store dimension), not the raw `catalog_category_product` assignment table — the index
 * carries anchor/parent propagation and visibility, so a product assigned only to a child category
 * still lists under its anchor parents. This mirrors Magento's `Index::getCategoryProductIndexData`.
 */
class CategoryDataProvider implements DocumentDataProviderInterface
{
    /**
     * @param ResourceConnection $resource
     * @param FieldNameResolver $fieldNameResolver
     * @param IndexScopeResolverInterface $tableResolver resolves the per-store category-product index table
     * @param DimensionFactory $dimensionFactory builds the store dimension the table resolver keys on
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly FieldNameResolver $fieldNameResolver,
        private readonly IndexScopeResolverInterface $tableResolver,
        private readonly DimensionFactory $dimensionFactory
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

        $connection = $this->resource->getConnection();
        $table = $this->tableResolver->resolve(
            AbstractAction::MAIN_INDEX_TABLE,
            [$this->dimensionFactory->create(Store::ENTITY, (string)$storeId)]
        );
        $select = $connection->select()
            ->from($table, ['product_id', 'category_id', 'position'])
            ->where('store_id = ?', $storeId)
            ->where('product_id IN (?)', $ids);

        foreach ($connection->fetchAll($select) as $row) {
            $productId = (string)$row['product_id'];
            if (!isset($documents[$productId])) {
                continue;
            }
            $categoryId = (int)$row['category_id'];
            $documents[$productId]['category_ids'][] = $categoryId;
            $positionField = $this->fieldNameResolver->resolve('position', ['categoryId' => $categoryId]);
            $documents[$productId][$positionField] = (int)$row['position'];
        }

        return $documents;
    }
}
