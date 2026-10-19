<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\Provider\StaticFieldProvider;
use PHPUnit\Framework\TestCase;

class StaticFieldProviderTest extends TestCase
{
    public function testProvidesTheAlwaysPresentCatalogFields(): void
    {
        $fields = (new StaticFieldProvider())->getFields(1);

        $byName = [];
        foreach ($fields as $spec) {
            $byName[$spec->getName()] = $spec;
        }

        self::assertSame(
            ['sku', 'store_id', 'visibility', 'status', 'is_in_stock', 'is_out_of_stock'],
            array_keys($byName)
        );
        self::assertSame(array_keys($byName), StaticFieldProvider::OWNED_FIELDS);
        self::assertSame('int32', $byName['store_id']->getType());
        self::assertTrue($byName['store_id']->getFacet());
        self::assertTrue($byName['sku']->getIndex());
    }

    public function testDeclaresOptionalIntStockFields(): void
    {
        $byName = [];
        foreach ((new StaticFieldProvider())->getFields(1) as $spec) {
            $byName[$spec->getName()] = $spec;
        }

        self::assertSame(
            ['name' => 'is_in_stock', 'type' => 'int32', 'index' => true, 'optional' => true],
            $byName['is_in_stock']->toArray()
        );
        self::assertSame(
            ['name' => 'is_out_of_stock', 'type' => 'int32', 'sort' => true, 'optional' => true],
            $byName['is_out_of_stock']->toArray()
        );
    }

    public function testStaticFieldsAreStoreIndependent(): void
    {
        $provider = new StaticFieldProvider();

        $one = array_map(static fn (FieldSpec $f) => $f->toArray(), $provider->getFields(1));
        $two = array_map(static fn (FieldSpec $f) => $f->toArray(), $provider->getFields(2));

        self::assertSame($one, $two);
    }
}
