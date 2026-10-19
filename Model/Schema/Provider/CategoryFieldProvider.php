<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;

/**
 * Category membership and per-category sort position.
 *
 * `category_ids` is a facetable id array (layered navigation by category). Positions use one
 * regex field covering every `position_category_<id>` key (Magento's Elasticsearch naming), so a
 * new category is sortable without a schema change.
 */
class CategoryFieldProvider implements FieldProviderInterface
{
    public const POSITION_FIELD = 'position_category_.*';

    /**
     * @inheritDoc
     */
    public function getFields(int $storeId): array
    {
        return [
            new FieldSpec(name: 'category_ids', type: 'int64[]', facet: true, optional: true),
            new FieldSpec(name: self::POSITION_FIELD, type: 'int32', sort: true, optional: true),
        ];
    }
}
