<?php

/**
 * OpenRegister Integration Provider Interface
 *
 * Defines the contract for all integration providers in the pluggable
 * integration registry. Each integration (files, notes, OpenProject, etc.)
 * ships a class implementing this interface and registers it via DI tag.
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
 * @spec openspec/changes/integration-openproject/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Contract for integration providers in the pluggable integration registry.
 *
 * Every integration ships a vertical slice by implementing this interface.
 * Backend and frontend are paired by the same id string.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author  Conduction Development Team <info@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/integration-openproject/tasks.md#task-1
 */
interface IntegrationProvider
{

    /**
     * Unique identifier for this integration (e.g. 'openproject', 'deck').
     *
     * Used to pair backend provider with frontend registration.
     *
     * @return string The unique integration id.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getId(): string;

    /**
     * Human-readable label shown in UI.
     *
     * @return string The display label.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getLabel(): string;

    /**
     * Icon identifier for UI rendering.
     *
     * @return string The icon name.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getIcon(): string;

    /**
     * Integration group (e.g. 'builtin', 'external', 'community').
     *
     * @return string The group name.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getGroup(): string;

    /**
     * Required Nextcloud app id, or null if no NC app is needed.
     *
     * For internal NC integrations (deck, calendar) this returns the app id.
     * For external service integrations (OpenProject, XWiki) this returns null.
     *
     * @return string|null The required app id or null.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy: 'local' for NC-hosted, 'external' for external services.
     *
     * External integrations route through ExternalIntegrationRouter and OpenConnector.
     *
     * @return string The storage strategy ('local'|'external').
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getStorageStrategy(): string;

    /**
     * Whether this integration is currently enabled and available.
     *
     * Checks required app installation and any external source availability.
     *
     * @return bool True if the integration is available.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function isEnabled(): bool;

    /**
     * Auth requirements declaration for admin UI and OCS capabilities.
     *
     * Returns null for integrations that require no external auth setup.
     * Returns an array with 'type' and 'configSchema' for OAuth2 or similar.
     *
     * @return array<string,mixed>|null Auth requirements or null.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function authRequirements(): ?array;

    /**
     * Required permission for this integration or null for permission-less.
     *
     * Null means the underlying service's own ACLs govern access.
     *
     * @return string|null The required permission or null.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function requiresPermission(): ?string;

    /**
     * OpenConnector source id for external integrations, or null for local.
     *
     * External providers declare which OpenConnector source handles dispatch.
     * Non-external providers return null.
     *
     * @return string|null The OpenConnector source id or null.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function getOpenConnectorSource(): ?string;

    /**
     * Health check: returns the current availability status.
     *
     * @return string One of 'available', 'unavailable', 'degraded', 'expired'.
     *
     * @spec openspec/changes/integration-openproject/tasks.md#task-1
     */
    public function health(): string;

}//end interface
