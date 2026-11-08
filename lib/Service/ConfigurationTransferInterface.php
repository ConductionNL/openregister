<?php

/**
 * ConfigurationTransferInterface: exporting and importing a configuration as JSON through ConfigurationService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\Register;

/**
 * Turns registers and configurations into a configuration array and reads one back.
 */
interface ConfigurationTransferInterface extends AppConfigurationImporterInterface {

	/**
	 * Build OpenAPI Specification from configuration or register
	 *
	 * @param array|Configuration|Register $input The configuration array, Configuration object,
	 *                                            or Register object to build the OAS from.
	 * @param bool $includeObjects Whether to include objects in the registers.
	 *
	 * @psalm-param   array<string, mixed>|Configuration|Register $input
	 * @phpstan-param array<string, mixed>|Configuration|Register $input
	 *
	 * @return array The OpenAPI specification.
	 *
	 * @throws \Exception If configuration is invalid.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-6
	 */
	public function exportConfig(array|Configuration|Register $input = [], bool $includeObjects = false): array;

	/**
	 * Import configuration from a JSON file.
	 *
	 * This method imports configuration data from a JSON file. It can handle:
	 * - Full configurations with schemas, registers, and objects
	 * - Partial configurations with only objects (using existing schemas and registers)
	 * - Objects with references to existing schemas and registers
	 * - Version checking to prevent unnecessary imports
	 *
	 * ⚠️ IMPORTANT: This method MUST be called with a Configuration entity.
	 * Direct calls without a Configuration entity will throw an exception.
	 * This ensures proper tracking of imported entities.
	 *
	 * @param array $data The configuration JSON content
	 * @param Configuration|null $configuration The configuration entity (REQUIRED)
	 * @param string|null $owner The owner of the imported data (deprecated, use configuration->app)
	 * @param string|null $appId The app ID for version tracking (deprecated, use configuration->app)
	 * @param string|null $version The version for version tracking (deprecated, use data version)
	 * @param bool $force Force import even if the same or newer version already exists
	 *
	 * @throws \Exception If called without a Configuration entity or if import fails
	 * @return array Array of created/updated entities
	 *
	 * @phpstan-return array{
	 *     registers: array<Register>,
	 *     schemas: array<Schema>,
	 *     objects: array<ObjectEntity>,
	 *     endpoints: array,
	 *     sources: array,
	 *     mappings: array,
	 *     jobs: array,
	 *     synchronizations: array,
	 *     rules: array
	 * }
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-28
	 */
	public function importFromJson(
		array $data, ?Configuration $configuration = null, ?string $owner = null, ?string $appId = null, ?string $version = null,
		bool $force = false
	): array;
}//end interface
