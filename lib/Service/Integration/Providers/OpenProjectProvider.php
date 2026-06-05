<?php

/**
 * OpenRegister OpenProject Integration Provider
 *
 * Provides integration with OpenProject work packages via OpenConnector
 * external routing. This is the first external-service integration,
 * proving the storage='external' path of the integration registry.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouter;
use Psr\Log\LoggerInterface;

/**
 * Integration provider for OpenProject work packages.
 *
 * OpenProject is linked via OpenConnector (OAuth2). Storage is 'external' —
 * no local link table; all CRUD routes through ExternalIntegrationRouter.
 * Per ADR-019 AD-1, the conventional source name is 'openproject'.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\Providers
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-2
 */
class OpenProjectProvider extends AbstractIntegrationProvider
{

    /**
     * OpenConnector source id for OpenProject per ADR-019 AD-1.
     *
     * @var string
     */
    private const SOURCE_ID = 'openproject';

    /**
     * Constructor.
     *
     * @param ExternalIntegrationRouter $router External integration router.
     * @param LoggerInterface           $logger Logger.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function __construct(
        private readonly ExternalIntegrationRouter $router,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Unique identifier for this integration.
     *
     * @return string Always 'openproject'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function getId(): string
    {
        return self::SOURCE_ID;
    }//end getId()

    /**
     * Human-readable label shown in UI.
     *
     * @return string 'Projects'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function getLabel(): string
    {
        return 'Projects';
    }//end getLabel()

    /**
     * Icon identifier for UI rendering.
     *
     * @return string 'Briefcase'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function getIcon(): string
    {
        return 'Briefcase';
    }//end getIcon()

    /**
     * Integration group.
     *
     * @return string 'external'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function getGroup(): string
    {
        return 'external';
    }//end getGroup()

    /**
     * Storage strategy: external (no local link table).
     *
     * All CRUD routes through ExternalIntegrationRouter to OpenConnector.
     *
     * @return string Always 'external'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function getStorageStrategy(): string
    {
        return 'external';
    }//end getStorageStrategy()

    /**
     * OpenConnector source id for this integration.
     *
     * Returns the conventional source name per ADR-019 AD-1.
     *
     * @return string Always 'openproject'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function getOpenConnectorSource(): ?string
    {
        return self::SOURCE_ID;
    }//end getOpenConnectorSource()

    /**
     * Auth requirements for admin UI and OCS capabilities.
     *
     * OpenProject uses OAuth2. The configSchema documents the required fields.
     *
     * @return array<string,mixed> Auth requirements definition.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function authRequirements(): ?array
    {
        return [
            'type'         => 'oauth2',
            'configSchema' => [
                'url'           => [
                    'type'        => 'string',
                    'label'       => 'OpenProject URL',
                    'description' => 'Base URL of your OpenProject instance (e.g. https://openproject.example.com)',
                    'required'    => true,
                ],
                'client_id'     => [
                    'type'        => 'string',
                    'label'       => 'Client ID',
                    'description' => 'OAuth2 client id from OpenProject OAuth application',
                    'required'    => true,
                ],
                'client_secret' => [
                    'type'        => 'string',
                    'label'       => 'Client Secret',
                    'description' => 'OAuth2 client secret from OpenProject OAuth application',
                    'required'    => true,
                    'secret'      => true,
                ],
                'scope'         => [
                    'type'        => 'string',
                    'label'       => 'Scope',
                    'description' => 'OAuth2 scopes (default: api_v3)',
                    'required'    => false,
                    'default'     => 'api_v3',
                ],
            ],
        ];
    }//end authRequirements()

    /**
     * Permission requirement: null (OpenProject own ACLs govern visibility).
     *
     * @return string|null Always null.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Whether this integration is currently enabled.
     *
     * Enabled when the OpenConnector source 'openproject' exists and is configured.
     * When the source is missing, the integration is hidden from the registry.
     *
     * @return bool True if the OpenConnector source is present and not 'missing'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function isEnabled(): bool
    {
        try {
            $status = $this->router->checkAuthStatus(sourceId: self::SOURCE_ID);
            return ($status !== 'missing');
        } catch (\Exception $e) {
            $this->logger->debug(
                message: '[OpenProjectProvider] isEnabled check failed',
                context: ['error' => $e->getMessage()]
            );

            return false;
        }
    }//end isEnabled()

    /**
     * Health check returning auth/availability status.
     *
     * @return string One of 'available', 'unavailable', 'degraded', 'expired', 'missing'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-2
     */
    public function health(): string
    {
        try {
            $authStatus = $this->router->checkAuthStatus(sourceId: self::SOURCE_ID);

            return match ($authStatus) {
                'configured' => 'available',
                'expired'    => 'expired',
                'degraded'   => 'degraded',
                'missing'    => 'unavailable',
                default      => 'unavailable',
            };
        } catch (\Exception $e) {
            $this->logger->warning(
                message: '[OpenProjectProvider] Health check failed',
                context: ['error' => $e->getMessage()]
            );

            return 'unavailable';
        }
    }//end health()

    /**
     * List linked work packages for a given object.
     *
     * Delegates to ExternalIntegrationRouter which resolves the OpenConnector
     * source and invokes OpenConnector's list operation with object context.
     *
     * @param string              $objectId The OpenRegister object UUID.
     * @param array<string,mixed> $params   Pagination and filter parameters.
     *
     * @return array{items: array, total: int, authStatus: string} Paginated work packages.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function listWorkPackages(string $objectId, array $params = []): array
    {
        return $this->router->listItems(
            sourceId: self::SOURCE_ID,
            objectId: $objectId,
            params: $params
        );
    }//end listWorkPackages()

    /**
     * Link an existing work package to an object by work package id.
     *
     * @param string $objectId      The OpenRegister object UUID.
     * @param int    $workPackageId The OpenProject work package id.
     *
     * @return array{item: array, authStatus: string} The linked work package.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function linkWorkPackageById(string $objectId, int $workPackageId): array
    {
        return $this->router->linkItem(
            sourceId: self::SOURCE_ID,
            objectId: $objectId,
            data: ['workPackageId' => $workPackageId]
        );
    }//end linkWorkPackageById()

    /**
     * Link an existing work package to an object by work package URL.
     *
     * @param string $objectId        The OpenRegister object UUID.
     * @param string $workPackageUrl  The OpenProject work package URL.
     *
     * @return array{item: array, authStatus: string} The linked work package.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function linkWorkPackageByUrl(string $objectId, string $workPackageUrl): array
    {
        return $this->router->linkItem(
            sourceId: self::SOURCE_ID,
            objectId: $objectId,
            data: ['workPackageUrl' => $workPackageUrl]
        );
    }//end linkWorkPackageByUrl()

    /**
     * Unlink a work package from an object.
     *
     * @param string $objectId      The OpenRegister object UUID.
     * @param string $workPackageId The external work package id to unlink.
     *
     * @return array{authStatus: string} Result with auth status.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-3
     */
    public function unlinkWorkPackage(string $objectId, string $workPackageId): array
    {
        return $this->router->unlinkItem(
            sourceId: self::SOURCE_ID,
            objectId: $objectId,
            itemId: $workPackageId
        );
    }//end unlinkWorkPackage()

}//end class
