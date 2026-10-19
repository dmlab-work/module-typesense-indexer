<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Schema;

use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

class OptionAttributeListTest extends TestCase
{
    private function attribute(string $code, string $input, string $backend): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($backend);

        return $attribute;
    }

    public function testKeepsOnlyOptionAttributesKeyedByCodeAndLoadsOnce(): void
    {
        $color = $this->attribute('color', 'select', 'int');
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn([$color, $this->attribute('name', 'text', 'varchar')]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::once())->method('create')->willReturn($collection);

        $list = new OptionAttributeList($factory, new AttributeFieldPolicy(new FieldNameResolver()));

        self::assertSame(['color' => $color], $list->get());
        self::assertSame(['color' => $color], $list->get());
    }

    public function testResetReloadsSoALaterAttributeIsSeen(): void
    {
        $color = $this->attribute('color', 'select', 'int');
        $size = $this->attribute('size', 'select', 'int');
        $before = $this->createStub(Collection::class);
        $before->method('getItems')->willReturn([$color]);
        $after = $this->createStub(Collection::class);
        $after->method('getItems')->willReturn([$color, $size]);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->expects(self::exactly(2))->method('create')->willReturnOnConsecutiveCalls($before, $after);

        $list = new OptionAttributeList($factory, new AttributeFieldPolicy(new FieldNameResolver()));

        self::assertSame(['color' => $color], $list->get());
        $list->reset();
        self::assertSame(['color' => $color, 'size' => $size], $list->get());
        self::assertSame(['color' => $color, 'size' => $size], $list->get());
    }
}
