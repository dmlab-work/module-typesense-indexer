<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\PublicCollection;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Schema of the public storefront collection (`<alias>_public`), queried with the search-only key.
 * Holds a single NOT LOGGED IN `price` and no facets; only searchable option attributes declare
 * their `<code>_value` label field, so an `is_searchable` toggle stays a PATCH.
 */
class PublicSchemaBuilder
{
    /**
     * @param CollectionFactory $attributeCollectionFactory
     * @param AttributeFieldPolicy $policy
     */
    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
        private readonly AttributeFieldPolicy $policy
    ) {
    }

    /**
     * The public collection fields for the store view.
     *
     * @param int $storeId
     * @return FieldSpec[]
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function build(int $storeId): array
    {
        $fields = [
            new FieldSpec(name: 'name', type: 'string', index: true, sort: true),
            new FieldSpec(name: 'sku', type: 'string', index: true),
            new FieldSpec(name: 'short_description', type: 'string', index: true, optional: true),
        ];
        foreach ($this->attributeCollectionFactory->create()->getItems() as $attribute) {
            if ($this->policy->isOptionAttribute($attribute) && $this->policy->isSearchable($attribute)) {
                $fields[] = new FieldSpec(
                    name: $this->policy->labelFieldName($attribute),
                    type: 'string[]',
                    index: true,
                    optional: true
                );
            }
        }
        $fields[] = new FieldSpec(name: 'visibility', type: 'int32', index: true);
        $fields[] = new FieldSpec(name: 'status', type: 'int32', index: true);
        $fields[] = new FieldSpec(name: 'is_in_stock', type: 'int32', index: true, optional: true);
        $fields[] = new FieldSpec(name: 'price', type: 'float', sort: true, optional: true);

        return $fields;
    }
}
