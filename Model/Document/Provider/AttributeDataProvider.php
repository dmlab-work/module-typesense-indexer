<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

/**
 * The FAT rule made concrete: writes **every** attribute value into the document, regardless
 * of the attribute's search flags. A non-searchable, non-filterable attribute contributes no
 * schema field (the THIN half) but its value still lands here — that is what lets a later
 * flag change be an in-place schema PATCH instead of a catalog reindex.
 *
 * Values are shaped to their canonical Typesense representation as they are written, so a value
 * on disk already matches whatever type a future PATCH may declare (the PATCH rebuilds the index
 * from the stored documents, no reimport): a multiselect option list becomes an array of ids, a
 * datetime becomes a Unix epoch. Without this a later `is_filterable` toggle would rebuild a facet
 * from the raw comma-joined string `"12,15,33"` — one bogus value instead of three.
 *
 * `visibility`, `status` and `sku` are ordinary EAV attributes and are carried by this same
 * pass — no separate provider is needed for them.
 */
class AttributeDataProvider implements DocumentDataProviderInterface
{
    /**
     * Raw storage keys that are not catalog data and must not reach the document.
     */
    private const SKIP_KEYS = ['entity_id', 'row_id', 'created_at', 'updated_at'];

    /**
     * @var array<string,array{input:string,backend:string}>|null memoized attribute code ⇒ type
     */
    private ?array $attributeMeta = null;

    /**
     * @param CollectionFactory $collectionFactory
     * @param AttributeCollectionFactory $attributeCollectionFactory source of per-attribute type info
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly AttributeCollectionFactory $attributeCollectionFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function addData(array $documents, int $storeId): array
    {
        $ids = array_keys($documents);
        if ($ids === []) {
            return $documents;
        }

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addAttributeToSelect('*');
        $collection->addIdFilter($ids);

        $meta = $this->attributeMeta();

        foreach ($collection->getItems() as $product) {
            $id = (string)$product->getId();
            if (!isset($documents[$id])) {
                continue;
            }
            foreach ($product->getData() as $key => $value) {
                if (in_array($key, self::SKIP_KEYS, true)) {
                    continue;
                }
                $documents[$id][$key] = $this->normalize((string)$key, $value, $meta);
            }
        }

        return $documents;
    }

    /**
     * Shape a raw EAV value to the canonical Typesense representation for its attribute type.
     *
     * Only the shapes that do not survive a schema PATCH untouched are converted — a multiselect
     * option list (comma-joined string ⇒ array of ids) and a datetime (MySQL string ⇒ Unix epoch,
     * the `int64` the schema declares). Everything else is left as read; unmapped keys (price,
     * category, system columns) are not attributes and pass through.
     *
     * @param string $code attribute code
     * @param mixed $value raw EAV value
     * @param array<string,array{input:string,backend:string}> $meta
     * @return mixed
     */
    private function normalize(string $code, mixed $value, array $meta): mixed
    {
        if (!isset($meta[$code]) || $value === null) {
            return $value;
        }

        if ($meta[$code]['input'] === 'multiselect') {
            if (is_array($value)) {
                return array_values($value);
            }

            return $value === '' ? [] : array_values(array_filter(
                array_map('trim', explode(',', (string)$value)),
                static fn (string $v): bool => $v !== ''
            ));
        }

        if (in_array($meta[$code]['backend'], ['datetime', 'timestamp'], true)) {
            if (is_numeric($value)) {
                return (int)$value;
            }
            $timestamp = strtotime((string)$value);

            return $timestamp === false ? null : $timestamp;
        }

        return $value;
    }

    /**
     * Attribute code ⇒ its frontend input and backend type, loaded once.
     *
     * @return array<string,array{input:string,backend:string}>
     */
    private function attributeMeta(): array
    {
        if ($this->attributeMeta !== null) {
            return $this->attributeMeta;
        }

        $meta = [];
        foreach ($this->attributeCollectionFactory->create()->getItems() as $attribute) {
            $meta[(string)$attribute->getAttributeCode()] = [
                'input' => (string)$attribute->getFrontendInput(),
                'backend' => (string)$attribute->getBackendType(),
            ];
        }

        return $this->attributeMeta = $meta;
    }
}
