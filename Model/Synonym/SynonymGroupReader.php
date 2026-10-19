<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Synonym;

use Magento\Framework\App\ResourceConnection;

/**
 * Reads Magento's native synonym groups (`search_synonyms`) that apply to one store view.
 *
 * A group applies when it is scoped to the store, to the store's website, or to all websites.
 */
class SynonymGroupReader
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Groups with at least two distinct phrases.
     *
     * @param int $storeId
     * @param int $websiteId the store's website
     * @return array<int,string[]> group id ⇒ phrases
     */
    public function getForStore(int $storeId, int $websiteId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('search_synonyms'), ['group_id', 'synonyms'])
            ->where('store_id = ?', $storeId)
            ->orWhere('store_id = 0 AND website_id IN (?)', [0, $websiteId])
            ->order('group_id');

        $groups = [];
        foreach ($connection->fetchAll($select) as $row) {
            $phrases = $this->parse((string)$row['synonyms']);
            if (count($phrases) >= 2) {
                $groups[(int)$row['group_id']] = $phrases;
            }
        }

        return $groups;
    }

    /**
     * Comma-separated phrases → trimmed, non-empty, unique (case-sensitive).
     *
     * @param string $synonyms
     * @return string[]
     */
    private function parse(string $synonyms): array
    {
        $phrases = array_filter(
            array_map('trim', explode(',', $synonyms)),
            static fn (string $phrase): bool => $phrase !== ''
        );

        return array_values(array_unique($phrases));
    }
}
