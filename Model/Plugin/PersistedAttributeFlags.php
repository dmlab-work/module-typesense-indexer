<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin;

use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Model\AbstractModel;

/**
 * The persisted search/storefront flags of an existing attribute, read before it is saved.
 *
 * Uses the attribute's original data when it was `load()`ed; attributes from `EavConfig` or the
 * product attribute repository carry none, so their flags are read from `catalog_eav_attribute`.
 */
class PersistedAttributeFlags
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Flag ⇒ persisted value; empty when the attribute has no stored row.
     *
     * @param AbstractModel $attribute an existing attribute about to be saved
     * @return array<string,mixed>
     */
    public function get(AbstractModel $attribute): array
    {
        $flags = AttributeFieldPolicy::TRACKED_FLAGS;
        $orig = $attribute->getOrigData();
        if (is_array($orig) && !array_diff_key(array_flip($flags), $orig)) {
            return array_intersect_key($orig, array_flip($flags));
        }

        $attributeId = (int)$attribute->getId();
        if ($attributeId === 0) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('catalog_eav_attribute'), $flags)
            ->where('attribute_id = ?', $attributeId);

        return $connection->fetchRow($select) ?: [];
    }
}
