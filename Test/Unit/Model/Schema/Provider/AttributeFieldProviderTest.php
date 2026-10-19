<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\Provider\AttributeFieldProvider;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

class AttributeFieldProviderTest extends TestCase
{
    /**
     * @param array<string,mixed> $data
     */
    private function attribute(array $data): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($data['code'] ?? 'attr');
        $attribute->method('getIsSearchable')->willReturn($data['searchable'] ?? 0);
        $attribute->method('getIsFilterable')->willReturn($data['filterable'] ?? 0);
        $attribute->method('getIsFilterableInSearch')->willReturn($data['filterable_in_search'] ?? 0);
        $attribute->method('getUsedForSortBy')->willReturn($data['sort'] ?? 0);
        $attribute->method('getBackendType')->willReturn($data['backend'] ?? 'varchar');
        $attribute->method('getFrontendInput')->willReturn($data['frontend'] ?? 'text');

        return $attribute;
    }

    /**
     * @param Attribute[] $items
     */
    private function provider(array $items): AttributeFieldProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new AttributeFieldProvider($factory, new AttributeFieldPolicy(new FieldNameResolver()));
    }

    public function testMapsFlaggedAttributesToSpecs(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'color', 'filterable' => 1]),
            $this->attribute(['code' => 'name', 'searchable' => 1]),
        ]);

        $byName = [];
        foreach ($provider->getFields(1) as $spec) {
            $byName[$spec->getName()] = $spec;
        }

        self::assertSame(['color', 'name'], array_keys($byName));
        self::assertTrue($byName['color']->getFacet());
        self::assertTrue($byName['name']->getIndex());
    }

    public function testSkipsAttributesWithNoSchemaFlagsToKeepSchemaThin(): void
    {
        // A flagless attribute still lives in the FAT document, but declaring it would
        // waste RAM — so it contributes no schema field.
        $provider = $this->provider([
            $this->attribute(['code' => 'internal_note']),
            $this->attribute(['code' => 'color', 'filterable' => 1]),
        ]);

        $names = array_map(static fn (FieldSpec $f) => $f->getName(), $provider->getFields(1));

        self::assertNotContains('internal_note', $names);
        self::assertContains('color', $names);
    }

    public function testSkipsContextScopedAttributesOwnedByDedicatedProviders(): void
    {
        // `price` is flagged searchable/filterable/sortable but its scoped fields
        // (`price_<group>_<website>`) belong to PriceFieldProvider. The generic mapper must not
        // emit the empty-scope `price_0_0` — that field never receives any document data.
        $provider = $this->provider([
            $this->attribute(['code' => 'price', 'searchable' => 1, 'filterable' => 1, 'sort' => 1,
                'backend' => 'decimal', 'frontend' => 'price']),
            $this->attribute(['code' => 'position', 'sort' => 1, 'backend' => 'int']),
        ]);

        self::assertSame([], $provider->getFields(1));
    }

    public function testNeverEmitsARegexFieldName(): void
    {
        // `price_[0-9]+_.*` / `position_category_.*` belong to their dedicated providers only.
        $provider = $this->provider([
            $this->attribute(['code' => 'price', 'filterable' => 1, 'sort' => 1, 'backend' => 'decimal',
                'frontend' => 'price']),
            $this->attribute(['code' => 'position', 'sort' => 1, 'backend' => 'int']),
            $this->attribute(['code' => 'color', 'filterable' => 1]),
        ]);

        foreach ($provider->getFields(1) as $spec) {
            self::assertStringNotContainsString('.*', $spec->getName());
        }
    }

    public function testSkipsBaseCodesOwnedByStaticFieldProvider(): void
    {
        // sku/status/visibility are declared by StaticFieldProvider; re-emitting them here would make
        // the computed-schema dedup depend on DI order and could flip sku's `optional` false→true.
        $provider = $this->provider([
            $this->attribute(['code' => 'sku', 'searchable' => 1]),
            $this->attribute(['code' => 'status', 'filterable' => 1]),
            $this->attribute(['code' => 'visibility', 'filterable' => 1]),
            $this->attribute(['code' => 'color', 'filterable' => 1]),
        ]);

        $names = array_map(static fn (FieldSpec $f) => $f->getName(), $provider->getFields(1));

        self::assertSame(['color'], $names);
    }

    public function testSearchableOptionAttributeDeclaresItsLabelField(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'manufacturer', 'searchable' => 1, 'frontend' => 'select',
                'backend' => 'int']),
            $this->attribute(['code' => 'color', 'filterable' => 1, 'frontend' => 'select', 'backend' => 'int']),
        ]);

        $byName = [];
        foreach ($provider->getFields(1) as $spec) {
            $byName[$spec->getName()] = $spec->toArray();
        }

        self::assertSame(['manufacturer_value', 'color'], array_keys($byName));
        self::assertSame(
            ['name' => 'manufacturer_value', 'type' => 'string[]', 'index' => true, 'optional' => true],
            $byName['manufacturer_value']
        );
    }

    public function testSortableOptionAttributeDeclaresOnlyItsSortLabelField(): void
    {
        // The int32[] id field is never sortable; sorting goes through `sort_<code>`.
        $provider = $this->provider([
            $this->attribute(['code' => 'manufacturer', 'sort' => 1, 'frontend' => 'select', 'backend' => 'int']),
            $this->attribute(['code' => 'color', 'frontend' => 'select', 'backend' => 'int']),
        ]);

        $fields = array_map(static fn (FieldSpec $f) => $f->toArray(), $provider->getFields(1));

        self::assertSame(
            [['name' => 'sort_manufacturer', 'type' => 'string', 'sort' => true, 'optional' => true]],
            $fields
        );
    }

    public function testNonOptionAttributeHasNoLabelFields(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'name', 'searchable' => 1, 'sort' => 1]),
        ]);

        $names = array_map(static fn (FieldSpec $f) => $f->getName(), $provider->getFields(1));

        self::assertSame(['name'], $names);
    }

    public function testEmptyCollectionYieldsNoFields(): void
    {
        self::assertSame([], $this->provider([])->getFields(1));
    }
}
