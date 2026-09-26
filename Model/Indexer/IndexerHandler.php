<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\AliasManager;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Client\HealthChecker;
use DmLab\TypesenseCore\Model\Document\DocumentWriter;
use DmLab\TypesenseCore\Model\Document\ImportResult;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use Magento\Catalog\Model\Category;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\CatalogSearch\Model\Indexer\Fulltext\Processor;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Indexer\CacheContext;
use Magento\Framework\Indexer\IndexStructureInterface;
use Magento\Framework\Indexer\SaveHandler\Batch;
use Magento\Framework\Indexer\SaveHandler\IndexerInterface;
use Magento\Framework\Phrase;
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
 * Batch size comes from deployment config `indexer/batch_size/catalogsearch_fulltext/typesense_save`
 * (the ES convention, `elastic_save` → `typesense_save`), and the stream is chunked with Magento's
 * {@see Batch} so document building stays bounded regardless of catalog size.
 *
 * **`StackedActionsIndexerInterface` is deliberately not implemented.** That contract batches an
 * adapter's queued write queries (ES stacks bulk requests); core's {@see DocumentWriter} already
 * imports in bounded JSONL batches over one HTTP call each and exposes no query-stacking mode, so
 * there is nothing to stack — implementing it would only add empty enable/trigger hooks.
 *
 * @api
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IndexerHandler implements IndexerInterface
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
     * Memoized batch size — deployment config is read once per instance, not once per batch.
     *
     * @var int|null
     */
    private ?int $resolvedBatchSize = null;

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
     * @param array<string,mixed> $data carries `indexer_id` from the handler factory
     * @param int $batchSize fallback batch size when deployment config is unset
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
        private readonly array $data = [],
        private readonly int $batchSize = DocumentWriter::DEFAULT_BATCH_SIZE
    ) {
    }

    /**
     * Build the FAT documents for the scope, write them in batches, then swap the alias last.
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @param \Traversable<int|string,mixed> $documents id ⇒ raw index data
     * @return $this
     * @throws LocalizedException when no scope dimension is present or no collection is available
     * @throws TypesenseException on a transport or HTTP failure
     */
    public function saveIndex($dimensions, \Traversable $documents)
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName($this->getIndexerId(), $storeId);

        $pending = $this->pendingCollections->get($alias);
        $target = $pending ?? $this->resolveLiveCollection($alias);

        $scheduled = $this->processor->getIndexer()->isScheduled();

        $swapped = false;
        try {
            foreach ($this->batch->getItems($documents, $this->resolveBatchSize()) as $chunk) {
                $built = $this->documentBuilder->build(new \ArrayIterator($chunk), $storeId);
                if ($built === []) {
                    continue;
                }

                $this->writeBatch($target, $built);

                if ($scheduled) {
                    $this->registerCategoryCacheTags($built);
                }
            }

            // The alias moves only for a full rebuild; an incremental save wrote into the live collection.
            if ($pending !== null) {
                $this->aliasManager->swap($alias, $pending);
                $swapped = true;
            }
        } finally {
            if ($pending !== null) {
                // A rebuild that died before the swap leaves the pending collection orphaned — no alias
                // targets it, so a later swap never retires it. Drop it (best-effort) to reclaim its RAM.
                // But `swap()` repoints the alias *before* dropping the old collection: if that drop fails
                // (a transient non-404), swap() throws with the alias already serving `$pending`. Dropping
                // it then would destroy the now-live index — so only drop when the alias is not on it.
                if (!$swapped && !$this->aliasTargets($alias, $pending)) {
                    $this->dropPending($pending);
                }
                // Clear the entry even on failure, so a later incremental save in the same process does
                // not write into the abandoned build and swap the alias onto it.
                $this->pendingCollections->clear($alias);
            }
        }

        return $this;
    }

    /**
     * Remove documents by id from the scope's live collection; a 404 per id is the wanted state.
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @param \Traversable<int|string,mixed> $documents ids to remove
     * @return $this
     * @throws LocalizedException when no scope dimension is present
     * @throws TypesenseException on any failure other than a missing document
     */
    public function deleteIndex($dimensions, \Traversable $documents)
    {
        $storeId = $this->storeScopeResolver->resolve($dimensions);
        $alias = $this->nameResolver->getAliasName($this->getIndexerId(), $storeId);

        $target = $this->aliasManager->resolve($alias);
        if ($target === null) {
            return $this;
        }

        foreach ($documents as $documentId) {
            if ($documentId === null || $documentId === '') {
                continue;
            }
            $this->deleteOne($target, (string)$documentId);
        }

        return $this;
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
     * Delete one document, treating a 404 as the desired end state.
     *
     * @param string $collection
     * @param string $documentId
     * @throws TypesenseException on any failure other than a missing document
     */
    private function deleteOne(string $collection, string $documentId): void
    {
        try {
            $this->documentWriter->delete($collection, $documentId);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                throw $e;
            }
        }
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
