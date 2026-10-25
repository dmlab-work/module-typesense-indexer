<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\Search;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\FulltextInvalidationSuppressor;
use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use DmLab\TypesenseIndexer\Model\Plugin\Search\SynonymGroupRepositoryPlugin;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Search\Api\Data\SynonymGroupInterface;
use Magento\Search\Model\SynonymGroupRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynonymGroupRepositoryPluginTest extends TestCase
{
    /**
     * The fulltext indexer; a test that expects (no) invalidation sets it to a mock.
     *
     * @var IndexerInterface
     */
    private IndexerInterface $indexer;

    /** @var ReindexInvalidationMarker */
    private ReindexInvalidationMarker $marker;

    /** @var FulltextInvalidationSuppressor */
    private FulltextInvalidationSuppressor $suppressor;

    /** @var SynonymGroupInterface */
    private SynonymGroupInterface $group;

    protected function setUp(): void
    {
        $this->indexer = $this->createStub(IndexerInterface::class);
        $this->marker = $this->createStub(ReindexInvalidationMarker::class);
        $this->suppressor = new FulltextInvalidationSuppressor();
        $this->group = $this->createStub(SynonymGroupInterface::class);
    }

    private function plugin(
        SynonymSync $sync,
        string $engine,
        ?LoggerInterface $logger = null
    ): SynonymGroupRepositoryPlugin {
        $engineResolver = $this->createStub(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturnCallback(
            fn (string $id): IndexerInterface => $id === Fulltext::INDEXER_ID
                ? $this->indexer
                : self::fail('Unexpected indexer ' . $id)
        );

        return new SynonymGroupRepositoryPlugin(
            $sync,
            $engineResolver,
            $logger ?? $this->createStub(LoggerInterface::class),
            $registry,
            $this->marker,
            $this->suppressor
        );
    }

    private function expectInvalidation(bool $expected): void
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->expects($expected ? self::once() : self::never())->method('invalidate');
        $this->indexer = $indexer;
        $marker = $this->createMock(ReindexInvalidationMarker::class);
        $marker->expects($expected ? self::once() : self::never())->method('mark');
        $this->marker = $marker;
    }

    private function syncing(bool $synced = true): SynonymSync
    {
        $sync = $this->createMock(SynonymSync::class);
        $sync->expects(self::once())->method('syncAllStores')->willReturn($synced);

        return $sync;
    }

    public function testSaveRunsSuppressedThenResyncsAndReturnsTheResult(): void
    {
        $this->expectInvalidation(false);
        $suppressedDuringSave = null;
        $proceed = function (SynonymGroupInterface $group, bool $errorOnMerge) use (&$suppressedDuringSave) {
            $suppressedDuringSave = $this->suppressor->isActive();
            self::assertTrue($errorOnMerge);

            return $group;
        };

        $result = $this->plugin($this->syncing(), EngineCode::ENGINE)
            ->aroundSave($this->createStub(SynonymGroupRepository::class), $proceed, $this->group, true);

        self::assertSame($this->group, $result);
        self::assertTrue($suppressedDuringSave);
        self::assertFalse($this->suppressor->isActive());
    }

    public function testDeleteRunsSuppressedThenResyncs(): void
    {
        $this->expectInvalidation(false);
        $proceed = fn (SynonymGroupInterface $group): bool => $this->suppressor->isActive();

        $result = $this->plugin($this->syncing(), EngineCode::ENGINE)
            ->aroundDelete($this->createStub(SynonymGroupRepository::class), $proceed, $this->group);

        self::assertTrue($result);
    }

    public function testAnotherEngineNeitherSuppressesNorSyncs(): void
    {
        $this->expectInvalidation(false);
        $sync = $this->createMock(SynonymSync::class);
        $sync->expects(self::never())->method('syncAllStores');
        $plugin = $this->plugin($sync, 'opensearch');
        $repository = $this->createStub(SynonymGroupRepository::class);

        $proceed = fn (): bool => $this->suppressor->isActive();

        self::assertFalse($plugin->aroundDelete($repository, $proceed, $this->group));
        self::assertFalse($plugin->aroundSave($repository, $proceed, $this->group));
    }

    public function testAFailedOperationStillResyncsAndRethrows(): void
    {
        $this->expectInvalidation(false);
        $plugin = $this->plugin($this->syncing(), EngineCode::ENGINE);
        $this->expectExceptionMessage('merge conflict');

        try {
            $plugin->aroundSave(
                $this->createStub(SynonymGroupRepository::class),
                static fn () => throw new \RuntimeException('merge conflict'),
                $this->group
            );
        } finally {
            self::assertFalse($this->suppressor->isActive());
        }
    }

    public function testSyncFailureIsLoggedSwallowedAndInvalidates(): void
    {
        $this->expectInvalidation(true);
        $sync = $this->createStub(SynonymSync::class);
        $sync->method('syncAllStores')->willThrowException(new TypesenseException('down', 503));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('synonym sync failed'));

        $result = $this->plugin($sync, EngineCode::ENGINE, $logger)
            ->aroundDelete($this->createStub(SynonymGroupRepository::class), static fn (): bool => true, $this->group);

        self::assertTrue($result);
    }

    public function testAFailedStoreInvalidatesSoTheRebuildResyncs(): void
    {
        $this->expectInvalidation(true);

        $this->plugin($this->syncing(false), EngineCode::ENGINE)
            ->aroundSave($this->createStub(SynonymGroupRepository::class), fn () => $this->group, $this->group);
    }
}
