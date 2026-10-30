<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema;

use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\SearchableFieldsProvider;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

// The attribute mocks below are used as return stubs, not expectation targets.
#[AllowMockObjectsWithoutExpectations]
class SearchableFieldsProviderTest extends TestCase
{
    /**
     * @param array<string,mixed> $data
     */
    private function attribute(array $data): Attribute
    {
        // `getSearchWeight` is a magic DataObject getter (no declared method), so it reads `_data`.
        $attribute = $this->createPartialMock(
            Attribute::class,
            ['getAttributeCode', 'getIsSearchable', 'getFrontendInput', 'getBackendType', 'getSourceModel']
        );
        if (array_key_exists('weight', $data)) {
            $attribute->setData('search_weight', $data['weight']);
        }
        $attribute->method('getAttributeCode')->willReturn($data['code'] ?? 'attr');
        $attribute->method('getIsSearchable')->willReturn($data['searchable'] ?? 0);
        $attribute->method('getFrontendInput')->willReturn($data['input'] ?? 'text');
        $attribute->method('getBackendType')->willReturn($data['backend'] ?? 'varchar');
        $attribute->method('getSourceModel')->willReturn($data['source'] ?? null);

        return $attribute;
    }

    /**
     * @param Attribute[] $items
     */
    private function provider(array $items): SearchableFieldsProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($items);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new SearchableFieldsProvider($factory, new AttributeFieldPolicy(new FieldNameResolver()));
    }

    public function testReturnsOnlySearchableAttributesWithTheirWeight(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'name', 'searchable' => 1, 'weight' => 5]),
            $this->attribute(['code' => 'sku', 'searchable' => 1, 'weight' => 10]),
            $this->attribute(['code' => 'color', 'searchable' => 0, 'weight' => 3]),
        ]);

        self::assertSame(['name' => 5, 'sku' => 10], $provider->get(1));
    }

    public function testUnsetOrZeroWeightDefaultsToOne(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'name', 'searchable' => 1, 'weight' => 0]),
            $this->attribute(['code' => 'description', 'searchable' => 1]),
        ]);

        self::assertSame(['name' => 1, 'description' => 1], $provider->get(1));
    }

    public function testStringWeightFromTheDatabaseIsCastToInt(): void
    {
        $provider = $this->provider([$this->attribute(['code' => 'name', 'searchable' => 1, 'weight' => '5'])]);

        self::assertSame(['name' => 5], $provider->get(1));
    }

    public function testDisabledSearchAttributeIsAbsent(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'internal_note', 'searchable' => 0, 'weight' => 7]),
        ]);

        self::assertArrayNotHasKey('internal_note', $provider->get(1));
        self::assertSame([], $provider->get(1));
    }

    public function testContextScopedAttributesAreExcluded(): void
    {
        // `price` is a context-scoped code: unscoped it collapses to `price_0_0` (a float no
        // document fills and an invalid `query_by` target), so the searchable seam must omit it.
        $provider = $this->provider([
            $this->attribute(['code' => 'price', 'searchable' => 1, 'weight' => 2]),
            $this->attribute(['code' => 'name', 'searchable' => 1, 'weight' => 5]),
        ]);

        self::assertSame(['name' => 5], $provider->get(1));
    }

    public function testNeverReturnsARegexFieldName(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'price', 'searchable' => 1, 'input' => 'text', 'backend' => 'varchar']),
            $this->attribute(['code' => 'position', 'searchable' => 1, 'input' => 'text', 'backend' => 'varchar']),
            $this->attribute(['code' => 'name', 'searchable' => 1]),
        ]);

        self::assertSame(['name' => 1], $provider->get(1));
    }

    public function testNonStringSearchableAttributesAreExcluded(): void
    {
        // Typesense only allows string/string[] fields in `query_by`; a searchable numeric or boolean
        // attribute would make every search request fail, so it is omitted. An option attribute is
        // searched through its `<code>_value` labels; a custom-source multiselect (string[]) stays.
        $provider = $this->provider([
            $this->attribute(['code' => 'name', 'searchable' => 1, 'weight' => 5]),
            $this->attribute(['code' => 'weight_kg', 'searchable' => 1, 'weight' => 2, 'backend' => 'decimal']),
            $this->attribute(['code' => 'qty_int', 'searchable' => 1, 'weight' => 2, 'backend' => 'int']),
            $this->attribute(['code' => 'in_stock', 'searchable' => 1, 'weight' => 2, 'input' => 'boolean']),
            $this->attribute(['code' => 'colors', 'searchable' => 1, 'weight' => 3, 'input' => 'multiselect']),
            $this->attribute([
                'code' => 'tags', 'searchable' => 1, 'weight' => 3, 'input' => 'multiselect',
                'source' => 'Vendor\\Module\\Model\\Source\\Tags',
            ]),
        ]);

        self::assertSame(['name' => 5, 'colors_value' => 3, 'tags' => 3], $provider->get(1));
    }

    public function testOptionAttributeIsSearchedThroughItsLabelField(): void
    {
        $provider = $this->provider([
            $this->attribute(['code' => 'manufacturer', 'searchable' => 1, 'weight' => 4, 'input' => 'select',
                'backend' => 'int']),
            $this->attribute(['code' => 'color', 'searchable' => 0, 'input' => 'select', 'backend' => 'int']),
        ]);

        self::assertSame(['manufacturer_value' => 4], $provider->get(1));
    }

    public function testWeightsAreCarriedPerStore(): void
    {
        // The collection is fetched fresh per call, so a store-scoped attribute set is honoured;
        // here both stores see the same searchable field and its weight.
        $provider = $this->provider([
            $this->attribute(['code' => 'name', 'searchable' => 1, 'weight' => 4]),
        ]);

        self::assertSame(['name' => 4], $provider->get(1));
        self::assertSame(['name' => 4], $provider->get(2));
    }

    public function testEmptyCollectionYieldsNoFields(): void
    {
        self::assertSame([], $this->provider([])->get(1));
    }
}
