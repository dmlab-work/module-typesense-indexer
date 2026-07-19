<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Plugin;

use MageDevGroup\TypesenseCore\Exception\TypesenseException;
use MageDevGroup\TypesenseCore\Model\Collection\AliasManager;
use MageDevGroup\TypesenseCore\Model\Schema\Decision;
use MageDevGroup\TypesenseCore\Model\Schema\ReconcilePolicy;
use MageDevGroup\TypesenseCore\Model\Schema\Reconciler;
use MageDevGroup\TypesenseIndexer\Model\Config;
use MageDevGroup\TypesenseIndexer\Model\ConnectionSettings;
use MageDevGroup\TypesenseIndexer\Api\EngineCode;
use MageDevGroup\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use MageDevGroup\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use MageDevGroup\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Framework\Search\Request\Config as SearchRequestConfig;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AttributeSaveHandlerTest extends TestCase
{
    // Stubs by default; a test escalates one to a mock (createMock) only where it verifies calls.

    /** @var EngineResolverInterface */
    private EngineResolverInterface $engineResolver;

    /** @var Config */
    private Config $config;

    /** @var Reconciler */
    private Reconciler $reconciler;

    /** @var AliasManager */
    private AliasManager $aliasManager;

    /** @var DesiredSchemaBuilder */
    private DesiredSchemaBuilder $schemaBuilder;

    /** @var StoreManagerInterface */
    private StoreManagerInterface $storeManager;

    /** @var SearchRequestConfig */
    private SearchRequestConfig $searchRequestConfig;

    /** @var EavConfig */
    private EavConfig $eavConfig;

    /** @var IndexerInterface */
    private IndexerInterface $indexer;

    /** @var ReconcilePolicy the reconcile policy the config hands to the reconciler */
    private ReconcilePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReconcilePolicy(500000, ReconcilePolicy::AUTO);
        $this->engineResolver = $this->createStub(EngineResolverInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->reconciler = $this->createStub(Reconciler::class);
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->searchRequestConfig = $this->createStub(SearchRequestConfig::class);
        $this->eavConfig = $this->createStub(EavConfig::class);
        $this->indexer = $this->createStub(IndexerInterface::class);

        // The magic setter on the entity type is a no-op for these tests.
        $this->eavConfig->method('getEntityType')->willReturn($this->createStub(Type::class));
        $this->schemaBuilder->method('build')->willReturn([]);

        $this->engineIsTypesense();
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

    private function handler(): AttributeSaveHandler
    {
        $registry = $this->createStub(IndexerRegistry::class);
        $registry->method('get')->willReturn($this->indexer);

        return new AttributeSaveHandler(
            $this->engineResolver,
            $this->config,
            $this->reconciler,
            $this->aliasManager,
            $this->schemaBuilder,
            $this->nameResolver(),
            $this->storeManager,
            $registry,
            $this->searchRequestConfig,
            $this->eavConfig,
            $this->createStub(LoggerInterface::class)
        );
    }

    private function engineIsTypesense(): void
    {
        $this->engineResolver->method('getCurrentSearchEngine')->willReturn(EngineCode::ENGINE);
        $this->config->method('usesNativeInvalidation')->willReturn(false);
        $this->config->method('getReconcilePolicy')->willReturn($this->policy);
    }

    /**
     * @param int[] $storeIds
     */
    private function storesAre(array $storeIds): void
    {
        $stores = [];
        foreach ($storeIds as $id) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($id);
            $stores[] = $store;
        }
        $this->storeManager->method('getStores')->willReturn($stores);
    }

    private function decision(bool $needsRebuild): Decision
    {
        $decision = $this->createStub(Decision::class);
        $decision->method('needsRebuild')->willReturn($needsRebuild);
        $decision->method('getReason')->willReturn($needsRebuild ? 'too big' : null);

        return $decision;
    }

    public function testIsActiveWhenEngineIsTypesenseAndEscapeHatchOff(): void
    {
        self::assertTrue($this->handler()->isActive());
    }

    public function testIsInactiveForAnotherEngine(): void
    {
        $this->engineResolver = $this->createStub(EngineResolverInterface::class);
        $this->engineResolver->method('getCurrentSearchEngine')->willReturn('opensearch');

        self::assertFalse($this->handler()->isActive());
    }

    public function testIsInactiveWhenEscapeHatchOn(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('usesNativeInvalidation')->willReturn(true);

        self::assertFalse($this->handler()->isActive());
    }

    public function testRefreshSearchConfigResetsTheRequestConfig(): void
    {
        $this->searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $this->searchRequestConfig->expects(self::once())->method('reset');

        $this->handler()->refreshSearchConfig();
    }

    public function testRefreshSearchConfigMarksTheSearchableAttributesListStale(): void
    {
        // A real Type carries the magic setter, so the flag is actually recorded (a stub swallows it).
        $entityType = (new \ReflectionClass(Type::class))->newInstanceWithoutConstructor();
        $this->eavConfig = $this->createStub(EavConfig::class);
        $this->eavConfig->method('getEntityType')->willReturn($entityType);

        $this->handler()->refreshSearchConfig();

        self::assertTrue((bool)$entityType->getNeedRefreshSearchAttributesList());
    }

    public function testExistingAttributePatchesInPlaceWithoutInvalidating(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('typesense_catalogsearch_fulltext_1_live');

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::once())
            ->method('reconcile')
            ->with('typesense_catalogsearch_fulltext_1_live', [], $this->policy)
            ->willReturn($this->decision(false));

        $this->searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $this->searchRequestConfig->expects(self::once())->method('reset');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleExistingAttributeSave();
    }

    public function testReconcileReceivesThePolicyBuiltFromConfig(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical_1');

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::once())
            ->method('reconcile')
            ->with(self::anything(), self::anything(), self::identicalTo($this->policy))
            ->willReturn($this->decision(false));

        $this->handler()->handleExistingAttributeSave();
    }

    public function testExistingAttributeReconcilesEveryStore(): void
    {
        $this->storesAre([1, 2]);
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'physical_1'],
            ['typesense_catalogsearch_fulltext_2', 'physical_2'],
        ]);

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::exactly(2))->method('reconcile')->willReturn($this->decision(false));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleExistingAttributeSave();
    }

    public function testStoreWithoutALiveCollectionIsSkipped(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn(null);

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::never())->method('reconcile');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleExistingAttributeSave();
    }

    public function testNeedsRebuildFallsBackToInvalidation(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical_1');
        $this->reconciler->method('reconcile')->willReturn($this->decision(true));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleExistingAttributeSave();
    }

    public function testReconcileFailureFallsBackToInvalidation(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical_1');
        $this->reconciler->method('reconcile')->willThrowException(new TypesenseException('boom'));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleExistingAttributeSave();
    }

    public function testNewAttributeAlwaysInvalidates(): void
    {
        // A new attribute's column is in no document, so it reindexes regardless of collection state.
        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::never())->method('reconcile');

        $this->searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $this->searchRequestConfig->expects(self::once())->method('reset');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleNewAttributeSave();
    }
}
