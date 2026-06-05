<?php

/**
 * IntegrationProvider interface for ADR-019 integration registry.
 *
 * Every integration that links a Nextcloud ecosystem service to OpenRegister
 * objects MUST implement this interface and register via the DI tag
 * 'IntegrationProvider'. See ADR-019 for the full two-sided contract.
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

/**
 * Interface IntegrationProvider
 *
 * Describes the metadata and behaviour contract every integration provider must
 * expose. Implementations are discovered by the IntegrationRegistry via the
 * 'IntegrationProvider' DI tag (ADR-019).
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
 */
interface IntegrationProvider
{

    /**
     * Machine-readable id — must be globally unique and URL-safe.
     *
     * @return string  e.g. 'time-tracker', 'deck', 'mail'
     */
    public function getId(): string;

    /**
     * Human-readable label shown in UI chrome.
     *
     * @return string  e.g. 'Time', 'Deck', 'Mail'
     */
    public function getLabel(): string;

    /**
     * Icon identifier (Nextcloud material / mdi icon name).
     *
     * @return string  e.g. 'Clock', 'Trello', 'Email'
     */
    public function getIcon(): string;

    /**
     * Functional group used to cluster integrations in the sidebar/dashboard.
     *
     * @return string  e.g. 'workflow', 'communication', 'files'
     */
    public function getGroup(): string;

    /**
     * The NC app ID that must be installed for this integration to be active.
     * Return null if there is no required NC app (e.g. a purely external service).
     *
     * @return string|null  e.g. 'timemanager', 'deck', null
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy: 'link-table' | 'external' | 'metadata'.
     * Tells the registry how linked data is persisted.
     *
     * @return string
     */
    public function getStorageStrategy(): string;

    /**
     * Return the permission required for a user to see this integration's data.
     * Return null when the backend app's own ACLs are authoritative (ADR-005).
     *
     * @return string|null  e.g. 'read-time', or null
     */
    public function requiresPermission(): ?string;
}//end interface
