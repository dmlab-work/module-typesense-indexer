<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Document;

/**
 * A di-merged chain of {@see DocumentDataProviderInterface}.
 *
 * Each provider receives the previous one's output, so contributions accumulate in
 * registration order (a later provider can read or override an earlier one's field).
 */
class CompositeDocumentDataProvider implements DocumentDataProviderInterface
{
    /**
     * @param DocumentDataProviderInterface[] $providers di-merged, order is the chain order
     */
    public function __construct(
        private readonly array $providers = []
    ) {
        foreach ($this->providers as $provider) {
            if (!$provider instanceof DocumentDataProviderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'A document data provider must implement %s, got %s.',
                    DocumentDataProviderInterface::class,
                    get_debug_type($provider)
                ));
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function addData(array $documents, int $storeId): array
    {
        foreach ($this->providers as $provider) {
            $documents = $provider->addData($documents, $storeId);
        }

        return $documents;
    }
}
