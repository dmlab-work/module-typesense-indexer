<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema;

use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use PHPUnit\Framework\TestCase;

class FieldNameResolverTest extends TestCase
{
    /**
     * @var FieldNameResolver
     */
    private FieldNameResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FieldNameResolver();
    }

    public function testPlainAttributeMapsToItsCode(): void
    {
        self::assertSame('color', $this->resolver->resolve('color'));
        self::assertSame('name', $this->resolver->resolve('name', ['websiteId' => 3]));
    }

    public function testPriceFansOutPerCustomerGroupAndWebsite(): void
    {
        self::assertSame(
            'price_2_1',
            $this->resolver->resolve('price', ['customerGroupId' => 2, 'websiteId' => 1])
        );
    }

    public function testPriceDefaultsToZeroScopeWhenContextMissing(): void
    {
        self::assertSame('price_0_0', $this->resolver->resolve('price'));
    }

    public function testPositionFansOutPerCategory(): void
    {
        self::assertSame(
            'position_category_42',
            $this->resolver->resolve('position', ['categoryId' => 42])
        );
    }

    public function testPositionDefaultsToZeroCategoryWhenContextMissing(): void
    {
        self::assertSame('position_category_0', $this->resolver->resolve('position'));
    }

    public function testContextScopedCodesAreFlagged(): void
    {
        self::assertTrue($this->resolver->isContextScoped('price'));
        self::assertTrue($this->resolver->isContextScoped('position'));
        self::assertFalse($this->resolver->isContextScoped('color'));
        self::assertFalse($this->resolver->isContextScoped('name'));
    }
}
