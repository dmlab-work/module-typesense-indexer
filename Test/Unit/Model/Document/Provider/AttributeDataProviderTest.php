<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use MageDevGroup\TypesenseIndexer\Model\Document\Provider\AttributeDataProvider;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as AttributeCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AttributeDataProviderTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $products id ⇒ its getData() payload
     * @param array<string,array{input?:string,backend?:string}> $attributes code ⇒ its type info
     */
    private function provider(array $products, array $attributes = []): AttributeDataProvider
    {
        $items = [];
        foreach ($products as $id => $data) {
            $product = $this->createStub(Product::class);
            $product->method('getId')->willReturn($id);
            $product->method('getData')->willReturn($data);
            $items[] = $product;
        }

        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new AttributeDataProvider($factory, $this->attributeFactory($attributes));
    }

    /**
     * @param array<string,array{input?:string,backend?:string}> $attributes
     */
    private function attributeFactory(array $attributes): AttributeCollectionFactory
    {
        $items = [];
        foreach ($attributes as $code => $type) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getFrontendInput')->willReturn($type['input'] ?? 'text');
            $attribute->method('getBackendType')->willReturn($type['backend'] ?? 'varchar');
            $items[] = $attribute;
        }

        $collection = $this->createStub(AttributeCollection::class);
        $collection->method('getItems')->willReturn($items);

        $factory = $this->createStub(AttributeCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    public function testWritesEveryAttributeValueRegardlessOfFlagsTheFatInvariant(): void
    {
        // A realistic multi-attribute product: searchable, filterable, sortable AND plain
        // attributes with no schema flags at all. The FAT rule says all of them land.
        $provider = $this->provider([
            42 => [
                'sku' => 'ABC-1',
                'name' => 'Red Shirt',
                'color' => 7,
                'visibility' => 4,
                'status' => 1,
                'internal_note' => 'do not discount',
                'manufacturer' => 'Acme',
                'weight' => '0.5',
            ],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42', 'store_id' => 1]], 1);

        // Non-searchable, non-filterable, non-sortable values are still present.
        self::assertSame('do not discount', $documents['42']['internal_note']);
        self::assertSame('Acme', $documents['42']['manufacturer']);
        self::assertSame('0.5', $documents['42']['weight']);
        // The flagged and system attributes come through too.
        self::assertSame('Red Shirt', $documents['42']['name']);
        self::assertSame(4, $documents['42']['visibility']);
        self::assertSame(1, $documents['42']['status']);
        // The seeded id and store_id are preserved.
        self::assertSame('42', $documents['42']['id']);
        self::assertSame(1, $documents['42']['store_id']);
    }

    public function testDropsRawStorageKeysThatAreNotCatalogData(): void
    {
        $provider = $this->provider([
            42 => ['sku' => 'ABC-1', 'entity_id' => 42, 'row_id' => 99, 'created_at' => 'x', 'updated_at' => 'y'],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(['id' => '42', 'sku' => 'ABC-1'], $documents['42']);
    }

    public function testIgnoresProductsNotInTheDocumentSet(): void
    {
        $provider = $this->provider([
            42 => ['sku' => 'ABC-1'],
            99 => ['sku' => 'ZZZ-9'],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertArrayNotHasKey('99', $documents);
    }

    public function testEmptyDocumentSetSkipsTheQueryEntirely(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::never())->method('create');
        $attributeFactory = $this->createMock(AttributeCollectionFactory::class);
        $attributeFactory->expects(self::never())->method('create');

        self::assertSame([], (new AttributeDataProvider($factory, $attributeFactory))->addData([], 1));
    }

    public function testSplitsMultiselectOptionIdsIntoAnArray(): void
    {
        // The FAT value on disk must already be the array a later `string[]` facet PATCH expects —
        // the raw EAV value is the comma-joined option-id string.
        $provider = $this->provider(
            [42 => ['color' => '12,15,33', 'sku' => 'ABC-1']],
            ['color' => ['input' => 'multiselect']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(['12', '15', '33'], $documents['42']['color']);
        // A plain attribute is untouched.
        self::assertSame('ABC-1', $documents['42']['sku']);
    }

    public function testEmptyMultiselectBecomesAnEmptyArrayNotAOneElementEmptyString(): void
    {
        $provider = $this->provider(
            [42 => ['color' => '']],
            ['color' => ['input' => 'multiselect']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame([], $documents['42']['color']);
    }

    public function testConvertsDatetimeToUnixEpoch(): void
    {
        // A datetime attribute maps to `int64`; the value must be an epoch, not a `(int)`-mangled
        // date string (which would collapse `2025-06-01 00:00:00` to `2025`).
        $provider = $this->provider(
            [42 => ['special_from_date' => '2025-06-01 00:00:00']],
            ['special_from_date' => ['backend' => 'datetime']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(strtotime('2025-06-01 00:00:00'), $documents['42']['special_from_date']);
    }

    public function testLeavesValuesOfUnmappedKeysUntouched(): void
    {
        // A key with no attribute metadata (price_x_y, category fields, system columns) passes through.
        $provider = $this->provider(
            [42 => ['price_0_0' => '9.99', 'color' => '12,15']],
            ['color' => ['input' => 'multiselect']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame('9.99', $documents['42']['price_0_0']);
        self::assertSame(['12', '15'], $documents['42']['color']);
    }
}
