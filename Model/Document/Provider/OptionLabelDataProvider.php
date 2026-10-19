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
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;

/**
 * Adds the store labels of every option attribute in a document, regardless of flags (FAT):
 * `<code>_value` = the labels of its ids, `sort_<code>` = the label of the first id.
 * Runs after `children`, so a composite carries its children's labels too. Unknown ids are skipped.
 */
class OptionLabelDataProvider implements DocumentDataProviderInterface, RunScopedCacheInterface
{
    /**
     * @var array<int,array<string,array<string,string>>> store ⇒ code ⇒ option id ⇒ label
     */
    private array $labels = [];

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
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->labels = [];
    }

    /**
     * @inheritDoc
     */
    public function addData(array $documents, int $storeId): array
    {
        $attributes = $this->optionAttributes->get();

        foreach ($documents as $id => $document) {
            foreach ($attributes as $code => $attribute) {
                $ids = $document[$code] ?? null;
                if (!is_array($ids) || $ids === []) {
                    continue;
                }
                $labels = $this->labelsFor($attribute, $storeId);
                $values = [];
                foreach ($ids as $optionId) {
                    $label = $labels[trim((string)$optionId)] ?? null;
                    if ($label !== null) {
                        $values[$label] = $label;
                    }
                }
                if ($values === []) {
                    continue;
                }
                $values = array_values($values);
                $documents[$id][$this->policy->labelFieldName($attribute)] = $values;
                $documents[$id][$this->policy->sortLabelFieldName($attribute)] = $values[0];
            }
        }

        return $documents;
    }

    /**
     * Option id ⇒ store label, loaded once per store and attribute in an index run.
     *
     * @param Attribute $attribute
     * @param int $storeId
     * @return array<string,string>
     */
    private function labelsFor(Attribute $attribute, int $storeId): array
    {
        $code = (string)$attribute->getAttributeCode();
        if (isset($this->labels[$storeId][$code])) {
            return $this->labels[$storeId][$code];
        }

        $attribute->setData('store_id', $storeId);
        $labels = [];
        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            // Grouped sources nest their options under `value`.
            foreach (is_array($option['value'] ?? null) ? $option['value'] : [$option] as $item) {
                $value = (string)($item['value'] ?? '');
                $label = (string)($item['label'] ?? '');
                if ($value !== '' && $label !== '') {
                    $labels[$value] = $label;
                }
            }
        }

        return $this->labels[$storeId][$code] = $labels;
    }
}
