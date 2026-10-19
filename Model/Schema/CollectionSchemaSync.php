<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseCore\Model\Schema\Decision;
use DmLab\TypesenseCore\Model\Schema\ReconcilePolicy;
use DmLab\TypesenseCore\Model\Schema\Reconciler;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use Magento\Framework\Lock\LockManagerInterface;

/**
 * Reconciles an existing main or public collection toward the store's current desired schema.
 *
 * Desired-schema build through PATCH runs under a per-collection lock, so an attribute save and a
 * post-swap recheck cannot interleave and leave the older flag set applied last. The holder rebuilds the
 * desired set after its PATCH and goes again if it moved, so a save whose own reconcile timed out on the
 * lock is still applied. A set still moving after {@see self::MAX_PASSES} is reported as needing a rebuild.
 */
class CollectionSchemaSync
{
    /**
     * Lock name prefix; the collection name completes it.
     */
    private const LOCK_PREFIX = 'dmlab_typesense_schema_';

    /**
     * Seconds to wait for a concurrent reconcile of the same collection.
     */
    private const LOCK_TIMEOUT = 30;

    /**
     * Reconcile passes per lock hold before giving up on a desired set that keeps changing.
     */
    private const MAX_PASSES = 5;

    /**
     * @param Reconciler $reconciler core's in-place-or-rebuild schema decision, reading through `$collectionManager`
     * @param SchemaSnapshotCollectionManager $collectionManager pins one schema read for both the regex
     *        instances and the reconciler's diff, so a field materialized in between is never dropped
     * @param DesiredSchemaBuilder $schemaBuilder the per-store main `FieldSpec[]`
     * @param PublicSchemaBuilder $publicSchemaBuilder the per-store public `FieldSpec[]`
     * @param LockManagerInterface $lockManager serializes reconciles of one collection across processes
     */
    public function __construct(
        private readonly Reconciler $reconciler,
        private readonly SchemaSnapshotCollectionManager $collectionManager,
        private readonly DesiredSchemaBuilder $schemaBuilder,
        private readonly PublicSchemaBuilder $publicSchemaBuilder,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Reconcile a main collection toward the store's desired schema.
     *
     * @param string $collection
     * @param int $storeId
     * @param ReconcilePolicy $policy
     * @throws TypesenseException
     */
    public function reconcileMain(string $collection, int $storeId, ReconcilePolicy $policy): Decision
    {
        return $this->reconcile($collection, fn (): array => $this->schemaBuilder->build($storeId), $policy);
    }

    /**
     * Reconcile a public collection toward the store's public schema.
     *
     * @param string $collection
     * @param int $storeId
     * @param ReconcilePolicy $policy
     * @throws TypesenseException
     */
    public function reconcilePublic(string $collection, int $storeId, ReconcilePolicy $policy): Decision
    {
        return $this->reconcile(
            $collection,
            fn (): array => $this->publicSchemaBuilder->build($storeId),
            $policy
        );
    }

    /**
     * Reconcile a collection toward a desired field set, keeping its materialized regex instances.
     *
     * @param string $collection
     * @param callable():FieldSpec[] $desired built under the lock, so it reflects every committed save before it
     * @param ReconcilePolicy $policy
     * @throws TypesenseException also when the lock is not acquired in time
     */
    private function reconcile(string $collection, callable $desired, ReconcilePolicy $policy): Decision
    {
        $lock = self::LOCK_PREFIX . $collection;
        if (!$this->lockManager->lock($lock, self::LOCK_TIMEOUT)) {
            throw new TypesenseException(sprintf('Timed out waiting for the schema lock on "%s".', $collection));
        }
        try {
            $fields = $desired();
            for ($pass = 1;; $pass++) {
                $decision = $this->reconcileFields($collection, $fields, $policy);
                $current = $desired();
                if ($current == $fields || $decision->needsRebuild()) {
                    return $decision;
                }
                if ($pass >= self::MAX_PASSES) {
                    return Decision::rebuild(
                        $decision->getDiff(),
                        sprintf('desired schema still changing after %d reconcile passes', self::MAX_PASSES)
                    );
                }
                $fields = $current;
            }
        } finally {
            $this->lockManager->unlock($lock);
        }
    }

    /**
     * One reconcile pass against a pinned schema snapshot.
     *
     * @param string $collection
     * @param FieldSpec[] $fields
     * @param ReconcilePolicy $policy
     * @throws TypesenseException
     */
    private function reconcileFields(string $collection, array $fields, ReconcilePolicy $policy): Decision
    {
        $schema = $this->collectionManager->get($collection);
        $this->collectionManager->pin($collection, $schema);
        try {
            return $this->reconciler->reconcile($collection, $this->withRegexInstances($fields, $schema), $policy);
        } finally {
            $this->collectionManager->unpin($collection);
        }
    }

    /**
     * Add the concrete fields Typesense materialized from desired regex fields, so the PATCH keeps them.
     *
     * The engine lists every key a regex field matched (`price_0_1` under `price_[0-9]+_.*`) as its own
     * schema field; left out of the desired set, the reconcile would drop it and break facet/sort on it.
     *
     * @param FieldSpec[] $desired
     * @param array<mixed> $schema the `GET /collections/:name` response
     * @return FieldSpec[]
     */
    private function withRegexInstances(array $desired, array $schema): array
    {
        $declared = [];
        $patterns = [];
        foreach ($desired as $spec) {
            $declared[$spec->getName()] = true;
            if (str_contains($spec->getName(), '.*')) {
                $patterns[] = $spec;
            }
        }

        $fields = $schema['fields'] ?? [];
        if ($patterns === [] || !is_array($fields)) {
            return $desired;
        }

        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : null;
            if (!is_string($name) || isset($declared[$name])) {
                continue;
            }
            foreach ($patterns as $pattern) {
                if (preg_match('~^(?:' . $pattern->getName() . ')$~', $name)) {
                    $desired[] = $pattern->withOverridesFrom(new FieldSpec($name, ''));
                    break;
                }
            }
        }

        return $desired;
    }
}
