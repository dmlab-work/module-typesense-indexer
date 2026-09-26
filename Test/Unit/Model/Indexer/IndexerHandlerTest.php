<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use DmLab\TypesenseCore\Model\Document\ImportResult;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Indexer\IndexerHandler;
use DmLab\TypesenseIndexer\Model\Indexer\PendingCollectionRegistry;
use DmLab\TypesenseIndexer\Model\Indexer\StoreScopeResolver;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexStructureInterface;
use Magento\Framework\Indexer\IndexerInterface as RegisteredIndexer;
use Magento\Framework\Indexer\SaveHandler\Batch;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;

class IndexerHandlerTest extends TestCase
{
    private const INDEXER_ID = 'catalogsearch_fulltext';
    private const ALIAS = 'typesense_catalogsearch_fulltext_1';

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
            $data
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
        $this->aliasManager->method('resolve')->with(self::ALIAS)->willReturn('physical_live');

        $this->documentWriter->expects(self::once())
            ->method('importBatch')
            ->with('physical_live')
            ->willReturn($this->noFailures(1));
        $this->aliasManager->expects(self::never())->method('swap');

        $this->handler()->saveIndex($this->dimensions(), $this->stream([7 => []]));
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
        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->method('swap')->willThrowException(new TypesenseException('drop old failed', 500));
        $this->aliasManager->method('resolve')->with(self::ALIAS)->willReturn('physical_new');
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

    public function testDeleteRemovesDocumentsByIdFromTheLiveCollection(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter = $this->createMock(DocumentWriter::class);

        $deleted = [];
        $this->documentWriter->expects(self::exactly(2))
            ->method('delete')
            ->willReturnCallback(function (string $collection, string $id) use (&$deleted): array {
                $deleted[] = [$collection, $id];

                return [];
            });

        // A blank id is skipped, not sent as an empty delete.
        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10, '', 20]));

        self::assertSame([['physical_live', '10'], ['physical_live', '20']], $deleted);
    }

    public function testDeleteToleratesAMissingDocument(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter->method('delete')->willThrowException(
            new TypesenseException('gone', 404)
        );

        // A 404 for an already-absent document is the desired state, not a failure.
        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));

        $this->addToAssertionCount(1);
    }

    public function testDeleteRethrowsNon404Failures(): void
    {
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->documentWriter->method('delete')->willThrowException(
            new TypesenseException('boom', 500)
        );

        $this->expectException(TypesenseException::class);

        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));
    }

    public function testDeleteDoesNothingWhenTheAliasIsUnset(): void
    {
        $this->aliasManager->method('resolve')->willReturn(null);
        $this->documentWriter = $this->createMock(DocumentWriter::class);
        $this->documentWriter->expects(self::never())->method('delete');

        $this->handler()->deleteIndex($this->dimensions(), $this->stream([10]));
    }

    public function testIsAvailableDelegatesToTheCachedHealthChecker(): void
    {
        $this->healthChecker->method('isHealthy')->willReturn(false);
        self::assertFalse($this->handler()->isAvailable($this->dimensions()));

        $this->healthChecker = $this->createStub(HealthChecker::class);
        $this->healthChecker->method('isHealthy')->willReturn(true);
        self::assertTrue($this->handler()->isAvailable($this->dimensions()));
    }

    public function testDoesNotImplementStackedActions(): void
    {
        // Recorded decision: core's DocumentWriter batches over HTTP, there is no query-stacking mode.
        self::assertNotInstanceOf(
            \Magento\Framework\Indexer\SaveHandler\StackedActionsIndexerInterface::class,
            $this->handler()
        );
    }
}
