<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Schema;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;

/**
 * Computes the desired collection schema for a store view: `base + Σ(providers)`.
 *
 * Because the schema is computed rather than declared, two providers cannot silently
 * collide: when they contribute the same field name, the later one's properties are
 * layered onto the earlier's via {@see FieldSpec::withOverridesFrom()} (nested `extra`
 * merges, so a vector field's `hnsw_params` are not clobbered). The result is sorted by
 * field name so the schema handed to the reconciler is deterministic regardless of the
 * providers' registration order — a stable diff is what keeps a PATCH minimal.
 */
class DesiredSchemaBuilder
{
    /**
     * @param FieldProviderInterface $fieldProvider the di-merged {@see CompositeFieldProvider}
     */
    public function __construct(
        private readonly FieldProviderInterface $fieldProvider
    ) {
    }

    /**
     * The deduplicated, deterministically ordered schema for the store view.
     *
     * @param int $storeId
     * @return FieldSpec[]
     */
    public function build(int $storeId): array
    {
        /** @var array<string,FieldSpec> $byName */
        $byName = [];
        foreach ($this->fieldProvider->getFields($storeId) as $field) {
            $name = $field->getName();
            $byName[$name] = isset($byName[$name])
                ? $byName[$name]->withOverridesFrom($field)
                : $field;
        }

        ksort($byName);

        return array_values($byName);
    }
}
