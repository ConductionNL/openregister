<?php

/**
 * ExternalIntegrationRouter — dispatch sub-resource calls for
 * `storage='external'` providers through OpenConnector.
 *
 * Per AD-4 external providers don't carry their own HTTP client.
 * They declare an OpenConnector source via `getOpenConnectorSource()`,
 * and this router resolves the source, makes the call, and surfaces
 * structured failures via `ProviderUnavailableException` per AD-23.
 *
 * Cause classification:
 *   - openconnector-down            — OpenConnector NC app is disabled or missing.
 *   - openconnector-source-missing  — the declared source id can't be found
 *                                     (typo, deleted, never created).
 *   - upstream-service-down         — the OpenConnector source exists but the
 *                                     remote service it points at is unreachable.
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Routes external integrations' CRUD calls through OpenConnector.
 *
 * The router is intentionally thin: it knows how to talk to
 * OpenConnector's CallService / SourceMapper, classify the failure
 * mode when something goes wrong, and that's it. Per-provider
 * specifics (URL paths, payload shapes) live in the provider
 * implementations themselves — the router just provides the safe
 * transport.
 */
class ExternalIntegrationRouter
{

    /**
     * Cached availability flag for the OpenConnector NC app.
     *
     * Null means "not yet checked" — the first call resolves it.
     *
     * @var bool|null
     */
    private ?bool $openConnectorAvailable = null;

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager NC app manager — used to
     *                                       detect whether OpenConnector
     *                                       is installed + enabled.
     * @param ContainerInterface $container  DI container — used to
     *                                       lazily resolve OpenConnector's
     *                                       SourceMapper / CallService.
     * @param LoggerInterface    $logger     Logger for failure traces.
     *
     * @return void
     */
    public function __construct(
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Dispatch a CRUD call against the provider's OpenConnector source.
     *
     * @param IntegrationProvider $provider The provider making the call.
     *                                      MUST declare storage='external'
     *                                      and a non-null
     *                                      getOpenConnectorSource().
     * @param string              $method   HTTP method ('GET' / 'POST' /
     *                                      'PUT' / 'PATCH' / 'DELETE').
     * @param string              $path     Path relative to the source's
     *                                      base URL.
     * @param array<string,mixed> $options  Optional call options:
     *                                      - query: array of query params
     *                                      - body:  scalar or array body
     *                                      - headers: extra request headers
     *
     * @return array<string,mixed> The decoded response body.
     *
     * @throws ProviderUnavailableException When OpenConnector or the
     *                                      upstream service is
     *                                      unavailable. Use
     *                                      `getCause()` /
     *                                      `getDetails()` to pick
     *                                      the right user message.
     */
    public function call(
        IntegrationProvider $provider,
        string $method,
        string $path,
        array $options = [],
    ): array {
        $this->assertProviderIsExternal($provider);
        $this->assertOpenConnectorAvailable();

        $sourceId = (string) $provider->getOpenConnectorSource();
        $source   = $this->loadSource($sourceId, $provider->getId());

        try {
            return $this->invoke($source, $method, $path, $options);
        } catch (ProviderUnavailableException $e) {
            // Already classified — surface as-is.
            throw $e;
        } catch (\Throwable $e) {
            // Anything else surfacing from OpenConnector / the upstream
            // is treated as an upstream failure. The original throwable
            // is wrapped so the caller can introspect if needed.
            $this->logger->error(
                sprintf(
                    '[ExternalIntegrationRouter] upstream call failed for provider %s %s %s',
                    $provider->getId(),
                    $method,
                    $path
                ),
                ['exception' => $e]
            );
            throw new ProviderUnavailableException(
                sprintf(
                    'Upstream service for integration "%s" is unreachable.',
                    $provider->getId()
                ),
                ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN,
                $e
            );
        }//end try
    }//end call()

    /**
     * Cheap "is the connector reachable at all" check.
     *
     * Used by `IntegrationProvider::health()` implementations on
     * external providers when admin UI / OCS capabilities request
     * status. Never throws — returns a descriptor instead.
     *
     * @param IntegrationProvider $provider Provider to check.
     *
     * @return array{status: string, authStatus: string, message: ?string}
     */
    public function probe(IntegrationProvider $provider): array
    {
        if ($provider->getStorageStrategy() !== 'external') {
            return [
                'status'     => 'ok',
                'authStatus' => 'configured',
                'message'    => null,
            ];
        }

        if ($this->isOpenConnectorAvailable() === false) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'missing',
                'message'    => 'OpenConnector app is not installed or enabled.',
            ];
        }

        $sourceId = (string) $provider->getOpenConnectorSource();
        try {
            $this->loadSource($sourceId, $provider->getId());
        } catch (ProviderUnavailableException $e) {
            return [
                'status'     => 'unavailable',
                'authStatus' => 'missing',
                'message'    => $e->getMessage(),
            ];
        }

        return [
            'status'     => 'ok',
            'authStatus' => 'configured',
            'message'    => null,
        ];
    }//end probe()

    /**
     * Reject non-external providers — they should not reach the router.
     *
     * @param IntegrationProvider $provider Provider under inspection.
     *
     * @return void
     *
     * @throws \LogicException When called with a non-external provider.
     */
    private function assertProviderIsExternal(IntegrationProvider $provider): void
    {
        if ($provider->getStorageStrategy() !== 'external') {
            throw new \LogicException(
                sprintf(
                    'ExternalIntegrationRouter::call() invoked with non-external provider %s (storage=%s)',
                    $provider->getId(),
                    $provider->getStorageStrategy()
                )
            );
        }

        if ($provider->getOpenConnectorSource() === null) {
            throw new ProviderUnavailableException(
                sprintf(
                    'External provider "%s" did not declare an OpenConnector source.',
                    $provider->getId()
                ),
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING
            );
        }
    }//end assertProviderIsExternal()

    /**
     * Throw `ProviderUnavailableException` when the OpenConnector app
     * is disabled or missing.
     *
     * @return void
     *
     * @throws ProviderUnavailableException With cause openconnector-down.
     */
    private function assertOpenConnectorAvailable(): void
    {
        if ($this->isOpenConnectorAvailable() === true) {
            return;
        }

        throw new ProviderUnavailableException(
            'OpenConnector app is not installed or enabled.',
            ProviderUnavailableException::CAUSE_OPENCONNECTOR_DOWN
        );
    }//end assertOpenConnectorAvailable()

    /**
     * Cached lookup of OpenConnector app installation.
     *
     * @return bool
     */
    private function isOpenConnectorAvailable(): bool
    {
        if ($this->openConnectorAvailable === null) {
            $this->openConnectorAvailable = $this->appManager->isInstalled('openconnector')
                && $this->appManager->isEnabledForUser('openconnector');
        }

        return $this->openConnectorAvailable;
    }//end isOpenConnectorAvailable()

    /**
     * Resolve an OpenConnector source by id.
     *
     * Tries OpenConnector's SourceMapper. When the source isn't found
     * (or the mapper isn't loaded), surfaces a
     * `openconnector-source-missing` exception so the UI shows
     * "Reconfigure connector" rather than a generic 500.
     *
     * @param string $sourceId   Source identifier declared by the provider.
     * @param string $providerId Provider id (for error messages only).
     *
     * @return mixed The resolved Source entity (OpenConnector-shaped).
     *
     * @throws ProviderUnavailableException When the source is missing.
     */
    private function loadSource(string $sourceId, string $providerId)
    {
        try {
            $mapper = $this->container->get('OCA\\OpenConnector\\Db\\SourceMapper');
            $source = null;

            // OpenConnector's SourceMapper supports a stringy slug
            // lookup via `findByReference()` or `find(<id>)`. We try
            // both because the public API surface has evolved.
            if (method_exists($mapper, 'findByReference') === true) {
                $source = $mapper->findByReference($sourceId);
            } else if (method_exists($mapper, 'find') === true) {
                $source = $mapper->find($sourceId);
            }

            if ($source === null) {
                throw new \RuntimeException(sprintf('OpenConnector source "%s" not found', $sourceId));
            }

            return $source;
        } catch (\Throwable $e) {
            throw new ProviderUnavailableException(
                sprintf(
                    'OpenConnector source "%s" for integration "%s" is missing or unreadable.',
                    $sourceId,
                    $providerId
                ),
                ProviderUnavailableException::CAUSE_OPENCONNECTOR_SOURCE_MISSING,
                $e
            );
        }//end try
    }//end loadSource()

    /**
     * Invoke the upstream call via OpenConnector's CallService.
     *
     * The CallService API has varied across OpenConnector versions;
     * this method tries the canonical method names and falls back to
     * an exception that the caller wraps as upstream-down.
     *
     * @param mixed               $source  Resolved source entity.
     * @param string              $method  HTTP method.
     * @param string              $path    Path relative to source base URL.
     * @param array<string,mixed> $options Call options (query / body / headers).
     *
     * @return array<string,mixed> Decoded response body.
     *
     * @throws \RuntimeException When CallService is unreachable. The
     *                          caller wraps this as ProviderUnavailableException.
     */
    private function invoke($source, string $method, string $path, array $options): array
    {
        $callService = $this->container->get('OCA\\OpenConnector\\Service\\CallService');

        if (method_exists($callService, 'call') === true) {
            $response = $callService->call($source, $path, $method, $options);
            return $this->decodeResponse($response);
        }

        if (method_exists($callService, 'request') === true) {
            $response = $callService->request($source, $method, $path, $options);
            return $this->decodeResponse($response);
        }

        throw new \RuntimeException(
            'OpenConnector\\Service\\CallService does not expose a known call/request method.'
        );
    }//end invoke()

    /**
     * Normalise a CallService response into a decoded array.
     *
     * CallService returns either a CallLog entity (carrying a JSON
     * body), a raw array, or a scalar string. We always normalise to
     * an array; non-arrays are wrapped under a `body` key so callers
     * have a stable shape to introspect.
     *
     * @param mixed $response The raw return from CallService.
     *
     * @return array<string,mixed>
     */
    private function decodeResponse($response): array
    {
        if (is_array($response) === true) {
            return $response;
        }

        if (is_object($response) === true && method_exists($response, 'jsonSerialize') === true) {
            $data = $response->jsonSerialize();
            return is_array($data) === true ? $data : ['body' => $data];
        }

        if (is_string($response) === true) {
            $decoded = json_decode($response, true);
            if (is_array($decoded) === true) {
                return $decoded;
            }

            return ['body' => $response];
        }

        return ['body' => $response];
    }//end decodeResponse()

}//end class
