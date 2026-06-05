<?php

/**
 * External Integration Router
 *
 * Routes CRUD operations for external integration providers to the OpenConnector
 * app via its REST API. Providers with storage='external' delegate all calls here;
 * they carry no HTTP client and no credentials.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Routes external integration calls to OpenConnector.
 *
 * The provider declares which OpenConnector source to target. This router
 * resolves the base URL from OpenConnector's configuration (via appConfig or
 * a well-known loopback URL) and dispatches list/get/create/update/delete
 * operations. Auth credentials are held by OpenConnector — this router
 * carries none.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ExternalIntegrationRouter
{

    /**
     * Default loopback base URL for OpenConnector on the same NC instance.
     *
     * @var string
     */
    private const OC_BASE_PATH = '/index.php/apps/openconnector/api';

    /**
     * Constructor.
     *
     * @param IClientService  $clientService HTTP client factory.
     * @param IAppManager     $appManager    App manager for availability checks.
     * @param LoggerInterface $logger        Logger.
     */
    public function __construct(
        private readonly IClientService $clientService,
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Execute an operation against an OpenConnector source.
     *
     * @param string              $source    OpenConnector source identifier (e.g. 'xwiki').
     * @param string              $operation One of: list, get, create, update, delete.
     * @param array<string,mixed> $context   Operation context: {register, schema, object, id?, data?}.
     *
     * @return array<string,mixed> Response data.
     *
     * @throws RuntimeException When OpenConnector is unavailable or the call fails.
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function call(string $source, string $operation, array $context): array
    {
        if ($this->appManager->isInstalled(appId: 'openconnector') === false) {
            throw new RuntimeException(message: 'OpenConnector app is not installed');
        }

        $url    = $this->buildUrl(source: $source, operation: $operation, context: $context);
        $method = $this->resolveMethod(operation: $operation);
        $body   = $context['data'] ?? [];

        $this->logger->debug(
            message: '[ExternalIntegrationRouter] Calling OpenConnector',
            context: [
                'source'    => $source,
                'operation' => $operation,
                'url'       => $url,
            ]
        );

        try {
            $client  = $this->clientService->newClient();
            $options = ['headers' => ['Accept' => 'application/json']];

            if (in_array(needle: $method, haystack: ['POST', 'PUT'], strict: true) === true) {
                $options['json'] = $body;
            }

            $response = match ($method) {
                'GET'    => $client->get(url: $url, options: $options),
                'POST'   => $client->post(url: $url, options: $options),
                'PUT'    => $client->put(url: $url, options: $options),
                'DELETE' => $client->delete(url: $url, options: $options),
                default  => throw new RuntimeException(message: "Unsupported HTTP method: {$method}"),
            };

            $decoded = json_decode(json: $response->getBody(), associative: true);

            if (is_array(value: $decoded) === false) {
                return [];
            }

            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ExternalIntegrationRouter] OpenConnector call failed',
                context: [
                    'source'    => $source,
                    'operation' => $operation,
                    'message'   => $e->getMessage(),
                ]
            );
            throw new RuntimeException(
                message: "OpenConnector call failed for source '{$source}': ".$e->getMessage(),
                previous: $e
            );
        }//end try
    }//end call()

    /**
     * Probe the health of an OpenConnector source.
     *
     * @param string $source OpenConnector source identifier.
     *
     * @return array{status: string, message: string}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-3
     */
    public function probe(string $source): array
    {
        if ($this->appManager->isInstalled(appId: 'openconnector') === false) {
            return [
                'status'  => 'unavailable',
                'message' => 'OpenConnector app is not installed',
            ];
        }

        try {
            $this->call(source: $source, operation: 'health', context: []);
            return ['status' => 'ok', 'message' => 'Source reachable'];
        } catch (\Exception $e) {
            return [
                'status'  => 'unavailable',
                'message' => $e->getMessage(),
            ];
        }
    }//end probe()

    /**
     * Build the OpenConnector API URL for the given operation.
     *
     * @param string              $source    Source identifier.
     * @param string              $operation Operation name.
     * @param array<string,mixed> $context   Operation context.
     *
     * @return string Full URL.
     */
    private function buildUrl(string $source, string $operation, array $context): string
    {
        $base = self::OC_BASE_PATH;

        $objectId = $context['object'] ?? '';
        $linkedId = $context['id'] ?? '';

        return match ($operation) {
            'list'   => "{$base}/sources/{$source}/objects",
            'get'    => "{$base}/sources/{$source}/objects/{$linkedId}",
            'create' => "{$base}/sources/{$source}/objects",
            'update' => "{$base}/sources/{$source}/objects/{$linkedId}",
            'delete' => "{$base}/sources/{$source}/objects/{$linkedId}",
            'health' => "{$base}/sources/{$source}/health",
            default  => "{$base}/sources/{$source}/{$operation}",
        };
    }//end buildUrl()

    /**
     * Resolve the HTTP method for an operation.
     *
     * @param string $operation Operation name.
     *
     * @return string HTTP method.
     */
    private function resolveMethod(string $operation): string
    {
        return match ($operation) {
            'list'   => 'GET',
            'get'    => 'GET',
            'health' => 'GET',
            'create' => 'POST',
            'update' => 'PUT',
            'delete' => 'DELETE',
            default  => 'GET',
        };
    }//end resolveMethod()
}//end class
