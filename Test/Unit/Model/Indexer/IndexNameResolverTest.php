<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Indexer;

use MageDevGroup\TypesenseIndexer\Model\ConnectionSettings;
use MageDevGroup\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use PHPUnit\Framework\TestCase;

class IndexNameResolverTest extends TestCase
{
    /**
     * Build a resolver whose configured index prefix is $prefix.
     */
    private function resolver(string $prefix = 'typesense'): IndexNameResolver
    {
        $settings = $this->createStub(ConnectionSettings::class);
        $settings->method('getIndexPrefix')->willReturn($prefix);

        return new IndexNameResolver($settings);
    }

    public function testBuildsPrefixIndexerStorePattern(): void
    {
        self::assertSame(
            'typesense_catalogsearch_fulltext_1',
            $this->resolver()->getAliasName('catalogsearch_fulltext', 1)
        );
    }

    public function testStoreIdIsPartOfTheAliasSoScopesDoNotCollide(): void
    {
        $resolver = $this->resolver();

        self::assertNotSame(
            $resolver->getAliasName('catalogsearch_fulltext', 1),
            $resolver->getAliasName('catalogsearch_fulltext', 2)
        );
    }

    public function testPrefixComesFromConfig(): void
    {
        self::assertSame(
            'custom_catalogsearch_fulltext_3',
            $this->resolver('custom')->getAliasName('catalogsearch_fulltext', 3)
        );
    }
}
