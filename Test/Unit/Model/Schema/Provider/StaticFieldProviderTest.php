<?php
/**
 * Copyright © DMLab. All rights reserved.
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
            ['sku', 'store_id', 'visibility', 'status'],
            array_keys($byName)
        );
        self::assertSame('int32', $byName['store_id']->getType());
        self::assertTrue($byName['store_id']->getFacet());
        self::assertTrue($byName['sku']->getIndex());
    }

    public function testStaticFieldsAreStoreIndependent(): void
    {
        $provider = new StaticFieldProvider();

        $one = array_map(static fn (FieldSpec $f) => $f->toArray(), $provider->getFields(1));
        $two = array_map(static fn (FieldSpec $f) => $f->toArray(), $provider->getFields(2));

        self::assertSame($one, $two);
    }
}
