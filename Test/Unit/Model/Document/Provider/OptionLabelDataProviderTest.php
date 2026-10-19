<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Unit\Model\Document\Provider;

use DmLab\TypesenseIndexer\Model\Document\Provider\OptionLabelDataProvider;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\OptionAttributeList;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use Magento\Eav\Model\Entity\Attribute\Source\AbstractSource;
use PHPUnit\Framework\TestCase;

class OptionLabelDataProviderTest extends TestCase
{
    /**
     * @param array<int,array<string,string>> $labelsByStore store ⇒ option id ⇒ label
     */
    private function source(array $labelsByStore, int &$store): AbstractSource
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturnCallback(static function () use ($labelsByStore, &$store) {
            $options = [];
            foreach ($labelsByStore[$store] ?? [] as $value => $label) {
                $options[] = ['value' => (string)$value, 'label' => $label];
            }

            return $options;
        });

        return $source;
    }

    /**
     * @param array<int,array<string,string>> $labelsByStore
     */
    private function attribute(string $code, string $input, array $labelsByStore = []): Attribute
    {
        $store = 0;
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getBackendType')->willReturn($input === 'select' ? 'int' : 'varchar');
        $attribute->method('setData')->willReturnCallback(
            static function ($key, $value = null) use (&$store, &$attribute) {
                if ($key === 'store_id') {
                    $store = (int)$value;
                }

                return $attribute;
            }
        );
        $attribute->method('getSource')->willReturn($this->source($labelsByStore, $store));

        return $attribute;
    }

    /**
     * @param Attribute[] $attributes
     */
    private function provider(array $attributes): OptionLabelDataProvider
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($attributes);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $policy = new AttributeFieldPolicy(new FieldNameResolver());

        return new OptionLabelDataProvider(new OptionAttributeList($factory, $policy), $policy);
    }

    public function testUsesTheStoreScopedLabel(): void
    {
        $provider = $this->provider([
            $this->attribute('manufacturer', 'select', [1 => ['12' => 'Acme'], 2 => ['12' => 'Acme DE']]),
        ]);

        $documents = $provider->addData(['42' => ['id' => '42', 'manufacturer' => [12]]], 2);

        self::assertSame(['Acme DE'], $documents['42']['manufacturer_value']);
        self::assertSame('Acme DE', $documents['42']['sort_manufacturer']);
    }

    public function testMultiselectGetsEveryLabelAndSortTakesTheFirst(): void
    {
        $provider = $this->provider([
            $this->attribute('material', 'multiselect', [1 => ['5' => 'Cotton', '7' => 'Wool', '9' => 'Silk']]),
        ]);

        $documents = $provider->addData(['42' => ['id' => '42', 'material' => ['7', '5', '7']]], 1);

        self::assertSame(['Wool', 'Cotton'], $documents['42']['material_value']);
        self::assertSame('Wool', $documents['42']['sort_material']);
    }

    public function testUnknownIdsAreSkipped(): void
    {
        $provider = $this->provider([
            $this->attribute('color', 'select', [1 => ['50' => 'Red']]),
            $this->attribute('size', 'select', [1 => ['60' => 'M']]),
        ]);

        $documents = $provider->addData(['42' => ['id' => '42', 'color' => [999, 50], 'size' => [999]]], 1);

        self::assertSame(['Red'], $documents['42']['color_value']);
        self::assertSame('Red', $documents['42']['sort_color']);
        self::assertArrayNotHasKey('size_value', $documents['42']);
        self::assertArrayNotHasKey('sort_size', $documents['42']);
    }

    public function testNonOptionAttributesAndAbsentValuesAreUntouched(): void
    {
        $provider = $this->provider([
            $this->attribute('name', 'text'),
            $this->attribute('color', 'select', [1 => ['50' => 'Red']]),
        ]);

        $document = ['id' => '42', 'name' => 'Tee', 'color' => []];

        self::assertSame(['42' => $document], $provider->addData(['42' => $document], 1));
    }

    public function testReadsOptionsNestedUnderAGroupedSource(): void
    {
        $source = $this->createStub(AbstractSource::class);
        $source->method('getAllOptions')->willReturn([
            ['label' => 'Warm', 'value' => [
                ['value' => '50', 'label' => 'Red'],
                ['value' => '51', 'label' => 'Orange'],
            ]],
            ['value' => '60', 'label' => 'Blue'],
        ]);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn('color');
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('getSource')->willReturn($source);

        $documents = $this->provider([$attribute])->addData(['1' => ['color' => [51, 60]]], 1);

        self::assertSame(['Orange', 'Blue'], $documents['1']['color_value']);
    }

    public function testSourceIsLoadedOncePerStoreAndAttribute(): void
    {
        $source = $this->createMock(AbstractSource::class);
        $source->expects(self::exactly(2))->method('getAllOptions')->with(false)
            ->willReturn([['value' => '50', 'label' => 'Red']]);
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn('color');
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('getSource')->willReturn($source);
        $provider = $this->provider([$attribute]);

        $batch = ['1' => ['color' => [50]], '2' => ['color' => [50]]];
        $provider->addData($batch, 1);
        $provider->addData($batch, 1);
        $documents = $provider->addData($batch, 2);

        self::assertSame(['Red'], $documents['2']['color_value']);
    }

    public function testResetReloadsLabelsSoALaterOptionIsLabelled(): void
    {
        $source = $this->createMock(AbstractSource::class);
        $source->expects(self::exactly(2))->method('getAllOptions')->willReturnOnConsecutiveCalls(
            [['value' => '50', 'label' => 'Red']],
            [['value' => '50', 'label' => 'Red'], ['value' => '51', 'label' => 'Teal']]
        );
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn('color');
        $attribute->method('getFrontendInput')->willReturn('select');
        $attribute->method('getBackendType')->willReturn('int');
        $attribute->method('getSource')->willReturn($source);
        $provider = $this->provider([$attribute]);

        $batch = ['1' => ['color' => [50, 51]]];
        self::assertSame(['Red'], $provider->addData($batch, 1)['1']['color_value']);
        $provider->reset();
        self::assertSame(['Red', 'Teal'], $provider->addData($batch, 1)['1']['color_value']);
    }
}
