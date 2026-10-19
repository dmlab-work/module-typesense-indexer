<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Schema\SchemaDiffer;
use DmLab\TypesenseIndexer\Model\Schema\CollectionSchemaSync;

/**
 * The flagship path: an undeclared, policy-cast FAT value becomes facetable by a schema PATCH alone.
 */
class FlagPatchTest extends TypesenseTestCase
{
    public function testPatchMakesAStoredUndeclaredValueFacetable(): void
    {
        $attributes = [$this->attribute('material', 'int', 'select')];
        $collection = $this->createCollection('flag', $this->desiredSchema($attributes));
        $documents = $this->buildDocuments($attributes, [
            1 => ['sku' => 'A', 'status' => '1', 'visibility' => '4', 'material' => '60,61'],
            2 => ['sku' => 'B', 'status' => '1', 'visibility' => '4', 'material' => '60'],
        ]);
        self::assertSame([60, 61], $documents['1']['material']);
        $this->import($collection, $documents);

        [$status] = $this->search($collection, ['facet_by' => 'material']);
        self::assertSame(404, $status);

        $this->patchCollection($collection, [
            ['name' => 'material', 'type' => 'int32[]', 'facet' => true, 'optional' => true],
        ]);

        $result = $this->searchOk($collection, ['facet_by' => 'material']);
        self::assertSame(['60' => 2, '61' => 1], $this->facetCounts($result, 'material'));
    }

    public function testComputedSchemaIsANoOpAndTurningAFlagOffPatchesItAway(): void
    {
        $differ = new SchemaDiffer();
        $on = [$this->attribute('brand', 'varchar', 'text', ['is_searchable' => 1, 'is_filterable' => 1])];
        $collection = $this->createCollection('flag_off', $this->desiredSchema($on));
        $this->import($collection, $this->buildDocuments($on, [
            1 => ['sku' => 'A', 'status' => '1', 'visibility' => '4', 'brand' => 'Acme'],
        ]));

        self::assertTrue($differ->diff($this->desiredSchema($on), $this->getCollection($collection))->isEmpty());

        $off = [$this->attribute('brand', 'varchar', 'text', ['is_searchable' => 1])];
        $diff = $differ->diff($this->desiredSchema($off), $this->getCollection($collection));
        self::assertFalse($diff->isEmpty());
        $this->patchCollection(
            $collection,
            array_map(static fn (FieldSpec $f): array => $f->toArray(), $diff->toPatchFields())
        );

        [$status] = $this->search($collection, ['facet_by' => 'brand']);
        self::assertSame(404, $status);
        self::assertSame(['1'], $this->hitIds($this->searchOk($collection, ['q' => 'Acme', 'query_by' => 'brand'])));
        self::assertTrue($differ->diff($this->desiredSchema($off), $this->getCollection($collection))->isEmpty());
    }

    public function testFlagPatchKeepsFieldsMaterializedFromRegexFields(): void
    {
        $differ = new SchemaDiffer();
        $collection = $this->createCollection('regex', $this->desiredSchema([]));
        $raw = ['sku' => 'A', 'status' => '1', 'visibility' => '4', 'price_0_1' => 9.5, 'position_category_3' => 2];
        $this->import($collection, $this->buildDocuments([], [1 => $raw]));
        $live = $this->getCollection($collection);
        self::assertContains('price_0_1', array_column($live['fields'], 'name'));

        $on = [$this->attribute('color', 'int', 'select', ['is_filterable' => 1])];
        $diff = $differ->diff($this->withRegexInstances($this->desiredSchema($on), $live), $live);
        self::assertSame([], $diff->getDropped());
        $this->patchCollection(
            $collection,
            array_map(static fn (FieldSpec $f): array => $f->toArray(), $diff->toPatchFields())
        );

        $result = $this->searchOk($collection, ['facet_by' => 'price_0_1', 'sort_by' => 'position_category_3:asc']);
        self::assertSame(['1'], $this->hitIds($result));
    }

    /**
     * @param FieldSpec[] $desired
     * @param array<mixed> $schema
     * @return FieldSpec[]
     */
    private function withRegexInstances(array $desired, array $schema): array
    {
        $sync = (new \ReflectionClass(CollectionSchemaSync::class))->newInstanceWithoutConstructor();

        return (new \ReflectionMethod($sync, 'withRegexInstances'))->invoke($sync, $desired, $schema);
    }
}
