<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\Provider\ChildAttributeDataProvider;
use DmLab\TypesenseIndexer\Model\Document\Provider\StockDataProvider;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as AttributeCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class ChildAttributeDataProviderTest extends TestCase
{
    private const ATTRIBUTES = [
        'color' => ['select', 'int'],
        'size' => ['select', 'int'],
        'material' => ['multiselect', 'varchar'],
        'name' => ['text', 'varchar'],
    ];

    /**
     * @param array<int,array{parent_id:int,child_id:int}> $relations
     */
    private function resource(array $relations): ResourceConnection
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($relations);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return $resource;
    }

    /**
     * @param array<int,array<string,mixed>> $children id ⇒ attribute values
     */
    private function childCollection(array $children): Collection
    {
        $items = [];
        foreach ($children as $id => $data) {
            $product = $this->createStub(Product::class);
            $product->method('getId')->willReturn($id);
            $product->method('getData')->willReturnCallback(static fn ($key = '') => $data[$key] ?? null);
            $items[] = $product;
        }

        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);

        return $collection;
    }

    private function factory(Collection $collection): CollectionFactory
    {
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    private function attributeFactory(): AttributeCollectionFactory
    {
        $items = [];
        foreach (self::ATTRIBUTES as $code => [$input, $backend]) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getFrontendInput')->willReturn($input);
            $attribute->method('getBackendType')->willReturn($backend);
            $items[] = $attribute;
        }
        $collection = $this->createStub(AttributeCollection::class);
        $collection->method('getItems')->willReturn($items);

        $factory = $this->createStub(AttributeCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    private function storeManager(int $websiteId = 1): StoreManagerInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn($websiteId);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return $storeManager;
    }

    /**
     * @param StockDataProvider|null $stock null shows out-of-stock children (no stock lookup)
     */
    private function provider(
        ResourceConnection $resource,
        CollectionFactory $factory,
        ?StoreManagerInterface $storeManager = null,
        ?StockDataProvider $stock = null
    ): ChildAttributeDataProvider {
        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('isShowOutOfStock')->willReturn($stock === null);

        return new ChildAttributeDataProvider(
            $resource,
            $factory,
            new OptionAttributeList($this->attributeFactory(), new AttributeFieldPolicy(new FieldNameResolver())),
            $storeManager ?? $this->storeManager(),
            $stockConfiguration,
            $stock ?? $this->createStub(StockDataProvider::class)
        );
    }

    /**
     * @param array<int,int> $outOfStock child id ⇒ `is_out_of_stock`
     */
    private function stock(array $outOfStock): StockDataProvider
    {
        $stock = $this->createStub(StockDataProvider::class);
        $stock->method('addData')->willReturnCallback(
            static function (array $documents) use ($outOfStock): array {
                foreach ($documents as $id => $document) {
                    if (isset($outOfStock[$id])) {
                        $documents[$id]['is_out_of_stock'] = $outOfStock[$id];
                    }
                }

                return $documents;
            }
        );

        return $stock;
    }

    public function testConfigurableParentGetsTheUnionOfItsChildrenOptionValues(): void
    {
        $provider = $this->provider(
            $this->resource([
                ['parent_id' => 62, 'child_id' => 63],
                ['parent_id' => 62, 'child_id' => 64],
                ['parent_id' => 62, 'child_id' => 65],
            ]),
            $this->factory($this->childCollection([
                63 => ['color' => '49', 'size' => '167', 'material' => '31,33'],
                64 => ['color' => '49', 'size' => '168'],
                65 => ['color' => '50', 'size' => '167', 'material' => '33'],
            ]))
        );

        $documents = $provider->addData(['62' => ['id' => '62']], 1);

        self::assertSame([49, 50], $documents['62']['color']);
        self::assertSame([167, 168], $documents['62']['size']);
        self::assertSame([31, 33], $documents['62']['material']);
    }

    public function testKeepsTheParentsOwnValues(): void
    {
        $provider = $this->provider(
            $this->resource([['parent_id' => 62, 'child_id' => 63]]),
            $this->factory($this->childCollection([63 => ['color' => '49']]))
        );

        $documents = $provider->addData(['62' => ['id' => '62', 'color' => ['50', '49']]], 1);

        self::assertSame([50, 49], $documents['62']['color']);
    }

    public function testAChildSharedByTwoParentsFeedsBoth(): void
    {
        $provider = $this->provider(
            $this->resource([['parent_id' => 45, 'child_id' => 7], ['parent_id' => 46, 'child_id' => 7]]),
            $this->factory($this->childCollection([7 => ['color' => '49']]))
        );

        $documents = $provider->addData(['45' => ['id' => '45'], '46' => ['id' => '46']], 1);

        self::assertSame([49], $documents['45']['color']);
        self::assertSame([49], $documents['46']['color']);
    }

    public function testLoadsOnlyEnabledChildrenOfTheStoresWebsite(): void
    {
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('setStoreId')->with(2);
        $collection->expects(self::once())->method('addIdFilter')->with(['63', '64']);
        $collection->expects(self::once())->method('addWebsiteFilter')->with(5);
        $collection->expects(self::once())->method('addAttributeToFilter')
            ->with('status', Status::STATUS_ENABLED);
        $collection->expects(self::once())->method('addAttributeToSelect')
            ->with(['color', 'size', 'material']);
        // The disabled / other-website child 64 is filtered out by the collection.
        $child = $this->createStub(Product::class);
        $child->method('getId')->willReturn(63);
        $child->method('getData')->willReturnCallback(static fn ($key = '') => $key === 'color' ? '49' : null);
        $collection->method('getItems')->willReturn([$child]);

        $provider = $this->provider(
            $this->resource([['parent_id' => 62, 'child_id' => 63], ['parent_id' => 62, 'child_id' => 64]]),
            $this->factory($collection),
            $this->storeManager(5)
        );

        $documents = $provider->addData(['62' => ['id' => '62']], 2);

        self::assertSame([49], $documents['62']['color']);
        self::assertArrayNotHasKey('size', $documents['62']);
    }

    public function testSkipsOutOfStockChildrenWhenTheyAreHidden(): void
    {
        $collection = $this->createMock(Collection::class);
        // 64 is out of stock, 65 has no stock row: neither is loaded.
        $collection->expects(self::once())->method('addIdFilter')->with([63]);
        $collection->method('getItems')->willReturn($this->childCollection([63 => ['color' => '49']])->getItems());

        $provider = $this->provider(
            $this->resource([
                ['parent_id' => 62, 'child_id' => 63],
                ['parent_id' => 62, 'child_id' => 64],
                ['parent_id' => 62, 'child_id' => 65],
            ]),
            $this->factory($collection),
            null,
            $this->stock([63 => 0, 64 => 1])
        );

        self::assertSame([49], $provider->addData(['62' => ['id' => '62']], 1)['62']['color']);
    }

    public function testEveryChildOutOfStockSkipsTheChildLoad(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::never())->method('create');
        $document = ['id' => '62', 'color' => [50]];

        $provider = $this->provider(
            $this->resource([['parent_id' => 62, 'child_id' => 63]]),
            $factory,
            null,
            $this->stock([63 => 1])
        );

        self::assertSame(['62' => $document], $provider->addData(['62' => $document], 1));
    }

    public function testNonOptionAttributesAreUntouched(): void
    {
        $provider = $this->provider(
            $this->resource([['parent_id' => 62, 'child_id' => 63]]),
            $this->factory($this->childCollection([63 => ['name' => 'Child name', 'color' => '49']]))
        );

        $documents = $provider->addData(['62' => ['id' => '62', 'name' => 'Parent name']], 1);

        self::assertSame('Parent name', $documents['62']['name']);
        self::assertSame([49], $documents['62']['color']);
    }

    public function testCompositeWithoutEnabledChildrenKeepsItsDocument(): void
    {
        $provider = $this->provider(
            $this->resource([['parent_id' => 62, 'child_id' => 63]]),
            $this->factory($this->childCollection([]))
        );

        $document = ['id' => '62', 'color' => ['50']];

        self::assertSame(['62' => $document], $provider->addData(['62' => $document], 1));
    }

    public function testNoRelationsSkipsTheChildLoad(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::never())->method('create');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->expects(self::never())->method('getStore');

        $documents = ['42' => ['id' => '42', 'color' => ['7']]];

        self::assertSame($documents, $this->provider($this->resource([]), $factory, $storeManager)
            ->addData($documents, 1));
    }

    public function testEmptyDocumentSetSkipsTheRelationQuery(): void
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects(self::never())->method('getConnection');
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::never())->method('create');

        self::assertSame([], $this->provider($resource, $factory)->addData([], 1));
    }
}
