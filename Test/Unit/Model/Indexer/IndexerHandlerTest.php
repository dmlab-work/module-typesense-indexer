<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use DmLab\TypesenseCore\Model\Document\ImportResult;
use DmLab\TypesenseCore\Model\Schema\Decision;
use DmLab\TypesenseCore\Model\Schema\SchemaDiff;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Indexer\IndexerHandler;
use DmLab\TypesenseIndexer\Model\Indexer\OrphanCollectionPruner;
use DmLab\TypesenseIndexer\Model\Indexer\PendingCollectionRegistry;
use DmLab\TypesenseIndexer\Model\Indexer\RunScopedCacheInterface;
use DmLab\TypesenseIndexer\Model\Indexer\StoreScopeResolver;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicDocumentProjector;
use DmLab\TypesenseIndexer\Model\Schema\CollectionSchemaSync;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexStructureInterface;
use Magento\Framework\Indexer\IndexerInterface as RegisteredIndexer;
use Magento\Framework\Indexer\SaveHandler\Batch;
use Magento\Framework\Indexer\SaveHandler\StackedActionsIndexerInterface;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class IndexerHandlerTest extends TestCase
{
    private const INDEXER_ID = 'catalogsearch_fulltext';
    private const ALIAS = 'typesense_catalogsearch_fulltext_1';
    private const PUBLIC_ALIAS = 'typesense_catalogsearch_fulltext_1_public';

    /** @var IndexStructureInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $indexStructure;

    /** @var DocumentBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private $documentBuilder;

    /** @var DocumentWriter&\PHPUnit\Framework\MockObject\MockObject */
    private $documentWriter;

    /** @var AliasManager&\PHPUnit\Framework\MockObject\MockObject */
    private $aliasManager;

    /** @var CollectionManager&\PHPUnit\Framework\MockObject\MockObject */
    private $collectionManager;

    /** @var HealthChecker&\PHPUnit\Framework\MockObject\MockObject */
    private $healthChecker;

    /** @var PendingCollectionRegistry */
    private PendingCollectionRegistry $pendingCollections;

    /** @var ScopeResolverInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $scopeResolver;

    /** @var DeploymentConfig&\PHPUnit\Framework\MockObject\MockObject */
    private $deploymentConfig;

    /** @var CacheContext&\PHPUnit\Framework\MockObject\MockObject */
    private $cacheContext;

    /** @var Processor&\PHPUnit\Framework\MockObject\MockObject */
    private $processor;

    /** @var RegisteredIndexer&\PHPUnit\Framework\MockObject\MockObject */
    private $registeredIndexer;

    /** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $logger;

    /** @var PublicDocumentProjector&\PHPUnit\Framework\MockObject\MockObject */
    private $publicProjector;

    /** @var StoreManagerInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $storeManager;

    /** @var SynonymSync&\PHPUnit\Framework\MockObject\MockObject */
    private $synonymSync;

    /** @var CollectionSchemaSync&\PHPUnit\Framework\MockObject\MockObject */
    private $schemaSync;

    /** @var OrphanCollectionPruner&\PHPUnit\Framework\MockObject\MockObject */
    private $pruner;

    /** @var RunScopedCacheInterface[] */
    private array $runScopedCaches = [];

    protected function setUp(): void
    {
        // Stubs by default; a test escalates one to a mock (createMock) only where it verifies calls.
        $this->indexStructure = $this->createStub(IndexStructureInterface::class);
        $this->documentBuilder = $this->createStub(DocumentBuilder::class);
        $this->documentWriter = $this->createStub(DocumentWriter::class);
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->collectionManager = $this->createStub(CollectionManager::class);
        $this->healthChecker = $this->createStub(HealthChecker::class);
        $this->pendingCollections = new PendingCollectionRegistry();
        $this->scopeResolver = $this->createStub(ScopeResolverInterface::class);
        $this->deploymentConfig = $this->createStub(DeploymentConfig::class);
        $this->cacheContext = $this->createStub(CacheContext::class);
        $this->processor = $this->createStub(Processor::class);
        $this->registeredIndexer = $this->createStub(RegisteredIndexer::class);
        $this->logger = $this->createStub(LoggerInterface::class);
        $this->publicProjector = $this->createStub(PublicDocumentProjector::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->synonymSync = $this->createStub(SynonymSync::class);
        $this->pruner = $this->createStub(OrphanCollectionPruner::class);
        $this->schemaSync = $this->createStub(CollectionSchemaSync::class);

        // Store 1 belongs to website 3; the projector tags each document with the website it was given.
        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(3);
        $this->storeManager->method('getStore')->willReturn($store);
        $this->publicProjector->method('project')->willReturnCallback(
            static fn (array $document, int $websiteId): array => [
                'id' => $document['id'],
                'public_website' => $websiteId,
            ]
        );

        $this->processor->method('getIndexer')->willReturn($this->registeredIndexer);
        $this->registeredIndexer->method('isScheduled')->willReturn(false);

        // Store scope 1 for every dimension unless a test overrides it.
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getId')->willReturn(1);
        $this->scopeResolver->method('getScope')->willReturn($scope);

        // The document builder echoes each chunk as documents keyed by id unless a test overrides it.
        $this->documentBuilder->method('build')->willReturnCallback(
            static function (\Traversable $documents, int $storeId): array {
                $built = [];
                foreach ($documents as $id => $raw) {
                    $row = is_array($raw) ? $raw : [];
                    $row['id'] = (string)$id;
                    $row['store_id'] = $storeId;
                    $built[(string)$id] = $row;
                }

                return $built;
            }
        );
    }

    /**
     * A real resolver whose configured index prefix is the default `typesense`.
     */
    private function nameResolver(): IndexNameResolver
    {
        $settings = $this->createStub(ConnectionSettings::class);
        $settings->method('getIndexPrefix')->willReturn('typesense');

        return new IndexNameResolver($settings);
    }

    private function handler(array $data = ['indexer_id' => self::INDEXER_ID]): IndexerHandler
    {
        return new IndexerHandler(
            $this->indexStructure,
            $this->documentBuilder,
            $this->documentWriter,
            $this->aliasManager,
            $this->collectionManager,
            $this->healthChecker,
            $this->nameResolver(),
            $this->pendingCollections,
            new StoreScopeResolver($this->scopeResolver),
            new Batch(),
            $this->deploymentConfig,
            $this->cacheContext,
            $this->processor,
            $this->logger,
            $this->publicProjector,
            $this->storeManager,
            $this->synonymSync,
            $this->pruner,
            $this->schemaSync,
            $data,
            runScopedCaches: $this->runScopedCaches
        );
    }

    /**
     * @param array<int|string,mixed> $rows id => raw index data
     */
    private function stream(array $rows): \Traversable
    {
        return new \ArrayIterator($rows);
    }

    /**
     * @return \Magento\Framework\Search\Request\Dimension[]
     */
    private function dimensions(): array
    {
        return [new \Magento\Framework\Search\Request\Dimension('scope', '1')];
    }

    private function noFailures(int $count): ImportResult
    {
        return new ImportResult($count, 0, []);
    }

    public function testSaveWritesToThePendingCollectionThenSwapsTheAliasLast(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->aliasManager = $this->createMock(AliasManager::class);

        $sequence = [];
        $this->documentWriter->expects(self::once())
            ->method('importBatch')
            ->with('physical_new')
            ->willReturnCallback(function () use (&$sequence): ImportResult {
                $sequence[] = 'import';

                return $this->noFailures(2);
            });
        $this->aliasManager->expects(self::once())
            ->method('swap')
            ->with(self::ALIAS, 'physical_new')
            ->willReturnCallback(function () use (&$sequence): ?string {
                $sequence[] = 'swap';

                return null;
            });

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => []]));

        self::assertSame(['import', 'swap'], $sequence, 'the alias must swap only after documents are written');
        self::assertNull($this->pendingCollections->get(self::ALIAS), 'the pending collection is cleared');
    }

    public function testIncrementalSaveWritesToTheLiveCollectionAndDoesNotSwap(): void
    {
        // No pending collection: an incremental save resolves the alias to its live collection.
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, null],
        ]);

        $this->documentWriter->expects(self::once())
            ->method('importBatch')
            ->with('physical_live')
            ->willReturn($this->noFailures(1));
        $this->aliasManager->expects(self::never())->method('swap');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => []]));
    }

    public function testFullRebuildImportsMainAndProjectedPublicThenSwapsBoth(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->aliasManager = $this->createMock(AliasManager::class);

        $sequence = [];
        $imported = [];
        $this->documentWriter->method('importBatch')->willReturnCallback(
            function (string $collection, iterable $documents) use (&$sequence, &$imported): ImportResult {
                $sequence[] = 'import:' . $collection;
                $imported[$collection] = $documents;

                return $this->noFailures(1);
            }
        );
        $this->aliasManager->expects(self::exactly(2))
            ->method('swap')
            ->willReturnCallback(function (string $alias, string $collection) use (&$sequence): ?string {
                $sequence[] = 'swap:' . $alias . '=' . $collection;

                return null;
            });

        $this->handler()->saveIndex($this->dimensions(), $this->stream([5 => ['name' => 'Tee']]));

        self::assertSame(
            [
                'import:physical_new',
                'import:physical_public_new',
                'swap:' . self::ALIAS . '=physical_new',
                'swap:' . self::PUBLIC_ALIAS . '=physical_public_new',
            ],
            $sequence
        );
        self::assertSame(['5' => ['name' => 'Tee', 'id' => '5', 'store_id' => 1]], $imported['physical_new']);
        // The public collection receives the projection, computed for the store's website.
        self::assertSame(['5' => ['id' => '5', 'public_website' => 3]], $imported['physical_public_new']);
        self::assertNull($this->pendingCollections->get(self::ALIAS));
        self::assertNull($this->pendingCollections->get(self::PUBLIC_ALIAS));
    }

    public function testFailedRebuildDropsBothPendingCollections(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->documentWriter->method('importBatch')->willThrowException(new TypesenseException('boom', 500));
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $dropped = [];
        $this->collectionManager->expects(self::exactly(2))
            ->method('drop')
            ->willReturnCallback(function (string $name) use (&$dropped): array {
                $dropped[] = $name;

                return [];
            });

        try {
            $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
            self::fail('the import failure should propagate');
        } catch (TypesenseException) {
            // expected
        }

        self::assertSame(['physical_new', 'physical_public_new'], $dropped);
        self::assertNull($this->pendingCollections->get(self::ALIAS));
        self::assertNull($this->pendingCollections->get(self::PUBLIC_ALIAS));
    }

    public function testPublicSwapFailureKeepsTheSwappedMainAndDropsOnlyThePublicPending(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->aliasManager->method('swap')->willReturnCallback(
            static function (string $alias): ?string {
                if ($alias === self::PUBLIC_ALIAS) {
                    throw new TypesenseException('public swap failed', 500);
                }

                return null;
            }
        );
        // The public alias still serves its old collection, so the public pending is an orphan.
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_new'],
            [self::PUBLIC_ALIAS, 'physical_public_old'],
        ]);
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::once())->method('drop')->with('physical_public_new');
        // The main alias did move, so its prune and synonym sync still run.
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::once())->method('prune')->with(self::ALIAS, 'physical_new');
        $this->synonymSync = $this->createMock(SynonymSync::class);
        $this->synonymSync->expects(self::once())->method('syncStore')->with(1);

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('public swap failed');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testIncrementalSaveWritesProjectedDocumentsToTheLivePublicCollection(): void
    {
        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, 'physical_public_live'],
        ]);
        $this->aliasManager->expects(self::never())->method('swap');

        $imported = [];
        $this->documentWriter->method('importBatch')->willReturnCallback(
            function (string $collection, iterable $documents) use (&$imported): ImportResult {
                $imported[$collection] = $documents;

                return $this->noFailures(1);
            }
        );

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => []]));

        self::assertSame(['physical_live', 'physical_public_live'], array_keys($imported));
        self::assertSame(['7' => ['id' => '7', 'public_website' => 3]], $imported['physical_public_live']);
    }

    public function testIncrementalSaveWithoutAPublicAliasWarnsAndStillWritesMain(): void
    {
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, null],
        ]);
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->expects(self::once())
            ->method('importBatch')
            ->with('physical_live')
            ->willReturn($this->noFailures(1));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains(self::PUBLIC_ALIAS));
        $this->publicProjector = $this->createMock(PublicDocumentProjector::class);
        $this->publicProjector->expects(self::never())->method('project');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => [], 8 => []]));
    }

    public function testSaveThrowsWhenNoCollectionIsAvailable(): void
    {
        $this->aliasManager->method('resolve')->willReturn(null);

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testSaveReadsBatchSizeFromDeploymentConfig(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->deploymentConfig = $this->createMock(DeploymentConfig::class);
        // Memoized: deployment config is read exactly once per instance, not once per batch.
        $this->deploymentConfig->expects(self::once())
            ->method('get')
            ->with('indexer/batch_size/catalogsearch_fulltext/typesense_save')
            ->willReturn(2);
        $this->documentWriter = $this->createMock(DocumentWriter::class);

        // Three documents at a deployment batch size of 2 → two chunks → two import calls of size 2.
        $this->documentWriter->expects(self::exactly(2))
            ->method('importBatch')
            ->with('physical_new', self::anything(), DocumentWriter::ACTION_UPSERT, 2)
            ->willReturn($this->noFailures(2));
        $this->aliasManager->method('swap')->willReturn(null);

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => [], 3 => []]));
    }

    public function testSaveFallsBackToInjectedBatchSizeWhenDeploymentConfigIsUnset(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->deploymentConfig->method('get')->willReturn(null);
        $this->documentWriter = $this->createMock(DocumentWriter::class);

        $this->documentWriter->expects(self::once())
            ->method('importBatch')
            ->with('physical_new', self::anything(), DocumentWriter::ACTION_UPSERT, DocumentWriter::DEFAULT_BATCH_SIZE)
            ->willReturn($this->noFailures(1));
        $this->aliasManager->method('swap')->willReturn(null);

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testAConfiguredBatchSizeOfZeroBecomesOne(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->deploymentConfig->method('get')->willReturn('0');
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->expects(self::exactly(2))
            ->method('importBatch')
            ->with('physical_new', self::anything(), DocumentWriter::ACTION_UPSERT, 1)
            ->willReturn($this->noFailures(1));

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => []]));
    }

    public function testSaveRegistersCategoryCacheTagsOnlyWhenScheduled(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->registeredIndexer = $this->createStub(RegisteredIndexer::class);
        $this->registeredIndexer->method('isScheduled')->willReturn(true);
        $this->processor = $this->createStub(Processor::class);
        $this->processor->method('getIndexer')->willReturn($this->registeredIndexer);

        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(2));
        $this->aliasManager->method('swap')->willReturn(null);
        $this->cacheContext = $this->createMock(CacheContext::class);
        $this->cacheContext->expects(self::once())
            ->method('registerEntities')
            ->with(\Magento\Catalog\Model\Category::CACHE_TAG, [5, 6]);

        $this->handler()->saveIndex(
            $this->dimensions(),
            $this->stream([1 => ['category_ids' => [5, 6]], 2 => ['category_ids' => 5]])
        );
    }

    public function testSaveDoesNotRegisterCacheTagsWhenNotScheduled(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->aliasManager->method('swap')->willReturn(null);
        $this->cacheContext = $this->createMock(CacheContext::class);
        $this->cacheContext->expects(self::never())->method('registerEntities');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => ['category_ids' => [9]]]));
    }

    public function testSaveLogsPartialImportFailures(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->aliasManager->method('swap')->willReturn(null);
        $this->documentWriter->method('importBatch')->willReturn(
            new ImportResult(1, 2, [['line' => 3, 'error' => 'bad', 'document' => ['id' => '3']]])
        );
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('2 of 3 documents rejected'), self::anything());

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => [], 3 => []]));
    }

    public function testSaveDoesNotLogWhenNoDocumentsFail(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->aliasManager->method('swap')->willReturn(null);
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(2));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::never())->method('error');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => []]));
    }

    public function testCleanCreatesTheFreshCollectionWithoutDroppingTheLiveOneOrWritingDocuments(): void
    {
        $this->indexStructure = $this->createMock(IndexStructureInterface::class);
        $this->documentWriter = $this->createMock(DocumentWriter::class);

        // The live collection must stay behind the alias during the rebuild — swap() retires it later.
        $this->indexStructure->expects(self::never())->method('delete');
        $this->indexStructure->expects(self::once())
            ->method('create')
            ->with(self::INDEXER_ID, []);
        $this->documentWriter->expects(self::never())->method('importBatch');

        $this->handler()->cleanIndex($this->dimensions());
    }

    public function testSaveClearsAndDropsThePendingCollectionWhenImportFails(): void
    {
        // A full rebuild that dies mid-write must not leave a pending entry a later incremental save
        // would write into and swap onto, and must drop the orphaned collection to reclaim its RAM.
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willThrowException(
            new TypesenseException('boom', 500)
        );
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::once())->method('drop')->with('physical_new');

        try {
            $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
            self::fail('the import failure should propagate');
        } catch (TypesenseException) {
            // expected
        }

        self::assertNull($this->pendingCollections->get(self::ALIAS));
    }

    public function testSaveDoesNotDropTheCollectionOnASuccessfulSwap(): void
    {
        // After a successful swap the pending collection is the new live one — it must not be dropped.
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->aliasManager->method('swap')->willReturn(null);
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::never())->method('drop');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testSaveDoesNotDropThePendingCollectionWhenSwapAlreadyRepointedTheAlias(): void
    {
        // swap() repoints the alias *before* dropping the old collection; a failure dropping the old one
        // throws with the alias already serving the new (pending) collection. The finally must not drop
        // it — that would destroy the now-live index. It only drops when the alias is not on pending.
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->aliasManager->method('swap')->willThrowException(new TypesenseException('drop old failed', 500));
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_new'],
            [self::PUBLIC_ALIAS, null],
        ]);
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::never())->method('drop');

        try {
            $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
            self::fail('the swap failure should propagate');
        } catch (TypesenseException) {
            // expected
        }

        self::assertNull($this->pendingCollections->get(self::ALIAS));
    }

    public function testSaveKeepsThePendingCollectionWhenTheAliasCannotBeResolvedAfterAFailedSwap(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->aliasManager->method('swap')->willThrowException(new TypesenseException('swap failed', 500));
        $resolves = 0;
        $this->aliasManager->method('resolve')->willReturnCallback(
            static function (string $alias) use (&$resolves): ?string {
                // The first call resolves the public alias for writing; the cleanup's lookup then fails.
                if (++$resolves > 1) {
                    throw new TypesenseException('unreachable', 503);
                }

                return null;
            }
        );
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::never())->method('drop');

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('swap failed');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testSaveSwallowsADropFailureSoTheReindexErrorPropagates(): void
    {
        // The drop runs in finally; a drop failure must not mask the original import failure.
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willThrowException(
            new TypesenseException('boom', 500)
        );
        $this->collectionManager = $this->createStub(CollectionManager::class);
        $this->collectionManager->method('drop')->willThrowException(
            new TypesenseException('drop failed', 500)
        );

        $this->expectException(TypesenseException::class);
        $this->expectExceptionMessage('boom');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    /**
     * A writer stub that records every `deleteBy` as [collection, filter].
     *
     * @param array<int,array{0:string,1:string}> $deleted
     */
    private function recordDeletes(array &$deleted): void
    {
        $this->documentWriter = $this->createStub(DocumentWriter::class);
        $this->documentWriter->method('importBatch')->willReturnCallback(
            fn (string $collection, iterable $documents): ImportResult => $this->noFailures(count($documents))
        );
        $this->documentWriter->method('deleteBy')->willReturnCallback(
            function (string $collection, string $filter) use (&$deleted): int {
                $deleted[] = [$collection, $filter];

                return 1;
            }
        );
    }

    private function resolveBothLive(): void
    {
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, 'physical_public'],
        ]);
    }

    public function testDeleteRemovesDocumentsByIdFromBothLiveCollections(): void
    {
        $this->resolveBothLive();
        $deleted = [];
        $this->recordDeletes($deleted);

        // Blank and non-numeric ids are dropped, duplicates collapse.
        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10, '', 20, 'x]', 10, null]));

        self::assertSame(
            [['physical_live', 'id:[10,20]'], ['physical_public', 'id:[10,20]']],
            $deleted
        );
    }

    public function testDeleteBatchesIdsIntoChunksOfOneHundred(): void
    {
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, null],
        ]);
        $deleted = [];
        $this->recordDeletes($deleted);

        $this->handler()->deleteIndex($this->dimensions(), $this->stream(range(1, 250)));

        self::assertSame(
            [
                ['physical_live', 'id:[' . implode(',', range(1, 100)) . ']'],
                ['physical_live', 'id:[' . implode(',', range(101, 200)) . ']'],
                ['physical_live', 'id:[' . implode(',', range(201, 250)) . ']'],
            ],
            $deleted
        );
    }

    public function testDeleteSkipsThePublicCollectionWhenItsAliasIsUnset(): void
    {
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, null],
        ]);
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->expects(self::once())->method('deleteBy')->with('physical_live', 'id:[10]');

        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));
    }

    public function testDeleteToleratesAMissingCollection(): void
    {
        $this->resolveBothLive();
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->expects(self::exactly(2))->method('deleteBy')->willThrowException(
            new TypesenseException('gone', 404)
        );

        // A 404 means the collection is gone: nothing left to delete, not a failure.
        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));
    }

    public function testDeleteRethrowsNon404Failures(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter->method('deleteBy')->willThrowException(
            new TypesenseException('boom', 500)
        );

        $this->expectException(TypesenseException::class);

        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));
    }

    public function testDeleteDoesNothingWhenTheAliasIsUnset(): void
    {
        $this->aliasManager->method('resolve')->willReturn(null);
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->expects(self::never())->method('deleteBy');

        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));
    }

    public function testEverySaveResetsTheRunScopedCachesBeforeBuilding(): void
    {
        $this->resolveBothLive();
        $sequence = [];
        foreach (['types', 'labels'] as $name) {
            $cache = $this->createMock(RunScopedCacheInterface::class);
            $cache->expects(self::exactly(2))->method('reset')->willReturnCallback(
                static function () use (&$sequence, $name): void {
                    $sequence[] = 'reset ' . $name;
                }
            );
            $this->runScopedCaches[] = $cache;
        }
        $this->documentBuilder = $this->createStub(DocumentBuilder::class);
        $this->documentBuilder->method('build')->willReturnCallback(
            static function () use (&$sequence): array {
                $sequence[] = 'build';

                return ['1' => ['id' => '1']];
            }
        );
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));

        $handler = $this->handler();
        $handler->saveIndex($this->dimensions(), $this->stream([1 => []]));
        $handler->saveIndex($this->dimensions(), $this->stream([1 => []]));

        self::assertSame(
            ['reset types', 'reset labels', 'build', 'reset types', 'reset labels', 'build'],
            $sequence,
            'one load per run: reset once at the start of each save, never per batch'
        );
    }

    public function testUnstackedSaveDoesNotRetainBuiltIds(): void
    {
        // Hand-rolled fakes: PHPUnit test doubles record every invocation, which would swamp the measurement.
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, null],
        ]);
        $this->documentWriter = new class extends DocumentWriter {
            public function __construct()
            {
            }

            public function importBatch(
                string $collection,
                iterable $documents,
                string $action = self::ACTION_UPSERT,
                int $batchSize = self::DEFAULT_BATCH_SIZE
            ): ImportResult {
                return new ImportResult(0, 0, []);
            }
        };
        $this->documentBuilder = new class extends DocumentBuilder {
            /** @var int[] memory usage at each batch */
            public array $usage = [];

            public function __construct()
            {
            }

            public function build(\Traversable $documents, int $storeId): array
            {
                $this->usage[] = memory_get_usage();
                $built = [];
                foreach ($documents as $id => $raw) {
                    $built[(string)$id] = ['id' => (string)$id];
                }

                return $built;
            }
        };
        $rows = static function (): \Generator {
            for ($id = 1; $id <= 100000; $id++) {
                yield $id => [];
            }
        };

        $this->handler()->saveIndex($this->dimensions(), $rows());

        // Retaining 100k ids costs megabytes; a bounded stream stays flat across batches.
        $usage = $this->documentBuilder->usage;
        self::assertCount(1000, $usage);
        self::assertLessThan(512 * 1024, end($usage) - $usage[1]);
    }

    public function testImplementsStackedActions(): void
    {
        self::assertInstanceOf(StackedActionsIndexerInterface::class, $this->handler());
    }

    public function testStackedSaveUpsertsFirstAndDeletesOnlyTheIdsItDidNotRebuild(): void
    {
        $this->resolveBothLive();
        $sequence = [];
        $this->documentWriter = $this->createStub(DocumentWriter::class);
        $this->documentWriter->method('importBatch')->willReturnCallback(
            function (string $collection) use (&$sequence): ImportResult {
                $sequence[] = ['import', $collection];

                return $this->noFailures(1);
            }
        );
        $this->documentWriter->method('deleteBy')->willReturnCallback(
            function (string $collection, string $filter) use (&$sequence): int {
                $sequence[] = ['delete', $collection, $filter];

                return 1;
            }
        );
        // Product 3 was disabled: the builder drops it, so it must be deleted.
        $this->documentBuilder = $this->createStub(DocumentBuilder::class);
        $this->documentBuilder->method('build')->willReturn(
            ['1' => ['id' => '1'], '2' => ['id' => '2']]
        );

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([1, 2, 3]));
        self::assertSame([], $sequence, 'a stacked delete sends no HTTP');

        $handler->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => [], 3 => []]));
        $handler->triggerStackedActions();
        $handler->disableStackedActions();

        self::assertSame(
            [
                ['import', 'physical_live'],
                ['import', 'physical_public'],
                ['delete', 'physical_live', 'id:[3]'],
                ['delete', 'physical_public', 'id:[3]'],
            ],
            $sequence,
            'upserts come first, only the non-rebuilt id is deleted, trigger flushes nothing'
        );
    }

    public function testStackedDeletesDuringAFullRebuildHitThePendingCollections(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $deleted = [];
        $this->documentWriter->expects(self::exactly(2))->method('deleteBy')->willReturnCallback(
            static function (string $collection, string $filter) use (&$deleted): int {
                $deleted[] = [$collection, $filter];

                return 1;
            }
        );

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([1, 9]));
        $handler->saveIndex($this->dimensions(), $this->stream([1 => []]));

        self::assertSame([['physical_new', 'id:[9]'], ['physical_public_new', 'id:[9]']], $deleted);
    }

    public function testStackedSaveRebuildingEveryIdDeletesNothing(): void
    {
        $this->resolveBothLive();
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(2));
        $this->documentWriter->expects(self::never())->method('deleteBy');

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([1, 2]));
        $handler->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => []]));
        $handler->triggerStackedActions();
    }

    public function testStackedSaveRebuildingNothingDeletesEveryBufferedId(): void
    {
        $this->resolveBothLive();
        $deleted = [];
        $this->recordDeletes($deleted);
        // Every product was disabled or removed: the builder returns no documents.
        $this->documentBuilder = $this->createStub(DocumentBuilder::class);
        $this->documentBuilder->method('build')->willReturn([]);

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([1, 2, 3]));
        $handler->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => [], 3 => []]));
        $handler->triggerStackedActions();

        self::assertSame(
            [['physical_live', 'id:[1,2,3]'], ['physical_public', 'id:[1,2,3]']],
            $deleted,
            'every buffered id is deleted once, trigger flushes nothing more'
        );
    }

    public function testStackedActionsWithoutAPublicAliasTouchOnlyTheMainCollection(): void
    {
        $this->aliasManager->method('resolve')->willReturnMap([
            [self::ALIAS, 'physical_live'],
            [self::PUBLIC_ALIAS, null],
        ]);
        $deleted = [];
        $this->recordDeletes($deleted);
        $this->documentBuilder = $this->createStub(DocumentBuilder::class);
        $this->documentBuilder->method('build')->willReturn(['1' => ['id' => '1']]);

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([1, 2]));
        $handler->saveIndex($this->dimensions(), $this->stream([1 => [], 2 => []]));
        $handler->deleteIndex($this->dimensions(), $this->stream([4]));
        $handler->triggerStackedActions();

        self::assertSame([['physical_live', 'id:[2]'], ['physical_live', 'id:[4]']], $deleted);
    }

    public function testTriggerFlushesStackedDeletesWithoutASave(): void
    {
        $this->resolveBothLive();
        $deleted = [];
        $this->recordDeletes($deleted);

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([5, 6]));
        self::assertSame([], $deleted);

        $handler->triggerStackedActions();
        $handler->triggerStackedActions();

        self::assertSame(
            [['physical_live', 'id:[5,6]'], ['physical_public', 'id:[5,6]']],
            $deleted,
            'trigger flushes once and empties the buffer'
        );
    }

    public function testDisableClearsTheStackedBuffer(): void
    {
        $this->resolveBothLive();
        $deleted = [];
        $this->recordDeletes($deleted);

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([5]));
        $handler->disableStackedActions();
        $handler->triggerStackedActions();
        self::assertSame([], $deleted, 'disable drops the buffered ids');

        // Non-stacked again: deletes go straight out.
        $handler->deleteIndex($this->dimensions(), $this->stream([7]));
        self::assertSame([['physical_live', 'id:[7]'], ['physical_public', 'id:[7]']], $deleted);
    }

    public function testEnableClearsAStaleBuffer(): void
    {
        $this->resolveBothLive();
        $deleted = [];
        $this->recordDeletes($deleted);

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([5]));
        $handler->enableStackedActions();
        $handler->triggerStackedActions();

        self::assertSame([], $deleted);
    }

    public function testStackedDeleteRethrowsNon404Failures(): void
    {
        $this->resolveBothLive();
        $this->documentWriter->method('deleteBy')->willThrowException(new TypesenseException('boom', 500));

        $handler = $this->handler();
        $handler->enableStackedActions();
        $handler->deleteIndex($this->dimensions(), $this->stream([5]));

        $this->expectException(TypesenseException::class);
        $handler->triggerStackedActions();
    }

    public function testFullRebuildSyncsTheStoreSynonymSetAfterTheSwaps(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->synonymSync = $this->createMock(SynonymSync::class);

        $sequence = [];
        $this->aliasManager->method('swap')->willReturnCallback(
            static function (string $alias) use (&$sequence): ?string {
                $sequence[] = 'swap:' . $alias;

                return null;
            }
        );
        $this->synonymSync->expects(self::once())
            ->method('syncStore')
            ->with(1)
            ->willReturnCallback(static function () use (&$sequence): void {
                $sequence[] = 'synonyms';
            });

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));

        self::assertSame(['swap:' . self::ALIAS, 'swap:' . self::PUBLIC_ALIAS, 'synonyms'], $sequence);
    }

    public function testIncrementalSaveDoesNotSyncSynonyms(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->synonymSync = $this->createMock(SynonymSync::class);
        $this->synonymSync->expects(self::never())->method('syncStore');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => []]));
    }

    public function testFailedRebuildDoesNotSyncSynonyms(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willThrowException(new TypesenseException('boom'));
        $this->synonymSync = $this->createMock(SynonymSync::class);
        $this->synonymSync->expects(self::never())->method('syncStore');

        $this->expectException(TypesenseException::class);
        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testSynonymSyncFailureIsLoggedAndDoesNotFailTheRebuild(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->synonymSync->method('syncStore')->willThrowException(new TypesenseException('down'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('synonym sync failed for store 1'));

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));

        self::assertNull($this->pendingCollections->get(self::ALIAS));
    }

    public function testFullRebuildPrunesOlderCollectionsAfterEachSwap(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));

        $sequence = [];
        $this->aliasManager->method('swap')->willReturnCallback(
            static function (string $alias) use (&$sequence): ?string {
                $sequence[] = 'swap:' . $alias;

                return null;
            }
        );
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::exactly(2))->method('prune')->willReturnCallback(
            static function (string $alias, string $current) use (&$sequence): void {
                $sequence[] = 'prune:' . $alias . '|' . $current;
            }
        );

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));

        self::assertSame([
            'swap:' . self::ALIAS,
            'swap:' . self::PUBLIC_ALIAS,
            'prune:' . self::ALIAS . '|physical_new',
            'prune:' . self::PUBLIC_ALIAS . '|physical_public_new',
        ], $sequence);
    }

    public function testFullRebuildReconcilesEachPendingSchemaBeforeAndAfterItsSwap(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));

        $sequence = [];
        $this->schemaSync = $this->createMock(CollectionSchemaSync::class);
        $this->schemaSync->expects(self::exactly(2))->method('reconcileMain')->willReturnCallback(
            static function (string $collection, int $storeId, $policy) use (&$sequence): Decision {
                self::assertSame(0, $policy->getRebuildThreshold(), 'a pending collection blocks no writer');
                $sequence[] = 'main:' . $collection . '|' . $storeId;

                return Decision::inPlace(new SchemaDiff());
            }
        );
        $this->schemaSync->expects(self::exactly(2))->method('reconcilePublic')->willReturnCallback(
            static function (string $collection, int $storeId) use (&$sequence): Decision {
                $sequence[] = 'public:' . $collection . '|' . $storeId;

                return Decision::inPlace(new SchemaDiff());
            }
        );
        $this->aliasManager->method('swap')->willReturnCallback(
            static function (string $alias) use (&$sequence): ?string {
                $sequence[] = 'swap:' . $alias;

                return null;
            }
        );

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));

        self::assertSame([
            'main:physical_new|1',
            'swap:' . self::ALIAS,
            'main:physical_new|1',
            'public:physical_public_new|1',
            'swap:' . self::PUBLIC_ALIAS,
            'public:physical_public_new|1',
        ], $sequence);
    }

    public function testPendingSchemaNeedingARebuildIsSwappedThenFailsTheRebuild(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->pendingCollections->set(self::PUBLIC_ALIAS, 'physical_public_new');
        $this->schemaSync->method('reconcilePublic')->willReturn(Decision::inPlace(new SchemaDiff()));
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->schemaSync->method('reconcileMain')->willReturn(Decision::rebuild(new SchemaDiff(), 'type change'));
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::exactly(2))->method('warning')->with(self::stringContains('physical_new'));
        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->expects(self::exactly(2))->method('swap');
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::exactly(2))->method('prune');

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('changed during the rebuild of store 1');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testFailedPostSwapSchemaCheckIsLoggedAndFailsTheRebuildAfterCleanup(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $calls = 0;
        $this->schemaSync->method('reconcileMain')->willReturnCallback(
            static function () use (&$calls): Decision {
                if ($calls++ > 0) {
                    throw new TypesenseException('timeout');
                }

                return Decision::inPlace(new SchemaDiff());
            }
        );
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())->method('error')->with(self::stringContains('post-swap'));
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::once())->method('prune')->with(self::ALIAS, 'physical_new');

        try {
            $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
            self::fail('Expected the rebuild to fail.');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('changed during the rebuild of store 1', $e->getMessage());
        }

        self::assertSame(2, $calls);
        self::assertNull($this->pendingCollections->get(self::ALIAS));
    }

    public function testIncrementalSaveDoesNotReconcileTheLiveSchema(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->schemaSync = $this->createMock(CollectionSchemaSync::class);
        $this->schemaSync->expects(self::never())->method('reconcileMain');
        $this->schemaSync->expects(self::never())->method('reconcilePublic');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => []]));
    }

    public function testIncrementalSaveDoesNotPrune(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter->method('importBatch')->willReturn($this->noFailures(1));
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::never())->method('prune');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => []]));
    }

    public function testFailedRebuildDoesNotPrune(): void
    {
        $this->pendingCollections->set(self::ALIAS, 'physical_new');
        $this->documentWriter->method('importBatch')->willThrowException(new TypesenseException('boom'));
        $this->pruner = $this->createMock(OrphanCollectionPruner::class);
        $this->pruner->expects(self::never())->method('prune');

        $this->expectException(TypesenseException::class);
        $this->handler()->saveIndex($this->dimensions(), $this->stream([1 => []]));
    }

    public function testIsAvailableDelegatesToTheCachedHealthChecker(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(false);
        self::assertFalse($this->handler()->isAvailable($this->dimensions()));

        $this->healthChecker = $this->createStub(HealthChecker::class);
        $this->healthChecker->method('isHealthy')->willReturn(true);
        self::assertTrue($this->handler()->isAvailable($this->dimensions()));
    }
}
