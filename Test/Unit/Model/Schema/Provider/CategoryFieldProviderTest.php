<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\Provider\CategoryFieldProvider;
use PHPUnit\Framework\TestCase;

class CategoryFieldProviderTest extends TestCase
{
    /**
     * @return array<string,FieldSpec>
     */
    private function byName(int $storeId = 1): array
    {
        $out = [];
        foreach ((new CategoryFieldProvider())->getFields($storeId) as $spec) {
            $out[$spec->getName()] = $spec;
        }

        return $out;
    }

    public function testProvidesCategoryIdsAndASingleRegexPositionField(): void
    {
        self::assertSame(['category_ids', 'position_category_.*'], array_keys($this->byName()));
    }

    public function testCategoryIdsIsAFacetableIdArray(): void
    {
        $spec = $this->byName()['category_ids'];

        self::assertSame('int64[]', $spec->getType());
        self::assertTrue($spec->getFacet());
        self::assertTrue($spec->getOptional());
    }

    public function testPositionFieldIsASortableOptionalInt(): void
    {
        $spec = $this->byName()['position_category_.*'];

        self::assertSame('int32', $spec->getType());
        self::assertTrue($spec->getSort());
        self::assertTrue($spec->getOptional());
        self::assertNull($spec->getFacet());
    }

    public function testFieldsAreTheSameForEveryStore(): void
    {
        self::assertEquals($this->byName(1), $this->byName(4));
    }
}
