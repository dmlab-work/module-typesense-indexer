<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model\Schema;

use MageDevGroup\TypesenseCore\Model\Collection\FieldSpec;
use MageDevGroup\TypesenseIndexer\Model\Schema\CompositeFieldProvider;
use MageDevGroup\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use MageDevGroup\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use PHPUnit\Framework\TestCase;

class DesiredSchemaBuilderTest extends TestCase
{
    /**
     * A provider whose per-store output is supplied by a callback, so per-store
     * differences can be exercised.
     *
     * @param callable(int):FieldSpec[] $fn
     */
    private function provider(callable $fn): FieldProviderInterface
    {
        return new class ($fn) implements FieldProviderInterface {
            /** @param callable(int):FieldSpec[] $fn */
            public function __construct(private $fn)
            {
            }

            public function getFields(int $storeId): array
            {
                return ($this->fn)($storeId);
            }
        };
    }

    /**
     * @param FieldSpec[] $fields
     */
    private function fixed(array $fields): FieldProviderInterface
    {
        return $this->provider(static fn (int $storeId): array => $fields);
    }

    private function builder(FieldProviderInterface ...$providers): DesiredSchemaBuilder
    {
        return new DesiredSchemaBuilder(new CompositeFieldProvider($providers));
    }

    /**
     * @return array<string,FieldSpec>
     */
    private function byName(DesiredSchemaBuilder $builder, int $storeId = 1): array
    {
        $out = [];
        foreach ($builder->build($storeId) as $spec) {
            $out[$spec->getName()] = $spec;
        }

        return $out;
    }

    public function testResultIsSortedByNameDeterministically(): void
    {
        $builder = $this->builder(
            $this->fixed([new FieldSpec('sku', 'string'), new FieldSpec('color', 'string')]),
            $this->fixed([new FieldSpec('id', 'string'), new FieldSpec('brand', 'string')]),
        );

        $names = array_map(static fn (FieldSpec $f) => $f->getName(), $builder->build(1));

        self::assertSame(['brand', 'color', 'id', 'sku'], $names);
    }

    public function testDuplicateNameIsMergedWithLaterProviderWinning(): void
    {
        $builder = $this->builder(
            $this->fixed([new FieldSpec('color', 'string', facet: true)]),
            $this->fixed([new FieldSpec('color', 'string', sort: true)]),
        );

        $specs = $this->byName($builder);

        self::assertCount(1, $specs, 'the duplicate collapses to a single field');
        self::assertTrue($specs['color']->getFacet(), 'earlier flag is retained');
        self::assertTrue($specs['color']->getSort(), 'later flag is layered on');
    }

    public function testDeduplicationIsIndependentOfProviderOrder(): void
    {
        $a = $this->fixed([new FieldSpec('color', 'string', facet: true)]);
        $b = $this->fixed([new FieldSpec('color', 'string', sort: true)]);

        $forward = $this->byName($this->builder($a, $b));
        $reverse = $this->byName($this->builder($b, $a));

        self::assertSame(
            $forward['color']->toArray(),
            $reverse['color']->toArray(),
            'same fields, either order, produce the same merged spec'
        );
    }

    public function testPerStoreDifferencesAreReflected(): void
    {
        $priceLike = $this->provider(
            static fn (int $storeId): array => [new FieldSpec('price_0_' . $storeId, 'float', sort: true)]
        );
        $builder = $this->builder($this->fixed([new FieldSpec('sku', 'string')]), $priceLike);

        self::assertArrayHasKey('price_0_1', $this->byName($builder, 1));
        self::assertArrayHasKey('price_0_2', $this->byName($builder, 2));
    }

    public function testThirdPartyProviderIsIncludedAndCarriesExtra(): void
    {
        // The seam typesense-semantic uses: a di-merged provider declaring an engine
        // property this layer does not model (embed / model_config) via FieldSpec::extra.
        $semantic = $this->fixed([
            new FieldSpec(
                name: 'embedding',
                type: 'float[]',
                extra: ['num_dim' => 384, 'embed' => ['from' => ['name'], 'model_config' => ['model_name' => 'ts/e5']]]
            ),
        ]);
        $builder = $this->builder($this->fixed([new FieldSpec('sku', 'string')]), $semantic);

        $embedding = $this->byName($builder)['embedding'];

        self::assertSame('float[]', $embedding->getType());
        self::assertSame(384, $embedding->getExtra()['num_dim']);
        self::assertSame('ts/e5', $embedding->toArray()['embed']['model_config']['model_name']);
    }
}
