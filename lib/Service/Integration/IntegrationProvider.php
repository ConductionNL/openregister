<?php

/**
 * IntegrationProvider Abstract Base Class
 *
 * Defines the contract every integration must fulfil to participate in the
 * OpenRegister pluggable integration registry (ADR-019). Concrete providers
 * extend this class and are auto-discovered via the `IntegrationProvider`
 * DI tag registered in Application.php.
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
 * @spec openspec/changes/integration-talk/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Abstract base class for OpenRegister integration providers.
 *
 * Every built-in and third-party integration must extend this class. The
 * registry discovers all tagged providers and applies the three-stage filter
 * described in ADR-019 (registry → schema → component).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @spec openspec/changes/integration-talk/tasks.md#task-1
 */
abstract class IntegrationProvider
{

    /**
     * Unique identifier for this integration (e.g. 'talk', 'deck', 'notes').
     *
     * @return string
     */
    abstract public function getId(): string;

    /**
     * Human-readable label shown in the UI.
     *
     * @return string
     */
    abstract public function getLabel(): string;

    /**
     * Icon name from @mdi/js (e.g. 'ChatOutline', 'CardBulleted').
     *
     * @return string
     */
    abstract public function getIcon(): string;

    /**
     * Logical grouping key for sidebar/tab ordering.
     * Suggested values: 'comms', 'tasks', 'files', 'data'.
     *
     * @return string
     */
    abstract public function getGroup(): string;

    /**
     * NC app ID that must be installed and enabled for this provider to appear.
     * Return null if the integration has no external app dependency.
     *
     * @return string|null
     */
    public function getRequiredApp(): ?string
    {
        return null;
    }//end getRequiredApp()

    /**
     * Storage strategy for linked items.
     * Supported values: 'link-table', 'external', 'metadata'.
     *
     * @return string
     */
    public function getStorageStrategy(): string
    {
        return 'link-table';
    }//end getStorageStrategy()

    /**
     * NC permission string required to access this integration.
     * Return null to inherit Talk's own ACL (or have no extra guard).
     *
     * @return string|null
     */
    public function requiresPermission(): ?string
    {
        return null;
    }//end requiresPermission()

    /**
     * List linked items for a specific OR object.
     *
     * @param string $objectUuid The OR object UUID.
     * @param int    $limit      Maximum items to return.
     * @param int    $offset     Pagination offset.
     *
     * @return array<int,array<string,mixed>> List of linked item representations.
     */
    abstract public function listForObject(string $objectUuid, int $limit = 20, int $offset = 0): array;

    /**
     * Link an external item to an OR object.
     *
     * @param string               $objectUuid The OR object UUID.
     * @param array<string,mixed>  $data       Integration-specific payload describing the item to link.
     *
     * @return array<string,mixed> The created link representation.
     */
    abstract public function linkToObject(string $objectUuid, array $data): array;

    /**
     * Remove a link between an OR object and an external item.
     *
     * @param string $objectUuid The OR object UUID.
     * @param string $linkId     The link record identifier (integration-specific).
     *
     * @return bool True on success, false if the link was not found.
     */
    abstract public function unlinkFromObject(string $objectUuid, string $linkId): bool;

}//end class
