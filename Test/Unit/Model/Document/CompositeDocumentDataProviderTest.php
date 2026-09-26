<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document;

use DmLab\TypesenseIndexer\Model\Document\CompositeDocumentDataProvider;
use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use PHPUnit\Framework\TestCase;

class CompositeDocumentDataProviderTest extends TestCase
{
    /**
     * @param callable(array<string,array<string,mixed>>,int):array<string,array<string,mixed>> $fn
     */
    private function provider(callable $fn): DocumentDataProviderInterface
    {
        return new class ($fn) implements DocumentDataProviderInterface {
            /** @param callable $fn */
            public function __construct(private $fn)
            {
            }

            public function addData(array $documents, int $storeId): array
            {
                return ($this->fn)($documents, $storeId);
            }
        };
    }

    public function testChainsProvidersInOrderEachSeeingThePrevious(): void
    {
        $composite = new CompositeDocumentDataProvider([
            $this->provider(static function (array $docs): array {
                $docs['1']['a'] = 1;

                return $docs;
            }),
            $this->provider(static function (array $docs): array {
                // The second provider reads the field the first added, proving the chain.
                $docs['1']['b'] = $docs['1']['a'] + 1;

                return $docs;
            }),
        ]);

        $result = $composite->addData(['1' => ['id' => '1']], 1);

        self::assertSame(['id' => '1', 'a' => 1, 'b' => 2], $result['1']);
    }

    public function testStoreIdIsPassedThroughToEveryProvider(): void
    {
        $seen = [];
        $composite = new CompositeDocumentDataProvider([
            $this->provider(static function (array $docs, int $storeId) use (&$seen): array {
                $seen[] = $storeId;

                return $docs;
            }),
            $this->provider(static function (array $docs, int $storeId) use (&$seen): array {
                $seen[] = $storeId;

                return $docs;
            }),
        ]);

        $composite->addData(['1' => ['id' => '1']], 7);

        self::assertSame([7, 7], $seen);
    }

    public function testEmptyChainReturnsDocumentsUnchanged(): void
    {
        $composite = new CompositeDocumentDataProvider();

        self::assertSame(['1' => ['id' => '1']], $composite->addData(['1' => ['id' => '1']], 1));
    }

    public function testRejectsAProviderOfTheWrongType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CompositeDocumentDataProvider([new \stdClass()]);
    }
}
