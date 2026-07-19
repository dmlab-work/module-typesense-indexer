<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Schema;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;

/**
 * Contributes schema fields to a store view's Typesense collection.
 *
 * This is the open/closed seam of the THIN schema: `desired = base + Σ(providers)`.
 * A module adds fields by di-merging a provider into `CompositeFieldProvider` — it never
 * PATCHes a collection itself (single-writer rule). `typesense-semantic` uses this to
 * declare vector fields carrying `embed` / `model_config` in a `FieldSpec`'s `extra`.
 *
 * @api
 */
interface FieldProviderInterface
{
    /**
     * The fields this provider contributes for the given store view.
     *
     * @param int $storeId
     * @return FieldSpec[]
     */
    public function getFields(int $storeId): array;
}
