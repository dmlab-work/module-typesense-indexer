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
 * One regex field covering every `price_<customerGroupId>_<websiteId>` key, so a new customer
 * group or website is sortable/facetable without a schema change. The digit keeps bundle attributes
 * (`price_view`, `price_type`) out: Typesense rejects a document whose matching key is not a float,
 * and only treats names containing `.*` as regex.
 */
class PriceFieldProvider implements FieldProviderInterface
{
    public const FIELD = 'price_[0-9]+_.*';

    /**
     * @inheritDoc
     */
    public function getFields(int $storeId): array
    {
        return [new FieldSpec(name: self::FIELD, type: 'float', facet: true, sort: true, optional: true)];
    }
}
