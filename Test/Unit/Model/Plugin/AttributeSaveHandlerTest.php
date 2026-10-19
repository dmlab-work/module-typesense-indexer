<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Schema\Decision;
use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseCore\Model\Schema\Reconciler;
use DmLab\TypesenseIndexer\Model\Config;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Plugin\AttributeSaveHandler;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\CollectionSchemaSync;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\SchemaSnapshotCollectionManager;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Type;
use Magento\Framework\Indexer\IndexerInterface;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
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

    /** @var SchemaSnapshotCollectionManager */
    private SchemaSnapshotCollectionManager $collectionManager;

    /** @var DesiredSchemaBuilder */
    private DesiredSchemaBuilder $schemaBuilder;

    /** @var PublicSchemaBuilder */
    private PublicSchemaBuilder $publicSchemaBuilder;

    /** @var StoreManagerInterface */
    private StoreManagerInterface $storeManager;

    /** @var SearchRequestConfig */
    private SearchRequestConfig $searchRequestConfig;

    /** @var EavConfig */
    private EavConfig $eavConfig;

    /** @var IndexerInterface */
    private IndexerInterface $indexer;

    /** @var ReindexInvalidationMarker */
    private ReindexInvalidationMarker $invalidationMarker;

    /** @var ReconcilePolicy the reconcile policy the config hands to the reconciler */
    private ReconcilePolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ReconcilePolicy(500000, ReconcilePolicy::AUTO);
        $this->engineResolver = $this->createStub(EngineResolverInterface::class);
        $this->config = $this->createStub(Config::class);
        $this->reconciler = $this->createStub(Reconciler::class);
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->collectionManager = $this->createStub(SchemaSnapshotCollectionManager::class);
        $this->collectionManager->method('get')->willReturn(['fields' => []]);
        $this->schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $this->publicSchemaBuilder = $this->createStub(PublicSchemaBuilder::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->searchRequestConfig = $this->createStub(SearchRequestConfig::class);
        $this->eavConfig = $this->createStub(EavConfig::class);
        $this->indexer = $this->createStub(IndexerInterface::class);
        $this->invalidationMarker = $this->createStub(ReindexInvalidationMarker::class);

        // The magic setter on the entity type is a no-op for these tests.
        $this->eavConfig->method('getEntityType')->willReturn($this->createStub(Type::class));
        $this->schemaBuilder->method('build')->willReturn([]);
        $this->publicSchemaBuilder->method('build')->willReturn([]);

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
        $lockManager = $this->createStub(LockManagerInterface::class);
        $lockManager->method('lock')->willReturn(true);

        return new AttributeSaveHandler(
            $this->engineResolver,
            $this->config,
            $this->aliasManager,
            new CollectionSchemaSync(
                $this->reconciler,
                $this->collectionManager,
                $this->schemaBuilder,
                $this->publicSchemaBuilder,
                $lockManager
            ),
            $this->nameResolver(),
            $this->storeManager,
            $registry,
            $this->searchRequestConfig,
            $this->eavConfig,
            $this->createStub(LoggerInterface::class),
            $this->invalidationMarker
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
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'typesense_catalogsearch_fulltext_1_live'],
            ['typesense_catalogsearch_fulltext_1_public', null],
        ]);

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::once())
            ->method('reconcile')
            ->with('typesense_catalogsearch_fulltext_1_live', [], $this->policy)
            ->willReturn($this->decision(false));

        $this->searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $this->searchRequestConfig->expects(self::once())->method('reset');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleAttributeSave();
    }

    public function testLiveInstancesOfRegexFieldsStayInTheDesiredSchema(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'live'],
            ['typesense_catalogsearch_fulltext_1_public', null],
        ]);
        $regex = new FieldSpec('price_[0-9]+_.*', 'float', true, null, true, true);
        $this->schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $this->schemaBuilder->method('build')->willReturn([$regex, new FieldSpec('sku', 'string')]);
        $this->collectionManager = $this->createStub(SchemaSnapshotCollectionManager::class);
        $this->collectionManager->method('get')->willReturnMap([['live', ['fields' => [
            ['name' => 'sku', 'type' => 'string'],
            ['name' => 'price_[0-9]+_.*', 'type' => 'float'],
            ['name' => 'price_0_1', 'type' => 'float'],
            ['name' => 'price_x_1', 'type' => 'float'],
            ['name' => 'stale_attr', 'type' => 'string'],
        ]]]]);

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::once())
            ->method('reconcile')
            ->with('live', self::callback(static function (array $desired): bool {
                $names = array_map(static fn (FieldSpec $spec): string => $spec->getName(), $desired);
                $instance = $desired[2] ?? null;

                return $names === ['price_[0-9]+_.*', 'sku', 'price_0_1']
                    && $instance->getType() === 'float'
                    && $instance->getFacet() === true
                    && $instance->getSort() === true;
            }))
            ->willReturn($this->decision(false));

        $this->handler()->handleAttributeSave();
    }

    public function testReconcileReceivesThePolicyBuiltFromConfig(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical_1');

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::exactly(2))
            ->method('reconcile')
            ->with(self::anything(), self::anything(), self::identicalTo($this->policy))
            ->willReturn($this->decision(false));

        $this->handler()->handleAttributeSave();
    }

    public function testExistingAttributeReconcilesEveryStore(): void
    {
        $this->storesAre([1, 2]);
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'physical_1'],
            ['typesense_catalogsearch_fulltext_2', 'physical_2'],
            ['typesense_catalogsearch_fulltext_1_public', 'physical_1_public'],
            ['typesense_catalogsearch_fulltext_2_public', 'physical_2_public'],
        ]);

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::exactly(4))->method('reconcile')->willReturn($this->decision(false));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleAttributeSave();
    }

    public function testStoreWithoutALiveCollectionIsSkipped(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn(null);

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::never())->method('reconcile');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleAttributeSave();
    }

    public function testNeedsRebuildFallsBackToInvalidation(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical_1');
        $this->reconciler->method('reconcile')->willReturn($this->decision(true));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');
        $this->invalidationMarker = $this->createMock(ReindexInvalidationMarker::class);
        $this->invalidationMarker->expects(self::once())->method('mark');

        $this->handler()->handleAttributeSave();
    }

    public function testReconcileFailureFallsBackToInvalidation(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical_1');
        $this->reconciler->method('reconcile')->willThrowException(new TypesenseException('boom'));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleAttributeSave();
    }

    public function testAttributeSaveDoesNotInvalidateWhenEveryStorePatchesInPlace(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical');
        $this->reconciler->method('reconcile')->willReturn($this->decision(false));

        $this->searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $this->searchRequestConfig->expects(self::once())->method('reset');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleAttributeSave();
    }

    public function testPublicAliasIsReconciledAgainstThePublicSchema(): void
    {
        $this->storesAre([1]);
        $mainFields = [new FieldSpec(name: 'color', type: 'int32[]', facet: true)];
        $publicFields = [new FieldSpec(name: 'color_value', type: 'string[]', index: true, optional: true)];
        $this->schemaBuilder = $this->createStub(DesiredSchemaBuilder::class);
        $this->schemaBuilder->method('build')->willReturnMap([[1, $mainFields]]);
        $this->publicSchemaBuilder = $this->createStub(PublicSchemaBuilder::class);
        $this->publicSchemaBuilder->method('build')->willReturnMap([[1, $publicFields]]);
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'main_1'],
            ['typesense_catalogsearch_fulltext_1_public', 'public_1'],
        ]);

        $calls = [];
        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::exactly(2))
            ->method('reconcile')
            ->willReturnCallback(function (string $collection, array $fields) use (&$calls): Decision {
                $calls[$collection] = $fields;

                return $this->decision(false);
            });

        $this->handler()->handleAttributeSave();

        self::assertSame(['main_1' => $mainFields, 'public_1' => $publicFields], $calls);
    }

    public function testSearchableToggleOnAnOptionAttributePatchesBothAliasesWithoutInvalidating(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'main_1'],
            ['typesense_catalogsearch_fulltext_1_public', 'public_1'],
        ]);

        $reconciled = [];
        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::exactly(2))
            ->method('reconcile')
            ->willReturnCallback(function (string $collection) use (&$reconciled): Decision {
                $reconciled[] = $collection;

                return $this->decision(false);
            });

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleAttributeSave();

        self::assertSame(['main_1', 'public_1'], $reconciled);
    }

    public function testPublicAliasNeedingARebuildInvalidates(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturnMap([
            ['typesense_catalogsearch_fulltext_1', 'main_1'],
            ['typesense_catalogsearch_fulltext_1_public', 'public_1'],
        ]);
        $this->reconciler->method('reconcile')->willReturnCallback(
            fn (string $collection): Decision => $this->decision($collection === 'public_1')
        );

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleAttributeSave();
    }

    public function testAttributeDeleteReconcilesWithoutInvalidating(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical');

        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::exactly(2))->method('reconcile')->willReturn($this->decision(false));

        $this->searchRequestConfig = $this->createMock(SearchRequestConfig::class);
        $this->searchRequestConfig->expects(self::once())->method('reset');

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleAttributeDelete();
    }

    public function testAttributeDeleteNeedingARebuildInvalidates(): void
    {
        $this->storesAre([1]);
        $this->aliasManager->method('resolve')->willReturn('physical');
        $this->reconciler->method('reconcile')->willReturn($this->decision(true));

        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleAttributeDelete();
    }

    public function testLabelsExposedMarksAndInvalidatesWithoutReconciling(): void
    {
        $this->reconciler = $this->createMock(Reconciler::class);
        $this->reconciler->expects(self::never())->method('reconcile');
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');
        $this->invalidationMarker = $this->createMock(ReindexInvalidationMarker::class);
        $this->invalidationMarker->expects(self::once())->method('mark');

        $this->handler()->handleLabelsExposed();
    }

    public function testLabelsExposedUnderTheEscapeHatchStillInvalidates(): void
    {
        $this->config = $this->createStub(Config::class);
        $this->config->method('usesNativeInvalidation')->willReturn(true);
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::once())->method('invalidate');

        $this->handler()->handleLabelsExposed();
    }

    public function testLabelsExposedOnAnotherEngineDoesNothing(): void
    {
        $this->engineResolver = $this->createStub(EngineResolverInterface::class);
        $this->engineResolver->method('getCurrentSearchEngine')->willReturn('opensearch');
        $this->indexer = $this->createMock(IndexerInterface::class);
        $this->indexer->expects(self::never())->method('invalidate');

        $this->handler()->handleLabelsExposed();
    }
}
