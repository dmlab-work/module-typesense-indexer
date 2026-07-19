<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Schema\Provider;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldNameResolver;
use MageDevGroup\TypesenseIndexer\Model\Schema\Provider\CategoryFieldProvider;
use Magento\Catalog\Model\ResourceModel\Category\Collection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory;
use PHPUnit\Framework\TestCase;

class CategoryFieldProviderTest extends TestCase
{
    /**
     * @param int[] $categoryIds
     * @param null|\Closure(int):void $onSetStoreId records the store the collection was scoped to
     */
    private function provider(array $categoryIds, ?\Closure $onSetStoreId = null): CategoryFieldProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getAllIds')->willReturn($categoryIds);
        if ($onSetStoreId !== null) {
            $collection->method('setStoreId')->willReturnCallback($onSetStoreId);
        }

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new CategoryFieldProvider($factory, new FieldNameResolver());
    }

    public function testAlwaysProvidesCategoryIdsFacet(): void
    {
        $spec = null;
        foreach ($this->provider([])->getFields(1) as $field) {
            if ($field->getName() === 'category_ids') {
                $spec = $field;
            }
        }

        self::assertInstanceOf(FieldSpec::class, $spec);
        self::assertSame('int64[]', $spec->getType());
        self::assertTrue($spec->getFacet());
    }

    public function testProvidesPositionFieldPerCategory(): void
    {
        $names = array_map(
            static fn (FieldSpec $f) => $f->getName(),
            $this->provider([3, 5])->getFields(1)
        );

        self::assertContains('position_category_3', $names);
        self::assertContains('position_category_5', $names);
    }

    public function testScopesTheCategorySetToTheStore(): void
    {
        $seen = null;
        $this->provider([], static function (int $storeId) use (&$seen): void {
            $seen = $storeId;
        })->getFields(4);

        self::assertSame(4, $seen);
    }

    public function testPositionFieldsAreSortable(): void
    {
        $position = null;
        foreach ($this->provider([7])->getFields(1) as $field) {
            if ($field->getName() === 'position_category_7') {
                $position = $field;
            }
        }

        self::assertNotNull($position);
        self::assertTrue($position->getSort());
        self::assertSame('int32', $position->getType());
    }
}
