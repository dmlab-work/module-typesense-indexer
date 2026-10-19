<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Document\CompositeDocumentDataProvider;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use DmLab\TypesenseIndexer\Model\Document\Provider\ChildAttributeDataProvider;
use DmLab\TypesenseIndexer\Model\Document\Provider\StockDataProvider;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\AttributeTypeMap;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\FieldProviderInterface;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection as AttributeCollection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory as AttributeCollectionFactory;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DocumentBuilderTest extends TestCase
{
    /**
     * @param FieldSpec[] $schema fields the schema policy declares for the store
     * @param DocumentDataProviderInterface[] $dataProviders the enrichment chain
     * @param array<string,array{0:string,1:string}> $attributes code ⇒ [backend_type, frontend_input]
     */
    private function builder(array $schema, array $dataProviders, array $attributes = []): DocumentBuilder
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

        $items = [];
        foreach ($attributes as $code => [$backend, $input]) {
            $attribute = $this->createStub(Attribute::class);
            $attribute->method('getAttributeCode')->willReturn($code);
            $attribute->method('getBackendType')->willReturn($backend);
            $attribute->method('getFrontendInput')->willReturn($input);
            $items[] = $attribute;
        }
        $collection = $this->createStub(AttributeCollection::class);
        $collection->method('getItems')->willReturn($items);
        $factory = $this->createStub(AttributeCollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return new DocumentBuilder(
            new CompositeDocumentDataProvider($dataProviders),
            new AttributeTypeMap(
                $factory,
                new AttributeFieldPolicy(new FieldNameResolver()),
                new DesiredSchemaBuilder($fieldProvider)
            )
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

    public function testCastsUndeclaredAttributeValuesToTheirPolicyType(): void
    {
        $builder = $this->builder(
            [],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['price'] = '52.000000';
                    $docs['42']['eco_collection'] = '1';
                    $docs['42']['manufacturer'] = '12';
                    $docs['42']['material'] = '12,15';
                    $docs['42']['type_id'] = 'simple';

                    return $docs;
                }),
            ],
            [
                'price' => ['decimal', 'price'],
                'eco_collection' => ['int', 'boolean'],
                'manufacturer' => ['int', 'select'],
                'material' => ['varchar', 'multiselect'],
            ]
        );

        $document = $builder->build(new \ArrayIterator([42 => []]), 1)['42'];

        self::assertSame(52.0, $document['price']);
        self::assertSame(1, $document['eco_collection']);
        self::assertSame([12], $document['manufacturer']);
        self::assertSame([12, 15], $document['material']);
        self::assertSame('simple', $document['type_id']);
    }

    public function testDeclaredTypeWinsOverPolicyTypeAndCollapsesAnOptionList(): void
    {
        // `visibility` is an int select (policy `int32[]`) but StaticFieldProvider declares it `int32`.
        $builder = $this->builder(
            [new FieldSpec('visibility', 'int32', facet: true)],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['visibility'] = ['4'];
                    $docs['43']['visibility'] = [];

                    return $docs;
                }),
            ],
            ['visibility' => ['int', 'select']]
        );

        $documents = $builder->build(new \ArrayIterator([42 => [], 43 => []]), 1);

        self::assertSame(4, $documents['42']['visibility']);
        self::assertArrayNotHasKey('visibility', $documents['43']);
    }

    public function testStructuredFieldTypesKeepTheirArrayValues(): void
    {
        $builder = $this->builder(
            [
                new FieldSpec('location', 'geopoint', optional: true),
                new FieldSpec('meta', 'object', optional: true),
                new FieldSpec('extra', 'auto', optional: true),
            ],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['location'] = [50.45, 30.52];
                    $docs['42']['meta'] = ['brand' => 'X'];
                    $docs['42']['extra'] = ['a', 'b'];

                    return $docs;
                }),
            ]
        );

        $documents = $builder->build(new \ArrayIterator([42 => []]), 1);

        self::assertSame([50.45, 30.52], $documents['42']['location']);
        self::assertSame(['brand' => 'X'], $documents['42']['meta']);
        self::assertSame(['a', 'b'], $documents['42']['extra']);
    }

    public function testDropsEmptyEntriesFromIntLists(): void
    {
        $builder = $this->builder(
            [],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['material'] = ['12', '', null, '15'];
                    $docs['43']['material'] = '';

                    return $docs;
                }),
            ],
            ['material' => ['varchar', 'multiselect']]
        );

        $documents = $builder->build(new \ArrayIterator([42 => [], 43 => []]), 1);

        self::assertSame([12, 15], $documents['42']['material']);
        self::assertSame([], $documents['43']['material']);
    }

    public function testUndeclaredScopedKeysTakeTheTypeOfTheMatchingRegexField(): void
    {
        $builder = $this->builder(
            [
                new FieldSpec('price_[0-9]+_.*', 'float', facet: true, sort: true, optional: true),
                new FieldSpec('position_category_.*', 'int32', sort: true, optional: true),
            ],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['price_7_3'] = '19.990000';
                    $docs['42']['position_category_999'] = '5';
                    $docs['42']['pricey'] = '1';

                    return $docs;
                }),
            ]
        );

        $document = $builder->build(new \ArrayIterator([42 => []]), 1)['42'];

        self::assertSame(19.99, $document['price_7_3']);
        self::assertSame(5, $document['position_category_999']);
        self::assertSame('1', $document['pricey'], 'a key matching no regex stays untouched');
    }

    public function testExactTypeWinsOverAMatchingRegexField(): void
    {
        $builder = $this->builder(
            [new FieldSpec('price_[0-9]+_.*', 'float', optional: true)],
            [
                $this->dataProvider(static function (array $docs): array {
                    $docs['42']['price_view'] = '3';

                    return $docs;
                }),
            ],
            ['price_view' => ['varchar', 'text']]
        );

        self::assertSame('3', $builder->build(new \ArrayIterator([42 => []]), 1)['42']['price_view']);
    }

    public function testCompositeParentCarriesItsChildrenOptionIdsAsAnIntList(): void
    {
        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchAll')->willReturn([
            ['parent_id' => 62, 'child_id' => 63],
            ['parent_id' => 62, 'child_id' => 64],
        ]);
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $children = [];
        foreach ([63 => '49', 64 => '50'] as $id => $color) {
            $child = $this->createStub(Product::class);
            $child->method('getId')->willReturn($id);
            $child->method('getData')->willReturnCallback(static fn ($key = '') => $key === 'color' ? $color : null);
            $children[] = $child;
        }
        $childCollection = $this->createStub(ProductCollection::class);
        $childCollection->method('getItems')->willReturn($children);
        $productFactory = $this->createStub(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturn($childCollection);

        $color = $this->createStub(Attribute::class);
        $color->method('getAttributeCode')->willReturn('color');
        $color->method('getFrontendInput')->willReturn('select');
        $color->method('getBackendType')->willReturn('int');
        $attributes = $this->createStub(AttributeCollection::class);
        $attributes->method('getItems')->willReturn([$color]);
        $attributeFactory = $this->createStub(AttributeCollectionFactory::class);
        $attributeFactory->method('create')->willReturn($attributes);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getWebsiteId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $stockConfiguration = $this->createStub(StockConfigurationInterface::class);
        $stockConfiguration->method('isShowOutOfStock')->willReturn(true);

        $builder = $this->builder(
            [],
            [
                // The parent's own EAV value, as AttributeDataProvider writes it.
                $this->dataProvider(static function (array $docs): array {
                    $docs['62']['color'] = ['49'];

                    return $docs;
                }),
                new ChildAttributeDataProvider(
                    $resource,
                    $productFactory,
                    new OptionAttributeList($attributeFactory, new AttributeFieldPolicy(new FieldNameResolver())),
                    $storeManager,
                    $stockConfiguration,
                    $this->createStub(StockDataProvider::class)
                ),
            ],
            ['color' => ['int', 'select']]
        );

        self::assertSame([49, 50], $builder->build(new \ArrayIterator([62 => []]), 1)['62']['color']);
    }
}
