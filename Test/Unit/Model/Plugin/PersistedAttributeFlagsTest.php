<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin;

use DmLab\TypesenseIndexer\Model\Plugin\PersistedAttributeFlags;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class PersistedAttributeFlagsTest extends TestCase
{
    /**
     * @param array<string,mixed>|null $orig
     */
    private function attribute(?array $orig, int $id = 93): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getOrigData')->willReturn($orig);
        $attribute->method('getId')->willReturn($id);

        return $attribute;
    }

    /**
     * A connection expected to read `$row` for attribute `$id` `$times` times.
     *
     * @param array<string,string>|false $row
     */
    private function flags(array|false $row, int $times, int $id = 93): PersistedAttributeFlags
    {
        $select = $this->createMock(Select::class);
        $select->method('from')
            ->with('catalog_eav_attribute', AttributeFieldPolicy::TRACKED_FLAGS)
            ->willReturnSelf();
        $select->method('where')->with('attribute_id = ?', $id)->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->expects(self::exactly($times))->method('fetchRow')->with($select)->willReturn($row);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new PersistedAttributeFlags($resource);
    }

    public function testLoadedAttributeUsesItsOrigDataWithoutAQuery(): void
    {
        $orig = array_fill_keys(AttributeFieldPolicy::TRACKED_FLAGS, '1') + ['frontend_label' => 'Color'];

        self::assertSame(
            array_fill_keys(AttributeFieldPolicy::TRACKED_FLAGS, '1'),
            $this->flags(false, 0)->get($this->attribute($orig))
        );
    }

    public function testAttributeWithoutOrigDataReadsTheStoredRow(): void
    {
        $row = array_fill_keys(AttributeFieldPolicy::TRACKED_FLAGS, '0');

        self::assertSame($row, $this->flags($row, 1)->get($this->attribute(null)));
    }

    public function testPartialOrigDataReadsTheStoredRow(): void
    {
        $row = array_fill_keys(AttributeFieldPolicy::TRACKED_FLAGS, '1');

        self::assertSame($row, $this->flags($row, 1)->get($this->attribute(['is_searchable' => '0'])));
    }

    public function testMissingRowOrIdIsEmpty(): void
    {
        self::assertSame([], $this->flags(false, 1)->get($this->attribute(null)));
        self::assertSame([], $this->flags(false, 0)->get($this->attribute(null, 0)));
    }
}
