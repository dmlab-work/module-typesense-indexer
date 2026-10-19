<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Integration;

use DmLab\TypesenseIndexer\Model\PublicCollection\PublicDocumentProjector;
use DmLab\TypesenseIndexer\Model\PublicCollection\PublicSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;

/**
 * The public collection (C2) exposes no customer-group price and stays searchable.
 */
class PublicCollectionTest extends TypesenseTestCase
{
    private const WEBSITE_ID = 1;

    /** @var string */
    private string $collection;

    /** @var array<int,array<string,mixed>> */
    private array $projected = [];

    protected function setUp(): void
    {
        parent::setUp();

        $attributes = [$this->attribute('manufacturer', 'int', 'select', ['is_searchable' => 1])];
        $factory = $this->attributeFactory($attributes);
        $policy = new AttributeFieldPolicy(new FieldNameResolver());
        $projector = new PublicDocumentProjector(new OptionAttributeList($factory, $policy), $policy);

        $main = $this->buildDocuments($attributes, [
            1 => [
                'sku' => 'MH01', 'name' => 'Hero Hoodie', 'status' => '1', 'visibility' => '4',
                'manufacturer' => '7', 'manufacturer_value' => ['Zeta'],
                'price_0_1' => '52.000000', 'price_1_1' => '47.000000', 'price_2_1' => '40.000000',
            ],
            2 => [
                'sku' => 'MH02', 'name' => 'Argus Jacket', 'status' => '1', 'visibility' => '4',
                'price_0_1' => '10.000000', 'price_1_1' => '9.000000',
            ],
        ]);
        foreach ($main as $document) {
            $this->projected[] = $projector->project($document, self::WEBSITE_ID);
        }

        $this->collection = $this->createCollection(
            'public',
            (new PublicSchemaBuilder($factory, $policy))->build(self::STORE_ID)
        );
        $this->import($this->collection, $this->projected);
    }

    public function testProjectedDocumentsCarryOnlyTheNotLoggedInPrice(): void
    {
        foreach ($this->projected as $document) {
            self::assertSame([], preg_grep('/^price_/', array_keys($document)));
        }
        self::assertSame(52.0, $this->projected[0]['price']);
    }

    public function testGroupPriceFieldDoesNotExist(): void
    {
        [$status] = $this->search($this->collection, ['facet_by' => 'price_1_1']);

        self::assertSame(404, $status);
    }

    public function testNameIsSearchable(): void
    {
        $result = $this->searchOk($this->collection, ['q' => 'hoodie', 'query_by' => 'name']);

        self::assertSame(['1'], $this->hitIds($result));
    }

    public function testOptionLabelIsSearchable(): void
    {
        $result = $this->searchOk($this->collection, ['q' => 'zeta', 'query_by' => 'manufacturer_value']);

        self::assertSame(['1'], $this->hitIds($result));
    }
}
