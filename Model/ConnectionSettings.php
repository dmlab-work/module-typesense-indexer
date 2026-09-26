<?php
/**
 * Copyright © DMLab. All rights reserved.
 */
declare(strict_types=1);

namespace DmLab\TypesenseIndexer\Model;

use DmLab\TypesenseCore\Api\ConnectionSettingsInterface;
use DmLab\TypesenseCore\Exception\ConfigurationException;
use DmLab\TypesenseCore\Model\Config\Node;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

/**
 * Reads the Typesense connection from Magento config and satisfies core's contract.
 *
 * Core is pure transport and owns no config: indexer owns the `catalog/search/typesense_*`
 * paths and binds this reader as the real {@see ConnectionSettingsInterface}, overriding
 * core's throwing default. Builds the node list from the primary hostname/port plus optional
 * additional nodes into {@see Node} objects (each carrying the configured protocol), and
 * decrypts the admin API key (stored encrypted via the `Encrypted` backend model).
 *
 * The fields with an OpenSearch equivalent (hostname, port, index prefix, API key, server
 * timeout) live under Catalog Search — `catalog/search/typesense_*` — exactly where OpenSearch
 * keeps its own, gated on `engine=typesense`.
 *
 * The path constants are public so `typesense-search`'s `Setup\InstallConfig` can write the
 * connection at `setup:install --search-engine=typesense` against the same literal paths.
 */
class ConnectionSettings implements ConnectionSettingsInterface
{
    /** Primary node hostname; lives under Catalog Search like OpenSearch's server hostname. */
    public const XML_PATH_SERVER_HOSTNAME = 'catalog/search/typesense_server_hostname';

    /** Primary node port; lives under Catalog Search like OpenSearch's server port. */
    public const XML_PATH_SERVER_PORT = 'catalog/search/typesense_server_port';

    /** Collection-name prefix; mirrors OpenSearch's index prefix. */
    public const XML_PATH_INDEX_PREFIX = 'catalog/search/typesense_index_prefix';

    /** Optional extra `host:port` nodes for HA, tried after the primary. Under Catalog Search. */
    public const XML_PATH_ADDITIONAL_NODES = 'catalog/search/typesense_additional_nodes';

    /** Transport protocol, `http` or `https`. Under Catalog Search with the connection. */
    public const XML_PATH_PROTOCOL = 'catalog/search/typesense_protocol';

    /** Typesense admin API key, stored encrypted. Lives under Catalog Search as the auth credential. */
    public const XML_PATH_API_KEY = 'catalog/search/typesense_api_key';

    /** Per-node request timeout, seconds. Lives under Catalog Search like OpenSearch's server timeout. */
    public const XML_PATH_CONNECTION_TIMEOUT = 'catalog/search/typesense_server_timeout';

    /** Timeout for long-running writes (import, schema PATCH, delete by filter), seconds. Under Catalog Search. */
    public const XML_PATH_OPERATION_TIMEOUT = 'catalog/search/typesense_operation_timeout';

    /** Retries per request after the node list is exhausted. Under Catalog Search. */
    public const XML_PATH_RETRY_COUNT = 'catalog/search/typesense_retry_count';

    /** Seconds a health-check result stays cached. Under Catalog Search. */
    public const XML_PATH_HEALTH_CACHE_TTL = 'catalog/search/typesense_health_cache_ttl';

    /** Protocols the client can speak; the admin source model offers exactly these. */
    public const ALLOWED_PROTOCOLS = ['http', 'https'];

    private const DEFAULT_PROTOCOL = 'http';
    private const DEFAULT_CONNECTION_TIMEOUT = 5;
    private const DEFAULT_OPERATION_TIMEOUT = 300;
    private const DEFAULT_RETRY_COUNT = 2;
    private const DEFAULT_HEALTH_CACHE_TTL = 30;
    private const DEFAULT_INDEX_PREFIX = 'typesense';

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    /**
     * Configured nodes, primary first then any additional, each carrying the configured protocol.
     *
     * @return Node[]
     * @throws ConfigurationException when the primary is unset or any entry is malformed
     */
    public function getNodes(): array
    {
        $protocol = $this->getProtocol();

        $host = $this->readNonEmptyString(self::XML_PATH_SERVER_HOSTNAME);
        if ($host === null) {
            throw new ConfigurationException(
                'No Typesense server hostname configured: set ' . self::XML_PATH_SERVER_HOSTNAME . '.'
            );
        }

        $port = $this->readNonEmptyString(self::XML_PATH_SERVER_PORT);
        if ($port === null) {
            throw new ConfigurationException(
                'No Typesense server port configured: set ' . self::XML_PATH_SERVER_PORT . '.'
            );
        }

        // Reuse the host:port parser so the primary gets the same scheme/range validation
        // as the additional nodes.
        $nodes = [$this->parseNode($host . ':' . $port, $protocol)];

        $additional = $this->readNonEmptyString(self::XML_PATH_ADDITIONAL_NODES);
        if ($additional !== null) {
            foreach (explode(',', $additional) as $entry) {
                $entry = trim($entry);
                if ($entry === '') {
                    continue;
                }
                $nodes[] = $this->parseNode($entry, $protocol);
            }
        }

        return $nodes;
    }

    /**
     * Collection-name prefix, kept distinct from other engines' indices.
     */
    public function getIndexPrefix(): string
    {
        return $this->readNonEmptyString(self::XML_PATH_INDEX_PREFIX) ?? self::DEFAULT_INDEX_PREFIX;
    }

    /**
     * Decrypted admin API key.
     *
     * @throws ConfigurationException when unset
     */
    public function getApiKey(): string
    {
        $key = $this->readEncrypted(self::XML_PATH_API_KEY);
        if ($key === null) {
            throw new ConfigurationException(
                'No Typesense admin API key configured: set ' . self::XML_PATH_API_KEY . '.'
            );
        }

        return $key;
    }

    /**
     * Per-node request timeout in seconds.
     */
    public function getConnectionTimeout(): int
    {
        return $this->readPositiveInt(self::XML_PATH_CONNECTION_TIMEOUT, self::DEFAULT_CONNECTION_TIMEOUT);
    }

    /**
     * Timeout in seconds for writes the engine answers only once the work is done.
     *
     * A bulk import and a schema PATCH (which rebuilds the index from stored documents)
     * both run for as long as the batch or collection is big; the connection timeout is
     * sized for reads and would abort them mid-flight.
     */
    public function getOperationTimeout(): int
    {
        return $this->readPositiveInt(self::XML_PATH_OPERATION_TIMEOUT, self::DEFAULT_OPERATION_TIMEOUT);
    }

    /**
     * Retries per request after the node list is exhausted.
     */
    public function getRetryCount(): int
    {
        return $this->readNonNegativeInt(self::XML_PATH_RETRY_COUNT, self::DEFAULT_RETRY_COUNT);
    }

    /**
     * Seconds a health-check result stays cached; 0 disables caching.
     */
    public function getHealthCacheTtl(): int
    {
        return $this->readNonNegativeInt(self::XML_PATH_HEALTH_CACHE_TTL, self::DEFAULT_HEALTH_CACHE_TTL);
    }

    /**
     * Transport protocol applied to every node.
     *
     * @throws ConfigurationException when the configured value is not http or https
     */
    private function getProtocol(): string
    {
        $protocol = $this->readNonEmptyString(self::XML_PATH_PROTOCOL);
        if ($protocol === null) {
            return self::DEFAULT_PROTOCOL;
        }

        $protocol = strtolower($protocol);
        if (!in_array($protocol, self::ALLOWED_PROTOCOLS, true)) {
            throw new ConfigurationException(
                sprintf('Unsupported Typesense protocol "%s": expected http or https.', $protocol)
            );
        }

        return $protocol;
    }

    /**
     * Parse one `host:port` entry into a node reachable over the given protocol.
     *
     * @param string $entry
     * @param string $protocol
     * @throws ConfigurationException
     */
    private function parseNode(string $entry, string $protocol): Node
    {
        $separator = strrpos($entry, ':');
        if ($separator === false) {
            throw new ConfigurationException(
                sprintf('Malformed Typesense node "%s": expected host:port.', $entry)
            );
        }

        $host = trim(substr($entry, 0, $separator));
        $port = trim(substr($entry, $separator + 1));

        if ($host === '' || !preg_match('/^\d+$/', $port)) {
            throw new ConfigurationException(
                sprintf('Malformed Typesense node "%s": expected host:port.', $entry)
            );
        }

        // "http://host:8108" splits into a host of "http://host", which would otherwise
        // build a URL of "http://http://host:8108" and fail as an unhelpful connection error.
        if (str_contains($host, '/')) {
            throw new ConfigurationException(
                sprintf('Malformed Typesense node "%s": expected host:port without a scheme.', $entry)
            );
        }

        $portNumber = (int)$port;
        if ($portNumber < 1 || $portNumber > 65535) {
            throw new ConfigurationException(
                sprintf('Malformed Typesense node "%s": port must be between 1 and 65535.', $entry)
            );
        }

        return new Node($host, $portNumber, $protocol);
    }

    /**
     * Read and decrypt an encrypted config value, or null when unset.
     *
     * @param string $path
     */
    private function readEncrypted(string $path): ?string
    {
        $encrypted = $this->scopeConfig->getValue($path);
        $value = is_string($encrypted) && $encrypted !== '' ? trim($this->encryptor->decrypt($encrypted)) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Read a config value as a trimmed non-empty string, or null.
     *
     * @param string $path
     */
    private function readNonEmptyString(string $path): ?string
    {
        $value = $this->scopeConfig->getValue($path);
        $value = is_scalar($value) ? trim((string)$value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Read a config value as an int of at least 1, falling back on anything else.
     *
     * A zero timeout is a misconfiguration, not "no timeout", so it takes the default too.
     *
     * @param string $path
     * @param int $default
     */
    private function readPositiveInt(string $path, int $default): int
    {
        $value = $this->readNonNegativeInt($path, $default);

        return $value < 1 ? $default : $value;
    }

    /**
     * Read a config value as an int of at least 0, falling back on anything else.
     *
     * @param string $path
     * @param int $default
     */
    private function readNonNegativeInt(string $path, int $default): int
    {
        $raw = $this->readNonEmptyString($path);
        if ($raw === null || !preg_match('/^\d+$/', $raw)) {
            return $default;
        }

        return (int)$raw;
    }
}
