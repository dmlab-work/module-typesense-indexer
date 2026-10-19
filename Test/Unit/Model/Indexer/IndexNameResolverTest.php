<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
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

    public function testPublicAliasIsMainAliasWithPublicSuffix(): void
    {
        self::assertSame(
            'typesense_catalogsearch_fulltext_1_public',
            $this->resolver()->getPublicAliasName('catalogsearch_fulltext', 1)
        );
    }
}
