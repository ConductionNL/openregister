<?php

/**
 * AppSettingsInterface: reads and updates the openregister application settings, and runs the settings-page maintenance actions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

/**
 * Reads and updates the OpenRegister application settings, and runs the settings-page maintenance actions.
 * Implemented by Service\SettingsService.
 */
interface AppSettingsInterface {

	/**
	 * Clear cache
	 *
	 * @param string|null $cacheType Type of cache to clear
	 *
	 * @return array Clear cache result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function clearCache(?string $cacheType = null): array;

	/**
	 * Convert memory limit string to bytes.
	 *
	 * @param string $memoryLimit Memory limit string (e.g., '128M', '1G').
	 *
	 * @return int Memory limit in bytes.
	 *
	 * @spec exclude Pure memory-limit-string-to-bytes parsing helper; no orchestration or persisted state.
	 */
	public function convertToBytes(string $memoryLimit): int;

	/**
	 * Format bytes into human readable format
	 *
	 * @param int $bytes Number of bytes.
	 * @param int $precision Decimal precision.
	 *
	 * @return string Formatted string.
	 *
	 * @spec exclude Pure byte-to-human-readable formatting helper; no orchestration or persisted state.
	 */
	public function formatBytes(int $bytes, int $precision = 2): string;

	/**
	 * Get archival settings only
	 *
	 * @return array Archival settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getArchivalSettingsOnly(): array;

	/**
	 * Get cache statistics
	 *
	 * @return array Cache statistics
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function getCacheStats(): array;

	/**
	 * Get the persisted Deck default board+stack for a schema (or null).
	 *
	 * @param string $schemaSlug Schema slug or numeric id used to scope the default.
	 *
	 * @return array{boardId:int,stackId:int}|null Default pair, or null when none has been recorded yet.
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function getDeckDefault(string $schemaSlug): ?array;

	/**
	 * Get file settings only
	 *
	 * @return array File settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getFileSettingsOnly(): array;

	/**
	 * Get LLM settings only
	 *
	 * @return array LLM settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getLLMSettingsOnly(): array;

	/**
	 * Get multitenancy settings only
	 *
	 * @return array[] Multitenancy settings
	 *
	 * @psalm-return array{multitenancy: array{enabled: false|mixed,
	 *     defaultUserTenant: ''|mixed, defaultObjectTenant: ''|mixed,
	 *     adminOverride: mixed|true}, availableTenants: array}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getMultitenancySettingsOnly(): array;

	/**
	 * Get object settings only
	 *
	 * @return array Object settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getObjectSettingsOnly(): array;

	/**
	 * Get organisation settings only
	 *
	 * @return (mixed|null|true)[][] Organisation settings
	 *
	 * @psalm-return array{organisation: array{default_organisation: mixed|null,
	 *               auto_create_default_organisation: mixed|true}}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getOrganisationSettingsOnly(): array;

	/**
	 * Get RBAC settings only
	 *
	 * @return array RBAC settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getRbacSettingsOnly(): array;

	/**
	 * Get retention settings only
	 *
	 * @return array Retention settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getRetentionSettingsOnly(): array;

	/**
	 * Get search backend configuration
	 *
	 * @return array Search backend configuration
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-2
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getSearchBackendConfig(): array;

	/**
	 * Get settings
	 *
	 * @return array Settings configuration
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function getSettings(): array;

	/**
	 * Get statistics for the settings dashboard.
	 *
	 * This method provides counts and warnings for objects, logs, and other entities
	 * in the OpenRegister system. It uses optimized SQL queries to fetch all counts
	 * in a single database roundtrip for better performance.
	 *
	 * @return array<string, mixed> Statistics array with warnings and totals
	 *
	 * @psalm-return array{
	 *     timestamp: int,
	 *     date: string,
	 *     warnings: array{
	 *         objectsWithoutOwner: int,
	 *         objectsWithoutOrganisation: int,
	 *         auditTrailsWithoutExpiry: int,
	 *         searchTrailsWithoutExpiry: int,
	 *         expiredAuditTrails: int,
	 *         expiredSearchTrails: int,
	 *         expiredObjects: int
	 *     },
	 *     totals: array{
	 *         totalObjects: int,
	 *         totalAuditTrails: int,
	 *         totalSearchTrails: int,
	 *         totalConfigurations: int,
	 *         totalOrganisations: int,
	 *         totalRegisters: int,
	 *         totalSchemas: int,
	 *         totalSources: int,
	 *         totalWebhookLogs: int,
	 *         deletedObjects: int
	 *     },
	 *     cache?: array<string, mixed>,
	 *     system: array{
	 *         php_version: string,
	 *         memory_limit: string,
	 *         max_execution_time: string
	 *     }
	 * }
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function getStats(): array;

	/**
	 * Get version info only
	 *
	 * @return array Version information
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function getVersionInfoOnly(): array;

	/**
	 * Mask sensitive token for display.
	 *
	 * Shows first 4 and last 4 characters, masks the middle.
	 *
	 * @param string $token The token to mask.
	 *
	 * @return string The masked token.
	 *
	 * @spec exclude Pure display helper masking the middle of a token; no orchestration or persisted state.
	 */
	public function maskToken(string $token): string;

	/**
	 * Mass validate objects by re-saving them to trigger business logic
	 *
	 * Re-saves all objects in the system to ensure all business logic
	 * is triggered and objects are properly processed according to current rules.
	 *
	 * @param int $maxObjects Maximum number of objects to process (0 = all).
	 * @param int $batchSize Batch size for processing (default: 1000).
	 * @param string $mode Processing mode: 'serial' or 'parallel'.
	 * @param bool $collectErrors Whether to collect all errors or stop on first.
	 *
	 * @return array Mass validation results
	 *
	 * @throws \Exception If mass validation operation fails.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag needed for error collection behavior
	 */
	public function massValidateObjects(
		int $maxObjects = 0,
		int $batchSize = 1000,
		string $mode = 'serial',
		bool $collectErrors = false,
	): array;

	/**
	 * Rebase configuration from source.
	 *
	 * Resets configuration to default or imports from source.
	 * This is typically used for configuration management.
	 *
	 * @param array $options Rebase options
	 *
	 * @return ((string|true)[][]|bool|int|string)[] Rebase result
	 *
	 * @psalm-return array{success: bool, error?: 'Rebase failed', message: string,
	 *     rebased?: array{cache?: array{success: true,
	 *     message: 'Cache cleared and ready for rebuild'}},
	 *     timestamp?: int<1, max>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-5
	 */
	public function rebase(array $options = []): array;

	/**
	 * Persist the Deck default board+stack for a schema. Overwrites any prior value.
	 *
	 * @param string $schemaSlug Schema slug or numeric id used to scope the default.
	 * @param int $boardId NC Deck board id.
	 * @param int $stackId NC Deck stack id on that board.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-deck/spec.md
	 */
	public function setDeckDefault(string $schemaSlug, int $boardId, int $stackId): void;

	/**
	 * Update archival settings only
	 *
	 * @param array $data Archival settings data
	 *
	 * @return array Updated archival settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateArchivalSettingsOnly(array $data): array;

	/**
	 * Update file settings only
	 *
	 * @param array $data File settings data
	 *
	 * @return array Updated file settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateFileSettingsOnly(array $data): array;

	/**
	 * Update LLM settings only
	 *
	 * @param array $data LLM settings data
	 *
	 * @return array Updated LLM settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateLLMSettingsOnly(array $data): array;

	/**
	 * Update multitenancy settings only
	 *
	 * @param array $data Multitenancy settings data
	 *
	 * @return array Updated multitenancy settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateMultitenancySettingsOnly(array $data): array;

	/**
	 * Update object settings only
	 *
	 * @param array $data Object settings data
	 *
	 * @return array Updated object settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateObjectSettingsOnly(array $data): array;

	/**
	 * Update organisation settings only
	 *
	 * @param array $data Organisation settings data
	 *
	 * @return (mixed|null|true)[][] Updated organisation settings
	 *
	 * @psalm-return array{organisation: array{default_organisation: mixed|null,
	 *               auto_create_default_organisation: mixed|true}}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateOrganisationSettingsOnly(array $data): array;

	/**
	 * Update RBAC settings only
	 *
	 * @param array $data RBAC settings data
	 *
	 * @return array Updated RBAC settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateRbacSettingsOnly(array $data): array;

	/**
	 * Update retention settings only
	 *
	 * @param array $data Retention settings data
	 *
	 * @return array Updated retention settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateRetentionSettingsOnly(array $data): array;

	/**
	 * Update settings
	 *
	 * @param array $data Settings data
	 *
	 * @return array Updated settings
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-1
	 */
	public function updateSettings(array $data): array;

	/**
	 * Validate all objects in the system
	 *
	 * Triggers validation logic for all objects without re-saving them.
	 * This is a lighter-weight operation compared to massValidateObjects.
	 *
	 * @return array Validation results
	 *
	 * @throws \Exception If validation operation fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-4
	 */
	public function validateAllObjects(): array;

	/**
	 * Warmup names cache
	 *
	 * @return array Warmup result
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-settings-mgmt/tasks.md#task-3
	 */
	public function warmupNamesCache(): array;
}//end interface
