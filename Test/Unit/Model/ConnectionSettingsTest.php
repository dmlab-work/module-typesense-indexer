<?php
/**
 * Copyright © MageDevGroup. All rights reserved.
 */
declare(strict_types=1);

namespace MageDevGroup\TypesenseIndexer\Test\Unit\Model;

use MageDevGroup\TypesenseCore\Exception\ConfigurationException;
use MageDevGroup\TypesenseIndexer\Model\ConnectionSettings;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

// Both doubles are built once in setUp(); most cases stub reads, a few assert on decrypt().
#[AllowMockObjectsWithoutExpectations]
class ConnectionSettingsTest extends TestCase
{
    /** @var ScopeConfigInterface|MockObject */
    private MockObject $scopeConfig;

    /** @var EncryptorInterface|MockObject */
    private MockObject $encryptor;

    /** @var ConnectionSettings */
    private ConnectionSettings $settings;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->settings = new ConnectionSettings($this->scopeConfig, $this->encryptor);
    }

    /**
     * Stub the scope config with a path => value map; unlisted paths read as null.
     *
     * @param array<string,mixed> $values
     */
    private function stubConfig(array $values): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static fn(string $path) => $values[$path] ?? null);
    }

    public function testPrimaryNodeFromHostnameAndPort(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
        ]);

        $nodes = $this->settings->getNodes();

        self::assertCount(1, $nodes);
        self::assertSame('typesense', $nodes[0]->getHost());
        self::assertSame(8108, $nodes[0]->getPort());
    }

    public function testEveryNodeCarriesTheConfiguredProtocol(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'a.example',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
            ConnectionSettings::XML_PATH_ADDITIONAL_NODES => 'b.example:8109',
            ConnectionSettings::XML_PATH_PROTOCOL => 'https',
        ]);

        $nodes = $this->settings->getNodes();

        self::assertSame(['https', 'https'], array_map(static fn($node) => $node->getProtocol(), $nodes));
        self::assertSame('https://a.example:8108', $nodes[0]->getBaseUrl());
        self::assertSame('https://b.example:8109', $nodes[1]->getBaseUrl());
    }

    public function testNodesDefaultToHttpWhenNoProtocolIsConfigured(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
        ]);

        self::assertSame('http', $this->settings->getNodes()[0]->getProtocol());
    }

    public function testAdditionalNodesFollowThePrimaryInOrder(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'a.example',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
            ConnectionSettings::XML_PATH_ADDITIONAL_NODES => 'b.example:8109 ,c.example:9200',
        ]);

        $nodes = $this->settings->getNodes();

        self::assertCount(3, $nodes);
        self::assertSame(['a.example', 'b.example', 'c.example'], array_map(
            static fn($node) => $node->getHost(),
            $nodes
        ));
        self::assertSame([8108, 8109, 9200], array_map(static fn($node) => $node->getPort(), $nodes));
    }

    public function testMissingHostnameIsRejected(): void
    {
        $this->stubConfig([ConnectionSettings::XML_PATH_SERVER_PORT => '8108']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No Typesense server hostname configured');

        $this->settings->getNodes();
    }

    public function testMissingPortIsRejected(): void
    {
        $this->stubConfig([ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense']);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No Typesense server port configured');

        $this->settings->getNodes();
    }

    public function testNonNumericPortIsRejected(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => 'http',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('expected host:port');

        $this->settings->getNodes();
    }

    public function testOutOfRangePortIsRejected(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '70000',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('port must be between 1 and 65535');

        $this->settings->getNodes();
    }

    public function testHostnameCarryingASchemeIsRejected(): void
    {
        // The likeliest paste error: it parses as host "http://typesense" and would
        // otherwise build "http://http://typesense:8108".
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'http://typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('expected host:port without a scheme');

        $this->settings->getNodes();
    }

    public function testMalformedAdditionalNodeIsRejected(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'good',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
            ConnectionSettings::XML_PATH_ADDITIONAL_NODES => 'bad',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Malformed Typesense node "bad"');

        $this->settings->getNodes();
    }

    public function testAdditionalNodesOfOnlySeparatorsLeaveJustThePrimary(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
            ConnectionSettings::XML_PATH_ADDITIONAL_NODES => ' , , ',
        ]);

        self::assertCount(1, $this->settings->getNodes());
    }

    public function testUnsupportedProtocolIsRejected(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
            ConnectionSettings::XML_PATH_PROTOCOL => 'ftp',
        ]);

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('Unsupported Typesense protocol "ftp"');

        $this->settings->getNodes();
    }

    public function testProtocolIsNormalisedToLowerCase(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_SERVER_HOSTNAME => 'typesense',
            ConnectionSettings::XML_PATH_SERVER_PORT => '8108',
            ConnectionSettings::XML_PATH_PROTOCOL => 'HTTPS',
        ]);

        self::assertSame('https', $this->settings->getNodes()[0]->getProtocol());
    }

    public function testIndexPrefixIsRead(): void
    {
        $this->stubConfig([ConnectionSettings::XML_PATH_INDEX_PREFIX => 'shop']);

        self::assertSame('shop', $this->settings->getIndexPrefix());
    }

    public function testIndexPrefixDefaultsToTypesense(): void
    {
        $this->stubConfig([]);

        self::assertSame('typesense', $this->settings->getIndexPrefix());
    }

    public function testApiKeyIsDecrypted(): void
    {
        $this->stubConfig([ConnectionSettings::XML_PATH_API_KEY => 'ciphertext']);
        $this->encryptor->expects(self::once())
            ->method('decrypt')
            ->with('ciphertext')
            ->willReturn('xyz-admin-key');

        self::assertSame('xyz-admin-key', $this->settings->getApiKey());
    }

    public function testMissingApiKeyIsRejected(): void
    {
        $this->stubConfig([]);
        $this->encryptor->expects(self::never())->method('decrypt');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No Typesense admin API key configured');

        $this->settings->getApiKey();
    }

    public function testApiKeyDecryptingToEmptyIsRejected(): void
    {
        $this->stubConfig([ConnectionSettings::XML_PATH_API_KEY => 'ciphertext']);
        $this->encryptor->method('decrypt')->willReturn('');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No Typesense admin API key configured');

        $this->settings->getApiKey();
    }

    public function testNumericSettingsAreRead(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_CONNECTION_TIMEOUT => '12',
            ConnectionSettings::XML_PATH_OPERATION_TIMEOUT => '600',
            ConnectionSettings::XML_PATH_RETRY_COUNT => '4',
            ConnectionSettings::XML_PATH_HEALTH_CACHE_TTL => '0',
        ]);

        self::assertSame(12, $this->settings->getConnectionTimeout());
        self::assertSame(600, $this->settings->getOperationTimeout());
        self::assertSame(4, $this->settings->getRetryCount());
        self::assertSame(0, $this->settings->getHealthCacheTtl());
    }

    public function testNumericSettingsFallBackToDefaults(): void
    {
        $this->stubConfig([]);

        self::assertSame(5, $this->settings->getConnectionTimeout());
        self::assertSame(300, $this->settings->getOperationTimeout());
        self::assertSame(2, $this->settings->getRetryCount());
        self::assertSame(30, $this->settings->getHealthCacheTtl());
    }

    public function testNonNumericSettingsFallBackToDefaults(): void
    {
        $this->stubConfig([
            ConnectionSettings::XML_PATH_CONNECTION_TIMEOUT => 'soon',
            ConnectionSettings::XML_PATH_RETRY_COUNT => '-1',
            ConnectionSettings::XML_PATH_HEALTH_CACHE_TTL => '',
        ]);

        self::assertSame(5, $this->settings->getConnectionTimeout());
        self::assertSame(2, $this->settings->getRetryCount());
        self::assertSame(30, $this->settings->getHealthCacheTtl());
    }

    public function testZeroTimeoutFallsBackToDefault(): void
    {
        $this->stubConfig([ConnectionSettings::XML_PATH_CONNECTION_TIMEOUT => '0']);

        self::assertSame(5, $this->settings->getConnectionTimeout());
    }

    public function testConfigXmlSuppliesConnectionAndSchemaDefaults(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/etc/config.xml');
        self::assertNotFalse($xml);

        $search = $xml->default->catalog->search;
        self::assertSame('8108', (string)$search->typesense_server_port);
        self::assertSame('typesense', (string)$search->typesense_index_prefix);
        self::assertSame('5', (string)$search->typesense_server_timeout);
        self::assertSame('http', (string)$search->typesense_protocol);
        self::assertSame('300', (string)$search->typesense_operation_timeout);
        self::assertSame('2', (string)$search->typesense_retry_count);
        self::assertSame('30', (string)$search->typesense_health_cache_ttl);

        $schema = $xml->default->magedevgroup_typesense->schema;
        self::assertSame('500000', (string)$schema->rebuild_threshold);
        self::assertSame('auto', (string)$schema->decision_override);
    }
}
