<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Api;

/**
 * The public read seam for "which fields are searchable, and at what query weight".
 *
 * This is the ONLY supported way to learn the searchable field set. It is derived from the
 * same {@see \DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy} that builds the
 * schema — `is_searchable` selects the field, `search_weight` gives its weight — so the query
 * side can never drift from the indexed side.
 *
 * Consumers: `typesense-search` maps the result onto `query_by` / `query_by_weights`;
 * `typesense-instant-search` onto its browser search config. Two consumers earn the seam.
 *
 * Weights are a query-time concept only — Typesense has no per-field weight in a schema, so
 * `search_weight` never reaches a `FieldSpec`.
 *
 * @api
 */
interface SearchableFieldsProviderInterface
{
    /**
     * Searchable field name ⇒ query weight for the given store view.
     *
     * @param int $storeId
     * @return array<string,int>
     */
    public function get(int $storeId): array;
}
