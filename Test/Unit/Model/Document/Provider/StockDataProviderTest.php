<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\Provider\StockDataProvider;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class StockDataProviderTest extends TestCase
{
    /**
     * @var array<string,mixed> every `where($condition, $value)` call
     */
    private array $where = [];

    /**
     * @var array<int,array{0:mixed,1:string,2:mixed}> every `join($name, $cond, $cols)` call
     */
    private array $joins = [];

    /**
     * @param array<int,array<string,mixed>> $legacyRows rows the legacy stock-status query returns
     * @param string[] $existingTables tables `isTableExists` reports as present
     * @param string|int|false $stockId the sales-channel stock id lookup result
     * @param array<int|string,int|string> $msiPairs entity_id => is_salable rows of the MSI join
     * @param bool $msiEnabled whether `Magento_InventoryIndexer` is enabled
     */
    private function provider(
        array $legacyRows,
        array $existingTables = [],
        string|int|false $stockId = false,
        array $msiPairs = [],
        bool $msiEnabled = true
    ): StockDataProvider {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('join')->willReturnCallback(
            function ($name, string $condition, $columns) use ($select) {
                $this->joins[] = [$name, $condition, $columns];

                return $select;
            }
        );
        $select->method('where')->willReturnCallback(
            function (string $condition, $value = null) use ($select) {
                $this->where[$condition] = $value;

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($legacyRows);
        $connection->method('fetchOne')->willReturn($stockId);
        $connection->method('fetchPairs')->willReturn($msiPairs);
        $connection->method('isTableExists')->willReturnCallback(
            static fn (string $table): bool => in_array($table, $existingTables, true)
        );

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('getDefaultScopeId')->willReturn(0);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(3);
        $website = $this->createStub(WebsiteInterface::class);
        $website->method('getCode')->willReturn('eu');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $storeManager->method('getWebsite')->willReturnMap([[3, $website]]);

        $moduleManager = $this->createStub(ModuleManager::class);
        $moduleManager->method('isEnabled')->willReturn($msiEnabled);

        return new StockDataProvider($resource, $stockConfiguration, $storeManager, $moduleManager);
    }

    public function testLegacyPathStoresIntStockFlagsAndQuantity(): void
    {
        $provider = $this->provider([
            ['product_id' => 42, 'stock_status' => '1', 'qty' => '13.0000'],
            ['product_id' => 7, 'stock_status' => '0', 'qty' => '0.0000'],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42'], '7' => ['id' => '7']], 1);

        self::assertSame(1, $documents['42']['is_in_stock']);
        self::assertSame(0, $documents['42']['is_out_of_stock']);
        self::assertSame(13.0, $documents['42']['qty']);
        self::assertSame(0, $documents['7']['is_in_stock']);
        self::assertSame(1, $documents['7']['is_out_of_stock']);
        self::assertSame(0.0, $documents['7']['qty']);
    }

    public function testScopesLegacyQueryToDefaultStockAndScope(): void
    {
        $provider = $this->provider([['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000']]);

        $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(Stock::DEFAULT_STOCK_ID, $this->where['stock_id = ?']);
        self::assertSame(0, $this->where['website_id = ?']);
        self::assertArrayHasKey('product_id IN (?)', $this->where);
    }

    public function testProductWithoutStockRowGetsNoStockKeys(): void
    {
        $provider = $this->provider([['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000']]);

        $documents = $provider->addData(['42' => ['id' => '42'], '9' => ['id' => '9']], 1);

        self::assertSame(['id' => '9'], $documents['9']);
    }

    public function testMsiSalabilityFromTheWebsiteStockJoinedBySku(): void
    {
        $provider = $this->provider(
            [
                ['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000'],
                ['product_id' => 7, 'stock_status' => 1, 'qty' => '2.0000'],
            ],
            ['inventory_stock_sales_channel', 'inventory_stock_2'],
            '2',
            [42 => '1', 7 => '0']
        );

        $documents = $provider->addData(['42' => ['id' => '42'], '7' => ['id' => '7']], 1);

        self::assertSame(0, $documents['42']['is_out_of_stock']);
        self::assertSame(1, $documents['7']['is_out_of_stock']);
        // `is_in_stock` stays the legacy main-stock value.
        self::assertSame(1, $documents['7']['is_in_stock']);

        self::assertSame('website', $this->where['type = ?']);
        self::assertSame('eu', $this->where['code = ?']);
        self::assertSame([['s' => 'inventory_stock_2'], 's.sku = cpe.sku', ['is_salable']], $this->joins[0]);
        self::assertSame(['42', '7'], array_map('strval', $this->where['cpe.entity_id IN (?)']));
    }

    public function testMsiProductWithoutStockRowIsOutOfStock(): void
    {
        $provider = $this->provider(
            [['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000']],
            ['inventory_stock_sales_channel', 'inventory_stock_1'],
            1,
            []
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(1, $documents['42']['is_in_stock']);
        self::assertSame(1, $documents['42']['is_out_of_stock']);
    }

    public function testMissingMsiTablesFallBackToLegacy(): void
    {
        $provider = $this->provider([['product_id' => 42, 'stock_status' => 0, 'qty' => '0.0000']]);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(1, $documents['42']['is_out_of_stock']);
        self::assertSame([], $this->joins);
    }

    public function testDisabledMsiWithLeftoverTablesFallsBackToLegacy(): void
    {
        $provider = $this->provider(
            [['product_id' => 42, 'stock_status' => 0, 'qty' => '0.0000']],
            ['inventory_stock_sales_channel', 'inventory_stock_2'],
            '2',
            [42 => '1'],
            false
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(1, $documents['42']['is_out_of_stock']);
        self::assertSame([], $this->joins);
    }

    public function testWebsiteWithoutSalesChannelFallsBackToLegacy(): void
    {
        $provider = $this->provider(
            [['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000']],
            ['inventory_stock_sales_channel'],
            false
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(0, $documents['42']['is_out_of_stock']);
        self::assertSame([], $this->joins);
    }

    public function testMissingStockIndexTableFallsBackToLegacy(): void
    {
        $provider = $this->provider(
            [['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000']],
            ['inventory_stock_sales_channel'],
            '5'
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(0, $documents['42']['is_out_of_stock']);
        self::assertSame([], $this->joins);
    }

    public function testEmptyDocumentSetSkipsTheQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        $provider = new StockDataProvider(
            $resource,
            $this->createStub(StockConfigurationInterface::class),
            $this->createStub(StoreManagerInterface::class),
            $this->createStub(ModuleManager::class)
        );

        self::assertSame([], $provider->addData([], 1));
    }
}
