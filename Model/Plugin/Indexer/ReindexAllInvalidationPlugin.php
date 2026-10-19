<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\Indexer;

use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerInterface;

/**
 * Re-invalidates the fulltext index after a full reindex during which an attribute save invalidated it.
 *
 * `reindexAll()` marks the indexer valid unconditionally, so a store already rebuilt earlier in the run
 * would otherwise keep a schema change that needed a rebuild.
 */
class ReindexAllInvalidationPlugin
{
    /**
     * @param ReindexInvalidationMarker $marker
     */
    public function __construct(
        private readonly ReindexInvalidationMarker $marker
    ) {
    }

    /**
     * Run the full reindex, then restore an invalidation recorded while it ran.
     *
     * @param IndexerInterface $subject
     * @param callable $proceed
     * @return void
     */
    public function aroundReindexAll(IndexerInterface $subject, callable $proceed): void
    {
        if ($subject->getId() !== Fulltext::INDEXER_ID) {
            $proceed();

            return;
        }

        $startedAt = time();
        $proceed();

        if ($this->marker->consumeSince($startedAt)) {
            $subject->invalidate();
        }
    }
}
