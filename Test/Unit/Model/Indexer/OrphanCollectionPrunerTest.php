<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use DmLab\TypesenseIndexer\Model\Indexer\OrphanCollectionPruner;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrphanCollectionPrunerTest extends TestCase
{
    private const ALIAS = 'typesense_catalogsearch_fulltext_1';

    protected function setUp(): void
    {
        if (!method_exists(CollectionManager::class, 'getList')) {
            self::markTestSkipped('requires core 0.2.0 (C5)');
        }
    }

    /**
     * @param string[] $names
     * @param string[] $dropped
     */
    private function collectionManager(array $names, array &$dropped): CollectionManager
    {
        $manager = $this->createStub(CollectionManager::class);
        $manager->method('getList')->willReturn($names);
        $manager->method('drop')->willReturnCallback(
            static function (string $name) use (&$dropped): array {
                $dropped[] = $name;

                return [];
            }
        );

        return $manager;
    }

    /**
     * A pruner whose locked drop delegates to `$manager->drop()`.
     */
    private function pruner(CollectionManager $manager, LoggerInterface $logger): OrphanCollectionPruner
    {
        $finder = $this->createStub(OrphanFinder::class);
        $finder->method('drop')->willReturnCallback(
            static function (string $name) use ($manager): bool {
                $manager->drop($name);

                return true;
            }
        );

        return new OrphanCollectionPruner($manager, $finder, $logger);
    }

    public function testDropsOnlyOlderVersionsOfTheSameAlias(): void
    {
        $dropped = [];
        $manager = $this->collectionManager([
            self::ALIAS . '_100_aaaaaa',
            self::ALIAS . '_150_bbbbbb',
            self::ALIAS . '_200_cccccc',
            self::ALIAS . '_300_dddddd',
            self::ALIAS . '_public_100_eeeeee',
            'typesense_catalogsearch_fulltext_10_100_ffffff',
            self::ALIAS . '_100_not-hex',
            'other_collection',
        ], $dropped);

        $this->pruner($manager, $this->createStub(LoggerInterface::class))
            ->prune(self::ALIAS, self::ALIAS . '_200_cccccc');

        self::assertSame([self::ALIAS . '_100_aaaaaa', self::ALIAS . '_150_bbbbbb'], $dropped);
    }

    public function testKeepsAVersionWithTheSameTimestamp(): void
    {
        $dropped = [];
        $manager = $this->collectionManager([self::ALIAS . '_200_aaaaaa', self::ALIAS . '_200_cccccc'], $dropped);

        $this->pruner($manager, $this->createStub(LoggerInterface::class))
            ->prune(self::ALIAS, self::ALIAS . '_200_cccccc');

        self::assertSame([], $dropped);
    }

    public function testEmptyCurrentDropsEveryVersionOfTheAlias(): void
    {
        $dropped = [];
        $manager = $this->collectionManager([
            self::ALIAS . '_100_aaaaaa',
            self::ALIAS . '_300_dddddd',
            self::ALIAS . '_public_100_eeeeee',
        ], $dropped);

        $this->pruner($manager, $this->createStub(LoggerInterface::class))->prune(self::ALIAS, '');

        self::assertSame([self::ALIAS . '_100_aaaaaa', self::ALIAS . '_300_dddddd'], $dropped);
    }

    public function testCurrentNotOfTheAliasDropsNothing(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->expects(self::never())->method('getList');
        $manager->expects(self::never())->method('drop');

        $this->pruner($manager, $this->createStub(LoggerInterface::class))
            ->prune(self::ALIAS, 'unversioned');
    }

    public function testListFailureIsLoggedAndSwallowed(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->method('getList')->willThrowException(new TypesenseException('down', 503));
        $manager->expects(self::never())->method('drop');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('could not list collections'));

        $this->pruner($manager, $logger)->prune(self::ALIAS, '');
    }

    public function testDropFailureIsLoggedAndTheRestStillDropped(): void
    {
        $dropped = [];
        $manager = $this->createStub(CollectionManager::class);
        $manager->method('getList')->willReturn([self::ALIAS . '_100_aaaaaa', self::ALIAS . '_150_bbbbbb']);
        $manager->method('drop')->willReturnCallback(
            static function (string $name) use (&$dropped): array {
                if (str_ends_with($name, 'aaaaaa')) {
                    throw new TypesenseException('boom', 500);
                }
                $dropped[] = $name;

                return [];
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains(self::ALIAS . '_100_aaaaaa'));

        $this->pruner($manager, $logger)->prune(self::ALIAS, '');

        self::assertSame([self::ALIAS . '_150_bbbbbb'], $dropped);
    }

    public function testDropOfAnAlreadyMissingCollectionIsSilent(): void
    {
        $manager = $this->createStub(CollectionManager::class);
        $manager->method('getList')->willReturn([self::ALIAS . '_100_aaaaaa']);
        $manager->method('drop')->willThrowException(new TypesenseException('gone', 404));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');

        $this->pruner($manager, $logger)->prune(self::ALIAS, '');
    }

    public function testDropsThroughTheLockedAliasAwareFinder(): void
    {
        $manager = $this->createMock(CollectionManager::class);
        $manager->method('getList')->willReturn([self::ALIAS . '_100_aaaaaa']);
        $manager->expects(self::never())->method('drop');
        $finder = $this->createMock(OrphanFinder::class);
        $finder->expects(self::once())->method('drop')->with(self::ALIAS . '_100_aaaaaa')->willReturn(false);

        (new OrphanCollectionPruner($manager, $finder, $this->createStub(LoggerInterface::class)))
            ->prune(self::ALIAS, self::ALIAS . '_200_cccccc');
    }
}
