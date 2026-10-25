<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\Indexer;

use DmLab\TypesenseIndexer\Model\Indexer\FulltextInvalidationSuppressor;
use DmLab\TypesenseIndexer\Model\Plugin\Indexer\SuppressedInvalidationPlugin;
use Magento\CatalogSearch\Model\Indexer\Fulltext;
use Magento\Framework\Indexer\IndexerInterface;
use PHPUnit\Framework\TestCase;

class SuppressedInvalidationPluginTest extends TestCase
{
    private function invalidates(string $indexerId, bool $suppressed): bool
    {
        $suppressor = new FulltextInvalidationSuppressor();
        $plugin = new SuppressedInvalidationPlugin($suppressor);
        $indexer = $this->createStub(IndexerInterface::class);
        $indexer->method('getId')->willReturn($indexerId);

        $ran = false;
        $invalidate = static function () use ($plugin, $indexer, &$ran): void {
            $plugin->aroundInvalidate($indexer, static function () use (&$ran): void {
                $ran = true;
            });
        };
        $suppressed ? $suppressor->run($invalidate) : $invalidate();

        return $ran;
    }

    public function testFulltextInvalidationIsDroppedOnlyWhileSuppressed(): void
    {
        self::assertFalse($this->invalidates(Fulltext::INDEXER_ID, true));
        self::assertTrue($this->invalidates(Fulltext::INDEXER_ID, false));
    }

    public function testOtherIndexersAreNeverSuppressed(): void
    {
        self::assertTrue($this->invalidates('catalog_product_price', true));
    }

    public function testSuppressionNestsAndReturnsTheResult(): void
    {
        $suppressor = new FulltextInvalidationSuppressor();

        $result = $suppressor->run(static function () use ($suppressor): string {
            $suppressor->run(static fn () => null);
            self::assertTrue($suppressor->isActive());

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertFalse($suppressor->isActive());
    }

    public function testSuppressionEndsWhenTheOperationThrows(): void
    {
        $suppressor = new FulltextInvalidationSuppressor();
        $this->expectException(\RuntimeException::class);

        try {
            $suppressor->run(static fn () => throw new \RuntimeException('boom'));
        } finally {
            self::assertFalse($suppressor->isActive());
        }
    }
}
