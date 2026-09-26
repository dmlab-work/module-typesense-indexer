<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model\Indexer;

use Magento\Framework\App\ScopeResolverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Resolves the canonical store id an indexer scope dimension carries.
 *
 * Shared by {@see IndexerHandler} and {@see IndexStructure}: both halves of the indexer contract
 * key their collection on the same store scope, so the resolution lives in one collaborator instead
 * of being duplicated across them.
 */
class StoreScopeResolver
{
    /**
     * @param ScopeResolverInterface $scopeResolver resolves the store scope carried by the dimension
     */
    public function __construct(
        private readonly ScopeResolverInterface $scopeResolver
    ) {
    }

    /**
     * The canonical store id carried by the first dimension.
     *
     * @param \Magento\Framework\Search\Request\Dimension[] $dimensions
     * @throws LocalizedException when no dimension is present
     */
    public function resolve(array $dimensions): int
    {
        $dimension = current($dimensions);
        if ($dimension === false) {
            throw new LocalizedException(
                new Phrase('A store scope dimension is required to resolve the Typesense collection.')
            );
        }

        return (int)$this->scopeResolver->getScope($dimension->getValue())->getId();
    }
}
