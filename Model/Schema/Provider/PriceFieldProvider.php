<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A sortable, facetable price field per customer group for the store view's website:
 * `price_<customerGroupId>_<websiteId>` (prices are scoped per group and website).
 *
 * The website comes from the store view, so two store views on different websites yield
 * different price fields — the per-store difference the schema must carry.
 */
class PriceFieldProvider implements FieldProviderInterface
{
    /**
     * @param CollectionFactory $groupCollectionFactory
     * @param StoreManagerInterface $storeManager
     * @param FieldNameResolver $fieldNameResolver
     */
    public function __construct(
        private readonly CollectionFactory $groupCollectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly FieldNameResolver $fieldNameResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getFields(int $storeId): array
    {
        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();

        $fields = [];
        foreach ($this->groupCollectionFactory->create()->getAllIds() as $groupId) {
            $fields[] = new FieldSpec(
                name: $this->fieldNameResolver->resolve(
                    'price',
                    ['customerGroupId' => (int)$groupId, 'websiteId' => $websiteId]
                ),
                type: 'float',
                facet: true,
                sort: true,
                optional: true
            );
        }

        return $fields;
    }
}
