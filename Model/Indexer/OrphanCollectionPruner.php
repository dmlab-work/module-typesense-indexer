<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\CollectionManager;
use DmLab\TypesenseCore\Model\Collection\OrphanFinder;
use Psr\Log\LoggerInterface;

/**
 * Drops versioned collections of an alias left behind by killed rebuilds (OOM, SIGKILL).
 *
 * Only versions older than `$current` go: a newer one may be another process's pending build.
 * Each drop runs under the alias lock and skips an aliased collection, so a concurrent swap is safe.
 * Best-effort — failures are logged, never thrown.
 */
class OrphanCollectionPruner
{
    /**
     * @param CollectionManager $collectionManager lists collections
     * @param OrphanFinder $orphanFinder drops one collection unless an alias points at it
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CollectionManager $collectionManager,
        private readonly OrphanFinder $orphanFinder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Drop every `<alias>_<ts>_<hex>` collection older than `$current`; an empty `$current` drops them all.
     *
     * @param string $alias
     * @param string $current the live collection, or `''`
     */
    public function prune(string $alias, string $current): void
    {
        $pattern = '/^' . preg_quote($alias, '/') . '_(\d+)_([0-9a-f]+)$/';
        $currentTs = null;
        if ($current !== '') {
            if (!preg_match($pattern, $current, $match)) {
                return;
            }
            $currentTs = (int)$match[1];
        }

        try {
            $names = $this->collectionManager->getList();
        } catch (\Exception $e) {
            $this->logger->error(
                sprintf('Typesense indexer: could not list collections to prune "%s".', $alias),
                ['exception' => $e]
            );

            return;
        }

        foreach ($names as $name) {
            if ($name === $current || !preg_match($pattern, $name, $match)) {
                continue;
            }
            if ($currentTs !== null && (int)$match[1] >= $currentTs) {
                continue;
            }
            $this->drop($name);
        }
    }

    /**
     * Drop one orphan; a 404 means another process already did.
     *
     * @param string $name
     */
    private function drop(string $name): void
    {
        try {
            $this->orphanFinder->drop($name);
        } catch (TypesenseException $e) {
            if ($e->getStatusCode() !== 404) {
                $this->logDropFailure($name, $e);
            }
        } catch (\Exception $e) {
            $this->logDropFailure($name, $e);
        }
    }

    /**
     * Log a failed orphan drop.
     *
     * @param string $name
     * @param \Exception $e
     */
    private function logDropFailure(string $name, \Exception $e): void
    {
        $this->logger->error(
            sprintf('Typesense indexer: could not drop orphaned collection "%s".', $name),
            ['exception' => $e]
        );
    }
}
