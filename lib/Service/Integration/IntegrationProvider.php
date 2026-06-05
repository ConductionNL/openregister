<?php

/**
 * IntegrationProvider Interface
 *
 * Defines the contract every integration leaf must implement.
 * Backend half of the two-sided integration registry (ADR-019).
 *
 * @category Interface
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Contract for a pluggable integration provider (ADR-019).
 *
 * Each provider declares metadata (id, label, icon, group, storage
 * strategy) and implements CRUD over a specific external or NC-native
 * resource. External providers delegate CRUD to ExternalIntegrationRouter.
 *
 * @category Interface
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
 */
interface IntegrationProvider
{
    /**
     * Unique identifier (e.g. 'xwiki', 'deck', 'openproject').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getId(): string;

    /**
     * Human-readable label shown in the admin Integrations page.
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getLabel(): string;

    /**
     * Material Design icon name (e.g. 'FileDocumentMultiple').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getIcon(): string;

    /**
     * Provider group for admin grouping (e.g. 'external', 'native').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getGroup(): string;

    /**
     * The NC app id that must be installed for this provider (or null).
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy: 'local' (OR DB) or 'external' (OpenConnector).
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getStorageStrategy(): string;

    /**
     * OpenConnector source id, or null for local providers.
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function getOpenConnectorSource(): ?string;

    /**
     * Auth requirements descriptor, exposed in OCS capabilities.
     *
     * @return array{type: string, configuredVia?: string, source?: string, supports?: string[]}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.2
     */
    public function authRequirements(): array;

    /**
     * Whether this provider is currently available (required app installed, etc.).
     *
     * @return bool
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function isEnabled(): bool;

    /**
     * NC permission required to use this integration, or null (inherits object RBAC).
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.1
     */
    public function requiresPermission(): ?string;

    /**
     * List linked items for an object.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param array  $params   Pagination / filter params
     *
     * @return array
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function list(string $register, string $schema, string $objectId, array $params=[]): array;

    /**
     * Get a single linked item.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $id       Item id
     *
     * @return array|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function get(string $register, string $schema, string $objectId, string $id): ?array;

    /**
     * Create a link between an object and an external item.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param array  $data     Link data (e.g. reference, url)
     *
     * @return array Created link record
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function create(string $register, string $schema, string $objectId, array $data): array;

    /**
     * Update a linked item record.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $id       Item id
     * @param array  $data     Updated data
     *
     * @return array Updated link record
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function update(string $register, string $schema, string $objectId, string $id, array $data): array;

    /**
     * Remove a link between an object and an external item.
     *
     * @param string $register Register slug
     * @param string $schema   Schema slug
     * @param string $objectId Object UUID
     * @param string $id       Item id
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function delete(string $register, string $schema, string $objectId, string $id): void;

    /**
     * Health check — returns status and optional auth-expired signal.
     *
     * @return array{status: string, authExpired?: bool, reason?: string}
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function health(): array;

    /**
     * Shape a raw provider row to the canonical integration row shape.
     *
     * @param array $row Raw row from provider / router
     *
     * @return array Normalised row
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1.3
     */
    public function normalizeRow(array $row): array;
}//end interface
