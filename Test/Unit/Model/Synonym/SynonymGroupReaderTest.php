<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Synonym;

use DmLab\TypesenseIndexer\Model\Synonym\SynonymGroupReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class SynonymGroupReaderTest extends TestCase
{
    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array{string,string,mixed}> $calls captures select calls by reference
     */
    private function reader(array $rows, array &$calls = []): SynonymGroupReader
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'where', 'orWhere', 'order'] as $method) {
            $select->method($method)->willReturnCallback(
                static function (...$args) use ($method, &$calls, $select) {
                    $calls[] = [$method, ...array_slice($args, 0, 2)];

                    return $select;
                }
            );
        }

        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn($rows);

        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new SynonymGroupReader($resource);
    }

    public function testScopesToTheStoreItsWebsiteAndAllWebsites(): void
    {
        $calls = [];
        $this->reader([], $calls)->getForStore(2, 5);

        self::assertContains(['from', 'search_synonyms', ['group_id', 'synonyms']], $calls);
        self::assertContains(['where', 'store_id = ?', 2], $calls);
        self::assertContains(['orWhere', 'store_id = 0 AND website_id IN (?)', [0, 5]], $calls);
    }

    public function testParsesTrimsDedupesAndDropsEmptyPhrases(): void
    {
        $groups = $this->reader([
            ['group_id' => '3', 'synonyms' => ' shirt, tee ,,shirt, Shirt '],
        ])->getForStore(1, 1);

        self::assertSame([3 => ['shirt', 'tee', 'Shirt']], $groups);
    }

    public function testSkipsGroupsWithFewerThanTwoPhrases(): void
    {
        $groups = $this->reader([
            ['group_id' => '1', 'synonyms' => 'solo'],
            ['group_id' => '2', 'synonyms' => 'dup, dup'],
            ['group_id' => '4', 'synonyms' => ' , '],
            ['group_id' => '5', 'synonyms' => 'pants,trousers'],
        ])->getForStore(1, 1);

        self::assertSame([5 => ['pants', 'trousers']], $groups);
    }
}
