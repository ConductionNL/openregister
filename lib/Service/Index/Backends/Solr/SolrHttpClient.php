<?php

/**
 * SolrHttpClient
 *
 * Handles HTTP client configuration and basic HTTP operations for Solr.
 * Responsible for building URLs, managing HTTP client, and making requests.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Solr
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Solr;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * SolrHttpClient
 *
 * Manages HTTP client and URL building for Solr operations.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Index\Backends\Solr
 */
class SolrHttpClient
{
    /**
     * HTTP client for Solr requests.
     *
     * @var GuzzleClient
     */
    private GuzzleClient $httpClient;

    /**
     * Solr connection configuration.
     *
     * @var array
     */
    private array $config = [];

    /**
     * Settings service.
     *
     * @var SettingsService
     */
    private readonly SettingsService $settingsService;

    /**
     * Logger.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     *
     * @return void
     */
    public function __construct(
        SettingsService $settingsService,
        LoggerInterface $logger
    ) {
        $this->settingsService = $settingsService;
        $this->logger          = $logger;

        $this->initializeConfig();
        $this->initializeHttpClient();
    }//end __construct()

    /**
     * Initialize Solr configuration from settings.
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        $settings = $this->settingsService->getSolrSettings();

        $this->config = [
            'enabled' => ($settings['enabled'] ?? false),
            'host'    => ($settings['host'] ?? 'localhost'),
            'port'    => ((int) ($settings['port'] ?? 8983)),
            'path'    => ($settings['path'] ?? '/solr'),
            'core'    => ($settings['core'] ?? 'openregister'),
            'timeout' => ((int) ($settings['timeout'] ?? 30)),
        ];
    }//end initializeConfig()

    /**
     * Initialize HTTP client for Solr requests.
     *
     * @return void
     */
    private function initializeHttpClient(): void
    {
        $this->httpClient = new GuzzleClient(
            [
                'timeout'         => $this->config['timeout'],
                'connect_timeout' => 5,
                'http_errors'     => false,
                'verify'          => false,
                'headers'         => [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
            ]
        );
    }//end initializeHttpClient()

    /**
     * Check if Solr is configured.
     *
     * @return bool True if configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->config['enabled'])
            && !empty($this->config['host'])
            && !empty($this->config['core']);
    }//end isConfigured()

    /**
     * Get Solr configuration.
     *
     * @return array Configuration array
     */
    public function getConfig(): array
    {
        return $this->config;
    }//end getConfig()

    /**
     * Get HTTP client.
     *
     * @return GuzzleClient HTTP client
     */
    public function getHttpClient(): GuzzleClient
    {
        return $this->httpClient;
    }//end getHttpClient()

    /**
     * Build base Solr URL.
     *
     * @return string Base Solr URL
     */
    public function buildSolrBaseUrl(): string
    {
        $host = $this->config['host'];
        $port = $this->config['port'];
        $path = $this->config['path'];

        return "http://{$host}:{$port}{$path}";
    }//end buildSolrBaseUrl()

    /**
     * Get endpoint URL for a collection.
     *
     * @param string|null $collection Collection name (null = use default core)
     *
     * @return string Endpoint URL
     */
    public function getEndpointUrl(?string $collection = null): string
    {
        $baseUrl = $this->buildSolrBaseUrl();
        $core    = $collection ?? $this->config['core'];

        return "{$baseUrl}/{$core}";
    }//end getEndpointUrl()

    /**
     * Make a GET request to Solr.
     *
     * @param string $url  URL to request
     * @param array  $opts Additional options
     *
     * @return array Response data
     *
     * @throws Exception If request fails
     */
    public function get(string $url, array $opts = []): array
    {
        try {
            $response = $this->httpClient->get($url, $opts);
            $body     = (string) $response->getBody();

            return json_decode($body, true) ?? [];
        } catch (Exception $e) {
            $this->logger->error('[SolrHttpClient] GET request failed', ['url' => $url, 'error' => $e->getMessage()]);
            throw $e;
        }//end try
    }//end get()

    /**
     * Make a POST request to Solr.
     *
     * @param string $url  URL to request
     * @param array  $data Data to send
     * @param array  $opts Additional options
     *
     * @return array Response data
     *
     * @throws Exception If request fails
     */
    public function post(string $url, array $data = [], array $opts = []): array
    {
        try {
            $opts['json'] = $data;
            $response     = $this->httpClient->post($url, $opts);
            $body         = (string) $response->getBody();

            return json_decode($body, true) ?? [];
        } catch (Exception $e) {
            $this->logger->error('[SolrHttpClient] POST request failed', ['url' => $url, 'error' => $e->getMessage()]);
            throw $e;
        }//end try
    }//end post()

    /**
     * Get tenant-specific collection name.
     *
     * Adds tenant prefix to collection name if multitenancy is enabled.
     *
     * @param string $baseCollectionName Base collection name
     *
     * @return string Tenant-specific collection name
     */
    public function getTenantSpecificCollectionName(string $baseCollectionName): string
    {
        // Get tenant from settings if multitenancy is enabled.
        $settings = $this->settingsService->getSettings();

        if (($settings['multitenancy']['enabled'] ?? false) === true) {
            $tenant = $settings['multitenancy']['tenant'] ?? 'default';
            return "{$tenant}_{$baseCollectionName}";
        }

        return $baseCollectionName;
    }//end getTenantSpecificCollectionName()
}//end class
