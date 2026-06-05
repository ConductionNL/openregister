<?php

/**
 * OpenRegister External Integration Router
 *
 * Dispatches CRUD operations to external services via OpenConnector sources.
 * Resolves the OpenConnector source by id, handles auth-status propagation,
 * and provides request-scoped caching so repeated same-object lookups
 * in one request hit memory instead of re-invoking OpenConnector.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use OCA\OpenRegister\Service\RequestScopedCache;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Routes CRUD operations to external services through OpenConnector.
 *
 * External integration providers delegate all operations here. This class
 * handles OpenConnector source resolution, rate-limit surfacing, auth-expiry
 * detection, and request-scoped caching per ADR-019 AD-2.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-3
 */
class ExternalIntegrationRouter
{

    /**
     * OpenConnector base URL config key.
     *
     * @var string
     */
    private const OPENCONNECTOR_BASE_URL = 'openconnector_base_url';

    /**
     * Default OpenConnector base URL.
     *
     * @var string
     */
    private const DEFAULT_BASE_URL = 'http://localhost:8080';

    /**
     * Cache namespace for external integration responses.
     *
     * @var string
     */
    private const CACHE_NAMESPACE = 'external_integration';

    /**
     * Constructor.
     *
     * @param Client             $httpClient HTTP client for OpenConnector calls.
     * @param RequestScopedCache $cache      Request-scoped cache (AD-2: no cross-request persistence).
     * @param IConfig            $config     Nextcloud config for base URL.
     * @param LoggerInterface    $logger     Logger.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function __construct(
        private readonly Client $httpClient,
        private readonly RequestScopedCache $cache,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List linked items for a given object via OpenConnector source.
     *
     * Returns cached response if the same object was fetched earlier in this request.
     *
     * @param string              $sourceId The OpenConnector source id (e.g. 'openproject').
     * @param string              $objectId The OpenRegister object UUID.
     * @param array<string,mixed> $params   Additional query parameters (filters, pagination).
     *
     * @return array{items: array, total: int, authStatus: string} Paginated result with auth status.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function listItems(string $sourceId, string $objectId, array $params=[]): array
    {
        $cacheKey = $sourceId.':'.$objectId.':'.md5(serialize(value: $params));

        if ($this->cache->has(namespace: self::CACHE_NAMESPACE, key: $cacheKey) === true) {
            // @var array{items: array, total: int, authStatus: string} $cached
            $cached = $this->cache->get(namespace: self::CACHE_NAMESPACE, key: $cacheKey);
            return $cached;
        }

        $result = $this->invokeOpenConnector(
            sourceId: $sourceId,
            operation: 'list',
            objectId: $objectId,
            data: $params
        );

        $this->cache->set(namespace: self::CACHE_NAMESPACE, key: $cacheKey, value: $result);

        return $result;
    }//end listItems()

    /**
     * Link an item to an object via OpenConnector source.
     *
     * @param string              $sourceId The OpenConnector source id.
     * @param string              $objectId The OpenRegister object UUID.
     * @param array<string,mixed> $data     Link data (e.g. workPackageId, url).
     *
     * @return array{item: array, authStatus: string} The linked item.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function linkItem(string $sourceId, string $objectId, array $data): array
    {
        return $this->invokeOpenConnector(
            sourceId: $sourceId,
            operation: 'link',
            objectId: $objectId,
            data: $data
        );
    }//end linkItem()

    /**
     * Unlink an item from an object via OpenConnector source.
     *
     * @param string $sourceId The OpenConnector source id.
     * @param string $objectId The OpenRegister object UUID.
     * @param string $itemId   The external item id to unlink.
     *
     * @return array{authStatus: string} Result with auth status.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function unlinkItem(string $sourceId, string $objectId, string $itemId): array
    {
        return $this->invokeOpenConnector(
            sourceId: $sourceId,
            operation: 'unlink',
            objectId: $objectId,
            data: ['itemId' => $itemId]
        );
    }//end unlinkItem()

    /**
     * Check the auth status for a given OpenConnector source.
     *
     * Returns one of: 'configured', 'expired', 'missing'.
     *
     * @param string $sourceId The OpenConnector source id.
     *
     * @return string The auth status.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function checkAuthStatus(string $sourceId): string
    {
        $cacheKey = 'auth_status:'.$sourceId;

        if ($this->cache->has(namespace: self::CACHE_NAMESPACE, key: $cacheKey) === true) {
            // @var string $status
            $status = $this->cache->get(namespace: self::CACHE_NAMESPACE, key: $cacheKey);
            return $status;
        }

        $baseUrl = $this->getBaseUrl();

        try {
            $response = $this->httpClient->get(
                uri: "{$baseUrl}/index.php/apps/openconnector/api/sources/{$sourceId}/auth-status",
                options: ['timeout' => 10]
            );

            $body   = json_decode(json: (string) $response->getBody(), associative: true) ?? [];
            $status = (string) ($body['status'] ?? 'configured');

            $this->cache->set(namespace: self::CACHE_NAMESPACE, key: $cacheKey, value: $status);

            return $status;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 404) {
                $this->cache->set(namespace: self::CACHE_NAMESPACE, key: $cacheKey, value: 'missing');
                return 'missing';
            }

            $this->logger->warning(
                message: '[ExternalIntegrationRouter] Auth status check failed for source',
                context: ['source' => $sourceId, 'error' => $e->getMessage()]
            );

            return 'missing';
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[ExternalIntegrationRouter] Auth status check failed for source',
                context: ['source' => $sourceId, 'error' => $e->getMessage()]
            );

            return 'missing';
        }//end try
    }//end checkAuthStatus()

    /**
     * Invoke an operation on OpenConnector for a given source and object.
     *
     * @param string              $sourceId  The OpenConnector source id.
     * @param string              $operation The operation to perform ('list', 'link', 'unlink').
     * @param string              $objectId  The OpenRegister object UUID.
     * @param array<string,mixed> $data      Operation data / parameters.
     *
     * @return array<string,mixed> The operation result including authStatus.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    private function invokeOpenConnector(
        string $sourceId,
        string $operation,
        string $objectId,
        array $data=[]
    ): array {
        $baseUrl = $this->getBaseUrl();

        $this->logger->info(
            message: '[ExternalIntegrationRouter] Invoking OpenConnector',
            context: [
                'source'    => $sourceId,
                'operation' => $operation,
                'objectId'  => $objectId,
            ]
        );

        try {
            $response = $this->httpClient->post(
                uri: "{$baseUrl}/index.php/apps/openconnector/api/sources/{$sourceId}/invoke",
                options: [
                    'json'    => [
                        'operation' => $operation,
                        'objectId'  => $objectId,
                        'data'      => $data,
                    ],
                    'timeout' => 30,
                ]
            );

            $body = json_decode(json: (string) $response->getBody(), associative: true) ?? [];

            return array_merge(
                ['items' => [], 'total' => 0, 'authStatus' => 'configured'],
                $body
            );
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();

            if ($statusCode === 401 || $statusCode === 403) {
                $this->logger->warning(
                    message: '[ExternalIntegrationRouter] Auth expired for source',
                    context: ['source' => $sourceId, 'status' => $statusCode]
                );

                return [
                    'items'      => [],
                    'total'      => 0,
                    'authStatus' => 'expired',
                    'error'      => 'Authentication expired. Reconnect in OpenConnector.',
                ];
            }

            if ($statusCode === 429) {
                $this->logger->warning(
                    message: '[ExternalIntegrationRouter] Rate-limited by source',
                    context: ['source' => $sourceId]
                );

                return [
                    'items'      => [],
                    'total'      => 0,
                    'authStatus' => 'degraded',
                    'error'      => 'Rate-limited, retrying…',
                ];
            }

            $this->logger->error(
                message: '[ExternalIntegrationRouter] OpenConnector invocation failed',
                context: ['source' => $sourceId, 'error' => $e->getMessage()]
            );

            return [
                'items'      => [],
                'total'      => 0,
                'authStatus' => 'unavailable',
                'error'      => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ExternalIntegrationRouter] OpenConnector invocation failed',
                context: ['source' => $sourceId, 'error' => $e->getMessage()]
            );

            return [
                'items'      => [],
                'total'      => 0,
                'authStatus' => 'unavailable',
                'error'      => $e->getMessage(),
            ];
        }//end try
    }//end invokeOpenConnector()

    /**
     * Get the OpenConnector base URL from configuration.
     *
     * @return string The base URL (without trailing slash).
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    private function getBaseUrl(): string
    {
        $url = $this->config->getSystemValue(valueName: self::OPENCONNECTOR_BASE_URL, default: self::DEFAULT_BASE_URL);
        return rtrim(string: (string) $url, characters: '/');
    }//end getBaseUrl()
}//end class
