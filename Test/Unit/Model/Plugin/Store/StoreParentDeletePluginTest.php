<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\Store;

use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Indexer\StoreCollectionRemover;
use DmLab\TypesenseIndexer\Model\Plugin\Store\StoreParentDeletePlugin;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\ResourceModel\Group as GroupResource;
use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class StoreParentDeletePluginTest extends TestCase
{
    private function plugin(StoreCollectionRemover $remover, string $engine): StoreParentDeletePlugin
    {
        $engineResolver = $this->createStub(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);

        return new StoreParentDeletePlugin($remover, $engineResolver);
    }

    public function testWebsiteDeleteRemovesItsStoresCollectionsAfterTheDelete(): void
    {
        $website = $this->createStub(Website::class);
        $website->method('getStoreIds')->willReturn(['3' => '3', '4' => '4']);
        $deleted = false;
        $removed = [];
        $remover = $this->createMock(StoreCollectionRemover::class);
        $remover->expects(self::exactly(2))->method('remove')->willReturnCallback(
            static function (int $storeId) use (&$deleted, &$removed): void {
                self::assertTrue($deleted);
                $removed[] = $storeId;
            }
        );
        $subject = $this->createStub(WebsiteResource::class);

        $result = $this->plugin($remover, EngineCode::ENGINE)->aroundDelete(
            $subject,
            static function () use (&$deleted, $subject) {
                $deleted = true;

                return $subject;
            },
            $website
        );

        self::assertSame($subject, $result);
        self::assertSame([3, 4], $removed);
    }

    public function testGroupDeleteRemovesItsStoresCollections(): void
    {
        $group = $this->createStub(Group::class);
        $group->method('getStoreIds')->willReturn([5]);
        $remover = $this->createMock(StoreCollectionRemover::class);
        $remover->expects(self::once())->method('remove')->with(5);
        $subject = $this->createStub(GroupResource::class);

        $this->plugin($remover, EngineCode::ENGINE)->aroundDelete($subject, static fn () => $subject, $group);
    }

    public function testDoesNothingUnderAnotherEngine(): void
    {
        $group = $this->createStub(Group::class);
        $group->method('getStoreIds')->willReturn([5]);
        $remover = $this->createMock(StoreCollectionRemover::class);
        $remover->expects(self::never())->method('remove');
        $subject = $this->createStub(GroupResource::class);

        $result = $this->plugin($remover, 'opensearch')->aroundDelete($subject, static fn () => $subject, $group);

        self::assertSame($subject, $result);
    }

    public function testAFailedDeleteRemovesNothing(): void
    {
        $group = $this->createStub(Group::class);
        $group->method('getStoreIds')->willReturn([5]);
        $remover = $this->createMock(StoreCollectionRemover::class);
        $remover->expects(self::never())->method('remove');

        $this->expectException(\RuntimeException::class);
        $this->plugin($remover, EngineCode::ENGINE)->aroundDelete(
            $this->createStub(GroupResource::class),
            static fn () => throw new \RuntimeException('FK'),
            $group
        );
    }
}
