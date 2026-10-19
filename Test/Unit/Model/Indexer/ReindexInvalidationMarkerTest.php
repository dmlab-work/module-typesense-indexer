<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Indexer;

use DmLab\TypesenseIndexer\Model\Indexer\ReindexInvalidationMarker;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\TestCase;

class ReindexInvalidationMarkerTest extends TestCase
{
    public function testMarkStoresTheCurrentTime(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->expects(self::once())->method('saveFlag')->with(
            'dmlab_typesense_indexer_invalidated_at',
            self::callback(fn ($at): bool => is_int($at) && abs($at - time()) <= 1)
        );

        (new ReindexInvalidationMarker($flagManager))->mark();
    }

    public function testConsumesAMarkRecordedSinceTheGivenTime(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(1000);
        $flagManager->expects(self::once())->method('deleteFlag')->with('dmlab_typesense_indexer_invalidated_at');

        self::assertTrue((new ReindexInvalidationMarker($flagManager))->consumeSince(1000));
    }

    public function testIgnoresAMarkOlderThanTheGivenTime(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(999);
        $flagManager->expects(self::never())->method('deleteFlag');

        self::assertFalse((new ReindexInvalidationMarker($flagManager))->consumeSince(1000));
    }

    public function testNoMarkIsNothingToConsume(): void
    {
        $flagManager = $this->createMock(FlagManager::class);
        $flagManager->method('getFlagData')->willReturn(null);
        $flagManager->expects(self::never())->method('deleteFlag');

        self::assertFalse((new ReindexInvalidationMarker($flagManager))->consumeSince(1000));
    }
}
