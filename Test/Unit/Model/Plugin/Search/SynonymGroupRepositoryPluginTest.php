<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Plugin\Search;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseIndexer\Api\EngineCode;
use DmLab\TypesenseIndexer\Model\Plugin\Search\SynonymGroupRepositoryPlugin;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\Framework\Search\EngineResolverInterface;
use Magento\Search\Api\Data\SynonymGroupInterface;
use Magento\Search\Model\SynonymGroupRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynonymGroupRepositoryPluginTest extends TestCase
{
    private function plugin(
        SynonymSync $sync,
        string $engine,
        ?LoggerInterface $logger = null
    ): SynonymGroupRepositoryPlugin {
        $engineResolver = $this->createStub(EngineResolverInterface::class);
        $engineResolver->method('getCurrentSearchEngine')->willReturn($engine);

        return new SynonymGroupRepositoryPlugin(
            $sync,
            $engineResolver,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }

    public function testSaveResyncsAllStoresAndReturnsTheResultUnchanged(): void
    {
        $sync = $this->createMock(SynonymSync::class);
        $sync->expects(self::once())->method('syncAllStores');
        $group = $this->createStub(SynonymGroupInterface::class);

        $result = $this->plugin($sync, EngineCode::ENGINE)
            ->afterSave($this->createStub(SynonymGroupRepository::class), $group);

        self::assertSame($group, $result);
    }

    public function testDeleteResyncsAllStoresAndReturnsTheResultUnchanged(): void
    {
        $sync = $this->createMock(SynonymSync::class);
        $sync->expects(self::once())->method('syncAllStores');

        $result = $this->plugin($sync, EngineCode::ENGINE)
            ->afterDelete($this->createStub(SynonymGroupRepository::class), true);

        self::assertTrue($result);
    }

    public function testDoesNothingUnderAnotherEngine(): void
    {
        $sync = $this->createMock(SynonymSync::class);
        $sync->expects(self::never())->method('syncAllStores');
        $plugin = $this->plugin($sync, 'opensearch');
        $repository = $this->createStub(SynonymGroupRepository::class);

        self::assertTrue($plugin->afterDelete($repository, true));
        $plugin->afterSave($repository, null);
    }

    public function testSyncFailureIsLoggedAndSwallowed(): void
    {
        $sync = $this->createStub(SynonymSync::class);
        $sync->method('syncAllStores')->willThrowException(new TypesenseException('down', 503));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(self::stringContains('synonym sync failed'));

        $result = $this->plugin($sync, EngineCode::ENGINE, $logger)
            ->afterDelete($this->createStub(SynonymGroupRepository::class), true);

        self::assertTrue($result);
    }
}
