<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

/**
 * Request-scoped switch that drops `catalogsearch_fulltext` invalidations raised inside {@see run()}.
 *
 * For changes already applied to Typesense in place, where a native plugin would still invalidate.
 */
class FulltextInvalidationSuppressor
{
    /**
     * Nesting depth of {@see run()} calls.
     *
     * @var int
     */
    private int $depth = 0;

    /**
     * Run `$operation` with fulltext invalidation suppressed.
     *
     * @param callable $operation
     * @return mixed what `$operation` returns
     */
    public function run(callable $operation): mixed
    {
        $this->depth++;
        try {
            return $operation();
        } finally {
            $this->depth--;
        }
    }

    /**
     * Whether a fulltext invalidation should be dropped right now.
     */
    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
