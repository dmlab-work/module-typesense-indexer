<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Schema;

use MageDevGroup\TypesenseIndexer\Api\SearchableFieldsProviderInterface;
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
            if (!$this->policy->isSearchable($attribute)
                || $this->policy->isContextScoped($attribute)
                || !$this->policy->isStringType($attribute)
            ) {
                // A field is a valid `query_by` target only when it is searchable AND string-typed.
                // Context-scoped codes (price/position) collapse to an empty-scope name no document
                // fills, and Typesense rejects `query_by` on non-string fields — so a searchable
                // numeric/select/bool attribute is skipped too, or every search request would fail.
                continue;
            }
            $fields[$this->policy->resolveFieldName($attribute)] = $this->policy->searchWeight($attribute);
        }

        return $fields;
    }
}
