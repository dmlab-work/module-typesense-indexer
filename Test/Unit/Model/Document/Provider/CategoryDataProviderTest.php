<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\Provider\CategoryDataProvider;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Indexer\Dimension;
use Magento\Framework\Indexer\DimensionFactory;
use Magento\Framework\Search\Request\IndexScopeResolverInterface;
use PHPUnit\Framework\TestCase;

class CategoryDataProviderTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $rows rows the category_product query returns
     */
    private function provider(array $rows): CategoryDataProvider
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $tableResolver = $this->createStub(IndexScopeResolverInterface::class);
        $tableResolver->method('resolve')->willReturn('catalog_category_product_index_store1');

        return new CategoryDataProvider($resource, new FieldNameResolver(), $tableResolver, $this->dimensionFactory());
    }

    /**
     * A factory that yields a stub store dimension — the resolver is stubbed, so its value is unused.
     */
    private function dimensionFactory(): DimensionFactory
    {
        $factory = $this->createStub(DimensionFactory::class);
        $factory->method('create')->willReturn($this->createStub(Dimension::class));

        return $factory;
    }

    public function testAddsCategoryIdsArrayAndPerCategoryPosition(): void
    {
        $provider = $this->provider([
            ['product_id' => 42, 'category_id' => 3, 'position' => 5],
            ['product_id' => 42, 'category_id' => 7, 'position' => 2],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame([3, 7], $documents['42']['category_ids']);
        self::assertSame(5, $documents['42']['position_category_3']);
        self::assertSame(2, $documents['42']['position_category_7']);
    }

    public function testIgnoresRowsForProductsOutsideTheDocumentSet(): void
    {
        $provider = $this->provider([
            ['product_id' => 99, 'category_id' => 3, 'position' => 5],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertArrayNotHasKey('category_ids', $documents['42']);
        self::assertArrayNotHasKey('99', $documents);
    }

    public function testEmptyDocumentSetSkipsTheQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');

        $provider = new CategoryDataProvider(
            $resource,
            new FieldNameResolver(),
            $this->createStub(IndexScopeResolverInterface::class),
            $this->dimensionFactory()
        );

        self::assertSame([], $provider->addData([], 1));
    }
}
