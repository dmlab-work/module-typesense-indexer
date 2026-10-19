<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema;

use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AttributeFieldPolicyTest extends TestCase
{
    /**
     * @var AttributeFieldPolicy
     */
    private AttributeFieldPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AttributeFieldPolicy(new FieldNameResolver());
    }

    /**
     * Build an attribute stub with the flags/types a test needs.
     *
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
        $attribute->method('getIsVisibleOnFront')->willReturn($data['visible_on_front'] ?? 0);
        $attribute->method('getUsedInProductListing')->willReturn($data['in_listing'] ?? 0);
        $attribute->method('getBackendType')->willReturn($data['backend'] ?? 'varchar');
        $attribute->method('getFrontendInput')->willReturn($data['frontend'] ?? 'text');
        $attribute->method('getSourceModel')->willReturn($data['source'] ?? null);

        return $attribute;
    }

    public function testSearchableMapsToIndex(): void
    {
        $onSpec = $this->policy->toFieldSpec($this->attribute(['searchable' => 1]));
        $offSpec = $this->policy->toFieldSpec($this->attribute(['searchable' => 0]));

        self::assertTrue($onSpec->getIndex());
        self::assertNull($offSpec->getIndex());
    }

    public function testFilterableMapsToFacet(): void
    {
        $onSpec = $this->policy->toFieldSpec($this->attribute(['filterable' => 1]));
        $offSpec = $this->policy->toFieldSpec($this->attribute(['filterable' => 0]));

        self::assertTrue($onSpec->getFacet());
        self::assertNull($offSpec->getFacet());
    }

    public function testDeclaredFieldStatesOffFlagsAsFalse(): void
    {
        $spec = $this->policy->toFieldSpec($this->attribute(['searchable' => 1]));

        self::assertFalse($spec->getFacet());
        self::assertFalse($spec->getSort());
    }

    public function testFilterableInSearchAlsoMapsToFacet(): void
    {
        $spec = $this->policy->toFieldSpec($this->attribute(['filterable_in_search' => 1]));

        self::assertTrue($spec->getFacet());
    }

    public function testUsedForSortByMapsToSort(): void
    {
        $onSpec = $this->policy->toFieldSpec($this->attribute(['sort' => 1]));
        $offSpec = $this->policy->toFieldSpec($this->attribute(['sort' => 0]));

        self::assertTrue($onSpec->getSort());
        self::assertNull($offSpec->getSort());
    }

    public function testUsedForSortByIsDroppedForArrayTypes(): void
    {
        $spec = $this->policy->toFieldSpec(
            $this->attribute(['sort' => 1, 'frontend' => 'multiselect'])
        );

        self::assertSame('int32[]', $spec->getType());
        self::assertNull($spec->getSort());
        self::assertArrayNotHasKey('sort', $spec->toArray());
    }

    public function testUsedForSortBySurvivesForScalarTypes(): void
    {
        $spec = $this->policy->toFieldSpec(
            $this->attribute(['sort' => 1, 'backend' => 'int', 'frontend' => 'boolean'])
        );

        self::assertSame('int32', $spec->getType());
        self::assertTrue($spec->getSort());
    }

    public function testAttributeDerivedFieldsAreOptional(): void
    {
        $spec = $this->policy->toFieldSpec($this->attribute(['searchable' => 1]));

        self::assertTrue($spec->getOptional());
    }

    public function testFieldNameComesFromResolver(): void
    {
        $spec = $this->policy->toFieldSpec(
            $this->attribute(['code' => 'price', 'backend' => 'decimal', 'frontend' => 'price']),
            ['customerGroupId' => 1, 'websiteId' => 2]
        );

        self::assertSame('price_1_2', $spec->getName());
    }

    #[DataProvider('typeMappingProvider')]
    public function testTypeMapping(string $backend, string $frontend, ?string $source, string $expected): void
    {
        $attribute = $this->attribute(['backend' => $backend, 'frontend' => $frontend, 'source' => $source]);

        self::assertSame($expected, $this->policy->toFieldSpec($attribute)->getType());
        self::assertSame($expected, $this->policy->resolveType($attribute));
    }

    /**
     * @return array<string,array{0:string,1:string,2:?string,3:string}>
     */
    public static function typeMappingProvider(): array
    {
        return [
            'multiselect option ids'      => ['varchar', 'multiselect', null, 'int32[]'],
            'multiselect table source'    => ['varchar', 'multiselect', Table::class, 'int32[]'],
            'multiselect custom source'   => ['varchar', 'multiselect', 'Vendor\\Source\\Tags', 'string[]'],
            'boolean yes/no int32'        => ['int', 'boolean', null, 'int32'],
            'decimal float'               => ['decimal', 'price', null, 'float'],
            'int int32'                   => ['int', 'text', null, 'int32'],
            'smallint select int32'       => ['smallint', 'select', null, 'int32'],
            'datetime int64'              => ['datetime', 'date', null, 'int64'],
            'timestamp int64'             => ['timestamp', 'date', null, 'int64'],
            'varchar string'              => ['varchar', 'text', null, 'string'],
            'static string'               => ['static', 'text', null, 'string'],
            'select int option ids'       => ['int', 'select', null, 'int32[]'],
            'select varchar string'       => ['varchar', 'select', 'Vendor\\Source\\Codes', 'string'],
        ];
    }

    #[DataProvider('optionAttributeProvider')]
    public function testIsOptionAttribute(string $backend, string $frontend, ?string $source, bool $expected): void
    {
        $attribute = $this->attribute(['backend' => $backend, 'frontend' => $frontend, 'source' => $source]);

        self::assertSame($expected, $this->policy->isOptionAttribute($attribute));
    }

    /**
     * @return array<string,array{0:string,1:string,2:?string,3:bool}>
     */
    public static function optionAttributeProvider(): array
    {
        return [
            'select int'                        => ['int', 'select', null, true],
            'select int custom source'          => ['int', 'select', 'Vendor\\Source\\Codes', true],
            'select varchar'                    => ['varchar', 'select', null, false],
            'multiselect no source'             => ['varchar', 'multiselect', null, true],
            'multiselect empty source'          => ['varchar', 'multiselect', '', true],
            'multiselect table source'          => ['varchar', 'multiselect', Table::class, true],
            'multiselect table source, leading slash' =>['varchar', 'multiselect', '\\' . Table::class, true],
            'multiselect custom source'         => ['varchar', 'multiselect', 'Vendor\\Source\\Tags', false],
            'boolean'                           => ['int', 'boolean', null, false],
            'text'                              => ['varchar', 'text', null, false],
        ];
    }

    public function testOptionAttributeIsIndexedOnlyWhenFilterable(): void
    {
        $searchableOnly = $this->policy->toFieldSpec(
            $this->attribute(['backend' => 'int', 'frontend' => 'select', 'searchable' => 1])
        );
        $filterable = $this->policy->toFieldSpec(
            $this->attribute(['backend' => 'int', 'frontend' => 'select', 'filterable' => 1])
        );

        self::assertNull($searchableOnly->getIndex());
        self::assertTrue($filterable->getIndex());
        self::assertTrue($filterable->getFacet());
    }

    public function testSearchConfigChangedTracksAdvancedSearchFlagBeyondSchemaFlags(): void
    {
        // `is_visible_in_advanced_search` has no schema effect, so it is absent from SCHEMA_FLAGS, but
        // native resets the search-request config when it changes — searchConfigChanged must catch it.
        self::assertTrue($this->policy->searchConfigChanged($this->changedAttribute('is_visible_in_advanced_search')));
        self::assertTrue($this->policy->searchConfigChanged($this->changedAttribute('is_searchable')));
        self::assertFalse($this->policy->searchConfigChanged($this->changedAttribute('used_for_sort_by')));
        self::assertFalse($this->policy->searchConfigChanged($this->changedAttribute('position')));
    }

    public function testSchemaFlagsSetDetectsAnyTruthySchemaFlag(): void
    {
        foreach (AttributeFieldPolicy::SCHEMA_FLAGS as $flag) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getData')->willReturnCallback(
                static fn (string $key): ?string => $key === $flag ? '1' : null
            );
            self::assertTrue($this->policy->schemaFlagsSet($attribute), $flag);
        }

        $unflagged = $this->createStub(Attribute::class);
        $unflagged->method('getData')->willReturnCallback(
            static fn (string $key): ?string => $key === 'is_visible_in_advanced_search' ? '1' : '0'
        );
        self::assertFalse($this->policy->schemaFlagsSet($unflagged));
    }

    /**
     * An attribute stub that reports exactly one changed flag.
     */
    private function changedAttribute(string $changedFlag): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('dataHasChangedFor')->willReturnCallback(
            static fn (string $flag): bool => $flag === $changedFlag
        );

        return $attribute;
    }

    public function testNoWeightKeyEverReachesAFieldSpec(): void
    {
        $spec = $this->policy->toFieldSpec(
            $this->attribute(['searchable' => 1, 'filterable' => 1, 'sort' => 1])
        );

        self::assertArrayNotHasKey('weight', $spec->toArray());
        self::assertArrayNotHasKey('weight', $spec->getExtra());
        self::assertSame([], $spec->getExtra());
    }

    #[DataProvider('exposureProvider')]
    public function testIsStorefrontExposed(array $data, bool $expected): void
    {
        self::assertSame($expected, $this->policy->isStorefrontExposed($this->attribute($data)));
    }

    /**
     * @return array<string,array{0:array<string,int>,1:bool}>
     */
    public static function exposureProvider(): array
    {
        return [
            'admin-only' => [[], false],
            'searchable' => [['searchable' => 1], true],
            'filterable' => [['filterable' => 1], true],
            'filterable in search' => [['filterable_in_search' => 1], true],
            'visible on front' => [['visible_on_front' => 1], true],
            'used in listing' => [['in_listing' => 1], true],
            'used for sort' => [['sort' => 1], true],
        ];
    }

    /**
     * @param array<string,mixed> $orig
     * @param array<string,mixed> $data
     */
    private function savedAttribute(array $orig, array $data, string $frontend = 'select'): Attribute
    {
        $data += $orig;
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getFrontendInput')->willReturn($frontend);
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('getOrigData')->willReturnCallback(static fn (string $key): mixed => $orig[$key] ?? null);
        $attribute->method('getData')->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null);

        return $attribute;
    }

    public function testOptionLabelsBecameExposedOnlyOnTheNonExposedToExposedTransition(): void
    {
        foreach (AttributeFieldPolicy::STOREFRONT_FLAGS as $flag) {
            self::assertTrue(
                $this->policy->optionLabelsBecameExposed($this->savedAttribute([$flag => '0'], [$flag => '1'])),
                $flag
            );
        }
        self::assertFalse($this->policy->optionLabelsBecameExposed(
            $this->savedAttribute(['is_visible_on_front' => 1], ['is_visible_on_front' => 0, 'is_searchable' => 1])
        ));
        self::assertFalse($this->policy->optionLabelsBecameExposed(
            $this->savedAttribute(['used_in_product_listing' => 1], ['used_in_product_listing' => 0])
        ));
        self::assertFalse($this->policy->optionLabelsBecameExposed($this->savedAttribute([], ['is_global' => 1])));
    }

    public function testOptionLabelsBecameExposedIgnoresNonOptionAttributes(): void
    {
        self::assertFalse($this->policy->optionLabelsBecameExposed(
            $this->savedAttribute(['is_visible_on_front' => 0], ['is_visible_on_front' => 1], 'text')
        ));
    }
}
