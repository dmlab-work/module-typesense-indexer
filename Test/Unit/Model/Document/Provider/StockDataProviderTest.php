<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use MageDevGroup\TypesenseIndexer\Model\Document\Provider\StockDataProvider;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Model\Stock;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class StockDataProviderTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $rows rows the stock-status query returns
     * @param array<string,mixed> $where captures each `where($condition, $value)` call by reference
     */
    private function provider(array $rows, array &$where = []): StockDataProvider
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnCallback(
            static function (string $condition, $value = null) use (&$where, $select) {
                $where[$condition] = $value;

                return $select;
            }
        );

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('getDefaultScopeId')->willReturn(0);

        return new StockDataProvider($resource, $stockConfiguration);
    }

    public function testAddsStockStatusAndQuantity(): void
    {
        $provider = $this->provider([
            ['product_id' => 42, 'stock_status' => 1, 'qty' => '13.0000'],
            ['product_id' => 7, 'stock_status' => 0, 'qty' => '0.0000'],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42'], '7' => ['id' => '7']], 1);

        self::assertTrue($documents['42']['is_in_stock']);
        self::assertSame(13.0, $documents['42']['qty']);
        self::assertFalse($documents['7']['is_in_stock']);
        self::assertSame(0.0, $documents['7']['qty']);
    }

    public function testScopesQueryToDefaultStockAndScope(): void
    {
        // The legacy table is keyed by product_id + website_id + stock_id; the read must scope to
        // the default stock/scope row Magento keeps per product, not just filter by product_id.
        $where = [];
        $provider = $this->provider([['product_id' => 42, 'stock_status' => 1, 'qty' => '5.0000']], $where);

        $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(Stock::DEFAULT_STOCK_ID, $where['stock_id = ?']);
        self::assertSame(0, $where['website_id = ?']);
        self::assertArrayHasKey('product_id IN (?)', $where);
    }

    public function testEmptyDocumentSetSkipsTheQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);

        self::assertSame([], (new StockDataProvider($resource, $stockConfiguration))->addData([], 1));
    }
}
