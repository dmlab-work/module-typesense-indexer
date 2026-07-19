<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Document;

use MageDevGroup\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;

/**
 * Turns the indexer's `\Traversable` of raw index data into Typesense documents.
 *
 * The document **id comes from the Traversable key** — `Magento\Framework\Indexer\SaveHandler\Batch`
 * yields `$id => $data`, so the id is never read from a field. Each document is seeded with its
 * `id` and `store_id`, enriched by the di-merged {@see DocumentDataProviderInterface} chain (the
 * FAT rule — every attribute value, regardless of flags), then declared fields are cast to the
 * type the schema policy assigns them so a value matches the field it is indexed into. Undeclared
 * keys are left untouched: Typesense stores them on disk as-is, which is what makes a later flag
 * change a pure schema PATCH.
 */
class DocumentBuilder
{
    /**
     * Per-store field name ⇒ type map, memoized for the life of the request. The desired schema is
     * constant for a store during a reindex run, so the field providers' DB queries must not re-run
     * for every batch.
     *
     * @var array<int,array<string,string>>
     */
    private array $typesByStore = [];

    /**
     * @param DocumentDataProviderInterface $dataProvider the di-merged {@see CompositeDocumentDataProvider}
     * @param DesiredSchemaBuilder $schemaBuilder source of the per-store field types used for casting
     */
    public function __construct(
        private readonly DocumentDataProviderInterface $dataProvider,
        private readonly DesiredSchemaBuilder $schemaBuilder
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
     * Cast every declared field to its schema type; re-stamp the id so a provider cannot clobber it.
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
        $types = $this->types($storeId);

        foreach ($documents as $id => $document) {
            foreach ($document as $name => $value) {
                if ($name === 'id') {
                    continue;
                }
                if ($value === null) {
                    unset($documents[$id][$name]);
                    continue;
                }
                if (!isset($types[$name])) {
                    continue;
                }
                $documents[$id][$name] = $this->castValue($value, $types[$name]);
            }
            $documents[$id]['id'] = (string)$id;
        }

        return $documents;
    }

    /**
     * The memoized field name ⇒ type map for the store view.
     *
     * @param int $storeId
     * @return array<string,string>
     */
    private function types(int $storeId): array
    {
        if (!isset($this->typesByStore[$storeId])) {
            $types = [];
            foreach ($this->schemaBuilder->build($storeId) as $spec) {
                $types[$spec->getName()] = $spec->getType();
            }
            $this->typesByStore[$storeId] = $types;
        }

        return $this->typesByStore[$storeId];
    }

    /**
     * Coerce a value to a Typesense field type; array types wrap a scalar into a one-element list.
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     */
    private function castValue(mixed $value, string $type): mixed
    {
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
     * multiselect attribute (the only array-typed EAV field) arrives from the product collection
     * as a comma-joined option-id string — `"12,15,33"` must become three facet values, not one.
     *
     * @param mixed $value
     * @return array<int,mixed>
     */
    private function toList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        return is_string($value) ? explode(',', $value) : [$value];
    }
}
