<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\Search;

use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\FulltextInvalidationSuppressor;
use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Search\Api\Data\SynonymGroupInterface;
use Magento\Search\Model\SynonymGroupRepository;
use Psr\Log\LoggerInterface;

/**
 * On Typesense, a synonym-group save or delete resyncs every store's synonym set in place instead of
 * invalidating the fulltext index.
 *
 * The native (ES-module) synonym plugin invalidates on every engine, so the repository call runs under
 * {@see FulltextInvalidationSuppressor}. A Typesense failure is logged, never surfaced (the admin save
 * must not break), and falls back to the invalidation, since a full rebuild resyncs every set.
 */
class SynonymGroupRepositoryPlugin
{
    /**
     * @param SynonymSync $synonymSync
     * @param EngineResolverInterface $engineResolver
     * @param LoggerInterface $logger
     * @param IndexerRegistry $indexerRegistry
     * @param ReindexInvalidationMarker $invalidationMarker keeps the invalidation past a running full reindex
     * @param FulltextInvalidationSuppressor $suppressor
     */
    public function __construct(
        private readonly SynonymSync $synonymSync,
        private readonly EngineResolverInterface $engineResolver,
        private readonly LoggerInterface $logger,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly ReindexInvalidationMarker $invalidationMarker,
        private readonly FulltextInvalidationSuppressor $suppressor
    ) {
    }

    /**
     * Save without invalidating, then resync.
     *
     * @param SynonymGroupRepository $subject
     * @param callable $proceed
     * @param SynonymGroupInterface $synonymGroup
     * @param bool $errorOnMergeConflict
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSave(
        SynonymGroupRepository $subject,
        callable $proceed,
        SynonymGroupInterface $synonymGroup,
        $errorOnMergeConflict = false
    ): mixed {
        return $this->inPlace(static fn () => $proceed($synonymGroup, $errorOnMergeConflict));
    }

    /**
     * Delete without invalidating, then resync.
     *
     * @param SynonymGroupRepository $subject
     * @param callable $proceed
     * @param SynonymGroupInterface $synonymGroup
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDelete(
        SynonymGroupRepository $subject,
        callable $proceed,
        SynonymGroupInterface $synonymGroup
    ): mixed {
        return $this->inPlace(static fn () => $proceed($synonymGroup));
    }

    /**
     * Run the repository operation with invalidation suppressed and resync after it, on Typesense only.
     *
     * The resync also runs when the operation throws: a merge may already have deleted groups.
     *
     * @param callable $operation
     * @return mixed
     */
    private function inPlace(callable $operation): mixed
    {
        if ($this->engineResolver->getCurrentSearchEngine() !== EngineCode::ENGINE) {
            return $operation();
        }

        try {
            return $this->suppressor->run($operation);
        } finally {
            $this->sync();
        }
    }

    /**
     * Resync all stores; invalidate the index if any store failed.
     */
    private function sync(): void
    {
        try {
            $synced = $this->synonymSync->syncAllStores();
        } catch (\Exception $e) {
            $this->logger->error('Typesense indexer: synonym sync failed.', ['exception' => $e]);
            $synced = false;
        }

        if (!$synced) {
            $this->invalidationMarker->mark();
            $this->indexerRegistry->get(Fulltext::INDEXER_ID)->invalidate();
        }
    }
}
