<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Document\Provider;

use MageDevGroup\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ResourceConnection;

/**
 * Stock signals (`is_in_stock`, `qty`) from the stock-status index. These are FAT document
 * values — a store can declare them facetable/sortable via a di-merged field provider, but
 * the value is always present regardless.
 *
 * `cataloginventory_stock_status` is keyed by `product_id` + `website_id` + `stock_id`, so the
 * read is scoped to the default stock and default scope — the row modern Magento maintains for
 * every product — mirroring the core resource model. Without the scope, a install carrying more
 * than one row per product would let an arbitrary last-fetched row win.
 */
class StockDataProvider implements DocumentDataProviderInterface
{
    /**
     * @param ResourceConnection $resource
     * @param StockConfigurationInterface $stockConfiguration source of the default stock scope id
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StockConfigurationInterface $stockConfiguration
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
        $select = $connection->select()
            ->from(
                $this->resource->getTableName('cataloginventory_stock_status'),
                ['product_id', 'stock_status', 'qty']
            )
            ->where('product_id IN (?)', $ids)
            ->where('stock_id = ?', Stock::DEFAULT_STOCK_ID)
            ->where('website_id = ?', $this->stockConfiguration->getDefaultScopeId());

        foreach ($connection->fetchAll($select) as $row) {
            $productId = (string)$row['product_id'];
            if (!isset($documents[$productId])) {
                continue;
            }
            $documents[$productId]['is_in_stock'] = (bool)$row['stock_status'];
            $documents[$productId]['qty'] = (float)$row['qty'];
        }

        return $documents;
    }
}
