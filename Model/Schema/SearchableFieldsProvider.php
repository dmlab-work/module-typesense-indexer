<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema;

use DmLab\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Default {@see SearchableFieldsProviderInterface}: reads the EAV product attributes and, via the
 * shared {@see AttributeFieldPolicy}, returns only the searchable ones keyed by resolved field
 * name with their query weight.
 *
 * Uses the same policy as the schema builder, so a field is searchable here iff it carries
 * `index:true` in the schema — the query side cannot address a field the index never declared.
 */
class SearchableFieldsProvider implements SearchableFieldsProviderInterface
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
     * @inheritDoc
     */
    public function get(int $storeId): array
    {
        $collection = $this->attributeCollectionFactory->create();

        $fields = [];
        foreach ($collection->getItems() as $attribute) {
            if (!$this->policy->isSearchable($attribute) || $this->policy->isContextScoped($attribute)) {
                // Context-scoped codes (price/position) collapse to an empty-scope name no document fills.
                continue;
            }
            if ($this->policy->isOptionAttribute($attribute)) {
                // Option ids are int32[]; the labels in `<code>_value` are what a shopper types.
                $fields[$this->policy->labelFieldName($attribute)] = $this->policy->searchWeight($attribute);
                continue;
            }
            if (!$this->policy->isStringType($attribute)) {
                // Typesense rejects `query_by` on non-string fields — one would fail every search request.
                continue;
            }
            $fields[$this->policy->resolveFieldName($attribute)] = $this->policy->searchWeight($attribute);
        }

        return $fields;
    }
}
