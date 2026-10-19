<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Stock signals: `is_in_stock` (int 0/1) and `qty` from the legacy stock-status index, plus
 * `is_out_of_stock` (int 0/1) — the website's MSI salability, or the legacy status when MSI is absent.
 *
 * The legacy read is scoped to the default stock and scope, the row Magento keeps per product.
 * MSI is detected by module status and its tables, so the module carries no MSI package dependency.
 */
class StockDataProvider implements DocumentDataProviderInterface
{
    /**
     * @param ResourceConnection $resource
     * @param StockConfigurationInterface $stockConfiguration source of the default stock scope id
     * @param StoreManagerInterface $storeManager resolves the store's website code for the MSI sales channel
     * @param ModuleManager $moduleManager MSI tables outlive a disabled MSI, so its status is checked too
     */
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly StoreManagerInterface $storeManager,
        private readonly ModuleManager $moduleManager
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

        $msiStockTable = $this->resolveMsiStockTable($connection, $storeId);

        foreach ($connection->fetchAll($select) as $row) {
            $productId = (string)$row['product_id'];
            if (!isset($documents[$productId])) {
                continue;
            }
            $inStock = (int)(bool)$row['stock_status'];
            $documents[$productId]['is_in_stock'] = $inStock;
            $documents[$productId]['qty'] = (float)$row['qty'];
            if ($msiStockTable === null) {
                $documents[$productId]['is_out_of_stock'] = 1 - $inStock;
            }
        }

        if ($msiStockTable !== null) {
            $documents = $this->addMsiSalability($connection, $msiStockTable, $documents, $ids);
        }

        return $documents;
    }

    /**
     * The `inventory_stock_<id>` index assigned to the store's website, or null when MSI is not usable.
     *
     * @param AdapterInterface $connection
     * @param int $storeId
     * @return string|null
     */
    private function resolveMsiStockTable(AdapterInterface $connection, int $storeId): ?string
    {
        $channelTable = $this->resource->getTableName('inventory_stock_sales_channel');
        if (!$this->moduleManager->isEnabled('Magento_InventoryIndexer')
            || !$connection->isTableExists($channelTable)
        ) {
            return null;
        }

        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        $websiteCode = (string)$this->storeManager->getWebsite($websiteId)->getCode();
        $stockId = $connection->fetchOne(
            $connection->select()
                ->from($channelTable, ['stock_id'])
                ->where('type = ?', 'website')
                ->where('code = ?', $websiteCode)
        );
        if ($stockId === false || $stockId === null) {
            return null;
        }

        $stockTable = $this->resource->getTableName('inventory_stock_' . (int)$stockId);

        return $connection->isTableExists($stockTable) ? $stockTable : null;
    }

    /**
     * Set `is_out_of_stock = 1 - is_salable` from the MSI stock index, joined by sku.
     *
     * A product absent from the index is out of stock, as in Magento's MSI search mapper.
     *
     * @param AdapterInterface $connection
     * @param string $stockTable
     * @param array<string,array<string,mixed>> $documents
     * @param array<int,int|string> $ids
     * @return array<string,array<string,mixed>>
     */
    private function addMsiSalability(
        AdapterInterface $connection,
        string $stockTable,
        array $documents,
        array $ids
    ): array {
        $select = $connection->select()
            ->from(['cpe' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(['s' => $stockTable], 's.sku = cpe.sku', ['is_salable'])
            ->where('cpe.entity_id IN (?)', $ids);

        foreach (array_keys($documents) as $productId) {
            $documents[$productId]['is_out_of_stock'] = 1;
        }
        foreach ($connection->fetchPairs($select) as $productId => $isSalable) {
            $productId = (string)$productId;
            if (isset($documents[$productId])) {
                $documents[$productId]['is_out_of_stock'] = 1 - (int)(bool)$isSalable;
            }
        }

        return $documents;
    }
}
