<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Model\Schema;

use MageDevGroup\TypesenseIndexer\Api\FieldNameResolverInterface;

/**
 * Resolves the Typesense field name for a catalog attribute.
 *
 * Most attributes map to their EAV code verbatim. A few are context-scoped and
 * fan out into several fields, matching Magento's Elasticsearch conventions so
 * `typesense-search` can address them the same way:
 *   - `price`    → `price_<customerGroupId>_<websiteId>`
 *   - `position` → `position_category_<categoryId>`
 */
class FieldNameResolver implements FieldNameResolverInterface
{
    /**
     * Attribute codes that fan out into scope-derived fields and are therefore owned by a
     * dedicated context-scoped provider ({@see PriceFieldProvider}, {@see CategoryFieldProvider}),
     * never by the generic attribute mapper.
     */
    private const CONTEXT_SCOPED = ['price', 'position'];

    /**
     * Field name for the given attribute code within an optional scope context.
     *
     * @param string $attributeCode
     * @param array<string,mixed> $context `customerGroupId`, `websiteId`, `categoryId`
     */
    public function resolve(string $attributeCode, array $context = []): string
    {
        return match ($attributeCode) {
            'price' => $this->priceFieldName($context),
            'position' => $this->positionFieldName($context),
            default => $attributeCode,
        };
    }

    /**
     * Whether the code is context-scoped — resolved without a context it would collapse to an
     * empty scope (`price_0_0`, `position_category_0`) that no document ever fills. Such codes
     * are declared only by their dedicated provider, so the generic mapper must skip them.
     *
     * @param string $attributeCode
     */
    public function isContextScoped(string $attributeCode): bool
    {
        return in_array($attributeCode, self::CONTEXT_SCOPED, true);
    }

    /**
     * `price_<customerGroupId>_<websiteId>` — price is stored per customer group and website.
     *
     * @param array<string,mixed> $context
     */
    private function priceFieldName(array $context): string
    {
        $customerGroupId = (int)($context['customerGroupId'] ?? 0);
        $websiteId = (int)($context['websiteId'] ?? 0);

        return 'price_' . $customerGroupId . '_' . $websiteId;
    }

    /**
     * `position_category_<categoryId>` — a product's sort position is per category.
     *
     * @param array<string,mixed> $context
     */
    private function positionFieldName(array $context): string
    {
        return 'position_category_' . (int)($context['categoryId'] ?? 0);
    }
}
