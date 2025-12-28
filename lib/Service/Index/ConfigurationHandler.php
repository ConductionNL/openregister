<?php

/**
 * OpenRegister ConfigurationHandler
 *
 * Handles Solr configuration initialization, validation, and management.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Index
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index;

use GuzzleHttp\Client as GuzzleClient;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * Configuration Handler for Index Service
 *
 * Manages all configuration-related operations for the search backend including:
 * - Loading and validating configuration
 * - Initializing HTTP clients
 * - Building connection URLs
 * - Configuration status checks
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Index
 */
class ConfigurationHandler
{
    /**
     * Solr configuration array.
     *
     * @var array{enabled: bool, host?: string, port?: int|string, core?: string, path?: string, username?: string, password?: string}
     */
    private array $solrConfig = [];

    /**
     * HTTP client for Solr communication.
     *
     * @var GuzzleClient|null
     */
    private ?GuzzleClient $httpClient = null;

    /**
     * Constructor for ConfigurationHandler.
     *
     * @param SettingsService $settingsService Service for retrieving settings.
     * @param LoggerInterface $logger          Logger for configuration events.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger
    ) {
        $this->initializeConfig();
        $this->initializeHttpClient();
    }//end __construct()

    /**
     * Initialize SOLR configuration.
     *
     * Loads configuration from SettingsService and validates it.
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        try {
            // @psalm-suppress InvalidPropertyAssignmentValue - getSolrSettings() returns array with compatible shape.
            $this->solrConfig = $this->settingsService->getSolrSettings();
        } catch (Exception $e) {
            $this->logger->warning(message: 'Failed to load SOLR settings', context: ['error' => $e->getMessage()]);
            /*
             * @psalm-suppress InvalidPropertyAssignmentValue - ['enabled' => false] is compatible with solrConfig type
             */

            $this->solrConfig = ['enabled' => false];
        }
    }//end initializeConfig()

    /**
     * Initialize HTTP client with authentication support.
     *
     * Creates a Guzzle HTTP client configured with:
     * - Timeouts for connection and requests.
     * - SSL certificate verification settings.
     * - HTTP Basic Authentication if credentials are configured.
     *
     * @return void
     */
    private function initializeHttpClient(): void
    {
        // Prepare Guzzle client configuration.
        // Allow self-signed certificates.
        // Don't throw exceptions for 4xx/5xx responses.
        $clientConfig = [
            'timeout'         => 30,
            'connect_timeout' => 10,
            'verify'          => false,
            'allow_redirects' => true,
            'http_errors'     => false,
        ];

        // Add HTTP Basic Authentication if credentials are provided.
        if (empty($this->solrConfig['username']) === false && empty($this->solrConfig['password']) === false) {
            $clientConfig['auth'] = [
                $this->solrConfig['username'],
                $this->solrConfig['password'],
                'basic',
            ];

            $this->logger->info(
                'ConfigurationHandler: HTTP Basic Authentication configured',
                [
                        'username'  => $this->solrConfig['username'],
                        'auth_type' => 'basic',
                    ]
            );
        }

        // TODO: Switch back to Nextcloud HTTP client when local access restrictions are properly configured.
        // Currently using direct Guzzle client to bypass Nextcloud's 'allow_local_address' restrictions.
        // Future improvement: $this->httpClient = $clientService->newClient(['allow_local_address' => true]).
        // This is necessary for SOLR/Zookeeper connections in Kubernetes environments.
        $this->httpClient = new GuzzleClient($clientConfig);
    }//end initializeHttpClient()

    /**
     * Check if Solr is properly configured.
     *
     * Validates that all required configuration parameters are present.
     *
     * @return bool True if Solr is configured, false otherwise.
     */
    public function isSolrConfigured(): bool
    {
        // Check if SOLR is enabled in settings.
        if (($this->solrConfig['enabled'] ?? false) !== true) {
            return false;
        }

        // Check if required configuration parameters are present.
        if (empty($this->solrConfig['host']) === true || empty($this->solrConfig['core']) === true) {
            return false;
        }

        return true;
    }//end isSolrConfigured()

    /**
     * Get tenant-specific collection name (legacy method, currently returns base name).
     *
     * Previously handled multi-tenancy by appending tenant identifiers.
     * Now simplified to return the base collection name.
     *
     * @param string $baseCollectionName Base collection name.
     *
     * @return string Collection name.
     */
    public function getTenantSpecificCollectionName(string $baseCollectionName): string
    {
        // Simply return the collection name without any tenant suffix.
        return $baseCollectionName;
    }//end getTenantSpecificCollectionName()

    /**
     * Build SOLR base URL from configuration.
     *
     * Constructs the base URL for Solr API calls using the configured
     * host, port, and path settings.
     *
     * @return string SOLR base URL.
     */
    public function buildSolrBaseUrl(): string
    {
        $host = $this->solrConfig['host'] ?? 'localhost';
        $port = $this->solrConfig['port'] ?? null;

        // Normalize port - convert string '0' to null, handle empty strings.
        if ($port === '0' || $port === '' || $port === null) {
            $port = null;
        } else {
            $port = (int) $port;

            if ($port === 0) {
                $port = null;
            }
        }

        // Allow custom path for reverse proxies or non-standard setups.
        $path = $this->solrConfig['path'] ?? '';
        if (empty($path) === false) {
            $path = '/' . ltrim($path, '/');
        }

        // Build protocol-relative or absolute URL based on configuration.
        $scheme = $this->solrConfig['scheme'] ?? 'http';

        // If port is specified, include it in the URL.
        if ($port !== null) {
            return sprintf('%s://%s:%d%s', $scheme, $host, $port, $path);
        }

        // No port specified - use default for the scheme.
        return sprintf('%s://%s%s', $scheme, $host, $path);
    }//end buildSolrBaseUrl()

    /**
     * Get the configured HTTP client.
     *
     * @return GuzzleClient|null HTTP client instance.
     */
    public function getHttpClient(): GuzzleClient|null
    {
        return $this->httpClient;
    }//end getHttpClient()

    /**
     * Get the Solr configuration array.
     *
     * @return (bool|int|string)[] Solr configuration.
     *
     * @psalm-return array{enabled: bool, host?: string, port?: int|string, core?: string, path?: string, username?: string, password?: string}
     */
    public function getSolrConfig(): array
    {
        return $this->solrConfig;
    }//end getSolrConfig()

    /**
     * Get the endpoint URL for a specific collection.
     *
     * @param string|null $collection Optional collection name.
     *
     * @return string Full endpoint URL.
     */
    public function getEndpointUrl(?string $collection = null): string
    {
        $baseUrl = $this->buildSolrBaseUrl();
        $core    = $collection ?? $this->solrConfig['core'] ?? 'openregister';

        return $baseUrl . '/solr/' . $core;
    }//end getEndpointUrl()

    /**
     * Get configuration status for a specific setting.
     *
     * @param string $key Configuration key to check.
     *
     * @return string Status description.
     *
     * @psalm-return '✓ Configured'|'✗ Not configured'
     */
    public function getConfigStatus(string $key): string
    {
        if (isset($this->solrConfig[$key]) === true && empty($this->solrConfig[$key]) === false) {
            return '✓ Configured';
        }

        return '✗ Not configured';
    }//end getConfigStatus()

    /**
     * Get port configuration status.
     *
     * @return string Port status description.
     */
    public function getPortStatus(): string
    {
        $port = $this->solrConfig['port'] ?? null;

        if ($port === null || $port === '' || $port === '0') {
            return '✓ Using default port';
        }

        return '✓ Port ' . $port;
    }//end getPortStatus()

    /**
     * Get core configuration status.
     *
     * @return string Core status description.
     */
    public function getCoreStatus(): string
    {
        $core = $this->solrConfig['core'] ?? 'openregister';
        return '✓ Core: ' . $core;
    }//end getCoreStatus()
}//end class
