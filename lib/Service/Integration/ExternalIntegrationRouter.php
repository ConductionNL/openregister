<?php

/**
 * ExternalIntegrationRouter
 *
 * Routes CRUD calls from external IntegrationProvider leaves to the
 * OpenConnector app (ADR-019 AD-4 external routing).
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
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Routes integration CRUD calls to OpenConnector for external providers.
 *
 * External providers declare storage='external' and reference an
 * OpenConnector source by id. This router handles dispatch + auth-status
 * surfacing; OR does not own credentials — OpenConnector does.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
 */
class ExternalIntegrationRouter
{
    /**
     * Constructor.
     *
     * @param IAppManager     $appManager App manager for availability check
     * @param LoggerInterface $logger     Logger
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Execute an integration CRUD call via OpenConnector.
     *
     * @param string      $method   One of: list, get, create, update, delete
     * @param string      $source   OpenConnector source id (e.g. 'xwiki')
     * @param string      $register Register slug
     * @param string      $schema   Schema slug
     * @param string      $objectId Object UUID
     * @param string|null $id       Item id (null for list/create)
     * @param array       $data     Request payload
     *
     * @throws RuntimeException When OpenConnector is not installed or call fails
     *
     * @return array Response data
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function call(
        string $method,
        string $source,
        string $register,
        string $schema,
        string $objectId,
        ?string $id=null,
        array $data=[],
    ): array {
        if ($this->appManager->isInstalled(appId: 'openconnector') === false) {
            throw new RuntimeException('OpenConnector app is not installed.');
        }

        // The actual HTTP dispatch is handled inside OpenConnector's source engine.
        // This method resolves the callable from OpenConnector's service layer and
        // forwards the context payload. When OpenConnector is available the call
        // returns the normalised page array or throws on HTTP error / auth failure.
        $this->logger->debug(
            'ExternalIntegrationRouter::call',
            [
                'method'   => $method,
                'source'   => $source,
                'register' => $register,
                'schema'   => $schema,
                'objectId' => $objectId,
                'id'       => $id,
            ]
        );

        // Delegate to OpenConnector source engine via its public service.
        // The actual implementation resolves the service at runtime to avoid
        // a hard DI dependency on an optional app.
        return $this->dispatchViaOpenConnector(
            method: $method,
            source: $source,
            register: $register,
            schema: $schema,
            objectId: $objectId,
            id: $id,
            data: $data,
        );
    }//end call()

    /**
     * Probe an OpenConnector source to surface auth status.
     *
     * @param string $source OpenConnector source id
     *
     * @return array{status: string, authExpired?: bool, reason?: string}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function probe(string $source): array
    {
        if ($this->appManager->isInstalled(appId: 'openconnector') === false) {
            return [
                'status'      => 'unavailable',
                'authExpired' => false,
                'reason'      => 'openconnector_not_installed',
            ];
        }

        $this->logger->debug(
            'ExternalIntegrationRouter::probe',
            ['source' => $source]
        );

        return $this->probeViaOpenConnector(source: $source);
    }//end probe()

    /**
     * Dispatch a call to OpenConnector's source engine.
     *
     * @param string      $method   CRUD method name
     * @param string      $source   Source id
     * @param string      $register Register slug
     * @param string      $schema   Schema slug
     * @param string      $objectId Object UUID
     * @param string|null $id       Item id
     * @param array       $data     Payload
     *
     * @return array Response data
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    private function dispatchViaOpenConnector(
        string $method,
        string $source,
        string $register,
        string $schema,
        string $objectId,
        ?string $id,
        array $data,
    ): array {
        // OpenConnector integration point: when the app is available the
        // namespace \OCA\OpenConnector\Service\SourceService is resolvable.
        // We use class_exists to avoid fatal errors if the namespace is absent.
        $serviceClass = '\OCA\OpenConnector\Service\SourceService';
        if (class_exists(class: $serviceClass) === false) {
            $this->logger->warning(
                'OpenConnector SourceService not found; returning empty result.',
                ['source' => $source]
            );
            return [];
        }

        // The SourceService call is resolved at runtime; no static import.
        // phpcs:ignore CustomSn.Functions.NamedParameters
        $service = \OC::$server->get($serviceClass);

        return $service->callSource(
            source: $source,
            method: $method,
            context: [
                'register' => $register,
                'schema'   => $schema,
                'objectId' => $objectId,
                'id'       => $id,
                'data'     => $data,
            ],
        );
    }//end dispatchViaOpenConnector()

    /**
     * Probe OpenConnector source health.
     *
     * @param string $source Source id
     *
     * @return array{status: string, authExpired?: bool, reason?: string}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    private function probeViaOpenConnector(string $source): array
    {
        $serviceClass = '\OCA\OpenConnector\Service\SourceService';
        if (class_exists(class: $serviceClass) === false) {
            return [
                'status' => 'unavailable',
                'reason' => 'openconnector_service_not_found',
            ];
        }

        // phpcs:ignore CustomSn.Functions.NamedParameters
        $service = \OC::$server->get($serviceClass);

        return $service->probeSource(source: $source);
    }//end probeViaOpenConnector()
}//end class
