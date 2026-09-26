<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Document\CompositeDocumentDataProvider;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentBuilderTest extends TestCase
{
    /**
     * @param FieldSpec[] $schema fields the schema policy declares for the store
     * @param DocumentDataProviderInterface[] $dataProviders the enrichment chain
     */
    private function builder(array $schema, array $dataProviders): DocumentBuilder
    {
        $fieldProvider = new class ($schema) implements FieldProviderInterface {
            /** @param FieldSpec[] $schema */
            public function __construct(private readonly array $schema)
            {
            }

            public function getFields(int $storeId): array
            {
                return $this->schema;
            }
        };

        return new DocumentBuilder(
            new CompositeDocumentDataProvider($dataProviders),
            new DesiredSchemaBuilder($fieldProvider)
        );
    }

    /**
     * @param callable(array<string,array<string,mixed>>,int):array<string,array<string,mixed>> $fn
     */
    private function dataProvider(callable $fn): DocumentDataProviderInterface
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

    public function testDocumentIdComesFromTheTraversableKeyNotAField(): void
    {
        // The raw payload carries a misleading `id` field; the real id is the stream key.
        $builder = $this->builder([new FieldSpec('id', 'string')], [
            $this->dataProvider(static function (array $docs): array {
                foreach ($docs as $id => $doc) {
                    $docs[$id]['id'] = 'WRONG-' . $id;
                }

                return $docs;
            }),
        ]);

        $documents = $builder->build(new \ArrayIterator([42 => ['x' => 1], 'ABC' => ['x' => 2]]), 1);

        // The canonical id is always the string form of the stream key (PHP coerces a
        // numeric array key back to int, so assert on the id field, not the map key).
        self::assertCount(2, $documents);
        self::assertSame('42', $documents['42']['id']);
        self::assertSame('ABC', $documents['ABC']['id']);
    }

    public function testSeedsStoreIdAndCastsItToTheSchemaType(): void
    {
        $builder = $this->builder([new FieldSpec('store_id', 'int32', facet: true)], []);

        $documents = $builder->build(new \ArrayIterator([42 => []]), 3);

        self::assertSame(3, $documents['42']['store_id']);
    }

    public function testCustomProvidersFieldsLandInTheDocument(): void
    {
        $builder = $this->builder([new FieldSpec('id', 'string')], [
            $this->dataProvider(static function (array $docs): array {
                $docs['42']['brand'] = 'Acme';

                return $docs;
            }),
        ]);

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertSame('Acme', $documents['42']['brand']);
    }

    public function testCastsDeclaredFieldsToTheirSchemaTypeAndLeavesUndeclaredKeysUntouched(): void
    {
        $builder = $this->builder(
            [
                new FieldSpec('id', 'string'),
                new FieldSpec('sku', 'string'),
                new FieldSpec('price_0_1', 'float', sort: true),
                new FieldSpec('category_ids', 'int64[]', facet: true),
            ],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['sku'] = 123;                       // declared string
                    $docs['42']['price_0_1'] = '19.99';             // declared float
                    $docs['42']['category_ids'] = ['3', '7'];       // declared int64[]
                    $docs['42']['internal_note'] = 'keep me as-is'; // undeclared → untouched (FAT on disk)

                    return $docs;
                }),
            ]
        );

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertSame('123', $documents['42']['sku']);
        self::assertSame(19.99, $documents['42']['price_0_1']);
        self::assertSame([3, 7], $documents['42']['category_ids']);
        self::assertSame('keep me as-is', $documents['42']['internal_note']);
    }

    public function testCastsBoolFloatListAndStringListFields(): void
    {
        $builder = $this->builder(
            [
                new FieldSpec('in_stock', 'bool', facet: true),
                new FieldSpec('prices', 'float[]', facet: true),
                new FieldSpec('tags', 'string[]', facet: true),
            ],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['in_stock'] = '1';           // declared bool
                    $docs['42']['prices'] = ['1', '2.5'];    // declared float[]
                    $docs['42']['tags'] = [5, 'red'];        // declared string[]

                    return $docs;
                }),
            ]
        );

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertTrue($documents['42']['in_stock']);
        self::assertSame([1.0, 2.5], $documents['42']['prices']);
        self::assertSame(['5', 'red'], $documents['42']['tags']);
    }

    /**
     * A multiselect attribute arrives as a comma-joined option-id string and must be split into
     * one facet value per option — otherwise layered navigation treats the whole string as a value.
     */
    public function testSplitsScalarStringIntoListForArrayField(): void
    {
        $builder = $this->builder(
            [new FieldSpec('color', 'string[]', facet: true)],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['color'] = '12,15,33'; // multiselect, comma-joined option ids

                    return $docs;
                }),
            ]
        );

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertSame(['12', '15', '33'], $documents['42']['color']);
    }

    /**
     * `(bool)` casting follows PHP's string rules — `'0'` is the one string that is falsey, so a
     * yes/no attribute stored as `'0'` becomes `false`, not `true`.
     */
    #[DataProvider('falseyBoolProvider')]
    public function testCastsFalseyBoolField(mixed $stored): void
    {
        $builder = $this->builder([new FieldSpec('in_stock', 'bool', facet: true)], [
            $this->dataProvider(static function (array $docs) use ($stored): array {
                $docs['42']['in_stock'] = $stored;

                return $docs;
            }),
        ]);

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertFalse($documents['42']['in_stock']);
    }

    /**
     * @return array<string,array{mixed}>
     */
    public static function falseyBoolProvider(): array
    {
        return [
            'int zero' => [0],
            'string zero' => ['0'],
            'empty string' => [''],
        ];
    }

    public function testWrapsAScalarIntoAListForAnArrayField(): void
    {
        $builder = $this->builder([new FieldSpec('category_ids', 'int64[]', facet: true)], [
            $this->dataProvider(static function (array $docs): array {
                $docs['42']['category_ids'] = '5';

                return $docs;
            }),
        ]);

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertSame([5], $documents['42']['category_ids']);
    }

    /**
     * A sparse attribute a product lacks arrives as `null`. Casting it would coerce the declared
     * facet/sort field to `0`/`""`, collapsing every such product into a bogus bucket. The builder
     * drops the key so the optional field is absent — matching Magento's ES mapper (`!== null`).
     */
    public function testDropsNullValuedFieldsInsteadOfCoercingThemToZero(): void
    {
        $builder = $this->builder(
            [
                new FieldSpec('material', 'int32', facet: true, sort: true),
                new FieldSpec('length', 'float', sort: true),
                new FieldSpec('label', 'string', facet: true),
                new FieldSpec('tags', 'string[]', facet: true),
            ],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['material'] = null;      // declared facet/sort → must be absent, not 0
                    $docs['42']['length'] = null;        // declared sort → must be absent, not 0.0
                    $docs['42']['label'] = null;         // declared facet → must be absent, not ""
                    $docs['42']['tags'] = null;          // declared array → must be absent, not [0]
                    $docs['42']['internal_note'] = null; // undeclared null → also dropped

                    return $docs;
                }),
            ]
        );

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertArrayNotHasKey('material', $documents['42']);
        self::assertArrayNotHasKey('length', $documents['42']);
        self::assertArrayNotHasKey('label', $documents['42']);
        self::assertArrayNotHasKey('tags', $documents['42']);
        self::assertArrayNotHasKey('internal_note', $documents['42']);
        // The id is still stamped.
        self::assertSame('42', $documents['42']['id']);
    }

    public function testProvidersRunInChainOrderAndCannotClobberTheId(): void
    {
        $builder = $this->builder([new FieldSpec('id', 'string')], [
            $this->dataProvider(static function (array $docs): array {
                $docs['42']['seq'] = 'a';

                return $docs;
            }),
            $this->dataProvider(static function (array $docs): array {
                $docs['42']['seq'] .= 'b';
                $docs['42']['id'] = 'clobbered';

                return $docs;
            }),
        ]);

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertSame('ab', $documents['42']['seq']);
        self::assertSame('42', $documents['42']['id'], 'the builder re-stamps the id after providers run');
    }
}
