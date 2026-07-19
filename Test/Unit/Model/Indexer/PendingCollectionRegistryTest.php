<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Indexer;

use MageDevGroup\TypesenseIndexer\Model\Indexer\PendingCollectionRegistry;
use PHPUnit\Framework\TestCase;

class PendingCollectionRegistryTest extends TestCase
{
    public function testGetReturnsNullWhenNothingIsPending(): void
    {
        $registry = new PendingCollectionRegistry();

        self::assertNull($registry->get('typesense_catalogsearch_fulltext_1'));
    }

    public function testSetThenGetCarriesThePhysicalCollectionAcrossTheAlias(): void
    {
        $registry = new PendingCollectionRegistry();
        $registry->set('typesense_catalogsearch_fulltext_1', 'typesense_catalogsearch_fulltext_1_1752710400_9f3ac1');

        self::assertSame(
            'typesense_catalogsearch_fulltext_1_1752710400_9f3ac1',
            $registry->get('typesense_catalogsearch_fulltext_1')
        );
    }

    public function testAliasesAreKeptSeparate(): void
    {
        $registry = new PendingCollectionRegistry();
        $registry->set('alias_1', 'physical_1');
        $registry->set('alias_2', 'physical_2');

        self::assertSame('physical_1', $registry->get('alias_1'));
        self::assertSame('physical_2', $registry->get('alias_2'));
    }

    public function testClearForgetsTheAlias(): void
    {
        $registry = new PendingCollectionRegistry();
        $registry->set('alias_1', 'physical_1');
        $registry->clear('alias_1');

        self::assertNull($registry->get('alias_1'));
    }
}
