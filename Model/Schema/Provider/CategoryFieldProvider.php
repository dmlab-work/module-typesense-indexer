<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema\Provider;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;

/**
 * Category membership and per-category sort position, scoped to the store view.
 *
 * `category_ids` is a facetable id array (layered navigation by category). Each category
 * a product can live in also gets its own `position_category_<id>` sort field, mirroring
 * Magento's Elasticsearch naming so `typesense-search` addresses them identically. The
 * category set is store-scoped, so different store views yield different position fields.
 */
class CategoryFieldProvider implements FieldProviderInterface
{
    /**
     * @param CollectionFactory $categoryCollectionFactory
     * @param FieldNameResolver $fieldNameResolver
     */
    public function __construct(
        private readonly CollectionFactory $categoryCollectionFactory,
        private readonly FieldNameResolver $fieldNameResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getFields(int $storeId): array
    {
        $fields = [
            new FieldSpec(name: 'category_ids', type: 'int64[]', facet: true, optional: true),
        ];

        $collection = $this->categoryCollectionFactory->create();
        $collection->setStoreId($storeId);
        foreach ($collection->getAllIds() as $categoryId) {
            $fields[] = new FieldSpec(
                name: $this->fieldNameResolver->resolve('position', ['categoryId' => (int)$categoryId]),
                type: 'int32',
                sort: true,
                optional: true
            );
        }

        return $fields;
    }
}
