<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Synonym;

use DmLab\TypesenseCore\Exception\TypesenseException;
use DmLab\TypesenseCore\Model\Synonym\SynonymSetManager;
use DmLab\TypesenseIndexer\Model\ConnectionSettings;
use DmLab\TypesenseIndexer\Model\Indexer\IndexNameResolver;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymGroupReader;
use DmLab\TypesenseIndexer\Model\Synonym\SynonymSync;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynonymSyncTest extends TestCase
{
    /** @var SynonymSetManager&MockObject */
    private $setManager;

    /** @var SynonymGroupReader&MockObject */
    private $reader;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->setManager = $this->createMock(SynonymSetManager::class);
        $this->reader = $this->createStub(SynonymGroupReader::class);
        $this->storeManager = $this->createStub(StoreManagerInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        // Store N belongs to website N * 10.
        $this->storeManager->method('getStore')->willReturnCallback(
            fn (int $storeId): StoreInterface => $this->store($storeId)
        );
    }

    private function store(int $storeId): StoreInterface
    {
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn($storeId);
        $store->method('getWebsiteId')->willReturn($storeId * 10);

        return $store;
    }

    private function sync(): SynonymSync
    {
        $settings = $this->createStub(ConnectionSettings::class);
        $settings->method('getIndexPrefix')->willReturn('typesense');

        return new SynonymSync(
            $this->reader,
            $this->setManager,
            new IndexNameResolver($settings),
            $this->storeManager,
            $this->logger
        );
    }

    public function testUpsertsTheStoreSetWithGroupItems(): void
    {
        $this->reader = $this->createMock(SynonymGroupReader::class);
        $this->reader->expects(self::once())
            ->method('getForStore')
            ->with(2, 20)
            ->willReturn([3 => ['shirt', 'tee'], 7 => ['pants', 'trousers', 'slacks']]);

        $this->setManager->expects(self::once())
            ->method('upsert')
            ->with('typesense_catalogsearch_fulltext_2_synonyms', [
                ['id' => 'group_3', 'synonyms' => ['shirt', 'tee']],
                ['id' => 'group_7', 'synonyms' => ['pants', 'trousers', 'slacks']],
            ]);

        $this->sync()->syncStore(2);
    }

    public function testAnEmptySetIsStillUpserted(): void
    {
        $this->reader->method('getForStore')->willReturn([]);
        $this->setManager->expects(self::once())
            ->method('upsert')
            ->with('typesense_catalogsearch_fulltext_1_synonyms', []);

        $this->sync()->syncStore(1);
    }

    public function testSyncAllStoresUpsertsOneSetPerStore(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->store(1), $this->store(2)]);
        $this->reader->method('getForStore')->willReturn([]);

        $names = [];
        $this->setManager->expects(self::exactly(2))
            ->method('upsert')
            ->willReturnCallback(static function (string $name) use (&$names): void {
                $names[] = $name;
            });

        $this->sync()->syncAllStores();

        self::assertSame(
            ['typesense_catalogsearch_fulltext_1_synonyms', 'typesense_catalogsearch_fulltext_2_synonyms'],
            $names
        );
    }

    public function testSyncAllStoresLogsAFailingStoreAndSyncsTheRest(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->store(1), $this->store(2), $this->store(3)]);
        $this->reader->method('getForStore')->willReturn([]);

        $names = [];
        $this->setManager->expects(self::exactly(3))
            ->method('upsert')
            ->willReturnCallback(static function (string $name) use (&$names): void {
                $names[] = $name;
                if ($name === 'typesense_catalogsearch_fulltext_2_synonyms') {
                    throw new TypesenseException('down', 503);
                }
            });
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::once())
            ->method('error')
            ->with(self::logicalAnd(self::stringContains('store 2'), self::stringContains('down')));

        $this->sync()->syncAllStores();

        self::assertSame(
            [
                'typesense_catalogsearch_fulltext_1_synonyms',
                'typesense_catalogsearch_fulltext_2_synonyms',
                'typesense_catalogsearch_fulltext_3_synonyms',
            ],
            $names
        );
    }

    public function testSyncAllStoresLogsEveryFailingStore(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->store(1), $this->store(2)]);
        $this->reader->method('getForStore')->willThrowException(new \RuntimeException('db gone'));
        $this->setManager->expects(self::never())->method('upsert');

        $messages = [];
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->logger->expects(self::exactly(2))
            ->method('error')
            ->willReturnCallback(static function (string $message) use (&$messages): void {
                $messages[] = $message;
            });

        $this->sync()->syncAllStores();

        self::assertStringContainsString('store 1', $messages[0]);
        self::assertStringContainsString('store 2', $messages[1]);
    }

    public function testDeleteStoreDeletesItsSet(): void
    {
        $this->setManager->expects(self::once())
            ->method('delete')
            ->with('typesense_catalogsearch_fulltext_4_synonyms');

        $this->sync()->deleteStore(4);
    }
}
