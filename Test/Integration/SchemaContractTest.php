<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;

/**
 * The computed schema (C1) and builder-cast documents accept the queries typesense-search sends.
 */
class SchemaContractTest extends TypesenseTestCase
{
    /** @var string */
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $attributes = $this->attributes();
        $schema = $this->desiredSchema($attributes);
        $names = array_map(static fn (FieldSpec $f): string => $f->getName(), $schema);
        self::assertContains('price_[0-9]+_.*', $names);
        self::assertContains('position_category_.*', $names);
        self::assertNotContains('position_category_777', $names);

        $this->collection = $this->createCollection('contract', $schema);
        $this->import($this->collection, $this->buildDocuments($attributes, [
            // A configurable whose `color` carries its children's values (49 own, 50 from a child).
            1 => $this->product('MH01', [
                'color' => '49,50',
                'eco_collection' => '1',
                'manufacturer' => '7',
                'manufacturer_value' => ['Zeta'],
                'sort_manufacturer' => 'Zeta',
                'position_category_777' => '1',
                'price_9_1' => '52.000000',
            ]),
            2 => $this->product('MH02', [
                'color' => '49',
                'eco_collection' => '0',
                'manufacturer' => '8',
                'manufacturer_value' => ['Acme'],
                'sort_manufacturer' => 'Acme',
                'position_category_777' => '3',
                'price_9_1' => '10.000000',
            ]),
            3 => $this->product('MH03', [
                'color' => '51',
                'eco_collection' => '0',
                'manufacturer' => '9',
                'manufacturer_value' => ['Mango'],
                'sort_manufacturer' => 'Mango',
                'position_category_777' => '2',
                'price_9_1' => '30.000000',
            ]),
        ]));
    }

    public function testBooleanFiltersWithAnInt(): void
    {
        $result = $this->searchOk($this->collection, ['filter_by' => 'eco_collection:=1']);

        self::assertSame(['1'], $this->hitIds($result));
    }

    public function testConfigurableMatchesAChildOptionValue(): void
    {
        $result = $this->searchOk($this->collection, ['filter_by' => 'color:=[50]']);

        self::assertSame(['1'], $this->hitIds($result));
    }

    public function testOptionFacetCountsEveryValue(): void
    {
        $result = $this->searchOk($this->collection, ['facet_by' => 'color']);

        self::assertSame(['49' => 2, '50' => 1, '51' => 1], $this->facetCounts($result, 'color'));
    }

    public function testPositionOfACategoryUnknownAtCreationSorts(): void
    {
        $result = $this->searchOk($this->collection, ['sort_by' => 'position_category_777:asc']);

        self::assertSame(['1', '3', '2'], $this->hitIds($result));
    }

    public function testPriceOfAGroupUnknownAtCreationFacets(): void
    {
        $result = $this->searchOk($this->collection, ['facet_by' => 'price_9_1', 'sort_by' => 'price_9_1:desc']);

        self::assertSame(['1', '3', '2'], $this->hitIds($result));
        self::assertSame(3, array_sum($this->facetCounts($result, 'price_9_1')));
    }

    public function testOptionLabelIsSearchable(): void
    {
        $result = $this->searchOk($this->collection, ['q' => 'acme', 'query_by' => 'manufacturer_value']);

        self::assertSame(['2'], $this->hitIds($result));
    }

    public function testSortsByOptionLabel(): void
    {
        $result = $this->searchOk($this->collection, ['sort_by' => 'sort_manufacturer:asc']);

        self::assertSame(['2', '3', '1'], $this->hitIds($result));
    }

    public function testBundleKeysSharingThePricePrefixAreAccepted(): void
    {
        $attributes = array_merge($this->attributes(), [
            $this->attribute('price_view', 'int', 'select'),
            $this->attribute('price_type', 'int', 'boolean'),
        ]);
        $collection = $this->createCollection('bundle', $this->desiredSchema($attributes));
        // import() asserts every document succeeded: an `int32[]` price_view under a price regex is rejected.
        $this->import($collection, $this->buildDocuments($attributes, [
            46 => $this->product('24-WG080', [
                'price_view' => '1',
                'price_type' => '0',
                'price_view_value' => ['As Low as'],
                'price_0_1' => '61.000000',
            ]),
        ]));

        $result = $this->searchOk($collection, ['facet_by' => 'price_0_1']);

        self::assertSame(['46'], $this->hitIds($result));
        self::assertSame(['61' => 1], $this->facetCounts($result, 'price_0_1'));
    }

    /**
     * @return Attribute[]
     */
    private function attributes(): array
    {
        return [
            $this->attribute('name', 'varchar', 'text', ['is_searchable' => 1]),
            $this->attribute('color', 'int', 'select', ['is_filterable' => 1]),
            $this->attribute('eco_collection', 'int', 'boolean', ['is_filterable' => 1]),
            $this->attribute('manufacturer', 'int', 'select', ['is_searchable' => 1, 'used_for_sort_by' => 1]),
        ];
    }

    /**
     * Raw values of one product, as the data-provider chain would add them.
     *
     * @param string $sku
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function product(string $sku, array $values): array
    {
        return ['sku' => $sku, 'name' => 'Product ' . $sku, 'status' => '1', 'visibility' => '4'] + $values;
    }
}
