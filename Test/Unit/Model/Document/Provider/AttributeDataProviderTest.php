<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\Provider\AttributeDataProvider;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
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
     * @param array<string,array{input?:string,backend?:string,html?:int}> $attributes code ⇒ its type info
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

        return new AttributeDataProvider($factory, $this->attributeFactory($attributes), $this->policy());
    }

    private function policy(): AttributeFieldPolicy
    {
        return new AttributeFieldPolicy(new FieldNameResolver());
    }

    /**
     * @param array<string,array{input?:string,backend?:string,html?:int}> $attributes
     */
    private function attributeFactory(array $attributes): AttributeCollectionFactory
    {
        $items = [];
        foreach ($attributes as $code => $type) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getFrontendInput')->willReturn($type['input'] ?? 'text');
            $attribute->method('getBackendType')->willReturn($type['backend'] ?? 'varchar');
            $attribute->method('getIsHtmlAllowedOnFront')->willReturn($type['html'] ?? 0);
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
        ], [
            'sku' => ['backend' => 'static'],
            'name' => [],
            'color' => ['input' => 'select', 'backend' => 'int'],
            'visibility' => ['input' => 'select', 'backend' => 'int'],
            'status' => ['input' => 'select', 'backend' => 'int'],
            'internal_note' => [],
            'manufacturer' => [],
            'weight' => ['backend' => 'decimal'],
        ]);

        $documents = $provider->addData(['42' => ['id' => '42', 'store_id' => 1]], 1);

        // Non-searchable, non-filterable, non-sortable values are still present.
        self::assertSame('do not discount', $documents['42']['internal_note']);
        self::assertSame('Acme', $documents['42']['manufacturer']);
        self::assertSame('0.5', $documents['42']['weight']);
        // The flagged and system attributes come through too.
        self::assertSame('Red Shirt', $documents['42']['name']);
        self::assertSame(['7'], $documents['42']['color']);
        self::assertSame(['4'], $documents['42']['visibility']);
        self::assertSame(['1'], $documents['42']['status']);
        // The seeded id and store_id are preserved.
        self::assertSame('42', $documents['42']['id']);
        self::assertSame(1, $documents['42']['store_id']);
    }

    public function testDropsRawStorageKeysThatAreNotCatalogData(): void
    {
        $provider = $this->provider(
            [42 => ['sku' => 'ABC-1', 'entity_id' => 42, 'row_id' => 99, 'created_at' => 'x', 'updated_at' => 'y']],
            ['sku' => [], 'created_at' => ['backend' => 'static'], 'updated_at' => ['backend' => 'static']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(['id' => '42', 'sku' => 'ABC-1'], $documents['42']);
    }

    public function testIgnoresProductsNotInTheDocumentSet(): void
    {
        $provider = $this->provider([
            42 => ['sku' => 'ABC-1'],
            99 => ['sku' => 'ZZZ-9'],
        ], ['sku' => []]);

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertArrayNotHasKey('99', $documents);
    }

    public function testEmptyDocumentSetSkipsTheQueryEntirely(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::never())->method('create');
        $attributeFactory = $this->createMock(AttributeCollectionFactory::class);
        $attributeFactory->expects(self::never())->method('create');

        self::assertSame([], (new AttributeDataProvider($factory, $attributeFactory, $this->policy()))->addData([], 1));
    }

    public function testSplitsMultiselectOptionIdsIntoAnArray(): void
    {
        // The FAT value on disk must already be the array a later `string[]` facet PATCH expects —
        // the raw EAV value is the comma-joined option-id string.
        $provider = $this->provider(
            [42 => ['color' => '12,15,33', 'sku' => 'ABC-1']],
            ['color' => ['input' => 'multiselect'], 'sku' => []]
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

        self::assertSame(1748736000, $documents['42']['special_from_date']);
    }

    public function testKeepsANumericDatetimeAndDropsAnUnparseableOne(): void
    {
        $provider = $this->provider(
            [42 => ['special_from_date' => '1748736000', 'news_from_date' => 'not a date']],
            ['special_from_date' => ['backend' => 'datetime'], 'news_from_date' => ['backend' => 'datetime']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(1748736000, $documents['42']['special_from_date']);
        self::assertNull($documents['42']['news_from_date'] ?? null);
    }

    public function testDropsNonAttributeKeysButKeepsTypeAndAttributeSet(): void
    {
        $provider = $this->provider(
            [42 => [
                'price_0_0' => '9.99',
                'is_salable' => '1',
                'options' => [],
                'type_id' => 'configurable',
                'attribute_set_id' => '4',
                'color' => '12,15',
            ]],
            ['color' => ['input' => 'multiselect']]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(
            ['id' => '42', 'type_id' => 'configurable', 'attribute_set_id' => '4', 'color' => ['12', '15']],
            $documents['42']
        );
    }

    public function testDropsGalleryAndNonScalarValuesOfNonOptionAttributes(): void
    {
        $provider = $this->provider(
            [42 => [
                'media_gallery' => ['images' => [['file' => '/a/b.jpg']]],
                'name' => ['not', 'scalar'],
                'tier_price' => [['qty' => 1]],
                'size' => ['20', '21'],
            ]],
            [
                'media_gallery' => ['input' => 'gallery'],
                'name' => [],
                'tier_price' => ['backend' => 'decimal'],
                'size' => ['input' => 'select', 'backend' => 'int'],
            ]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(['id' => '42', 'size' => ['20', '21']], $documents['42']);
    }

    public function testNormalizesSelectValueToAList(): void
    {
        $provider = $this->provider(
            [42 => ['manufacturer' => '12', 'country_of_manufacture' => 'DE', 'activity' => '']],
            [
                'manufacturer' => ['input' => 'select', 'backend' => 'int'],
                'country_of_manufacture' => ['input' => 'select'],
                'activity' => ['input' => 'select', 'backend' => 'int'],
            ]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame(['12'], $documents['42']['manufacturer']);
        self::assertSame([], $documents['42']['activity']);
        // A varchar select is not an option attribute and stays scalar.
        self::assertSame('DE', $documents['42']['country_of_manufacture']);
    }

    public function testKeepsRawImagePathsAsStrings(): void
    {
        $provider = $this->provider(
            [42 => ['image' => '/m/h/mh01.jpg', 'small_image' => '/m/h/s.jpg', 'thumbnail' => '/m/h/t.jpg']],
            [
                'image' => ['input' => 'media_image'],
                'small_image' => ['input' => 'media_image'],
                'thumbnail' => ['input' => 'media_image'],
            ]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame('/m/h/mh01.jpg', $documents['42']['image']);
        self::assertSame('/m/h/s.jpg', $documents['42']['small_image']);
        self::assertSame('/m/h/t.jpg', $documents['42']['thumbnail']);
    }

    public function testStripsHtmlAndCollapsesWhitespaceInHtmlText(): void
    {
        $provider = $this->provider(
            [42 => [
                'description' => "<p>Soft\n  <b>cotton</b></p>\t tee ",
                'short_description' => '<div>Light</div>  <i>tee</i>',
                'notes' => '<span>html   allowed</span>',
                'name' => '<b>Kept</b>',
            ]],
            [
                'description' => ['input' => 'textarea', 'backend' => 'text'],
                'short_description' => ['input' => 'pagebuilder', 'backend' => 'text'],
                'notes' => ['html' => 1],
                'name' => [],
            ]
        );

        $documents = $provider->addData(['42' => ['id' => '42']], 1);

        self::assertSame('Soft cotton tee', $documents['42']['description']);
        self::assertSame('Light tee', $documents['42']['short_description']);
        self::assertSame('html allowed', $documents['42']['notes']);
        // A plain text input without the HTML flag is left as read.
        self::assertSame('<b>Kept</b>', $documents['42']['name']);
    }

    public function testResetReloadsAttributeMetaSoALaterAttributeIsCast(): void
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(42);
        $product->method('getData')->willReturn(['size' => '5,6']);
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$product]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $size = $this->createStub(Attribute::class);
        $size->method('getAttributeCode')->willReturn('size');
        $size->method('getFrontendInput')->willReturn('multiselect');
        $size->method('getBackendType')->willReturn('varchar');
        $before = $this->createStub(AttributeCollection::class);
        $before->method('getItems')->willReturn([]);
        $after = $this->createStub(AttributeCollection::class);
        $after->method('getItems')->willReturn([$size]);
        $attributeFactory = $this->createMock(AttributeCollectionFactory::class);
        $attributeFactory->expects(self::exactly(2))->method('create')
            ->willReturnOnConsecutiveCalls($before, $after);

        $provider = new AttributeDataProvider($factory, $attributeFactory, $this->policy());

        self::assertArrayNotHasKey('size', $provider->addData(['42' => []], 1)['42']);
        $provider->reset();
        self::assertSame(['5', '6'], $provider->addData(['42' => []], 1)['42']['size']);
        self::assertSame(['5', '6'], $provider->addData(['42' => []], 1)['42']['size']);
    }
}
