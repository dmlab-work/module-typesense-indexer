<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema\Provider;

use DmLab\TypesenseIndexer\Model\Schema\Provider\PriceFieldProvider;
use PHPUnit\Framework\TestCase;

class PriceFieldProviderTest extends TestCase
{
    public function testProvidesASingleRegexPriceField(): void
    {
        $fields = (new PriceFieldProvider())->getFields(1);

        self::assertCount(1, $fields);
        self::assertSame('price_[0-9]+_.*', $fields[0]->getName());
        self::assertSame('float', $fields[0]->getType());
        self::assertTrue($fields[0]->getFacet());
        self::assertTrue($fields[0]->getSort());
        self::assertTrue($fields[0]->getOptional());
    }

    public function testFieldIsTheSameForEveryStore(): void
    {
        $provider = new PriceFieldProvider();

        self::assertEquals($provider->getFields(1), $provider->getFields(2));
    }
}
