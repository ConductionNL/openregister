<?php

/**
 * IntegrationProvider Interface
 *
 * Defines the contract for all pluggable integration providers in OpenRegister.
 * Every integration ships a vertical slice via a PHP class implementing this
 * interface, registered under the DI tag 'IntegrationProvider'.
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
 * @spec openspec/changes/integration-email/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Contract for all integration providers in the pluggable registry.
 *
 * Implementations are registered via the 'IntegrationProvider' DI tag and
 * discovered by IntegrationRegistry::getEnabled(). The registry filters out
 * providers whose required NC app is not installed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @spec openspec/changes/integration-email/tasks.md#task-1
 */
interface IntegrationProvider
{
    /**
     * Unique integration identifier (e.g. 'email', 'calendar', 'tasks').
     *
     * Used to pair backend and frontend registrations. Must be stable across
     * versions — consumers key on this value in linkedTypes and referenceType.
     *
     * @return string The provider id.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getId(): string;

    /**
     * Human-readable label for the integration (translatable).
     *
     * @return string The display label.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getLabel(): string;

    /**
     * MDI icon name used in sidebar tabs and registry UI (e.g. 'Email').
     *
     * @return string The MDI icon identifier.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getIcon(): string;

    /**
     * Grouping key for sidebar/registry organisation (e.g. 'comms', 'tasks').
     *
     * @return string The group key.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getGroup(): string;

    /**
     * Nextcloud app id that must be installed for this provider to be active.
     *
     * Return null if the integration has no NC app dependency.
     *
     * @return string|null The required app id or null.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy identifier ('link-table', 'external', 'property').
     *
     * @return string The storage strategy.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getStorageStrategy(): string;

    /**
     * Permission key required to use this integration, or null for RBAC-inherit.
     *
     * When null, access inherits from the object's RBAC rules plus the
     * required NC app's own access controls (e.g. Mail app account ownership).
     *
     * @return string|null The permission key or null.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function requiresPermission(): ?string;

    /**
     * Retrieve linked items for an object, applying pagination.
     *
     * @param string   $objectUuid The target object UUID.
     * @param int|null $limit      Maximum number of results.
     * @param int|null $offset     Pagination offset.
     *
     * @return array{results: array, total: int} The linked items with total count.
     *
     * @spec openspec/changes/integration-email/tasks.md#task-1
     */
    public function getLinkedItems(string $objectUuid, ?int $limit=null, ?int $offset=null): array;
}//end interface
