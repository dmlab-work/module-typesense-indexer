<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Schema;

use DmLab\TypesenseIndexer\Model\Indexer\RunScopedCacheInterface;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;

/**
 * Field name ⇒ Typesense type for every value a document may carry: the policy type of every
 * product attribute (declared or not), overlaid by the desired schema so declared types win
 * (e.g. `StaticFieldProvider`'s scalar `status`/`visibility`). Lets undeclared FAT values be stored
 * already cast, so a later flag PATCH rebuilds the field from correctly typed values.
 * A name with no exact entry falls back to the first matching regex spec (`price_[0-9]+_.*`).
 */
class AttributeTypeMap implements RunScopedCacheInterface
{
    /**
     * @var array<int,array<string,string>> memoized per store for one index run
     */
    private array $typesByStore = [];

    /**
     * @var array<int,array<string,string>> regex spec name ⇒ type, memoized per store
     */
    private array $patternsByStore = [];

    /**
     * @param CollectionFactory $attributeCollectionFactory
     * @param AttributeFieldPolicy $policy
     * @param DesiredSchemaBuilder $schemaBuilder
     */
    public function __construct(
        private readonly CollectionFactory $attributeCollectionFactory,
        private readonly AttributeFieldPolicy $policy,
        private readonly DesiredSchemaBuilder $schemaBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->typesByStore = [];
        $this->patternsByStore = [];
    }

    /**
     * The field name ⇒ type map for the store view.
     *
     * @param int $storeId
     * @return array<string,string>
     */
    public function get(int $storeId): array
    {
        if (isset($this->typesByStore[$storeId])) {
            return $this->typesByStore[$storeId];
        }

        $types = [];
        foreach ($this->attributeCollectionFactory->create()->getItems() as $attribute) {
            $types[(string)$attribute->getAttributeCode()] = $this->policy->resolveType($attribute);
            if ($this->policy->isOptionAttribute($attribute)) {
                // Exact entries, so a label field never falls through to a regex (`price_view_value`).
                $types[$this->policy->labelFieldName($attribute)] = 'string[]';
                $types[$this->policy->sortLabelFieldName($attribute)] = 'string';
            }
        }
        $patterns = [];
        foreach ($this->schemaBuilder->build($storeId) as $spec) {
            $types[$spec->getName()] = $spec->getType();
            if ($this->isRegexName($spec->getName())) {
                $patterns[$spec->getName()] = $spec->getType();
            }
        }
        $this->patternsByStore[$storeId] = $patterns;

        return $this->typesByStore[$storeId] = $types;
    }

    /**
     * The type of one field name: its exact entry, else the first regex spec matching it, else `null`.
     *
     * @param int $storeId
     * @param string $name
     * @return string|null
     */
    public function resolve(int $storeId, string $name): ?string
    {
        $types = $this->get($storeId);
        if (isset($types[$name])) {
            return $types[$name];
        }
        foreach ($this->patternsByStore[$storeId] as $pattern => $type) {
            if (preg_match('/^' . $pattern . '$/', $name) === 1) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Whether a schema field name is a Typesense regex field (recognized by `.*`).
     *
     * @param string $name
     * @return bool
     */
    private function isRegexName(string $name): bool
    {
        return str_contains($name, '.*');
    }
}
