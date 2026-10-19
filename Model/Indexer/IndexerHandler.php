<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use DmLab\TypesenseCore\Model\Document\ImportResult;
use DmLab\TypesenseCore\Model\Schema\Decision;
use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicDocumentProjector;
use DmLab\TypesenseIndexer\Model\Schema\CollectionSchemaSync;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\Catalog\Model\Category;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexStructureInterface;
use Magento\Framework\Indexer\SaveHandler\Batch;
use Magento\Framework\Indexer\SaveHandler\IndexerInterface;
use Magento\Framework\Indexer\SaveHandler\StackedActionsIndexerInterface;
use Magento\Framework\Phrase;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The `SaveHandler\IndexerInterface` half of the indexer contract: it turns the catalog stream into
 * Typesense documents and drives the zero-downtime reindex.
 *
 * A full reindex is `cleanIndex` → `saveIndex`: `cleanIndex` delegates to {@see IndexStructure} to
 * stand up a fresh physical collection (registered in {@see PendingCollectionRegistry}) and writes
 * **no** documents — the alias keeps serving the current live collection throughout. `saveIndex`
 * writes the FAT documents into that pending collection and, as its **final** step, swaps the alias
 * onto it (mirroring ES's `updateAlias()` at the end of `saveIndex`); the swap drops the collection
 * the alias left behind. An incremental `saveIndex` (a single row, no preceding `cleanIndex`) has no
 * pending collection, so it upserts into the alias's live collection and does not swap.
 *
 * Every write is mirrored into the public storefront collection (`<alias>_public`) as projected
 * documents ({@see PublicDocumentProjector}); it is rebuilt, swapped and cleaned up alongside the main one.
 *
 * Batch size comes from deployment config `indexer/batch_size/catalogsearch_fulltext/typesense_save`
 * (the ES convention, `elastic_save` → `typesense_save`), and the stream is chunked with Magento's
 * {@see Batch} so document building stays bounded regardless of catalog size.
 *
 * Partial reindex is stacked ({@see StackedActionsIndexerInterface}): deletes are buffered, `saveIndex`
 * upserts first and then deletes only the buffered ids it did not rebuild, so products never vanish
 * between delete and upsert. Deletes go out as `id:[…]` filters in chunks of {@see self::DELETE_CHUNK_SIZE}.
 *
 * Attribute memos ({@see RunScopedCacheInterface}) are reset at the start of every `saveIndex`, so a
 * long-running consumer sees attributes and options created since its previous run.
 *
 * A successful full rebuild also refreshes the store's synonym set ({@see SynonymSync}) and prunes
 * versioned collections older than the new live one ({@see OrphanCollectionPruner}).
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IndexerHandler implements IndexerInterface, StackedActionsIndexerInterface
{
    /**
     * Deployment-config prefix for per-indexer batch sizes, matching ES.
     */
    private const DEPLOYMENT_CONFIG_INDEXER_BATCHES = 'indexer/batch_size/';

    /**
     * Batch-size key under the fulltext indexer; the ES module uses `elastic_save`.
     */
    private const BATCH_SIZE_KEY = 'typesense_save';

    /**
     * Ids per `deleteBy` filter.
     */
    private const DELETE_CHUNK_SIZE = 100;

    /**
     * Memoized batch size — deployment config is read once per instance, not once per batch.
     *
     * @var int|null
     */
    private ?int $resolvedBatchSize = null;

    /**
     * @var bool
     */
    private bool $stacked = false;

    /**
     * Buffered stacked deletes: alias ⇒ id ⇒ id.
     *
     * @var array<string,array<string,string>>
     */
    private array $stackedDeletes = [];

    /**
     * @param IndexStructureInterface $indexStructure fresh-collection lifecycle for `cleanIndex`
     * @param DocumentBuilder $documentBuilder turns the raw stream into FAT Typesense documents
     * @param DocumentWriter $documentWriter core's batched import/delete primitives
     * @param AliasManager $aliasManager resolve the live collection and swap the alias
     * @param CollectionManager $collectionManager drops the pending collection when a rebuild fails
     * @param HealthChecker $healthChecker cached `isAvailable` (the factory turns false into a fatal)
     * @param IndexNameResolver $nameResolver alias name for the scope
     * @param PendingCollectionRegistry $pendingCollections the fresh collection `create()` staged
     * @param StoreScopeResolver $storeScopeResolver store id carried by the dimension
     * @param Batch $batch chunks the stream so building stays memory-bounded
     * @param DeploymentConfig $deploymentConfig source of the batch size
     * @param CacheContext $cacheContext category cache tags for scheduled reindex
     * @param Processor $processor tells us whether the indexer is scheduled
     * @param LoggerInterface $logger surfaces partial import failures
     * @param PublicDocumentProjector $publicProjector main document → public document
     * @param StoreManagerInterface $storeManager resolves the store's website for the public price
     * @param SynonymSync $synonymSync refreshes the store's synonym set after a full rebuild
     * @param OrphanCollectionPruner $pruner drops collections older than the freshly swapped one
     * @param CollectionSchemaSync $schemaSync brings a pending collection's schema up to date before the swap
     * @param array<string,mixed> $data carries `indexer_id` from the handler factory
     * @param int $batchSize fallback batch size when deployment config is unset
     * @param RunScopedCacheInterface[] $runScopedCaches attribute memos reset at the start of every run
     */
    public function __construct(
        private readonly IndexStructureInterface $indexStructure,
        private readonly DocumentBuilder $documentBuilder,
        private readonly DocumentWriter $documentWriter,
        private readonly AliasManager $aliasManager,
        private readonly CollectionManager $collectionManager,
        private readonly HealthChecker $healthChecker,
        private readonly IndexNameResolver $nameResolver,
        private readonly PendingCollectionRegistry $pendingCollections,
        private readonly StoreScopeResolver $storeScopeResolver,
        private readonly Batch $batch,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly CacheContext $cacheContext,
        private readonly Processor $processor,
        private readonly LoggerInterface $logger,
        private readonly PublicDocumentProjector $publicProjector,
        private readonly StoreManagerInterface $storeManager,
        private readonly SynonymSync $synonymSync,
        private readonly OrphanCollectionPruner $pruner,
        private readonly CollectionSchemaSync $schemaSync,
        private readonly array $data = [],
        private readonly int $batchSize = DocumentWriter::DEFAULT_BATCH_SIZE,
        private readonly array $runScopedCaches = []
    ) {
    }

    /**
     * Build the FAT documents for the scope, write them (and their public projection) in batches, then swap last.
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @param \Traversable<int|string,mixed> $documents id ⇒ raw index data
     * @return $this
     * @throws LocalizedException when no scope dimension is present, no collection is available or the
     *         schema changed during a full rebuild in a way it could not apply
     * @throws TypesenseException on a transport or HTTP failure
     */
    public function saveIndex($dimensions, \Traversable $documents)
    {
        foreach ($this->runScopedCaches as $cache) {
            $cache->reset();
        }

        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName($this->getIndexerId(), $storeId);
        $publicAlias = $this->nameResolver->getPublicAliasName($this->getIndexerId(), $storeId);

        $pending = $this->pendingCollections->get($alias);
        $publicPending = $this->pendingCollections->get($publicAlias);

        $swapped = false;
        $publicSwapped = false;
        $unapplied = false;
        try {
            $target = $pending ?? $this->resolveLiveCollection($alias);
            $publicTarget = $publicPending ?? $this->resolvePublicCollection($publicAlias);
            $websiteId = $publicTarget !== null
                ? (int)$this->storeManager->getStore($storeId)->getWebsiteId()
                : 0;

            $scheduled = $this->processor->getIndexer()->isScheduled();

            $builtIds = [];
            foreach ($this->batch->getItems($documents, $this->resolveBatchSize()) as $chunk) {
                $built = $this->documentBuilder->build(new \ArrayIterator($chunk), $storeId);
                if ($built === []) {
                    continue;
                }
                // Only stacked deletes consult the ids; a full rebuild would hold millions of them for nothing.
                if ($this->stacked) {
                    foreach (array_keys($built) as $id) {
                        $builtIds[(string)$id] = true;
                    }
                }

                $this->writeBatch($target, $built);
                if ($publicTarget !== null) {
                    $this->writeBatch($publicTarget, $this->project($built, $websiteId));
                }

                if ($scheduled) {
                    $this->registerCategoryCacheTags($built);
                }
            }

            if ($this->stacked) {
                $this->consumeStackedDeletes($alias, $target, $builtIds);
                $this->consumeStackedDeletes($publicAlias, $publicTarget, $builtIds);
            }

            // The aliases move only for a full rebuild; an incremental save wrote into the live collections.
            // A flag change saved mid-rebuild patched only the old live collection: carry it over before the
            // swap (no size threshold — nothing else writes to a pending collection) and re-check after it,
            // since a save landing between the two still patched the old one.
            if ($pending !== null) {
                $reconcile = fn (): Decision => $this->schemaSync->reconcileMain(
                    $pending,
                    $storeId,
                    new ReconcilePolicy()
                );
                $unapplied = $this->warnIfUnapplied($pending, $reconcile()) || $unapplied;
                $this->aliasManager->swap($alias, $pending);
                $swapped = true;
                $unapplied = $this->recheckSwapped($pending, $reconcile) || $unapplied;
            }
            if ($publicPending !== null) {
                $reconcile = fn (): Decision => $this->schemaSync->reconcilePublic(
                    $publicPending,
                    $storeId,
                    new ReconcilePolicy()
                );
                $unapplied = $this->warnIfUnapplied($publicPending, $reconcile()) || $unapplied;
                $this->aliasManager->swap($publicAlias, $publicPending);
                $publicSwapped = true;
                $unapplied = $this->recheckSwapped($publicPending, $reconcile) || $unapplied;
            }
        } finally {
            $this->settlePending($alias, $pending, $swapped);
            $this->settlePending($publicAlias, $publicPending, $publicSwapped);
            // Never throw, so they run (and mask nothing) even when the public swap failed after the main one.
            if ($swapped) {
                $this->pruner->prune($alias, $pending);
            }
            if ($publicSwapped) {
                $this->pruner->prune($publicAlias, $publicPending);
            }
            if ($swapped) {
                $this->syncSynonyms($storeId);
            }
        }

        // Fail the rebuild so Magento leaves the indexer invalid instead of marking it valid over a stale schema.
        if ($unapplied) {
            throw new LocalizedException(
                new Phrase('The Typesense schema changed during the rebuild of store %1; reindex again.', [$storeId])
            );
        }

        return $this;
    }

    /**
     * Remove documents by id from the scope's live main and public collections (buffered while stacked).
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @param \Traversable<int|string,mixed> $documents ids to remove
     * @return $this
     * @throws LocalizedException when no scope dimension is present
     * @throws TypesenseException on any failure other than a missing collection
     */
    public function deleteIndex($dimensions, \Traversable $documents)
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $aliases = [
            $this->nameResolver->getAliasName($this->getIndexerId(), $storeId),
            $this->nameResolver->getPublicAliasName($this->getIndexerId(), $storeId),
        ];
        $ids = $this->normalizeIds($documents);
        if ($ids === []) {
            return $this;
        }

        foreach ($aliases as $alias) {
            if ($this->stacked) {
                $this->stackedDeletes[$alias] = ($this->stackedDeletes[$alias] ?? []) + $ids;
                continue;
            }
            $target = $this->aliasManager->resolve($alias);
            if ($target !== null) {
                $this->deleteIds($target, $ids);
            }
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function enableStackedActions(): void
    {
        $this->stacked = true;
        $this->stackedDeletes = [];
    }

    /**
     * Flush the deletes no `saveIndex` consumed.
     *
     * @return void
     * @throws TypesenseException on any failure other than a missing collection
     */
    public function triggerStackedActions(): void
    {
        $pending = $this->stackedDeletes;
        $this->stackedDeletes = [];
        foreach ($pending as $alias => $ids) {
            $target = $this->aliasManager->resolve($alias);
            if ($target !== null) {
                $this->deleteIds($target, $ids);
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function disableStackedActions(): void
    {
        $this->stacked = false;
        $this->stackedDeletes = [];
    }

    /**
     * Stand up a fresh collection for the next rebuild — the live collection is left serving.
     *
     * Only `create()` runs: it registers a new pending collection for `saveIndex` to fill. The old
     * live collection stays behind the alias until `saveIndex`'s final `swap()` retires it, so the
     * store keeps serving throughout the rebuild (zero-downtime) and a mid-reindex failure leaves the
     * live collection intact. Dropping it here would break both.
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @return $this
     * @throws LocalizedException when no scope dimension is present
     * @throws TypesenseException
     */
    public function cleanIndex($dimensions)
    {
        $this->indexStructure->create($this->getIndexerId(), [], $dimensions);

        return $this;
    }

    /**
     * Whether Typesense is reachable.
     *
     * The factory calls this on every instantiation and turns a false into a `LogicException`, so it
     * delegates to core's cached, non-throwing health check.
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @return bool
     */
    public function isAvailable($dimensions = [])
    {
        return $this->healthChecker->isHealthy();
    }

    /**
     * Import one built batch and surface any per-document rejections to the indexer log.
     *
     * @param string $collection
     * @param array<string,array<string,mixed>> $documents built documents keyed by id
     * @throws TypesenseException
     */
    private function writeBatch(string $collection, array $documents): void
    {
        $result = $this->documentWriter->importBatch(
            $collection,
            $documents,
            DocumentWriter::ACTION_UPSERT,
            $this->resolveBatchSize()
        );

        if ($result->hasFailures()) {
            $this->logImportFailures($collection, $result);
        }
    }

    /**
     * Project built main documents onto the public collection.
     *
     * @param array<string,array<string,mixed>> $documents
     * @param int $websiteId
     * @return array<string,array<string,mixed>>
     */
    private function project(array $documents, int $websiteId): array
    {
        $projected = [];
        foreach ($documents as $id => $document) {
            $projected[$id] = $this->publicProjector->project($document, $websiteId);
        }

        return $projected;
    }

    /**
     * Reconcile a just-swapped collection again; it is live, so a failure is logged, never thrown.
     *
     * A failed check counts as unapplied: the schema cannot be confirmed, so the rebuild must not pass as valid.
     *
     * @param string $collection
     * @param callable():Decision $reconcile
     * @return bool whether the schema change was left unapplied (or could not be checked)
     */
    private function recheckSwapped(string $collection, callable $reconcile): bool
    {
        try {
            return $this->warnIfUnapplied($collection, $reconcile());
        } catch (TypesenseException $e) {
            $this->logger->error(
                sprintf('Typesense indexer: post-swap schema check of "%s" failed — %s', $collection, $e->getMessage())
            );

            return true;
        }
    }

    /**
     * Log a pending collection whose mid-rebuild schema drift a PATCH could not apply.
     *
     * @param string $collection
     * @param Decision $decision
     * @return bool whether the decision left the change unapplied
     */
    private function warnIfUnapplied(string $collection, Decision $decision): bool
    {
        if (!$decision->needsRebuild()) {
            return false;
        }
        $this->logger->warning(
            sprintf(
                'Typesense indexer: schema of "%s" changed during the rebuild and needs another one — %s',
                $collection,
                (string)$decision->getReason()
            )
        );

        return true;
    }

    /**
     * Refresh the store's synonym set after a full rebuild; a failure never fails the reindex.
     *
     * @param int $storeId
     */
    private function syncSynonyms(int $storeId): void
    {
        try {
            $this->synonymSync->syncStore($storeId);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Typesense indexer: synonym sync failed for store %d.', $storeId),
                ['exception' => $e]
            );
        }
    }

    /**
     * Retire an alias's pending collection after `saveIndex`: drop it unless it is (or may be) live.
     *
     * A rebuild that died before the swap leaves the pending collection orphaned — no alias targets it,
     * so a later swap never retires it; dropping it reclaims its RAM. But `swap()` repoints the alias
     * *before* dropping the old collection: if that drop fails, swap() throws with the alias already
     * serving `$pending`, so it is only dropped when the alias is not on it. The entry is cleared even on
     * failure, so a later incremental save in the same process does not write into the abandoned build.
     *
     * @param string $alias
     * @param string|null $pending
     * @param bool $swapped
     */
    private function settlePending(string $alias, ?string $pending, bool $swapped): void
    {
        if ($pending === null) {
            return;
        }
        if (!$swapped && !$this->aliasTargets($alias, $pending)) {
            $this->dropPending($pending);
        }
        $this->pendingCollections->clear($alias);
    }

    /**
     * Drop an orphaned pending collection after a failed rebuild — never mask the original failure.
     *
     * Runs from the `finally` of a failing `saveIndex`, so it must not throw: a drop failure is logged
     * and swallowed so the reindex error propagates unchanged.
     *
     * @param string $collection
     */
    private function dropPending(string $collection): void
    {
        try {
            $this->collectionManager->drop($collection);
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf(
                    'Typesense indexer: could not drop orphaned collection "%s" after a failed reindex.',
                    $collection
                ),
                ['exception' => $e]
            );
        }
    }

    /**
     * Whether the alias currently resolves to the given collection.
     *
     * Runs from the `finally` of a failing `saveIndex` to decide whether the pending collection is
     * safe to drop, so it must not throw: a resolution failure returns `true` (conservative) — when we
     * cannot confirm the alias moved off `$pending`, we must never drop it.
     *
     * @param string $alias
     * @param string $collection
     */
    private function aliasTargets(string $alias, string $collection): bool
    {
        try {
            return $this->aliasManager->resolve($alias) === $collection;
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Delete the alias's buffered ids that the save did not rebuild, then drop the buffer.
     *
     * @param string $alias
     * @param string|null $collection null when the alias has no collection to delete from
     * @param array<string,true> $builtIds
     * @throws TypesenseException on any failure other than a missing collection
     */
    private function consumeStackedDeletes(string $alias, ?string $collection, array $builtIds): void
    {
        $ids = array_diff_key($this->stackedDeletes[$alias] ?? [], $builtIds);
        unset($this->stackedDeletes[$alias]);
        if ($collection !== null && $ids !== []) {
            $this->deleteIds($collection, $ids);
        }
    }

    /**
     * Delete ids in `id:[…]` filter chunks; a 404 (collection gone) is the wanted state.
     *
     * @param string $collection
     * @param array<string,string> $ids
     * @throws TypesenseException on any failure other than a missing collection
     */
    private function deleteIds(string $collection, array $ids): void
    {
        foreach (array_chunk(array_values($ids), self::DELETE_CHUNK_SIZE) as $chunk) {
            try {
                $this->documentWriter->deleteBy($collection, 'id:[' . implode(',', $chunk) . ']');
            } catch (TypesenseException $e) {
                if ($e->getStatusCode() !== 404) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Ids as numeric strings, deduplicated; anything else could break the filter syntax.
     *
     * @param \Traversable<int|string,mixed> $documents
     * @return array<string,string> id ⇒ id
     */
    private function normalizeIds(\Traversable $documents): array
    {
        $ids = [];
        foreach ($documents as $documentId) {
            if (!is_scalar($documentId)) {
                continue;
            }
            $id = (string)$documentId;
            if (preg_match('/^\d+$/', $id) === 1) {
                $ids[$id] = $id;
            }
        }

        return $ids;
    }

    /**
     * Register `Category::CACHE_TAG` for the categories the batch touches.
     *
     * A scheduled reindex must invalidate those pages instead of leaving them stale.
     *
     * @param array<string,array<string,mixed>> $documents
     */
    private function registerCategoryCacheTags(array $documents): void
    {
        $categoryIds = [];
        foreach ($documents as $document) {
            $ids = $document['category_ids'] ?? null;
            if (is_array($ids)) {
                foreach ($ids as $id) {
                    $categoryIds[] = (int)$id;
                }
            } elseif (is_numeric($ids)) {
                $categoryIds[] = (int)$ids;
            }
        }

        if ($categoryIds !== []) {
            $this->cacheContext->registerEntities(Category::CACHE_TAG, array_unique($categoryIds));
        }
    }

    /**
     * Log the rejected documents of an import — never drop them silently.
     *
     * @param string $collection
     * @param ImportResult $result
     */
    private function logImportFailures(string $collection, ImportResult $result): void
    {
        $this->logger->error(
            sprintf(
                'Typesense indexer: %d of %d documents rejected for collection "%s"%s.',
                $result->getFailureCount(),
                $result->getTotalCount(),
                $collection,
                $result->areErrorsTruncated() ? ' (error sample truncated)' : ''
            ),
            ['errors' => $result->getErrors()]
        );
    }

    /**
     * The live collection an alias targets, or a hard error when neither a build nor a live one exists.
     *
     * @param string $alias
     * @throws LocalizedException when the scope has no collection to write into
     * @throws TypesenseException
     */
    private function resolveLiveCollection(string $alias): string
    {
        $target = $this->aliasManager->resolve($alias);
        if ($target === null) {
            throw new LocalizedException(
                new Phrase(
                    'No Typesense collection is available for alias "%1"; run a full reindex first.',
                    [$alias]
                )
            );
        }

        return $target;
    }

    /**
     * The live public collection, or null (with a warning) when the alias is unset.
     *
     * A store indexed before the public collection existed has no public alias until its next full
     * reindex; the main write must still go through.
     *
     * @param string $alias
     * @throws TypesenseException
     */
    private function resolvePublicCollection(string $alias): ?string
    {
        $target = $this->aliasManager->resolve($alias);
        if ($target === null) {
            $this->logger->warning(
                sprintf(
                    'Typesense indexer: public alias "%s" is unset, skipping its write; run a full reindex.',
                    $alias
                )
            );
        }

        return $target;
    }

    /**
     * Batch size from deployment config, falling back to the injected default.
     *
     * Read once per instance and memoized — the value is static for the handler's lifetime, so
     * re-reading deployment config on every batch would be wasted work.
     */
    private function resolveBatchSize(): int
    {
        if ($this->resolvedBatchSize !== null) {
            return $this->resolvedBatchSize;
        }

        $configured = $this->deploymentConfig->get(
            self::DEPLOYMENT_CONFIG_INDEXER_BATCHES . $this->getIndexerId() . '/' . self::BATCH_SIZE_KEY
        );

        // A configured 0/blank would make Batch and DocumentWriter (which rejects batchSize < 1) misbehave.
        return $this->resolvedBatchSize = $configured !== null ? max(1, (int)$configured) : $this->batchSize;
    }

    /**
     * The indexer id handed to the handler factory.
     */
    private function getIndexerId(): string
    {
        return (string)($this->data['indexer_id'] ?? Fulltext::INDEXER_ID);
    }
}
