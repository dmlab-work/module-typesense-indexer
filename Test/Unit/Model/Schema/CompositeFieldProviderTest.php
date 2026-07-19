<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Schema;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use MageDevGroup\TypesenseIndexer\Model\Schema\CompositeFieldProvider;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use PHPUnit\Framework\TestCase;

class CompositeFieldProviderTest extends TestCase
{
    /**
     * A provider stub returning a fixed list of specs, recording the store it was asked for.
     *
     * @param FieldSpec[] $fields
     */
    private function provider(array $fields): FieldProviderInterface
    {
        return new class ($fields) implements FieldProviderInterface {
            /** @param FieldSpec[] $fields */
            public function __construct(private array $fields)
            {
            }

            public function getFields(int $storeId): array
            {
                return $this->fields;
            }
        };
    }

    public function testConcatenatesEveryProvidersFields(): void
    {
        $composite = new CompositeFieldProvider([
            $this->provider([new FieldSpec('a', 'string')]),
            $this->provider([new FieldSpec('b', 'int32'), new FieldSpec('c', 'float')]),
        ]);

        $names = array_map(static fn (FieldSpec $f) => $f->getName(), $composite->getFields(1));

        self::assertSame(['a', 'b', 'c'], $names);
    }

    public function testEmptyWhenNoProviders(): void
    {
        self::assertSame([], (new CompositeFieldProvider())->getFields(1));
    }

    public function testPassesStoreIdThrough(): void
    {
        $spy = new class implements FieldProviderInterface {
            /**
             * @var int
             */
            public int $seenStore = -1;

            public function getFields(int $storeId): array
            {
                $this->seenStore = $storeId;

                return [];
            }
        };

        (new CompositeFieldProvider([$spy]))->getFields(7);

        self::assertSame(7, $spy->seenStore);
    }

    public function testRejectsNonProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentional wrong type */
        new CompositeFieldProvider([new \stdClass()]);
    }
}
