<?php

/**
 * ConfigurationServiceInterface: the public surface of ConfigurationService, for callers to type against.
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
use OCA\OpenRegister\Service\Configuration\CacheHandler;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Every public method of ConfigurationService. Callers depend on this interface, not on the class.
 */
interface ConfigurationServiceInterface extends ConfigurationTransferInterface {

	/**
	 * Checks if the OpenConnector service is available from the container.
	 *
	 * @return bool True if the OpenConnector service is available, false otherwise.
	 * @throws \Psr\Container\ContainerExceptionInterface|\Psr\Container\NotFoundExceptionInterface
	 *
	 * @spec exclude Facade plumbing: peer-app presence probe (installed-apps check + container resolve), no standalone behavioral contract.
	 */
	public function hasOpenConnector(): bool;

	/**
	 * Gets the uploaded json from the request data and returns it as a PHP array.
	 * Will first try to find an uploaded 'file', then if an 'url' is present in the body,
	 * and lastly if a 'json' dump has been posted.
	 *
	 * @param array $data All request params
	 * @param array|null $uploadedFiles The uploaded files array
	 *
	 * @return JSONResponse|array A PHP array with the uploaded json data or a JSONResponse in case of an error
	 *
	 * @throws \Exception
	 * @throws \GuzzleHttp\Exception\GuzzleException
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-6
	 */
	public function getUploadedJson(array $data, ?array $uploadedFiles): array|JSONResponse;

	/**
	 * The recorded import jobs of an app id, oldest first.
	 *
	 * Only jobs that created at least one traceable object are recorded, so an
	 * empty list means there is nothing an app can remove by job; a setup
	 * wizard hides its "remove this example set" button then.
	 *
	 * @param string $appId The app id the imports ran under (e.g. `learniq.demo`).
	 *
	 * @return array<int, array{jobId: string, version: string, created: int, importedAt: string}>
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-app-must-be-able-to-remove-the-objects-its-recorded-imports-created
	 */
	public function listImportJobs(string $appId): array;

	/**
	 * Soft-delete every object the recorded imports of an app id created.
	 *
	 * The call a setup wizard's "remove this example set" makes. It runs
	 * in-process and as a system operation, as importFromApp() did: the
	 * objects were written by the system, and an administrator's own RBAC on,
	 * say, an append-only schema must not stop the app removing its own
	 * example rows. WHO may remove is the calling app's decision; nothing
	 * routes here over HTTP.
	 *
	 * A job whose report has no errors is forgotten. A job with errors stays
	 * recorded, so the removal can be retried or finished with
	 * `occ openregister:objects:purge --import-job <id>`.
	 *
	 * @param string $appId The app id the imports ran under.
	 *
	 * @return array{appId: string, jobs: array<int, array<string, mixed>>, softDeleted: int, errors: array<int, array<string, string>>}
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext::run is the static scoped-elevation helper importFromApp() uses.
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-an-app-must-be-able-to-remove-the-objects-its-recorded-imports-created
	 */
	public function softDeleteAppImports(string $appId): array;

	/**
	 * Check the remote version of a configuration
	 *
	 * Fetches the configuration from the source URL and extracts its version.
	 * Updates the configuration entity with the remote version and last checked timestamp.
	 *
	 * @param Configuration $configuration The configuration to check
	 *
	 * @return string|null The remote version, or null if check failed
	 * @throws \GuzzleHttp\Exception\GuzzleException If HTTP request fails
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Version check has multiple error and validation conditions
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-7
	 */
	public function checkRemoteVersion(Configuration $configuration): ?string;

	/**
	 * Compare versions and get update status
	 *
	 * Returns detailed information about version comparison including
	 * whether an update is available and what the versions are.
	 *
	 * @param Configuration $configuration The configuration to compare versions for
	 *
	 * @return (bool|null|string)[]
	 *
	 * @phpstan-return array{
	 *     hasUpdate: bool,
	 *     localVersion: string|null,
	 *     remoteVersion: string|null,
	 *     lastChecked: string|null,
	 *     message: string
	 * }
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Version comparison has multiple null and comparison checks
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-7
	 */
	public function compareVersions(Configuration $configuration): array;

	/**
	 * Fetch remote configuration data.
	 *
	 * Downloads the configuration file from the source URL and returns
	 * the parsed data as an array. Delegates to FetchHandler for actual fetching.
	 *
	 * @param Configuration $configuration The configuration to fetch.
	 *
	 * @return JSONResponse|array The fetched configuration data or error response.
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException If HTTP request fails.
	 *
	 * @psalm-return JSONResponse<400|500, array{error: string, 'Content-Type'?: string}, array<never, never>>|array
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-6
	 */
	public function fetchRemoteConfiguration(Configuration $configuration): array|JSONResponse;

	/**
	 * Preview configuration changes before importing
	 *
	 * Compares the remote configuration with local entities and returns
	 * a detailed list of changes that would be applied, organized by entity type.
	 * Each change includes the action (create/update/skip), current state,
	 * and proposed state.
	 *
	 * @param Configuration $configuration The configuration to preview
	 *
	 * @throws \GuzzleHttp\Exception\GuzzleException If fetching remote configuration fails
	 *
	 * @return array|JSONResponse Preview data with registers, schemas, objects, endpoints, and metadata.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-6
	 */
	public function previewConfigurationChanges(Configuration $configuration): array|JSONResponse;

	/**
	 * Get the configured app version from appconfig
	 *
	 * This method retrieves the stored version of a given app from the appconfig,
	 * which is used to track which version of configuration was last imported.
	 *
	 * @param string $appId The app ID to get the version for.
	 *
	 * @return null|string The configured version or null if not set.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-7
	 */
	public function getConfiguredAppVersion(string $appId): ?string;

	/**
	 * Set the configured app version in appconfig
	 *
	 * This method stores the version of a configuration that was imported,
	 * allowing version tracking and comparison for updates.
	 *
	 * @param string $appId The app ID to set the version for.
	 * @param string $version The version to store.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-report-import-link/tasks.md#task-7
	 */
	public function setConfiguredAppVersion(string $appId, string $version): void;

	/**
	 * Search GitHub for OpenRegister configurations
	 *
	 * Delegates to GitHubHandler.
	 *
	 * @param string $search Search terms
	 * @param int $page Page number
	 * @param int $perPage Results per page
	 *
	 * @throws \Exception If search fails
	 *
	 * @return array Search results with total count, results array, page, and per_page.
	 *
	 * @spec exclude Facade plumbing: thin delegation to GitHubHandler::searchConfigurations, no standalone behavioral contract.
	 */
	public function searchGitHub(string $search = '', int $page = 1, int $perPage = 30): array;

	/**
	 * Search GitLab for OpenRegister configurations
	 *
	 * Delegates to GitLabHandler.
	 *
	 * @param string $search Search terms
	 * @param int $page Page number
	 * @param int $perPage Results per page
	 *
	 * @throws \Exception If search fails
	 *
	 * @return array Search results with total count, results array, page, and per_page.
	 *
	 * @spec exclude Facade plumbing: thin delegation to GitLabHandler::searchConfigurations, no standalone behavioral contract.
	 */
	public function searchGitLab(string $search = '', int $page = 1, int $perPage = 30): array;

	/**
	 * Get GitHubHandler for direct access
	 *
	 * @return GitHubHandler The GitHub handler
	 */
	public function getGitHubHandler(): GitHubHandler;

	/**
	 * Get GitLabHandler for direct access
	 *
	 * @return GitLabHandler The GitLab handler
	 */
	public function getGitLabHandler(): GitLabHandler;

	/**
	 * Get CacheHandler for direct access
	 *
	 * @return CacheHandler The cache handler
	 */
	public function getCacheHandler(): CacheHandler;

	/**
	 * Import a remote configuration, whole or narrowed to a selection.
	 *
	 * The preview's import endpoint passes what the administrator picked; the
	 * hourly ConfigurationCheckJob passes an empty selection, which means the
	 * whole remote document (seed data included). The document is fetched
	 * fresh, narrowed with ImportSelection, and handed to the importer, which
	 * keeps its per-entity version gates (`force` stays false).
	 *
	 * The configuration then tracks every register, schema and object the
	 * import returned, next to what it already tracked. Only a whole import
	 * moves `localVersion` to the remote version: after a partial one the rest
	 * of the update must stay on offer.
	 *
	 * @param Configuration $configuration Configuration to import
	 * @param array $selection Selected `registers`/`schemas` slugs and `register:schema:slug` object keys; empty = all
	 *
	 * @return array Import results, keyed as importFromJson() returns them
	 *
	 * @throws \Exception When the remote document cannot be fetched or the import fails
	 *
	 * @spec openspec/specs/data-import-export/spec.md#requirement-selective-configuration-import-and-auto-update-must-import-what-they-name
	 */
	public function importConfigurationWithSelection(Configuration $configuration, array $selection): array;
}//end interface
