<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\PublicCollection;

use DmLab\TypesenseIndexer\Model\PublicCollection\PublicDocumentProjector;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PublicDocumentProjectorTest extends TestCase
{
    /**
     * @param string $code
     * @param string $frontend
     * @param string $backend
     * @param string|null $exposedBy the storefront flag set on the attribute (getter name), null for admin-only
     */
    private function attribute(string $code, string $frontend, string $backend, ?string $exposedBy = null): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($frontend);
        $attribute->method('getBackendType')->willReturn($backend);
        if ($exposedBy !== null) {
            $attribute->method($exposedBy)->willReturn('1');
        }

        return $attribute;
    }

    private function projector(?CollectionFactory $factory = null): PublicDocumentProjector
    {
        if ($factory === null) {
            $factory = $this->createStub(CollectionFactory::class);
            $factory->method('create')->willReturn($this->collection());
        }

        $policy = new AttributeFieldPolicy(new FieldNameResolver());

        return new PublicDocumentProjector(new OptionAttributeList($factory, $policy), $policy);
    }

    private function collection(): Collection
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([
            $this->attribute('color', 'select', 'int', 'getIsFilterable'),
            $this->attribute('manufacturer', 'select', 'int', 'getIsVisibleOnFront'),
            $this->attribute('name', 'text', 'varchar', 'getIsSearchable'),
            $this->attribute('status', 'select', 'int'),
            $this->attribute('internal_flag', 'select', 'int'),
        ]);

        return $collection;
    }

    /**
     * @return array<string,mixed>
     */
    private function document(): array
    {
        return [
            'id' => '62',
            'name' => 'Hero Hoodie',
            'sku' => 'MH01',
            'short_description' => 'Warm',
            'visibility' => 4,
            'status' => 1,
            'url_key' => 'hero-hoodie',
            'thumbnail' => '/m/h/mh01.jpg',
            'image' => '/m/h/mh01-big.jpg',
            'tax_class_id' => [2],
            'price_0_1' => 54.0,
            'price_1_1' => 49.0,
            'price_4_1' => 40.0,
            'price_0_2' => 60.0,
            'color' => [50, 52],
            'color_value' => ['Black', 'Blue'],
            'sort_color' => 'Black',
            'manufacturer' => [7],
            'manufacturer_value' => ['Acme'],
            'category_ids' => [3, 9],
            'position_category_9' => 1,
            'qty' => 100.0,
            'is_in_stock' => true,
            'status_value' => ['Enabled'],
            'internal_flag' => [3],
            'internal_flag_value' => ['Supplier B'],
        ];
    }

    public function testNoGroupPriceKeySurvives(): void
    {
        $public = $this->projector()->project($this->document(), 1);

        foreach (array_keys($public) as $key) {
            self::assertStringStartsNotWith('price_', $key);
        }
    }

    public function testGroupZeroPriceOfTheWebsiteIsMapped(): void
    {
        self::assertSame(54.0, $this->projector()->project($this->document(), 1)['price']);
        self::assertSame(60.0, $this->projector()->project($this->document(), 2)['price']);
    }

    public function testExactKeySet(): void
    {
        $public = $this->projector()->project($this->document(), 1);

        self::assertEqualsCanonicalizing(
            [
                'id', 'name', 'sku', 'short_description', 'visibility', 'status', 'url_key', 'thumbnail',
                'color_value', 'manufacturer_value', 'price', 'tax_class_id', 'is_in_stock',
            ],
            array_keys($public)
        );
    }

    public function testEveryOptionLabelIsKeptAndIdAndSortFieldsDropped(): void
    {
        $public = $this->projector()->project($this->document(), 1);

        self::assertSame(['Black', 'Blue'], $public['color_value']);
        self::assertSame(['Acme'], $public['manufacturer_value']);
        self::assertArrayNotHasKey('color', $public);
        self::assertArrayNotHasKey('manufacturer', $public);
        self::assertArrayNotHasKey('sort_color', $public);
    }

    public function testLabelsOfNonExposedOptionAttributesAreDropped(): void
    {
        $public = $this->projector()->project($this->document(), 1);

        self::assertArrayNotHasKey('status_value', $public);
        self::assertArrayNotHasKey('internal_flag_value', $public);
    }

    #[DataProvider('exposureFlagProvider')]
    public function testEveryStorefrontFlagExposesLabels(string $getter): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$this->attribute('internal_flag', 'select', 'int', $getter)]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $public = $this->projector($factory)->project($this->document(), 1);

        self::assertSame(['Supplier B'], $public['internal_flag_value']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function exposureFlagProvider(): array
    {
        return [
            'searchable' => ['getIsSearchable'],
            'filterable' => ['getIsFilterable'],
            'filterable in search' => ['getIsFilterableInSearch'],
            'visible on front' => ['getIsVisibleOnFront'],
            'used in listing' => ['getUsedInProductListing'],
            'used for sort' => ['getUsedForSortBy'],
        ];
    }

    public function testThumbnailFallsBackToSmallImageThenImage(): void
    {
        $document = ['thumbnail' => 'no_selection', 'small_image' => '/s.jpg', 'image' => '/i.jpg'] + $this->document();
        self::assertSame('/s.jpg', $this->projector()->project($document, 1)['thumbnail']);

        $document = ['thumbnail' => '', 'small_image' => 'no_selection', 'image' => '/i.jpg'] + $this->document();
        self::assertSame('/i.jpg', $this->projector()->project($document, 1)['thumbnail']);

        $document = ['thumbnail' => null, 'image' => '/i.jpg'] + $this->document();
        self::assertSame('/i.jpg', $this->projector()->project($document, 1)['thumbnail']);
    }

    public function testThumbnailIsKeptWhenSet(): void
    {
        self::assertSame('/m/h/mh01.jpg', $this->projector()->project($this->document(), 1)['thumbnail']);
    }

    public function testNoImageAtAllOmitsThumbnail(): void
    {
        $document = ['thumbnail' => 'no_selection', 'small_image' => 'no_selection', 'image' => 'no_selection']
            + $this->document();
        $public = $this->projector()->project($document, 1);

        self::assertArrayNotHasKey('thumbnail', $public);
        self::assertArrayNotHasKey('small_image', $public);
        self::assertArrayNotHasKey('image', $public);
    }

    public function testTaxClassIsScalarized(): void
    {
        self::assertSame(2, $this->projector()->project($this->document(), 1)['tax_class_id']);

        $document = ['tax_class_id' => 3] + $this->document();
        self::assertSame(3, $this->projector()->project($document, 1)['tax_class_id']);

        $document = ['tax_class_id' => []] + $this->document();
        self::assertArrayNotHasKey('tax_class_id', $this->projector()->project($document, 1));
    }

    public function testInStockComesFromOutOfStockWhenPresent(): void
    {
        $document = ['is_out_of_stock' => 1, 'is_in_stock' => true] + $this->document();
        self::assertSame(0, $this->projector()->project($document, 1)['is_in_stock']);

        $document = ['is_out_of_stock' => 0, 'is_in_stock' => false] + $this->document();
        self::assertSame(1, $this->projector()->project($document, 1)['is_in_stock']);
    }

    public function testInStockFallsBackToLegacyValue(): void
    {
        self::assertSame(1, $this->projector()->project($this->document(), 1)['is_in_stock']);

        $document = ['is_in_stock' => 0] + $this->document();
        self::assertSame(0, $this->projector()->project($document, 1)['is_in_stock']);
    }

    public function testMissingKeysAreOmitted(): void
    {
        $public = $this->projector()->project(['id' => '5', 'sku' => 'X'], 1);

        self::assertSame(['id' => '5', 'sku' => 'X'], $public);
    }

    public function testOptionAttributesAreLoadedOnce(): void
    {
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::once())->method('create')->willReturn($this->collection());
        $projector = $this->projector($factory);

        $projector->project($this->document(), 1);
        $projector->project($this->document(), 1);
    }
}
