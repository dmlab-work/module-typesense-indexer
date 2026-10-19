<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\PublicCollection;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

class PublicSchemaBuilderTest extends TestCase
{
    private const SELECT = ['frontend' => 'select', 'backend' => 'int'];

    /**
     * @param array<string,mixed> $data
     */
    private function attribute(array $data): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($data['code']);
        $attribute->method('getIsSearchable')->willReturn($data['searchable'] ?? 0);
        $attribute->method('getIsFilterable')->willReturn($data['filterable'] ?? 0);
        $attribute->method('getUsedForSortBy')->willReturn($data['sort'] ?? 0);
        $attribute->method('getBackendType')->willReturn($data['backend'] ?? 'varchar');
        $attribute->method('getFrontendInput')->willReturn($data['frontend'] ?? 'text');

        return $attribute;
    }

    /**
     * @return array<string,FieldSpec>
     */
    private function build(): array
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([
            $this->attribute(['code' => 'manufacturer', 'searchable' => 1] + self::SELECT),
            $this->attribute(['code' => 'color', 'filterable' => 1, 'sort' => 1] + self::SELECT),
            $this->attribute(['code' => 'description', 'searchable' => 1, 'frontend' => 'textarea']),
            $this->attribute(['code' => 'price', 'filterable' => 1, 'backend' => 'decimal']),
        ]);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $builder = new PublicSchemaBuilder($factory, new AttributeFieldPolicy(new FieldNameResolver()));
        $byName = [];
        foreach ($builder->build(1) as $spec) {
            $byName[$spec->getName()] = $spec;
        }

        return $byName;
    }

    public function testExactFieldList(): void
    {
        self::assertSame(
            ['name', 'sku', 'short_description', 'manufacturer_value', 'visibility', 'status', 'is_in_stock', 'price'],
            array_keys($this->build())
        );
    }

    public function testFieldTypes(): void
    {
        $types = array_map(static fn (FieldSpec $spec) => $spec->getType(), $this->build());

        self::assertSame([
            'name' => 'string',
            'sku' => 'string',
            'short_description' => 'string',
            'manufacturer_value' => 'string[]',
            'visibility' => 'int32',
            'status' => 'int32',
            'is_in_stock' => 'int32',
            'price' => 'float',
        ], $types);
    }

    public function testNoGroupPriceFieldAndNoFacets(): void
    {
        foreach ($this->build() as $name => $spec) {
            self::assertStringStartsNotWith('price_', $name);
            self::assertNull($spec->getFacet(), $name);
        }
    }

    public function testPriceIsSortableAndOptional(): void
    {
        $price = $this->build()['price'];

        self::assertTrue($price->getSort());
        self::assertTrue($price->getOptional());
    }

    public function testSearchableLabelFieldIsIndexedAndOptional(): void
    {
        $label = $this->build()['manufacturer_value'];

        self::assertTrue($label->getIndex());
        self::assertTrue($label->getOptional());
    }

    public function testNameIsSortable(): void
    {
        self::assertTrue($this->build()['name']->getSort());
    }
}
