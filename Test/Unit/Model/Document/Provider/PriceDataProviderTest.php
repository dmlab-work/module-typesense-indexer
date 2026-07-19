<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use MageDevGroup\TypesenseIndexer\Model\Document\Provider\PriceDataProvider;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\Indexer\Product\Price\DimensionCollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\MultiDimensionProvider;
use Magento\Framework\Search\Request\IndexScopeResolverInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PriceDataProviderTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $rows rows the price-index query returns
     */
    private function provider(array $rows, int $websiteId): PriceDataProvider
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('union')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $tableResolver = $this->createStub(IndexScopeResolverInterface::class);
        $tableResolver->method('resolve')->willReturn('catalog_product_index_price');

        // No providers → one website-agnostic dimension set (default `none` mode), so a select is built.
        $dimensionFactory = $this->createStub(DimensionCollectionFactory::class);
        $dimensionFactory->method('create')->willReturn(new MultiDimensionProvider());

        return new PriceDataProvider(
            $resource,
            $storeManager,
            new FieldNameResolver(),
            $tableResolver,
            $dimensionFactory
        );
    }

    public function testAddsAFinalPricePerCustomerGroupNamedForTheWebsite(): void
    {
        $provider = $this->provider([
            ['entity_id' => 42, 'customer_group_id' => 0, 'final_price' => '19.9900'],
            ['entity_id' => 42, 'customer_group_id' => 1, 'final_price' => '17.5000'],
        ], 1);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(19.99, $documents['42']['price_0_1']);
        self::assertSame(17.5, $documents['42']['price_1_1']);
    }

    public function testFieldNameCarriesTheStoresWebsite(): void
    {
        $provider = $this->provider([
            ['entity_id' => 42, 'customer_group_id' => 0, 'final_price' => '5.00'],
        ], 2);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(5.0, $documents['42']['price_0_2']);
    }

    public function testEmptyDocumentSetSkipsTheQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $tableResolver = $this->createStub(IndexScopeResolverInterface::class);
        $dimensionFactory = $this->createStub(DimensionCollectionFactory::class);

        $provider = new PriceDataProvider(
            $resource,
            $storeManager,
            new FieldNameResolver(),
            $tableResolver,
            $dimensionFactory
        );

        self::assertSame([], $provider->addData([], 1));
    }
}
