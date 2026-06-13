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
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
 */
class TimeProvider implements IntegrationProvider
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
     * @return string
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getGroup(): string
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
}//end class
