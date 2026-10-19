<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Eav\Model\Entity\Attribute\Source\Table;
use Magento\Framework\Model\AbstractModel;

/**
 * The attribute → schema policy: maps one EAV product attribute to a core `FieldSpec`.
 *
 * This is the THIN half of the FAT-documents / THIN-schema rule — the document carries
 * every attribute value regardless of flags, but the schema declares only what a flag
 * requires:
 *   - `is_searchable`                          → `index:true` (option attributes: only when filterable;
 *                                                their searchable part is the `<code>_value` label field)
 *   - `is_filterable` / `is_filterable_in_search` → `facet:true`
 *   - `used_for_sort_by`                       → `sort:true`
 *   - `backend_type` / `frontend_input`        → Typesense field type
 *
 * An undeclared field leaves every flag `null`; a declared one carries explicit `facet`/`sort` booleans.
 * Every attribute-derived field is `optional:true` because catalogs are sparse.
 *
 * `search_weight` deliberately never reaches a `FieldSpec` — Typesense has no per-field weight;
 * it is a query-time parameter surfaced by `SearchableFieldsProviderInterface` (Task 4).
 */
class AttributeFieldPolicy
{
    /**
     * The attribute flags whose change alters the desired schema (or the searchable seam): a save
     * that touches one of these is worth reconciling, a save that touches none (label, default
     * value, sort order, …) leaves the schema identical. Note `used_for_sort_by` and
     * `is_filterable_in_search` — native's own gate omits both, but they map to `sort`/`facet` here.
     */
    public const SCHEMA_FLAGS = [
        'is_searchable',
        'is_filterable',
        'is_filterable_in_search',
        'used_for_sort_by',
    ];

    /**
     * The flags whose change requires the cached search-request config to be reset — native's own set
     * (`CatalogSearch\...\Plugin\Attribute`). `is_searchable`/`is_filterable` also alter the schema (see
     * {@see SCHEMA_FLAGS}); `is_visible_in_advanced_search` has no schema effect but still stales the
     * advanced-search request config, so it is a config refresh without a reconcile.
     */
    public const SEARCH_CONFIG_FLAGS = [
        'is_searchable',
        'is_filterable',
        'is_visible_in_advanced_search',
    ];

    /**
     * The flags that put an attribute on the storefront; only such option attributes get public labels.
     */
    public const STOREFRONT_FLAGS = [
        'is_searchable',
        'is_filterable',
        'is_filterable_in_search',
        'is_visible_on_front',
        'used_in_product_listing',
        'used_for_sort_by',
    ];

    /**
     * @param FieldNameResolver $fieldNameResolver
     */
    public function __construct(
        private readonly FieldNameResolver $fieldNameResolver
    ) {
    }

    /**
     * Whether this save changed a flag that alters the desired schema.
     *
     * Lets the attribute-save plugin skip the per-store reconcile round-trips for a save that
     * touched no schema-relevant flag — the native plugin gates its invalidation the same way.
     *
     * @param AbstractModel $attribute the attribute being saved (carries its dirty-field state)
     */
    public function schemaFlagsChanged(AbstractModel $attribute): bool
    {
        foreach (self::SCHEMA_FLAGS as $flag) {
            if ($attribute->dataHasChangedFor($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any schema-relevant flag is set — the gate for a new attribute, whose flags all read as changed.
     *
     * @param AbstractModel $attribute the attribute being saved
     */
    public function schemaFlagsSet(AbstractModel $attribute): bool
    {
        foreach (self::SCHEMA_FLAGS as $flag) {
            if ($attribute->getData($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this save changed a flag that stales the cached search-request config.
     *
     * Mirrors native's reset gate so `is_visible_in_advanced_search` — which has no schema effect and so
     * is absent from {@see SCHEMA_FLAGS} — still triggers a config refresh under the Typesense flow.
     *
     * @param AbstractModel $attribute the attribute being saved (carries its dirty-field state)
     */
    public function searchConfigChanged(AbstractModel $attribute): bool
    {
        foreach (self::SEARCH_CONFIG_FLAGS as $flag) {
            if ($attribute->dataHasChangedFor($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the attribute is exposed on the storefront (any {@see STOREFRONT_FLAGS} set).
     *
     * @param Attribute $attribute
     */
    public function isStorefrontExposed(Attribute $attribute): bool
    {
        return (bool)$attribute->getIsSearchable()
            || (bool)$attribute->getIsFilterable()
            || (bool)$attribute->getIsFilterableInSearch()
            || (bool)$attribute->getIsVisibleOnFront()
            || (bool)$attribute->getUsedInProductListing()
            || (bool)$attribute->getUsedForSortBy();
    }

    /**
     * Whether this save turns a non-exposed option attribute into a storefront-exposed one.
     *
     * Its labels are then missing from the public documents, which only a reindex adds.
     *
     * @param AbstractModel $attribute the attribute being saved (carries its original data)
     */
    public function optionLabelsBecameExposed(AbstractModel $attribute): bool
    {
        if (!$attribute instanceof Attribute || !$this->isOptionAttribute($attribute)) {
            return false;
        }

        $was = false;
        $is = false;
        foreach (self::STOREFRONT_FLAGS as $flag) {
            $was = $was || (bool)$attribute->getOrigData($flag);
            $is = $is || (bool)$attribute->getData($flag);
        }

        return !$was && $is;
    }

    /**
     * The `FieldSpec` this attribute contributes to the schema.
     *
     * @param Attribute $attribute
     * @param array<string,mixed> $context passed to the name resolver (price/position scope)
     */
    public function toFieldSpec(Attribute $attribute, array $context = []): FieldSpec
    {
        $index = $this->isOptionAttribute($attribute)
            ? $this->isFilterable($attribute)
            : $this->isSearchable($attribute);
        $facet = $this->isFilterable($attribute);
        $sort = $this->isSortable($attribute);
        // A declared field states facet/sort explicitly, so switching one off is a diff, not "engine default".
        $declared = $index || $facet || $sort;

        return new FieldSpec(
            name: $this->resolveFieldName($attribute, $context),
            type: $this->resolveType($attribute),
            facet: $declared ? $facet : null,
            index: $index ? true : null,
            sort: $declared ? $sort : null,
            optional: true
        );
    }

    /**
     * The label fields an option attribute declares: `<code>_value` (`string[]`, index) when searchable
     * and `sort_<code>` (`string`, sort) when `used_for_sort_by = 1`. The values are always in the
     * documents, so toggling either flag is a schema PATCH.
     *
     * @param Attribute $attribute
     * @return FieldSpec[]
     */
    public function toLabelFieldSpecs(Attribute $attribute): array
    {
        if (!$this->isOptionAttribute($attribute)) {
            return [];
        }

        $specs = [];
        if ($this->isSearchable($attribute)) {
            $specs[] = new FieldSpec(
                name: $this->labelFieldName($attribute),
                type: 'string[]',
                index: true,
                optional: true
            );
        }
        if ((int)$attribute->getUsedForSortBy() === 1) {
            $specs[] = new FieldSpec(
                name: $this->sortLabelFieldName($attribute),
                type: 'string',
                sort: true,
                optional: true
            );
        }

        return $specs;
    }

    /**
     * The `<code>_value` field holding an option attribute's store labels.
     *
     * @param Attribute $attribute
     */
    public function labelFieldName(Attribute $attribute): string
    {
        return $attribute->getAttributeCode() . '_value';
    }

    /**
     * The `sort_<code>` field holding the label of an option attribute's first value.
     *
     * @param Attribute $attribute
     */
    public function sortLabelFieldName(Attribute $attribute): string
    {
        return 'sort_' . $attribute->getAttributeCode();
    }

    /**
     * The Typesense field name this attribute maps to, in the given scope context.
     *
     * @param Attribute $attribute
     * @param array<string,mixed> $context
     */
    public function resolveFieldName(Attribute $attribute, array $context = []): string
    {
        return $this->fieldNameResolver->resolve((string)$attribute->getAttributeCode(), $context);
    }

    /**
     * Whether a dedicated context-scoped provider owns this attribute (price/position).
     *
     * Such codes are declared only by their own provider, so the generic mapper must skip them —
     * see {@see FieldNameResolver::isContextScoped()}.
     *
     * @param Attribute $attribute
     */
    public function isContextScoped(Attribute $attribute): bool
    {
        return $this->fieldNameResolver->isContextScoped((string)$attribute->getAttributeCode());
    }

    /**
     * Whether the attribute contributes an `index:true` field (participates in full-text search).
     *
     * @param Attribute $attribute
     */
    public function isSearchable(Attribute $attribute): bool
    {
        return (bool)$attribute->getIsSearchable();
    }

    /**
     * Whether the attribute maps to a Typesense string field — the only valid `query_by` target.
     *
     * Typesense rejects `query_by` on non-string fields (see the search API reference), so a
     * searchable numeric/select/bool/date attribute is `index:true` in the schema yet must be kept
     * out of the searchable seam — otherwise a single such attribute makes every search request fail.
     *
     * @param Attribute $attribute
     */
    public function isStringType(Attribute $attribute): bool
    {
        $type = $this->resolveType($attribute);

        return $type === 'string' || $type === 'string[]';
    }

    /**
     * Query-time weight for a searchable attribute (`query_by_weights`), never a schema property.
     *
     * Mirrors Magento's `getSearchWeight() ?: 1` convention (`RequestGenerator.php:188`): a zero,
     * null or unset weight defaults to 1.
     *
     * @param Attribute $attribute
     */
    public function searchWeight(Attribute $attribute): int
    {
        return (int)$attribute->getSearchWeight() ?: 1;
    }

    /**
     * Whether the attribute contributes a `sort:true` field.
     *
     * Requires the `used_for_sort_by` flag AND a Typesense-sortable type: the engine rejects
     * `sort:true` on array fields (`string[]`), and one such field makes the whole `create()`
     * return 400 — failing the entire store's reindex, not just that field. A multiselect marked
     * "Used for Sorting" is the trigger; it is kept out of the sort seam while still FAT in the doc.
     *
     * @param Attribute $attribute
     */
    private function isSortable(Attribute $attribute): bool
    {
        return (int)$attribute->getUsedForSortBy() === 1
            && !str_contains($this->resolveType($attribute), '[]');
    }

    /**
     * A field is facetable when either filterable flag is set (mirrors ES's `isFilterable`).
     *
     * @param Attribute $attribute
     */
    private function isFilterable(Attribute $attribute): bool
    {
        return (bool)$attribute->getIsFilterable() || (bool)$attribute->getIsFilterableInSearch();
    }

    /**
     * Whether the attribute stores option ids.
     *
     * An int-backed `select`, or a `multiselect` whose options live in `eav_attribute_option`
     * (no source model, or the EAV `Table` source).
     *
     * @param Attribute $attribute
     */
    public function isOptionAttribute(Attribute $attribute): bool
    {
        $input = $attribute->getFrontendInput();
        if ($input === 'select') {
            return (string)$attribute->getBackendType() === 'int';
        }
        if ($input !== 'multiselect') {
            return false;
        }
        $source = $attribute->getSourceModel();

        return empty($source) || (is_string($source) && ltrim($source, '\\') === Table::class);
    }

    /**
     * Typesense field type from the attribute's backend/frontend type.
     *
     * An option attribute is always `int32[]` (select too, so a composite can carry its children's
     * values); a yes/no attribute is `int32` 0/1 (a `bool` field rejects `filter_by=x:1`); any other
     * multiselect is `string[]`; timestamps are `int64`; everything unmatched falls back to `string`.
     *
     * @param Attribute $attribute
     */
    public function resolveType(Attribute $attribute): string
    {
        if ($this->isOptionAttribute($attribute)) {
            return 'int32[]';
        }
        if ($attribute->getFrontendInput() === 'multiselect') {
            return 'string[]';
        }
        if ($attribute->getFrontendInput() === 'boolean') {
            return 'int32';
        }

        return match ((string)$attribute->getBackendType()) {
            'decimal' => 'float',
            'int', 'smallint' => 'int32',
            'timestamp', 'datetime' => 'int64',
            default => 'string',
        };
    }
}
