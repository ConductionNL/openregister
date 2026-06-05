<?php

/**
 * Integration Provider Interface
 *
 * Defines the contract for all integration providers in OpenRegister.
 * Each integration implements this interface to declare metadata, auth
 * requirements, and CRUD delegation to the appropriate backend.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Contract for all integration providers.
 *
 * Every provider ships a vertical slice: metadata, auth requirements,
 * CRUD delegation, and row normalisation. External providers route
 * all operations through ExternalIntegrationRouter; internal providers
 * may call NC services directly.
 */
interface IntegrationProviderInterface
{
    /**
     * Unique identifier for this integration.
     *
     * @return string Integration ID (e.g. 'xwiki', 'openproject').
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getId(): string;

    /**
     * Human-readable label shown in the admin Integrations UI.
     *
     * @return string Display label.
     */
    public function getLabel(): string;

    /**
     * Icon identifier (Material Design or NC icon name).
     *
     * @return string Icon name.
     */
    public function getIcon(): string;

    /**
     * Category group for grouping in the integrations list.
     *
     * @return string Group name (e.g. 'external', 'native').
     */
    public function getGroup(): string;

    /**
     * NC app that must be installed for this integration.
     *
     * @return string|null App ID, or null when no app is required.
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy for linked objects.
     *
     * @return string Either 'local' or 'external'.
     */
    public function getStorageStrategy(): string;

    /**
     * Source identifier on the OpenConnector side (for external providers).
     *
     * @return string|null OpenConnector source name, or null for local providers.
     */
    public function getOpenConnectorSource(): ?string;

    /**
     * Authentication requirements for this integration.
     *
     * @return array{type: string, configuredVia: string, source?: string, supports: list<string>}
     */
    public function authRequirements(): array;

    /**
     * Whether this integration is currently enabled and usable.
     *
     * @return bool True when the required NC app is installed + source configured.
     */
    public function isEnabled(): bool;

    /**
     * List linked items for an OR object.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param array  $params   Optional pagination / filter parameters.
     *
     * @return array{results: list<array<string,mixed>>, total: int}
     */
    public function list(string $register, string $schema, string $objectId, array $params=[]): array;

    /**
     * Retrieve a single linked item.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param string $linkedId The linked item ID.
     *
     * @return array<string,mixed>|null Row or null when not found.
     */
    public function get(string $register, string $schema, string $objectId, string $linkedId): ?array;

    /**
     * Link (create) a new item to an OR object.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param array  $data     Link data (e.g. reference URL, title).
     *
     * @return array<string,mixed> The created link row.
     */
    public function create(string $register, string $schema, string $objectId, array $data): array;

    /**
     * Update a linked item.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param string $linkedId The linked item ID.
     * @param array  $data     Updated data.
     *
     * @return array<string,mixed> The updated row.
     */
    public function update(string $register, string $schema, string $objectId, string $linkedId, array $data): array;

    /**
     * Unlink (delete) a linked item.
     *
     * @param string $register The register identifier.
     * @param string $schema   The schema identifier.
     * @param string $objectId The object ID.
     * @param string $linkedId The linked item ID.
     *
     * @return void
     */
    public function delete(string $register, string $schema, string $objectId, string $linkedId): void;

    /**
     * Check the health / reachability of the backing service.
     *
     * @return array{status: string, message: string}
     */
    public function health(): array;

    /**
     * Normalise a raw row from the backing service into the canonical shape.
     *
     * @param array<string,mixed> $row Raw row from source.
     *
     * @return array<string,mixed> Normalised row.
     */
    public function normalizeRow(array $row): array;

    /**
     * Normalise a list of raw rows.
     *
     * @param list<array<string,mixed>> $rows Raw rows.
     *
     * @return list<array<string,mixed>> Normalised rows.
     */
    public function normalizeList(array $rows): array;
}//end interface
