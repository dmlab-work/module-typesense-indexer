<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\Store;

use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\StoreCollectionRemover;
use DmLab\TypesenseIndexer\Model\Plugin\Store\StoreDeletePlugin;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\ResourceModel\Store;
use PHPUnit\Framework\TestCase;

class StoreDeletePluginTest extends TestCase
{
    private function plugin(StoreCollectionRemover $remover, string $engine): StoreDeletePlugin
    {
        $engineResolver = $this->createStub(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);

        return new StoreDeletePlugin($remover, $engineResolver);
    }

    private function store(): AbstractModel
    {
        $store = $this->createStub(AbstractModel::class);
        $store->method('getId')->willReturn('7');

        return $store;
    }

    public function testRemovesTheDeletedStoreCollectionsAndReturnsTheResult(): void
    {
        $remover = $this->createMock(StoreCollectionRemover::class);
        $remover->expects(self::once())->method('remove')->with(7);
        $subject = $this->createStub(Store::class);

        $result = $this->plugin($remover, EngineCode::ENGINE)->afterDelete($subject, $subject, $this->store());

        self::assertSame($subject, $result);
    }

    public function testDoesNothingUnderAnotherEngine(): void
    {
        $remover = $this->createMock(StoreCollectionRemover::class);
        $remover->expects(self::never())->method('remove');
        $subject = $this->createStub(Store::class);

        $result = $this->plugin($remover, 'opensearch')->afterDelete($subject, $subject, $this->store());

        self::assertSame($subject, $result);
    }
}
