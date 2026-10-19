<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\PublicCollection;

use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;

/**
 * Projects a fat main document onto the public collection: only the C2 keys, and `price` = the
 * NOT LOGGED IN (group 0) price of the store's website. No other group's price ever survives.
 * Option labels are copied only for storefront-exposed attributes; admin-only selects stay private.
 */
class PublicDocumentProjector
{
    /**
     * Keys copied verbatim when present.
     */
    private const COPIED = ['id', 'name', 'sku', 'short_description', 'visibility', 'status', 'url_key'];

    /**
     * Image roles `thumbnail` falls back through, matching the instant-search proxy.
     */
    private const IMAGE_ROLES = ['thumbnail', 'small_image', 'image'];

    /**
     * @param OptionAttributeList $optionAttributes
     * @param AttributeFieldPolicy $policy
     */
    public function __construct(
        private readonly OptionAttributeList $optionAttributes,
        private readonly AttributeFieldPolicy $policy
    ) {
    }

    /**
     * The public document for a main document.
     *
     * @param array<string,mixed> $document
     * @param int $websiteId
     * @return array<string,mixed>
     */
    public function project(array $document, int $websiteId): array
    {
        $public = [];
        foreach (array_merge(self::COPIED, $this->labelFields()) as $key) {
            if (array_key_exists($key, $document)) {
                $public[$key] = $document[$key];
            }
        }

        $image = $this->image($document);
        if ($image !== null) {
            $public['thumbnail'] = $image;
        }

        $priceKey = 'price_0_' . $websiteId;
        if (isset($document[$priceKey])) {
            $public['price'] = (float)$document[$priceKey];
        }

        $taxClass = $document['tax_class_id'] ?? null;
        if (is_array($taxClass)) {
            $taxClass = $taxClass === [] ? null : reset($taxClass);
        }
        if ($taxClass !== null) {
            $public['tax_class_id'] = $taxClass;
        }

        if (isset($document['is_out_of_stock'])) {
            $public['is_in_stock'] = 1 - (int)(bool)$document['is_out_of_stock'];
        } elseif (isset($document['is_in_stock'])) {
            $public['is_in_stock'] = (int)(bool)$document['is_in_stock'];
        }

        return $public;
    }

    /**
     * The first set image of {@see IMAGE_ROLES}; `no_selection` is Magento's "no image" marker.
     *
     * @param array<string,mixed> $document
     * @return string|null
     */
    private function image(array $document): ?string
    {
        foreach (self::IMAGE_ROLES as $role) {
            $value = $document[$role] ?? null;
            if (is_string($value) && $value !== '' && $value !== 'no_selection') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Label fields of storefront-exposed option attributes (the schema declares only searchable ones).
     *
     * @return string[]
     */
    private function labelFields(): array
    {
        $fields = [];
        foreach ($this->optionAttributes->get() as $attribute) {
            if ($this->policy->isStorefrontExposed($attribute)) {
                $fields[] = $this->policy->labelFieldName($attribute);
            }
        }

        return $fields;
    }
}
