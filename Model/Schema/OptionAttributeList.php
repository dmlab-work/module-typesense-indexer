<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema;

use DmLab\TypesenseIndexer\Model\Indexer\RunScopedCacheInterface;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Every option attribute ({@see AttributeFieldPolicy::isOptionAttribute()}), keyed by code, loaded once per index run.
 *
 * Memoized for document building only; schema computation reads attributes fresh, since an attribute
 * save in the same request changes them.
 */
class OptionAttributeList implements RunScopedCacheInterface
{
    /**
     * @var array<string,Attribute>|null
     */
    private ?array $attributes = null;

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
    public function reset(): void
    {
        $this->attributes = null;
    }

    /**
     * Option attributes by code.
     *
     * @return array<string,Attribute>
     */
    public function get(): array
    {
        if ($this->attributes !== null) {
            return $this->attributes;
        }

        $attributes = [];
        foreach ($this->attributeCollectionFactory->create()->getItems() as $attribute) {
            if ($this->policy->isOptionAttribute($attribute)) {
                $attributes[(string)$attribute->getAttributeCode()] = $attribute;
            }
        }

        return $this->attributes = $attributes;
    }
}
