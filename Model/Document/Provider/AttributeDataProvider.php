<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use DmLab\TypesenseIndexer\Model\Indexer\RunScopedCacheInterface;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
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
 * from the stored documents, no reimport): an option value (select or multiselect) becomes a list
 * of ids, a datetime becomes a Unix epoch, HTML text loses its tags. Without this a later
 * `is_filterable` toggle would rebuild a facet from the raw comma-joined string `"12,15,33"` — one
 * bogus value instead of three.
 *
 * Only product attribute values (plus `type_id`, `attribute_set_id`) are written. Gallery data and
 * other non-scalar values are dropped; raw image paths (`image`, `small_image`, `thumbnail`) stay.
 *
 * `visibility`, `status` and `sku` are ordinary EAV attributes and are carried by this same
 * pass — no separate provider is needed for them.
 */
class AttributeDataProvider implements DocumentDataProviderInterface, RunScopedCacheInterface
{
    /**
     * Raw storage keys that are not catalog data and must not reach the document.
     */
    private const SKIP_KEYS = ['entity_id', 'row_id', 'created_at', 'updated_at'];

    /**
     * Non-attribute product columns that are still catalog data.
     */
    private const EXTRA_KEYS = ['type_id', 'attribute_set_id'];

    /**
     * Frontend inputs whose values may carry HTML markup.
     */
    private const HTML_INPUTS = ['textarea', 'texteditor', 'pagebuilder'];

    /**
     * @var array<string,array{input:string,backend:string,option:bool,html:bool}>|null memoized attribute code ⇒ type
     */
    private ?array $attributeMeta = null;

    /**
     * @param CollectionFactory $collectionFactory
     * @param AttributeCollectionFactory $attributeCollectionFactory source of per-attribute type info
     * @param AttributeFieldPolicy $policy decides which attributes hold option ids
     */
    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly AttributeCollectionFactory $attributeCollectionFactory,
        private readonly AttributeFieldPolicy $policy
    ) {
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->attributeMeta = null;
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
                $key = (string)$key;
                if ($this->keeps($key, $value, $meta)) {
                    $documents[$id][$key] = $this->normalize($key, $value, $meta);
                }
            }
        }

        return $documents;
    }

    /**
     * Whether a raw product key/value belongs in the document.
     *
     * @param string $key
     * @param mixed $value
     * @param array<string,array{input:string,backend:string,option:bool,html:bool}> $meta
     */
    private function keeps(string $key, mixed $value, array $meta): bool
    {
        if (in_array($key, self::EXTRA_KEYS, true)) {
            return is_scalar($value) || $value === null;
        }
        if (!isset($meta[$key]) || in_array($key, self::SKIP_KEYS, true) || $meta[$key]['input'] === 'gallery') {
            return false;
        }

        return is_scalar($value) || $value === null || $meta[$key]['option'];
    }

    /**
     * Shape a raw EAV value to the canonical Typesense representation for its attribute type.
     *
     * Only the shapes that do not survive a schema PATCH untouched are converted — an option value
     * or multiselect list (scalar or comma-joined string ⇒ list of ids) and a datetime (MySQL string
     * ⇒ Unix epoch, the `int64` the schema declares). HTML text is stripped to plain text. Everything
     * else is left as read.
     *
     * @param string $code attribute code
     * @param mixed $value raw EAV value
     * @param array<string,array{input:string,backend:string,option:bool,html:bool}> $meta
     * @return mixed
     */
    private function normalize(string $code, mixed $value, array $meta): mixed
    {
        if (!isset($meta[$code]) || $value === null) {
            return $value;
        }

        if ($meta[$code]['option'] || $meta[$code]['input'] === 'multiselect') {
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
            // Magento stores datetimes in UTC; don't depend on the process timezone.
            $date = date_create_immutable((string)$value, new \DateTimeZone('UTC'));

            return $date === false ? null : $date->getTimestamp();
        }

        if ($meta[$code]['html'] && is_string($value)) {
            // Same as CatalogSearch `DataProvider::getAttributeValue()`.
            return preg_replace('/\s+/u', ' ', trim(strip_tags($value)));
        }

        return $value;
    }

    /**
     * Attribute code ⇒ its frontend input, backend type, option-ness and HTML-ness, loaded once per index run.
     *
     * @return array<string,array{input:string,backend:string,option:bool,html:bool}>
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
                'option' => $this->policy->isOptionAttribute($attribute),
                'html' => in_array((string)$attribute->getFrontendInput(), self::HTML_INPUTS, true)
                    || (int)$attribute->getIsHtmlAllowedOnFront() === 1,
            ];
        }

        return $this->attributeMeta = $meta;
    }
}
