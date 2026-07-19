<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Schema\Provider;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldNameResolver;
use MageDevGroup\TypesenseIndexer\Model\Schema\Provider\PriceFieldProvider;
use Magento\Customer\Model\ResourceModel\Group\Collection;
use Magento\Customer\Model\ResourceModel\Group\CollectionFactory;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;

class PriceFieldProviderTest extends TestCase
{
    /**
     * @param int[] $groupIds
     * @param array<int,int> $storeToWebsite storeId ⇒ websiteId
     */
    private function provider(array $groupIds, array $storeToWebsite): PriceFieldProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn($groupIds);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturnCallback(
            function (int $storeId) use ($storeToWebsite): StoreInterface {
                $store = $this->createStub(StoreInterface::class);
                $store->method('getWebsiteId')->willReturn($storeToWebsite[$storeId]);

                return $store;
            }
        );

        return new PriceFieldProvider($factory, $storeManager, new FieldNameResolver());
    }

    public function testProvidesAPriceFieldPerCustomerGroupForTheStoresWebsite(): void
    {
        $provider = $this->provider([0, 1], [1 => 1]);

        $byName = [];
        foreach ($provider->getFields(1) as $spec) {
            $byName[$spec->getName()] = $spec;
        }

        self::assertSame(['price_0_1', 'price_1_1'], array_keys($byName));
        self::assertSame('float', $byName['price_0_1']->getType());
        self::assertTrue($byName['price_0_1']->getSort());
        self::assertTrue($byName['price_0_1']->getFacet());
    }

    public function testDifferentStoresOnDifferentWebsitesYieldDifferentFields(): void
    {
        $provider = $this->provider([0], [1 => 1, 2 => 2]);

        $storeOne = array_map(static fn (FieldSpec $f) => $f->getName(), $provider->getFields(1));
        $storeTwo = array_map(static fn (FieldSpec $f) => $f->getName(), $provider->getFields(2));

        self::assertSame(['price_0_1'], $storeOne);
        self::assertSame(['price_0_2'], $storeTwo);
    }

    public function testNoGroupsYieldNoPriceFields(): void
    {
        self::assertSame([], $this->provider([], [1 => 1])->getFields(1));
    }
}
