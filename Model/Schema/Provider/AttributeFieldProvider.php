<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Schema\Provider;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use MageDevGroup\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Turns EAV product attributes into schema fields via the {@see AttributeFieldPolicy}.
 *
 * Only attributes whose flags actually require a declared field are emitted — an
 * attribute with no `is_searchable` / `is_filterable` / `used_for_sort_by` produces no
 * `index`/`facet`/`sort`, so declaring it would spend RAM for nothing. Its *value* is
 * still written to every document by the document builder (the FAT rule); this is the
 * THIN half.
 */
class AttributeFieldProvider implements FieldProviderInterface
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
    public function getFields(int $storeId): array
    {
        $collection = $this->attributeCollectionFactory->create();

        $fields = [];
        foreach ($collection->getItems() as $attribute) {
            if ($this->policy->isContextScoped($attribute)) {
                // price/position fan out per scope — their dedicated provider owns those fields.
                continue;
            }
            if (in_array((string)$attribute->getAttributeCode(), StaticFieldProvider::OWNED_FIELDS, true)) {
                // sku/status/visibility are base fields StaticFieldProvider already declares — re-emitting
                // them would make the dedup order-dependent and could flip sku's `optional` false→true.
                continue;
            }
            $spec = $this->policy->toFieldSpec($attribute);
            if ($this->declaresField($spec)) {
                $fields[] = $spec;
            }
        }

        return $fields;
    }

    /**
     * True when the attribute's flags require a declared field (keeps the schema THIN).
     *
     * @param FieldSpec $spec
     */
    private function declaresField(FieldSpec $spec): bool
    {
        return $spec->getIndex() !== null
            || $spec->getFacet() !== null
            || $spec->getSort() !== null;
    }
}
