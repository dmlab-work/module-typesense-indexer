<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\Store;

use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\StoreCollectionRemover;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\ResourceModel\Store;

/**
 * Removes a deleted store view's Typesense collections; the native plugin only invalidates the index.
 */
class StoreDeletePlugin
{
    /**
     * @param StoreCollectionRemover $remover
     * @param EngineResolverInterface $engineResolver
     */
    public function __construct(
        private readonly StoreCollectionRemover $remover,
        private readonly EngineResolverInterface $engineResolver
    ) {
    }

    /**
     * Remove the deleted store view's collections when Typesense is the active engine.
     *
     * @param Store $subject
     * @param mixed $result
     * @param AbstractModel $store
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDelete(Store $subject, mixed $result, AbstractModel $store): mixed
    {
        if ($this->engineResolver->getCurrentSearchEngine() === EngineCode::ENGINE) {
            $this->remover->remove((int)$store->getId());
        }

        return $result;
    }
}
