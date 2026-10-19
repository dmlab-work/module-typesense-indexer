<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\Indexer;

use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use DmLab\TypesenseIndexer\Model\Plugin\Indexer\ReindexAllInvalidationPlugin;
use Magento\Framework\Indexer\IndexerInterface;
use PHPUnit\Framework\TestCase;

class ReindexAllInvalidationPluginTest extends TestCase
{
    private function indexer(string $id, bool $expectInvalidate): IndexerInterface
    {
        $indexer = $this->createMock(IndexerInterface::class);
        $indexer->method('getId')->willReturn($id);
        $indexer->expects($expectInvalidate ? self::once() : self::never())->method('invalidate');

        return $indexer;
    }

    public function testReinvalidatesWhenMarkedDuringTheRun(): void
    {
        $marker = $this->createMock(ReindexInvalidationMarker::class);
        $ran = false;
        $marker->expects(self::once())->method('consumeSince')
            ->with(self::callback(fn ($since): bool => abs($since - time()) <= 1))
            ->willReturnCallback(function () use (&$ran): bool {
                self::assertTrue($ran, 'checked after the reindex');

                return true;
            });

        (new ReindexAllInvalidationPlugin($marker))->aroundReindexAll(
            $this->indexer('catalogsearch_fulltext', true),
            function () use (&$ran): void {
                $ran = true;
            }
        );
    }

    public function testLeavesTheIndexerValidWithoutAMark(): void
    {
        $marker = $this->createStub(ReindexInvalidationMarker::class);
        $marker->method('consumeSince')->willReturn(false);

        (new ReindexAllInvalidationPlugin($marker))->aroundReindexAll(
            $this->indexer('catalogsearch_fulltext', false),
            fn () => null
        );
    }

    public function testOtherIndexersAreUntouched(): void
    {
        $marker = $this->createMock(ReindexInvalidationMarker::class);
        $marker->expects(self::never())->method('consumeSince');
        $ran = false;

        (new ReindexAllInvalidationPlugin($marker))->aroundReindexAll(
            $this->indexer('catalog_product_price', false),
            function () use (&$ran): void {
                $ran = true;
            }
        );

        self::assertTrue($ran);
    }
}
