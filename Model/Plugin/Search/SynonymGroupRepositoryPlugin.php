<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Plugin\Search;

use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Search\Model\SynonymGroupRepository;
use Psr\Log\LoggerInterface;

/**
 * Resyncs every store's synonym set after an admin synonym-group save or delete.
 *
 * A Typesense failure is logged, never surfaced: the admin save must not break.
 */
class SynonymGroupRepositoryPlugin
{
    /**
     * @param SynonymSync $synonymSync
     * @param EngineResolverInterface $engineResolver
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SynonymSync $synonymSync,
        private readonly EngineResolverInterface $engineResolver,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Resync after a group save.
     *
     * @param SynonymGroupRepository $subject
     * @param mixed $result
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(SynonymGroupRepository $subject, mixed $result): mixed
    {
        $this->sync();

        return $result;
    }

    /**
     * Resync after a group delete.
     *
     * @param SynonymGroupRepository $subject
     * @param mixed $result
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDelete(SynonymGroupRepository $subject, mixed $result): mixed
    {
        $this->sync();

        return $result;
    }

    /**
     * Resync all stores when Typesense is the active engine.
     */
    private function sync(): void
    {
        if ($this->engineResolver->getCurrentSearchEngine() !== EngineCode::ENGINE) {
            return;
        }

        try {
            $this->synonymSync->syncAllStores();
        } catch (\Exception $e) {
            $this->logger->error('Typesense indexer: synonym sync failed.', ['exception' => $e]);
        }
    }
}
