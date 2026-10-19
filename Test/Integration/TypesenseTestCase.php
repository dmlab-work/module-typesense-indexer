<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Test\Integration;

use DmLab\TypesenseCore\Model\Collection\FieldSpec;
use DmLab\TypesenseIndexer\Model\Document\DocumentBuilder;
use DmLab\TypesenseIndexer\Model\Document\DocumentDataProviderInterface;
use DmLab\TypesenseIndexer\Model\Schema\AttributeFieldPolicy;
use DmLab\TypesenseIndexer\Model\Schema\AttributeTypeMap;
use DmLab\TypesenseIndexer\Model\Schema\CompositeFieldProvider;
use DmLab\TypesenseIndexer\Model\Schema\DesiredSchemaBuilder;
use DmLab\TypesenseIndexer\Model\Schema\FieldNameResolver;
use DmLab\TypesenseIndexer\Model\Schema\Provider\AttributeFieldProvider;
use DmLab\TypesenseIndexer\Model\Schema\Provider\CategoryFieldProvider;
use DmLab\TypesenseIndexer\Model\Schema\Provider\PriceFieldProvider;
use DmLab\TypesenseIndexer\Model\Schema\Provider\StaticFieldProvider;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\Collection;
use Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory;
use PHPUnit\Framework\TestCase;

/**
 * Raw HTTP against the Typesense at `TYPESENSE_TEST_URL`; skipped when the env is not set.
 *
 * Every collection is named `it_<8 hex>_<suffix>` and dropped in tearDown().
 */
abstract class TypesenseTestCase extends TestCase
{
    protected const STORE_ID = 1;

    /** @var string `it_<8 hex>_`, unique per test */
    protected string $prefix;

    /** @var string */
    private string $url;

    /** @var string */
    private string $apiKey;

    /** @var string[] collections created by this test */
    private array $created = [];

    protected function setUp(): void
    {
        $this->url = rtrim((string)getenv('TYPESENSE_TEST_URL'), '/');
        $this->apiKey = (string)getenv('TYPESENSE_TEST_API_KEY');
        if ($this->url === '' || $this->apiKey === '') {
            $this->markTestSkipped('Set TYPESENSE_TEST_URL and TYPESENSE_TEST_API_KEY to run integration tests.');
        }

        $this->prefix = 'it_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        foreach ($this->created as $name) {
            $this->request('DELETE', '/collections/' . rawurlencode($name));
        }
        $this->created = [];
    }

    /**
     * Create a collection named `<prefix><suffix>`; returns the full name.
     *
     * @param string $suffix
     * @param FieldSpec[] $fields
     */
    protected function createCollection(string $suffix, array $fields): string
    {
        $name = $this->prefix . $suffix;
        $this->created[] = $name;
        [$status, $body] = $this->request('POST', '/collections', [
            'name' => $name,
            'fields' => array_map(static fn (FieldSpec $f): array => $f->toArray(), $fields),
        ]);
        self::assertSame(201, $status, 'create collection: ' . json_encode($body));

        return $name;
    }

    /**
     * PATCH the collection schema.
     *
     * @param string $collection
     * @param array<int,array<string,mixed>> $fields
     */
    protected function patchCollection(string $collection, array $fields): void
    {
        [$status, $body] = $this->request('PATCH', '/collections/' . rawurlencode($collection), ['fields' => $fields]);
        self::assertSame(200, $status, 'patch collection: ' . json_encode($body));
    }

    /**
     * The collection as `GET /collections/:name` returns it.
     *
     * @param string $collection
     * @return array<string,mixed>
     */
    protected function getCollection(string $collection): array
    {
        [$status, $body] = $this->request('GET', '/collections/' . rawurlencode($collection));
        self::assertSame(200, $status, 'get collection: ' . json_encode($body));

        return $body;
    }

    /**
     * Upsert documents via JSONL import; asserts every line succeeded.
     *
     * @param string $collection
     * @param array<int|string,array<string,mixed>> $documents
     */
    protected function import(string $collection, array $documents): void
    {
        $jsonl = implode("\n", array_map(static fn (array $d): string => (string)json_encode($d), $documents));
        [$status, $body] = $this->request(
            'POST',
            '/collections/' . rawurlencode($collection) . '/documents/import?action=upsert',
            $jsonl
        );
        self::assertSame(200, $status);
        // A one-document import answers a single JSON line, which request() has already decoded.
        $lines = is_string($body) ? explode("\n", $body) : [(string)json_encode($body)];
        foreach ($lines as $line) {
            self::assertTrue((bool)(json_decode($line, true)['success'] ?? false), 'import: ' . $line);
        }
    }

    /**
     * Search the collection; returns [status, decoded body].
     *
     * @param string $collection
     * @param array<string,string> $params
     * @return array{0:int,1:mixed}
     */
    protected function search(string $collection, array $params): array
    {
        $params += ['q' => '*'];

        return $this->request(
            'GET',
            '/collections/' . rawurlencode($collection) . '/documents/search?' . http_build_query($params)
        );
    }

    /**
     * Search that must succeed; returns the decoded body.
     *
     * @param string $collection
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    protected function searchOk(string $collection, array $params): array
    {
        [$status, $body] = $this->search($collection, $params);
        self::assertSame(200, $status, 'search: ' . json_encode($body));

        return $body;
    }

    /**
     * Delete documents matching a filter; returns `num_deleted`.
     *
     * @param string $collection
     * @param string $filterBy
     */
    protected function deleteByFilter(string $collection, string $filterBy): int
    {
        [$status, $body] = $this->request(
            'DELETE',
            '/collections/' . rawurlencode($collection) . '/documents?' . http_build_query(['filter_by' => $filterBy])
        );
        self::assertSame(200, $status, 'delete: ' . json_encode($body));

        return (int)$body['num_deleted'];
    }

    /**
     * Ids of the hits, in order.
     *
     * @param array<string,mixed> $result
     * @return string[]
     */
    protected function hitIds(array $result): array
    {
        return array_map(static fn (array $hit): string => (string)$hit['document']['id'], $result['hits']);
    }

    /**
     * Facet value ⇒ count for one field.
     *
     * @param array<string,mixed> $result
     * @param string $field
     * @return array<string,int>
     */
    protected function facetCounts(array $result, string $field): array
    {
        foreach ($result['facet_counts'] as $facet) {
            if ($facet['field_name'] === $field) {
                $counts = array_column($facet['counts'], 'count', 'value');
                ksort($counts);

                return $counts;
            }
        }

        return [];
    }

    /**
     * The desired schema the real static, category, price and attribute providers compute.
     *
     * @param Attribute[] $attributes
     * @return FieldSpec[]
     */
    protected function desiredSchema(array $attributes): array
    {
        return $this->schemaBuilder($attributes)->build(self::STORE_ID);
    }

    /**
     * Raw values cast by the real {@see DocumentBuilder}, keyed by id.
     *
     * @param Attribute[] $attributes
     * @param array<int,array<string,mixed>> $raw id ⇒ values a data-provider chain would add
     * @return array<string,array<string,mixed>>
     */
    protected function buildDocuments(array $attributes, array $raw): array
    {
        $dataProvider = $this->createStub(DocumentDataProviderInterface::class);
        $dataProvider->method('addData')->willReturnCallback(
            static function (array $documents) use ($raw): array {
                foreach ($documents as $id => $document) {
                    $documents[$id] = $document + $raw[(int)$id];
                }

                return $documents;
            }
        );
        $typeMap = new AttributeTypeMap(
            $this->attributeFactory($attributes),
            new AttributeFieldPolicy(new FieldNameResolver()),
            $this->schemaBuilder($attributes)
        );

        return (new DocumentBuilder($dataProvider, $typeMap))
            ->build(new \ArrayIterator(array_fill_keys(array_keys($raw), [])), self::STORE_ID);
    }

    /**
     * @param Attribute[] $attributes
     */
    private function schemaBuilder(array $attributes): DesiredSchemaBuilder
    {
        $policy = new AttributeFieldPolicy(new FieldNameResolver());

        return new DesiredSchemaBuilder(new CompositeFieldProvider([
            new StaticFieldProvider(),
            new CategoryFieldProvider(),
            new PriceFieldProvider(),
            new AttributeFieldProvider($this->attributeFactory($attributes), $policy),
        ]));
    }

    /**
     * An attribute collection factory over the given attribute stubs.
     *
     * @param Attribute[] $attributes
     */
    protected function attributeFactory(array $attributes): CollectionFactory
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getItems')->willReturn($attributes);
        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        return $factory;
    }

    /**
     * An EAV attribute stub.
     *
     * @param string $code
     * @param string $backend
     * @param string $input
     * @param array<string,int> $flags `is_searchable`, `is_filterable`, `used_for_sort_by`
     */
    protected function attribute(string $code, string $backend, string $input, array $flags = []): Attribute
    {
        $attribute = $this->createStub(Attribute::class);
        $attribute->method('getAttributeCode')->willReturn($code);
        $attribute->method('getBackendType')->willReturn($backend);
        $attribute->method('getFrontendInput')->willReturn($input);
        $attribute->method('getSourceModel')->willReturn(null);
        $attribute->method('getIsSearchable')->willReturn($flags['is_searchable'] ?? 0);
        $attribute->method('getIsFilterable')->willReturn($flags['is_filterable'] ?? 0);
        $attribute->method('getIsFilterableInSearch')->willReturn(0);
        $attribute->method('getUsedForSortBy')->willReturn($flags['used_for_sort_by'] ?? 0);

        return $attribute;
    }

    /**
     * One HTTP call; returns [status, decoded JSON body (raw string when not JSON)].
     *
     * @param string $method
     * @param string $path
     * @param array<string,mixed>|string|null $body array → JSON, string → sent verbatim
     * @return array{0:int,1:mixed}
     */
    private function request(string $method, string $path, array|string|null $body = null): array
    {
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        $curl = curl_init($this->url . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['X-TYPESENSE-API-KEY: ' . $this->apiKey, 'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
        }
        $raw = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        // phpcs:enable Magento2.Functions.DiscouragedFunction
        self::assertIsString($raw, 'Typesense unreachable at ' . $this->url);

        $decoded = json_decode($raw, true);

        return [$status, $decoded ?? $raw];
    }
}
