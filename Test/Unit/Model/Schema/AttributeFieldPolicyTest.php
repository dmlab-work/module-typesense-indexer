<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Schema;

use MageDevGroup\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldNameResolver;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
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
        $attribute->method('getBackendType')->willReturn($data['backend'] ?? 'varchar');
        $attribute->method('getFrontendInput')->willReturn($data['frontend'] ?? 'text');

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

        self::assertSame('string[]', $spec->getType());
        self::assertNull($spec->getSort());
        self::assertArrayNotHasKey('sort', $spec->toArray());
    }

    public function testUsedForSortBySurvivesForScalarTypes(): void
    {
        $spec = $this->policy->toFieldSpec(
            $this->attribute(['sort' => 1, 'backend' => 'int', 'frontend' => 'boolean'])
        );

        self::assertSame('bool', $spec->getType());
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
    public function testTypeMapping(string $backend, string $frontend, string $expected): void
    {
        $spec = $this->policy->toFieldSpec(
            $this->attribute(['backend' => $backend, 'frontend' => $frontend])
        );

        self::assertSame($expected, $spec->getType());
    }

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function typeMappingProvider(): array
    {
        return [
            'multiselect array'   => ['varchar', 'multiselect', 'string[]'],
            'multiselect int backend still array' => ['int', 'multiselect', 'string[]'],
            'boolean yes/no'      => ['int', 'boolean', 'bool'],
            'decimal float'       => ['decimal', 'price', 'float'],
            'int int32'           => ['int', 'text', 'int32'],
            'smallint int32'      => ['smallint', 'select', 'int32'],
            'datetime int64'      => ['datetime', 'date', 'int64'],
            'timestamp int64'     => ['timestamp', 'date', 'int64'],
            'varchar string'      => ['varchar', 'text', 'string'],
            'static string'       => ['static', 'text', 'string'],
            'select option id int32' => ['int', 'select', 'int32'],
        ];
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
}
