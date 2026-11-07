<?php

/**
 * AppConfigurationImporterInterface: importing an app's bundled configuration through ConfigurationService.
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

use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * Imports a configuration an app ships, from its data or from a file in the app.
 */
interface AppConfigurationImporterInterface {

	/**
	 * Import configuration from an app's JSON data.
	 *
	 * This is a convenience wrapper method for apps that want to import their
	 * configuration without manually managing Configuration entities. It:
	 * - Finds or creates a Configuration entity for the app
	 * - Handles version checking
	 * - Calls importFromJson with proper entity tracking
	 *
	 * @param string $appId The application ID (e.g. 'opencatalogi')
	 * @param array $data The configuration data to import
	 * @param string $version The version of the configuration
	 * @param bool $force Whether to force import regardless of version checks
	 *
	 * @return array The import results
	 * @throws Exception If import fails
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
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-6
	 */
	public function importFromApp(string $appId, array $data, string $version, bool $force = false): array;

	/**
	 * Import configuration from a file path.
	 *
	 * This method reads a configuration file from the filesystem and imports it.
	 * It's designed to be used by apps that store their configurations as JSON files
	 * and want OpenRegister to handle the file reading and import process.
	 *
	 * The file path should be relative to the Nextcloud root
	 * (e.g., 'apps-extra/opencatalogi/lib/Settings/publication_register.json')
	 * This enables the cron job to later check if the configuration file has been updated.
	 *
	 * @param string $appId The application ID (e.g. 'opencatalogi')
	 * @param string $filePath The file path relative to Nextcloud root
	 * @param string $version The version of the configuration
	 * @param bool $force Whether to force import regardless of version checks
	 *
	 * @return (ObjectEntity|Register|Schema|mixed)[][] Import result with counts and IDs
	 *
	 * @throws Exception If file cannot be read or import fails
	 *
	 * @since 0.2.10
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @psalm-return array{
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
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-6
	 */
	public function importFromFilePath(string $appId, string $filePath, string $version, bool $force = false): array;
}//end interface
