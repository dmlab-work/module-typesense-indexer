<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Indexer;

/**
 * Hands the freshly created physical collection from {@see IndexStructure} to the indexer handler.
 *
 * Magento drives a reindex as `cleanIndex` → `saveIndex`: `cleanIndex` calls
 * {@see IndexStructure::create()}, which stands up a new physical collection but does **not** yet
 * swap the alias onto it (the swap is the last line of `saveIndex`). Until that swap the new
 * collection is addressable by nobody but its creator, so the handler cannot rediscover it by
 * resolving the alias. This shared, request-scoped registry carries the name across: `create()`
 * records `alias => physical`, `saveIndex` reads it to know where to write and what to swap to.
 *
 * A single DI instance is shared, so both sides see the same map within a reindex run.
 *
 * @api
 */
class PendingCollectionRegistry
{
    /**
     * @var array<string,string> alias => physical collection awaiting the alias swap
     */
    private array $pending = [];

    /**
     * Record the physical collection a fresh build wrote behind an alias.
     *
     * @param string $alias
     * @param string $collection
     */
    public function set(string $alias, string $collection): void
    {
        $this->pending[$alias] = $collection;
    }

    /**
     * The physical collection a build created for an alias, or null when none is in flight.
     *
     * @param string $alias
     */
    public function get(string $alias): ?string
    {
        return $this->pending[$alias] ?? null;
    }

    /**
     * Forget an alias's pending collection once the build has been swapped in.
     *
     * @param string $alias
     */
    public function clear(string $alias): void
    {
        unset($this->pending[$alias]);
    }
}
