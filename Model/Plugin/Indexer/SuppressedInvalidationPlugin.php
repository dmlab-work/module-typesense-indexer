<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\Indexer;

use DmLab\TypesenseIndexer\Model\Indexer\FulltextInvalidationSuppressor;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerInterface;

/**
 * Drops a `catalogsearch_fulltext` invalidation while {@see FulltextInvalidationSuppressor} is active.
 */
class SuppressedInvalidationPlugin
{
    /**
     * @param FulltextInvalidationSuppressor $suppressor
     */
    public function __construct(
        private readonly FulltextInvalidationSuppressor $suppressor
    ) {
    }

    /**
     * Skip the invalidation when suppressed; other indexers are never affected.
     *
     * @param IndexerInterface $subject
     * @param callable $proceed
     * @return void
     */
    public function aroundInvalidate(IndexerInterface $subject, callable $proceed): void
    {
        if ($this->suppressor->isActive() && $subject->getId() === Fulltext::INDEXER_ID) {
            return;
        }

        $proceed();
    }
}
