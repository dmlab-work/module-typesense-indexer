<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use Magento\Framework\FlagManager;

/**
 * Durable record of a fulltext invalidation, so one raised mid-`reindexAll()` survives the run marking itself valid.
 */
class ReindexInvalidationMarker
{
    private const FLAG = 'dmlab_typesense_indexer_invalidated_at';

    /**
     * @param FlagManager $flagManager
     */
    public function __construct(
        private readonly FlagManager $flagManager
    ) {
    }

    /**
     * Record that the fulltext index was invalidated now.
     */
    public function mark(): void
    {
        $this->flagManager->saveFlag(self::FLAG, time());
    }

    /**
     * Whether an invalidation was recorded at or after `$since`; consumes the record when it was.
     *
     * @param int $since unix time a full reindex started
     * @return bool
     */
    public function consumeSince(int $since): bool
    {
        $markedAt = $this->flagManager->getFlagData(self::FLAG);
        if (!is_int($markedAt) || $markedAt < $since) {
            return false;
        }

        $this->flagManager->deleteFlag(self::FLAG);

        return true;
    }
}
