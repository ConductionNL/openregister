<?php

/**
 * ElasticsearchHttpClient
 *
 * Handles HTTP client configuration and basic HTTP operations for Elasticsearch.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Index\Backends\Elasticsearch
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Index\Backends\Elasticsearch;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use OCA\OpenRegister\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * ElasticsearchHttpClient
 *
 * Manages HTTP client and URL building for Elasticsearch operations.
 */
class ElasticsearchHttpClient
{

    private GuzzleClient $httpClient;

    private array $config = [];

    private readonly SettingsService $settingsService;

    private readonly LoggerInterface $logger;


    /**
     * Constructor
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
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
     * Initialize Elasticsearch configuration from settings.
     *
     * @return void
     */
    private function initializeConfig(): void
    {
        // For now, use hardcoded config since we don't have ES settings in SettingsService yet.
        $this->config = [
            'enabled' => true,
            'host'    => 'openregister-elasticsearch',
            'port'    => 9200,
            'scheme'  => 'http',
            'index'   => 'openregister',
            'timeout' => 30,
        ];

    }//end initializeConfig()


    /**
     * Initialize HTTP client for Elasticsearch requests.
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
     * Build Elasticsearch base URL.
     *
     * @return string Base URL
     */
    public function buildBaseUrl(): string
    {
        return sprintf(
            '%s://%s:%d',
            $this->config['scheme'],
            $this->config['host'],
            $this->config['port']
        );

    }//end buildBaseUrl()


    /**
     * Build endpoint URL for a specific index.
     *
     * @return string Endpoint URL
     */
    public function getEndpointUrl(string $index): string
    {
        return $this->buildBaseUrl().'/'.$index;

    }//end getEndpointUrl()


    /**
     * Execute GET request.
     *
     * @return array Response data
     */
    public function get(string $url): array
    {
        try {
            $response = $this->httpClient->get($url);
            $body     = (string) $response->getBody();
            return json_decode($body, true) ?: [];
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchHttpClient] GET failed',
                    [
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]
                    );
            throw $e;
        }

    }//end get()


    /**
     * Execute POST request.
     *
     * @return array Response data
     */
    public function post(string $url, array $data): array
    {
        try {
            $response = $this->httpClient->post(
                    $url,
                    [
                        'json' => $data,
                    ]
                    );
            $body     = (string) $response->getBody();
            return json_decode($body, true) ?: [];
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchHttpClient] POST failed',
                    [
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]
                    );
            throw $e;
        }

    }//end post()


    /**
     * Execute POST request with raw body (for bulk API).
     *
     * @return array Response data
     */
    public function postRaw(string $url, string $data): array
    {
        try {
            $response = $this->httpClient->post(
                    $url,
                    [
                        'body'    => $data,
                        'headers' => [
                            'Content-Type' => 'application/x-ndjson',
                        ],
                    ]
                    );
            $body     = (string) $response->getBody();
            return json_decode($body, true) ?: [];
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchHttpClient] POST (raw) failed',
                    [
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]
                    );
            throw $e;
        }//end try

    }//end postRaw()


    /**
     * Execute PUT request.
     *
     * @return array Response data
     */
    public function put(string $url, array $data): array
    {
        try {
            $response = $this->httpClient->put(
                    $url,
                    [
                        'json' => $data,
                    ]
                    );
            $body     = (string) $response->getBody();
            return json_decode($body, true) ?: [];
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchHttpClient] PUT failed',
                    [
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]
                    );
            throw $e;
        }

    }//end put()


    /**
     * Execute DELETE request.
     *
     * @return array Response data
     */
    public function delete(string $url): array
    {
        try {
            $response = $this->httpClient->delete($url);
            $body     = (string) $response->getBody();
            return json_decode($body, true) ?: [];
        } catch (Exception $e) {
            $this->logger->error(
                    '[ElasticsearchHttpClient] DELETE failed',
                    [
                        'url'   => $url,
                        'error' => $e->getMessage(),
                    ]
                    );
            throw $e;
        }

    }//end delete()


    /**
     * Get HTTP client instance.
     *
     * @return GuzzleClient HTTP client instance
     */
    public function getHttpClient(): GuzzleClient
    {
        return $this->httpClient;

    }//end getHttpClient()


    /**
     * Get Elasticsearch configuration.
     *
     * @return array Configuration array
     */
    public function getConfig(): array
    {
        return $this->config;

    }//end getConfig()


    /**
     * Check if Elasticsearch is configured.
     *
     * @return bool True if configured
     */
    public function isConfigured(): bool
    {
        return $this->config['enabled'] ?? false;

    }//end isConfigured()


}//end class
