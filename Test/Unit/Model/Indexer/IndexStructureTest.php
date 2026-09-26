<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Indexer\IndexStructure;
use DmLab\TypesenseIndexer\Model\Indexer\PendingCollectionRegistry;
use DmLab\TypesenseIndexer\Model\Indexer\StoreScopeResolver;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use Magento\Framework\App\ScopeInterface;
use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Search\Request\Dimension;
use PHPUnit\Framework\TestCase;

class IndexStructureTest extends TestCase
{
    private const INDEXER_ID = 'catalogsearch_fulltext';

    /** @var ScopeResolverInterface */
    private ScopeResolverInterface $scopeResolver;

    /** @var AliasManager */
    private AliasManager $aliasManager;

    /** @var CollectionManager */
    private CollectionManager $collectionManager;

    /** @var PendingCollectionRegistry */
    private PendingCollectionRegistry $pendingCollections;

    protected function setUp(): void
    {
        // Stubs by default; a test escalates one to a mock (createMock) only where it verifies calls.
        $this->scopeResolver = $this->createStub(ScopeResolverInterface::class);
        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->collectionManager = $this->createStub(CollectionManager::class);
        $this->pendingCollections = new PendingCollectionRegistry();
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

    /**
     * @param array<int,FieldSpec[]> $schemaByStore per-store field lists the providers contribute
     */
    private function structure(array $schemaByStore): IndexStructure
    {
        $fieldProvider = new class ($schemaByStore) implements FieldProviderInterface {
            /** @param array<int,FieldSpec[]> $schemaByStore */
            public function __construct(private readonly array $schemaByStore)
            {
            }

            public function getFields(int $storeId): array
            {
                return $this->schemaByStore[$storeId] ?? [];
            }
        };

        return new IndexStructure(
            new StoreScopeResolver($this->scopeResolver),
            new DesiredSchemaBuilder($fieldProvider),
            $this->aliasManager,
            $this->collectionManager,
            $this->nameResolver(),
            $this->pendingCollections
        );
    }

    /**
     * Make the scope resolver map the dimension value to a canonical store id.
     */
    private function scopeResolvesTo(int $storeId): void
    {
        $scope = $this->createStub(ScopeInterface::class);
        $scope->method('getId')->willReturn($storeId);
        $this->scopeResolver->method('getScope')->willReturn($scope);
    }

    /**
     * @return Dimension[]
     */
    private function dimensions(int $scopeValue): array
    {
        return [new Dimension('scope', (string)$scopeValue)];
    }

    public function testCreateIgnoresPassedFieldsAndComputesTheSchemaItself(): void
    {
        $this->scopeResolvesTo(1);
        $desired = [new FieldSpec('sku', 'string', index: true), new FieldSpec('price', 'float', sort: true)];
        $this->aliasManager->method('generateCollectionName')->willReturn('typesense_catalogsearch_fulltext_1_ts_ab');

        // The collection is built with the computed schema, never the (empty) $fields the caller passes.
        $this->collectionManager = $this->createMock(CollectionManager::class);
        $captured = null;
        $this->collectionManager->expects(self::once())
            ->method('create')
            ->willReturnCallback(function (string $name, array $fields) use (&$captured): array {
                $captured = $fields;

                return [];
            });

        $structure = $this->structure([1 => $desired]);

        // cleanIndex always passes an empty $fields array — a decoy non-empty one must still be ignored.
        $structure->create(self::INDEXER_ID, [new FieldSpec('decoy', 'string')], $this->dimensions(1));

        self::assertNotNull($captured);
        self::assertSame(['price', 'sku'], array_map(static fn (FieldSpec $f): string => $f->getName(), $captured));
    }

    public function testCreateMakesANewPhysicalCollectionAndRegistersItWithoutReconciling(): void
    {
        $this->scopeResolvesTo(1);

        $physical = 'typesense_catalogsearch_fulltext_1_1752710400_9f3ac1';
        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->expects(self::once())
            ->method('generateCollectionName')
            ->with('typesense_catalogsearch_fulltext_1')
            ->willReturn($physical);

        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::once())->method('create')->with($physical);
        // Standing up a new collection must NOT go through the reconcile path, which first get()s it.
        $this->collectionManager->expects(self::never())->method('get');

        $structure = $this->structure([1 => [new FieldSpec('sku', 'string')]]);
        $structure->create(self::INDEXER_ID, [], $this->dimensions(1));

        self::assertSame($physical, $this->pendingCollections->get('typesense_catalogsearch_fulltext_1'));
    }

    public function testCreateIsPerStoreScope(): void
    {
        $scope1 = $this->createStub(ScopeInterface::class);
        $scope1->method('getId')->willReturn(1);
        $scope2 = $this->createStub(ScopeInterface::class);
        $scope2->method('getId')->willReturn(2);
        $this->scopeResolver->method('getScope')
            ->willReturnMap([['1', $scope1], ['2', $scope2]]);

        $this->aliasManager->method('generateCollectionName')
            ->willReturnMap([
                ['typesense_catalogsearch_fulltext_1', 'physical_1'],
                ['typesense_catalogsearch_fulltext_2', 'physical_2'],
            ]);

        $structure = $this->structure([
            1 => [new FieldSpec('sku', 'string')],
            2 => [new FieldSpec('sku', 'string')],
        ]);

        $structure->create(self::INDEXER_ID, [], $this->dimensions(1));
        $structure->create(self::INDEXER_ID, [], $this->dimensions(2));

        self::assertSame('physical_1', $this->pendingCollections->get('typesense_catalogsearch_fulltext_1'));
        self::assertSame('physical_2', $this->pendingCollections->get('typesense_catalogsearch_fulltext_2'));
    }

    public function testDeleteDropsTheCollectionTheAliasTargets(): void
    {
        $this->scopeResolvesTo(1);

        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->expects(self::once())
            ->method('resolve')
            ->with('typesense_catalogsearch_fulltext_1')
            ->willReturn('physical_live');
        // The alias must be removed too, or it dangles at the dropped collection.
        $this->aliasManager->expects(self::once())
            ->method('delete')
            ->with('typesense_catalogsearch_fulltext_1');

        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::once())->method('drop')->with('physical_live');

        $structure = $this->structure([1 => []]);
        $structure->delete(self::INDEXER_ID, $this->dimensions(1));
    }

    public function testDeleteToleratesAMissingAliasOnRemoval(): void
    {
        $this->scopeResolvesTo(1);

        $this->aliasManager = $this->createStub(AliasManager::class);
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        $this->aliasManager->method('delete')
            ->willThrowException(new TypesenseException('gone', 404));

        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::once())->method('drop')->with('physical_live');

        $structure = $this->structure([1 => []]);
        // A 404 on the alias delete is the desired end state, not an error.
        $structure->delete(self::INDEXER_ID, $this->dimensions(1));
    }

    public function testDeleteRemovesTheAliasEvenWhenTheTargetCollectionIsAlreadyGone(): void
    {
        $this->scopeResolvesTo(1);

        $this->aliasManager = $this->createMock(AliasManager::class);
        $this->aliasManager->method('resolve')->willReturn('physical_live');
        // The dangling alias must still be removed once the target is confirmed gone.
        $this->aliasManager->expects(self::once())
            ->method('delete')
            ->with('typesense_catalogsearch_fulltext_1');

        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->method('drop')
            ->willThrowException(new TypesenseException('gone', 404));

        $structure = $this->structure([1 => []]);
        $structure->delete(self::INDEXER_ID, $this->dimensions(1));
    }

    public function testDeleteDropsNothingWhenTheAliasIsUnset(): void
    {
        $this->scopeResolvesTo(1);

        $this->aliasManager->method('resolve')->willReturn(null);

        $this->collectionManager = $this->createMock(CollectionManager::class);
        $this->collectionManager->expects(self::never())->method('drop');

        $structure = $this->structure([1 => []]);
        $structure->delete(self::INDEXER_ID, $this->dimensions(1));
    }

    public function testCreateThrowsWhenNoScopeDimensionIsPresent(): void
    {
        $structure = $this->structure([]);

        $this->expectException(LocalizedException::class);

        $structure->create(self::INDEXER_ID, [], []);
    }

    public function testDeleteThrowsWhenNoScopeDimensionIsPresent(): void
    {
        $structure = $this->structure([]);

        $this->expectException(LocalizedException::class);

        $structure->delete(self::INDEXER_ID, []);
    }
}
