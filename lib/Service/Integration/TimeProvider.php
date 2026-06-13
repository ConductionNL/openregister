<?php

/**
 * TimeProvider — ADR-019 integration provider for the Time Tracker integration.
 *
 * Registers with id='time-tracker', group='workflow', storage='link-table'.
 * The required backend app is configurable via the admin setting
 * `time-tracker.backend` (AD-1, AD-2 from design.md).
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
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

use OCP\IAppConfig;

/**
 * TimeProvider implements IntegrationProvider for the time-tracker integration.
 *
 * Extends AbstractIntegrationProvider so it inherits the default
 * implementations for the optional / CRUD methods (authRequirements,
 * getOpenConnectorSource, get/create/update/delete, health). Only the
 * methods specific to the time-tracker integration are overridden below,
 * plus the two remaining abstract contract methods (isEnabled, list).
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
 */
class TimeProvider extends AbstractIntegrationProvider
{

    /**
     * Integration id.
     */
    public const ID = 'time-tracker';

    /**
     * Default backend app.
     */
    private const DEFAULT_BACKEND = 'timemanager';

    /**
     * App configuration for reading the admin-configured backend.
     *
     * @var IAppConfig
     */
    private readonly IAppConfig $appConfig;

    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig App configuration.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function __construct(IAppConfig $appConfig)
    {
        $this->appConfig = $appConfig;
    }//end __construct()

    /**
     * Return the integration id.
     *
     * @return string
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getId(): string
    {
        return self::ID;
    }//end getId()

    /**
     * Return the human-readable label.
     *
     * @return string
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getLabel(): string
    {
        return 'Time';
    }//end getLabel()

    /**
     * Return the icon name.
     *
     * @return string
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getIcon(): string
    {
        return 'Clock';
    }//end getIcon()

    /**
     * Return the functional group.
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getGroup(): ?string
    {
        return 'workflow';
    }//end getGroup()

    /**
     * Return the required NC app (from admin setting or default 'timemanager').
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getRequiredApp(): ?string
    {
        return $this->appConfig->getValueString('openregister', 'time-tracker.backend', self::DEFAULT_BACKEND);
    }//end getRequiredApp()

    /**
     * Return the storage strategy.
     *
     * @return string
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * Permissions are governed by the backend app's ACLs; return null.
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * Whether the integration is enabled.
     *
     * This Tier-1 descriptor provider is always considered enabled; the
     * concrete backend-availability probe (checking whether the configured
     * TimeManager app is installed) lives on the Tier-2
     * `Providers\TimeProvider`, which backs the live link/list paths.
     *
     * @return bool
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function isEnabled(): bool
    {
        return true;
    }//end isEnabled()

    /**
     * List linked time entries for an object.
     *
     * Tier-1 descriptor provider returns an empty list; the live listing
     * (link-table read + legacy marker fallback) is implemented on the
     * Tier-2 `Providers\TimeProvider`.
     *
     * @param string              $register Register slug or numeric id.
     * @param string              $schema   Schema slug or numeric id.
     * @param string              $objectId Owning object uuid.
     * @param array<string,mixed> $filters  Optional filters.
     *
     * @return array<int,array<string,mixed>>
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter) Parameters are mandated by the IntegrationProvider interface;
     *   this Tier-1 descriptor provider returns an empty list — the Tier-2 provider uses all parameters.
     */
    public function list(string $register, string $schema, string $objectId, array $filters=[]): array
    {
        return [];
    }//end list()
}//end class
