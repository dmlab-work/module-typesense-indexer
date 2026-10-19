<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\AttributeTypeMap;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AttributeTypeMapTest extends TestCase
{
    /**
     * @param string $code
     * @param string $backend
     * @param string $input
     */
    private function attribute(string $code, string $backend, string $input): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getBackendType')->willReturn($backend);
        $attribute->method('getFrontendInput')->willReturn($input);

        return $attribute;
    }

    /**
     * @param FieldSpec[] $schema
     */
    private function fieldProvider(array $schema): FieldProviderInterface
    {
        $provider = $this->createStub(FieldProviderInterface::class);
        $provider->method('getFields')->willReturn($schema);

        return $provider;
    }

    public function testSchemaTypesOverridePolicyTypes(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([
            $this->attribute('visibility', 'int', 'select'),
            $this->attribute('color', 'int', 'select'),
            $this->attribute('eco_collection', 'int', 'boolean'),
            $this->attribute('price', 'decimal', 'price'),
        ]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $map = new AttributeTypeMap(
            $factory,
            new AttributeFieldPolicy(new FieldNameResolver()),
            new DesiredSchemaBuilder($this->fieldProvider([
                new FieldSpec('visibility', 'int32', facet: true),
                new FieldSpec('category_ids', 'int64[]', facet: true),
            ]))
        );

        self::assertSame(
            [
                'visibility' => 'int32',
                'visibility_value' => 'string[]',
                'sort_visibility' => 'string',
                'color' => 'int32[]',
                'color_value' => 'string[]',
                'sort_color' => 'string',
                'eco_collection' => 'int32',
                'price' => 'float',
                'category_ids' => 'int64[]',
            ],
            $map->get(1)
        );
    }

    public function testMemoizedPerStore(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$this->attribute('name', 'varchar', 'text')]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::exactly(2))->method('create')->willReturn($collection);
        $provider = $this->createMock(FieldProviderInterface::class);
        $provider->expects(self::exactly(2))->method('getFields')->willReturn([]);

        $map = new AttributeTypeMap(
            $factory,
            new AttributeFieldPolicy(new FieldNameResolver()),
            new DesiredSchemaBuilder($provider)
        );

        self::assertSame(['name' => 'string'], $map->get(1));
        self::assertSame(['name' => 'string'], $map->get(1));
        self::assertSame(['name' => 'string'], $map->get(2));
    }

    public function testResolveFallsBackToTheFirstMatchingRegexField(): void
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$this->attribute('price', 'decimal', 'price')]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $map = new AttributeTypeMap(
            $factory,
            new AttributeFieldPolicy(new FieldNameResolver()),
            new DesiredSchemaBuilder($this->fieldProvider([
                new FieldSpec('price_[0-9]+_.*', 'float', sort: true),
                new FieldSpec('position_category_.*', 'int32', sort: true),
            ]))
        );

        self::assertSame('float', $map->resolve(1, 'price'));
        self::assertSame('float', $map->resolve(1, 'price_7_3'));
        self::assertSame('int32', $map->resolve(1, 'position_category_999'));
        self::assertNull($map->resolve(1, 'xprice_1_1'));
        self::assertNull($map->resolve(1, 'price_view'), 'bundle attributes stay out of the price regex');
        self::assertNull($map->resolve(1, 'type_id'));
    }

    public function testOptionLabelFieldsAreExactStringEntriesThatBeatTheRegex(): void
    {
        // `price_view` is a bundle select: its label field must stay a string, not the `price_[0-9]+_.*` float.
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$this->attribute('price_view', 'int', 'select')]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $map = new AttributeTypeMap(
            $factory,
            new AttributeFieldPolicy(new FieldNameResolver()),
            new DesiredSchemaBuilder($this->fieldProvider([new FieldSpec('price_[0-9]+_.*', 'float', sort: true)]))
        );

        self::assertSame('string[]', $map->resolve(1, 'price_view_value'));
        self::assertSame('string', $map->resolve(1, 'sort_price_view'));
    }

    public function testResetReloadsSoALaterAttributeIsTyped(): void
    {
        $before = $this->createStub(Collection::class);
        $before->method('getItems')->willReturn([$this->attribute('name', 'varchar', 'text')]);
        $after = $this->createStub(Collection::class);
        $after->method('getItems')->willReturn([
            $this->attribute('name', 'varchar', 'text'),
            $this->attribute('size', 'int', 'select'),
        ]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::exactly(2))->method('create')->willReturnOnConsecutiveCalls($before, $after);

        $map = new AttributeTypeMap(
            $factory,
            new AttributeFieldPolicy(new FieldNameResolver()),
            new DesiredSchemaBuilder($this->fieldProvider([new FieldSpec('price_[0-9]+_.*', 'float')]))
        );

        self::assertNull($map->resolve(1, 'size'));
        $map->reset();
        self::assertSame('int32[]', $map->resolve(1, 'size'));
        self::assertSame('string[]', $map->resolve(1, 'size_value'));
        self::assertSame('float', $map->resolve(1, 'price_0_1'));
    }
}
