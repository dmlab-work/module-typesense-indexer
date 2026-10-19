<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

/**
 * An attribute-derived memo that lives for one index run: {@see IndexerHandler} resets it at the start
 * of every `saveIndex`, so a long-running consumer picks up attributes and options created since.
 */
interface RunScopedCacheInterface
{
    /**
     * Drop the memoized data; the next read loads it fresh.
     *
     * @return void
     */
    public function reset(): void;
}
