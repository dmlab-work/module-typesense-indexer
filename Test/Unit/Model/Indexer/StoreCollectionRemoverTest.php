<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Indexer\IndexStructure;
use DmLab\TypesenseIndexer\Model\Indexer\OrphanCollectionPruner;
use DmLab\TypesenseIndexer\Model\Indexer\PendingCollectionRegistry;
use DmLab\TypesenseIndexer\Model\Indexer\StoreCollectionRemover;
use DmLab\TypesenseIndexer\Model\Indexer\StoreScopeResolver;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StoreCollectionRemoverTest extends TestCase
{
    private const ALIAS = 'typesense_catalogsearch_fulltext_5';
    private const PUBLIC_ALIAS = 'typesense_catalogsearch_fulltext_5_public';

    /** @var AliasManager&\PHPUnit\Framework\MockObject\Stub */
    private $aliasManager;

    /** @var CollectionManager&\PHPUnit\Framework\MockObject\Stub */
    private $collectionManager;

    /** @var OrphanCollectionPruner&\PHPUnit\Framework\MockObject\Stub */
    private $pruner;

    /** @var SynonymSync&\PHPUnit\Framework\MockObject\Stub */
    private $synonymSync;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\Stub */
    private $logger;

    protected function setUp(): void
    {
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->collectionManager = $this->createStub(CollectionManager::class);
        $this->pruner = $this->createStub(OrphanCollectionPruner::class);
        $this->synonymSync = $this->createStub(SynonymSync::class);
        $this->logger = $this->createStub(LoggerInterface::class);
    }

    private function remover(): StoreCollectionRemover
    {
        $settings = $this->createStub(ConnectionSettings::class);
        $settings->method('getIndexPrefix')->willReturn('typesense');

        $nameResolver = new IndexNameResolver($settings);
        $indexStructure = new IndexStructure(
            $this->createStub(StoreScopeResolver::class),
            $this->createStub(DesiredSchemaBuilder::class),
            $this->aliasManager,
            $this->collectionManager,
            $nameResolver,
            $this->createStub(PendingCollectionRegistry::class),
            $this->createStub(PublicSchemaBuilder::class),
            $this->logger
        );

        return new StoreCollectionRemover(
            $nameResolver,
            $indexStructure,
            $this->pruner,
            $this->synonymSync,
            $this->logger
        );
    }

    private function resolveBoth(): void
    {
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'main_live'],
            [self::PUBLIC_ALIAS, 'public_live'],
        ]);
    }

    public function testDropsTargetsAliasesLeftoversAndTheSynonymSet(): void
    {
        $calls = [];
        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->resolveBoth();
        $this->aliasManager->expects(self::exactly(2))->method('delete')->willReturnCallback(
            static function (string $alias) use (&$calls): array {
                $calls[] = 'alias:' . $alias;

                return [];
            }
        );
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::exactly(2))->method('drop')->willReturnCallback(
            static function (string $name) use (&$calls): array {
                $calls[] = 'drop:' . $name;

                return [];
            }
        );
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::exactly(2))->method('prune')->willReturnCallback(
            static function (string $alias, string $current) use (&$calls): void {
                $calls[] = 'prune:' . $alias . '|' . $current;
            }
        );
        $this->synonymSync = $this->createMock(SynonymSync::class);
        $this->synonymSync->expects(self::once())->method('deleteStore')->with(5)->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'synonyms';
            }
        );

        $this->remover()->remove(5);

        self::assertSame([
            'drop:main_live',
            'alias:' . self::ALIAS,
            'prune:' . self::ALIAS . '|',
            'drop:public_live',
            'alias:' . self::PUBLIC_ALIAS,
            'prune:' . self::PUBLIC_ALIAS . '|',
            'synonyms',
        ], $calls);
    }

    public function testUnsetAliasesStillPruneAndDeleteTheSynonymSet(): void
    {
        $this->aliasManager->method('resolve')->willReturn(null);
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::never())->method('drop');
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::exactly(2))->method('prune');
        $this->synonymSync = $this->createMock(SynonymSync::class);
        $this->synonymSync->expects(self::once())->method('deleteStore');

        $this->remover()->remove(5);
    }

    public function testMissingTargetAndAliasAreTolerated(): void
    {
        $this->resolveBoth();
        $this->collectionManager->method('drop')->willThrowException(new TypesenseException('gone', 404));
        $this->aliasManager->method('delete')->willThrowException(new TypesenseException('gone', 404));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::never())->method('error');

        $this->remover()->remove(5);
    }

    public function testFailuresAreLoggedAndNeverThrown(): void
    {
        $this->resolveBoth();
        $this->collectionManager->method('drop')->willThrowException(new TypesenseException('boom', 500));
        $this->synonymSync->method('deleteStore')->willThrowException(new TypesenseException('down', 503));
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::exactly(2))->method('prune');
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::exactly(3))->method('error');

        $this->remover()->remove(5);
    }
}
