<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document;

/**
 * Contributes document data to a store view's Typesense documents.
 *
 * This is the FAT-document seam, mirroring Magento's batch data-mapper: a provider receives
 * the documents built so far (keyed by product id) and returns the same map with its data
 * merged in. Providers are chained by the {@see CompositeDocumentDataProvider}, so a later
 * one sees an earlier one's fields. A module adds data by di-merging a provider — it never
 * writes to a collection itself (single-writer rule). `typesense-semantic` uses this to add
 * embedding source values.
 *
 * @api
 */
interface DocumentDataProviderInterface
{
    /**
     * Merge this provider's data into the given documents for the store view.
     *
     * @param array<string,array<string,mixed>> $documents keyed by product id, document-so-far
     * @param int $storeId
     * @return array<string,array<string,mixed>> the same map with this provider's fields added
     */
    public function addData(array $documents, int $storeId): array;
}
