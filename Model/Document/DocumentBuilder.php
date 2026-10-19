<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document;

use DmLab\TypesenseIndexer\Model\Schema\AttributeTypeMap;

/**
 * Turns the indexer's `\Traversable` of raw index data into Typesense documents.
 *
 * The document **id comes from the Traversable key** — `Magento\Framework\Indexer\SaveHandler\Batch`
 * yields `$id => $data`, so the id is never read from a field. Each document is seeded with its
 * `id` and `store_id`, enriched by the di-merged {@see DocumentDataProviderInterface} chain (the
 * FAT rule — every attribute value, regardless of flags), then every attribute value and declared
 * field is cast to its {@see AttributeTypeMap} type (exact or via a regex spec), declared or not:
 * Typesense stores undeclared keys on disk as-is, so they must already be typed for a later flag
 * change to be a pure schema PATCH. Keys that are neither attributes nor declared are left untouched.
 */
class DocumentBuilder
{
    /**
     * Types a list value collapses to its first entry for; structured types (`geopoint`, `object`, `auto`) keep it.
     */
    private const SCALAR_TYPES = ['int32', 'int64', 'float', 'bool', 'string'];

    /**
     * @param DocumentDataProviderInterface $dataProvider the di-merged {@see CompositeDocumentDataProvider}
     * @param AttributeTypeMap $typeMap source of the per-store field types used for casting
     */
    public function __construct(
        private readonly DocumentDataProviderInterface $dataProvider,
        private readonly AttributeTypeMap $typeMap
    ) {
    }

    /**
     * Build the Typesense documents for a store view from the indexer's stream.
     *
     * @param \Traversable<int|string,mixed> $documents id ⇒ raw index data
     * @param int $storeId
     * @return array<string,array<string,mixed>> built documents, keyed by id
     */
    public function build(\Traversable $documents, int $storeId): array
    {
        $seed = [];
        foreach ($documents as $id => $raw) {
            $seed[(string)$id] = ['id' => (string)$id, 'store_id' => $storeId];
        }

        $built = $this->dataProvider->addData($seed, $storeId);

        return $this->cast($built, $storeId);
    }

    /**
     * Cast every typed field (attribute or declared) to its type; re-stamp the id so a provider cannot clobber it.
     *
     * A `null` value is a missing value, not a value: the key is dropped so an optional declared
     * field is *absent* rather than coerced (`null → 0`/`""`). Casting a null would pin every
     * product lacking a sparse filterable/sortable attribute into a bogus `0`/`""` facet bucket —
     * mirrors Magento's ES mapper, which writes a field only when the value is not null.
     *
     * @param array<string,array<string,mixed>> $documents
     * @param int $storeId
     * @return array<string,array<string,mixed>>
     */
    private function cast(array $documents, int $storeId): array
    {
        foreach ($documents as $id => $document) {
            foreach ($document as $name => $value) {
                if ($name === 'id') {
                    continue;
                }
                if ($value === null) {
                    unset($documents[$id][$name]);
                    continue;
                }
                $type = $this->typeMap->resolve($storeId, (string)$name);
                if ($type === null) {
                    continue;
                }
                $cast = $this->castValue($value, $type);
                if ($cast === null) {
                    unset($documents[$id][$name]);
                    continue;
                }
                $documents[$id][$name] = $cast;
            }
            $documents[$id]['id'] = (string)$id;
        }

        return $documents;
    }

    /**
     * Coerce a value to a Typesense field type; array types wrap a scalar into a one-element list.
     *
     * Returns `null` for an empty list cast to a scalar type (the caller drops the key).
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private function castValue(mixed $value, string $type): mixed
    {
        if (is_array($value) && in_array($type, self::SCALAR_TYPES, true)) {
            // A select stored as an option list (e.g. `visibility`) declared scalar: keep its one value.
            $value = $this->toList($value)[0] ?? null;
            if ($value === null) {
                return null;
            }
        }

        return match ($type) {
            'int32', 'int64' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            'string' => (string)$value,
            'int32[]', 'int64[]' => array_map(static fn ($v): int => (int)$v, $this->toList($value)),
            'float[]' => array_map(static fn ($v): float => (float)$v, $this->toList($value)),
            'string[]' => array_map(static fn ($v): string => (string)$v, $this->toList($value)),
            default => $value,
        };
    }

    /**
     * A value as a list. An array is taken verbatim; a scalar string is split on `,` because a
     * multiselect or option attribute arrives from the product collection
     * as a comma-joined option-id string — `"12,15,33"` must become three facet values, not one.
     * Empty (`''`/`null`) entries are dropped so an empty option list never yields a bogus `0`.
     *
     * @param mixed $value
     * @return array<int,mixed>
     */
    private function toList(mixed $value): array
    {
        $list = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : [$value]);

        return array_values(array_filter($list, static fn ($v): bool => $v !== '' && $v !== null));
    }
}
