<?php

/**
 * OpenRegister IntegrationProviderInterface
 *
 * Contract for all integration providers in the pluggable integration registry.
 * Providers declare their identity, auth requirements, storage strategy, and
 * delegate object-level CRUD to the appropriate storage backend.
 *
 * @category Integration
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-xwiki/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration;

/**
 * Interface for pluggable integration providers.
 *
 * Each provider connects OpenRegister objects to an external system (XWiki,
 * OpenProject, Collectives, etc.) and exposes a normalised CRUD surface.
 */
interface IntegrationProviderInterface
{
    /**
     * Machine-readable provider identifier (e.g. 'xwiki', 'openproject').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getId(): string;

    /**
     * Human-readable display label (e.g. 'Articles', 'Tasks').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getLabel(): string;

    /**
     * Icon identifier from the @mdi/js set (e.g. 'FileDocumentMultiple').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getIcon(): string;

    /**
     * Logical group this provider belongs to (e.g. 'external', 'nextcloud').
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getGroup(): string;

    /**
     * Nextcloud app ID that must be installed for this provider (or null).
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getRequiredApp(): ?string;

    /**
     * Storage strategy: 'internal' (stored in OR) or 'external' (delegated).
     *
     * @return string
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getStorageStrategy(): string;

    /**
     * OpenConnector source name for external providers (or null for internal).
     *
     * @return string|null
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function getOpenConnectorSource(): ?string;

    /**
     * Whether this provider is currently available (required app installed, etc.).
     *
     * @return bool
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function isEnabled(): bool;

    /**
     * Auth requirements declaration.
     *
     * Returns an array with at minimum a 'type' key. For external providers:
     * ['type' => 'external', 'configuredVia' => 'openconnector', 'source' => '...', 'supports' => [...]]
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function authRequirements(): array;

    /**
     * List linked items for a given OR object.
     *
     * @param string               $register Object register slug
     * @param string               $schema   Object schema slug
     * @param string               $objectId Object identifier
     * @param array<string, mixed> $params   Optional filter/pagination params
     *
     * @return array<int, array<string, mixed>>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function list(string $register, string $schema, string $objectId, array $params=[]): array;

    /**
     * Get a single linked item.
     *
     * @param string $register Object register slug
     * @param string $schema   Object schema slug
     * @param string $objectId Object identifier
     * @param string $itemId   Linked item identifier
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function get(string $register, string $schema, string $objectId, string $itemId): array;

    /**
     * Create a new link between an OR object and an external item.
     *
     * @param string               $register Object register slug
     * @param string               $schema   Object schema slug
     * @param string               $objectId Object identifier
     * @param array<string, mixed> $data     Link payload (e.g. ['reference' => $url])
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function create(string $register, string $schema, string $objectId, array $data): array;

    /**
     * Update a linked item.
     *
     * @param string               $register Object register slug
     * @param string               $schema   Object schema slug
     * @param string               $objectId Object identifier
     * @param string               $itemId   Linked item identifier
     * @param array<string, mixed> $data     Update payload
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function update(string $register, string $schema, string $objectId, string $itemId, array $data): array;

    /**
     * Remove the link between an OR object and an external item.
     *
     * External providers remove only the sub-resource pairing, never the item itself.
     *
     * @param string $register Object register slug
     * @param string $schema   Object schema slug
     * @param string $objectId Object identifier
     * @param string $itemId   Linked item identifier
     *
     * @return void
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function delete(string $register, string $schema, string $objectId, string $itemId): void;

    /**
     * Health-check the backing system. Returns ['status' => 'ok'] or ['status' => 'error', 'reason' => ...].
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function health(): array;

    /**
     * Normalise a raw row from the backing system to the canonical shape.
     *
     * @param array<string, mixed> $row Raw row data
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/integration-xwiki/tasks.md#task-1
     */
    public function normalizeRow(array $row): array;
}//end interface
