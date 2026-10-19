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
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Website;

/**
 * Removes the collections of store views deleted with their website or store group.
 *
 * Those store rows go by FK cascade, so {@see StoreDeletePlugin} never fires; their ids are read before the delete.
 */
class StoreParentDeletePlugin
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
     * Delete the website/group, then remove its store views' collections when Typesense is the active engine.
     *
     * @param AbstractDb $subject
     * @param \Closure $proceed
     * @param AbstractModel $object
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundDelete(AbstractDb $subject, \Closure $proceed, AbstractModel $object): mixed
    {
        $storeIds = $object instanceof Website || $object instanceof Group ? $object->getStoreIds() : [];

        $result = $proceed($object);

        if ($storeIds !== [] && $this->engineResolver->getCurrentSearchEngine() === EngineCode::ENGINE) {
            foreach ($storeIds as $storeId) {
                $this->remover->remove((int)$storeId);
            }
        }

        return $result;
    }
}
