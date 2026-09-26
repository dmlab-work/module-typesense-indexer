<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;

/**
 * The always-present catalog fields every document carries: `sku`, `store_id`,
 * `visibility`, `status`. These are the schema's base — store-independent and never
 * optional (every product has them), so faceting/sorting on them is always safe.
 *
 * `id` is deliberately **not** declared: Typesense reserves it as the document identifier,
 * silently dropping it from a `create` schema and rejecting it on a `PATCH` ("Field `id`
 * cannot be altered"). Declaring it would leave `id` permanently in the reconcile diff, so
 * every attribute-save PATCH would fail and fall back to a full reindex — defeating the
 * in-place flag-change path. The document builder still seeds each document's `id` from the
 * indexer's batch key, which is where Typesense expects it.
 */
class StaticFieldProvider implements FieldProviderInterface
{
    /**
     * The base field names this provider owns. The generic {@see AttributeFieldProvider} skips these
     * codes so a base field is never emitted twice — otherwise the computed-schema dedup would depend
     * on DI registration order and could flip `sku`'s `optional` false→true.
     */
    public const OWNED_FIELDS = ['sku', 'store_id', 'visibility', 'status'];

    /**
     * @inheritDoc
     */
    public function getFields(int $storeId): array
    {
        return [
            new FieldSpec(name: 'sku', type: 'string', index: true, sort: true),
            new FieldSpec(name: 'store_id', type: 'int32', facet: true),
            new FieldSpec(name: 'visibility', type: 'int32', facet: true),
            new FieldSpec(name: 'status', type: 'int32', facet: true),
        ];
    }
}
