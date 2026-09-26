<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Api;

/**
 * Published mapping from a Magento attribute code to the Typesense document field name.
 *
 * Context-scoped codes (`price`, `position`) become scoped field names
 * (`price_<customerGroupId>_<websiteId>`, `position_<categoryId>`). `typesense-search`
 * resolves query/aggregation field names through this contract instead of reaching into
 * the indexer's internal naming policy.
 *
 * @api
 */
interface FieldNameResolverInterface
{
    /**
     * The Typesense document field name for a Magento attribute code.
     *
     * @param string $attributeCode
     * @param array<string,mixed> $context `customerGroupId`, `websiteId`, `categoryId`
     */
    public function resolve(string $attributeCode, array $context = []): string;
}
