<?php

/**
 * OpenRegister Configuration Controller
 *
 * This file contains the controller class for handling configuration-related API requests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Controller\Support\ControllerSession;
use DateTime;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Controller\Support\ConfigurationControllerServices;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\GitHubClientInterface;
use OCA\OpenRegister\Service\Configuration\GitLabHandlerInterface;
use OCA\OpenRegister\Service\ConfigurationServiceInterface;
use OCA\OpenRegister\Service\NotificationServiceInterface;
use OCA\OpenRegister\Service\Security\SafeFetchUrl;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Class ConfigurationController
 *
 * Controller for managing configurations (CRUD and management operations).
 *
 * @package OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ConfigurationController extends Controller {
	use \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;

	/**
	 * The fields a create reads, in order, with their defaults.
	 *
	 * @var array<string, mixed>
	 */
	private const CREATE_DEFAULTS = [
		'title' => 'New Configuration',
		'description' => '',
		'type' => 'manual',
		'sourceType' => 'local',
		'sourceUrl' => null,
		'app' => null,
		'version' => '1.0.0',
		'localVersion' => null,
		'registers' => [],
		'schemas' => [],
		'objects' => [],
		'autoUpdate' => false,
		'notificationGroups' => [],
		'githubRepo' => null,
		'githubBranch' => null,
		'githubPath' => null,
	];

	/**
	 * The fields an update may change (when given and not null) with their setters.
	 *
	 * @var array<string, string>
	 */
	private const UPDATE_SETTERS = [
		'title' => 'setTitle',
		'description' => 'setDescription',
		'type' => 'setType',
		'sourceType' => 'setSourceType',
		'sourceUrl' => 'setSourceUrl',
		'app' => 'setApp',
		'localVersion' => 'setLocalVersion',
		'registers' => 'setRegisters',
		'schemas' => 'setSchemas',
		'objects' => 'setObjects',
		'autoUpdate' => 'setAutoUpdate',
		'notificationGroups' => 'setNotificationGroups',
		'githubRepo' => 'setGithubRepo',
		'githubBranch' => 'setGithubBranch',
		'githubPath' => 'setGithubPath',
	];

	/**
	 * Configuration mapper instance.
	 *
	 * @var ConfigurationMapperInterface The configuration mapper instance.
	 */
	private ConfigurationMapperInterface $configurationMapper;

	/**
	 * Configuration service instance.
	 *
	 * @var ConfigurationServiceInterface The configuration service instance.
	 */
	private ConfigurationServiceInterface $configurationService;

	/**
	 * Notification service instance.
	 *
	 * @var NotificationServiceInterface The notification service instance.
	 */
	private NotificationServiceInterface $notificationService;

	/**
	 * GitHub handler instance.
	 *
	 * @var GitHubClientInterface The GitHub handler instance.
	 */
	private GitHubClientInterface $githubHandler;

	/**
	 * GitLab handler instance.
	 *
	 * @var GitLabHandlerInterface The GitLab handler instance.
	 */
	private GitLabHandlerInterface $gitlabHandler;

	/**
	 * Logger instance.
	 *
	 * @var LoggerInterface The logger instance.
	 */
	private LoggerInterface $logger;

	/**
	 * App manager instance.
	 *
	 * @var IAppManager The app manager instance.
	 */
	private IAppManager $appManager;

	/**
	 * The session bundle, which answers the admin check.
	 *
	 * @var ControllerSession
	 */
	private readonly ControllerSession $session;

	/**
	 * Constructor
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request object
	 * @param ConfigurationControllerServices $services The configuration controller services.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		ConfigurationControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->session = $services->session;

		$this->configurationMapper = $services->mappers->configurationMapper;
		$this->configurationService = $services->domain->configurationService;
		$this->notificationService = $services->domain->notificationService;
		$this->githubHandler = $services->domain->githubHandler;
		$this->gitlabHandler = $services->domain->gitlabHandler;
		$this->appManager = $services->platform->appManager;
		$this->logger = $services->session->logger;
	}//end __construct()

	/**
	 * Check whether the currently authenticated user is a Nextcloud administrator.
	 *
	 * @return bool True if a user is signed in and belongs to the admin group.
	 */
	private function isCurrentUserAdmin(): bool {
		return $this->session->isAdmin();
	}//end isCurrentUserAdmin()

	/**
	 * Get all configurations.
	 *
	 * @return JSONResponse JSON response with configurations list
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|500, array<'Failed to fetch configurations'|Configuration>, array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function index(): JSONResponse {
		try {
			$configurations = $this->configurationMapper->findAll();

			return new JSONResponse(data: $configurations, statusCode: 200);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ConfigurationController] Failed to fetch configurations: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
				]
			);

			return new JSONResponse(data: ['error' => 'Failed to fetch configurations'], statusCode: 500);
		}//end try
	}//end index()

	/**
	 * Get a single configuration by ID.
	 *
	 * @param int $id The configuration ID
	 *
	 * @return JSONResponse JSON response with single configuration
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200, Configuration,
	 *     array<never, never>>|JSONResponse<403|404|500,
	 *     array{error: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function show(int $id): JSONResponse {
		// SEC-CTRL-3: prevent IDOR — only admins may read a configuration by id.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->onConfiguration(
			id: $id,
			failure: 'fetch',
			action: fn (Configuration $configuration): JSONResponse => new JSONResponse(data: $configuration, statusCode: 200),
			failed: fn (): JSONResponse => new JSONResponse(data: ['error' => 'Failed to fetch configuration'], statusCode: 500)
		);
	}//end show()

	/**
	 * Run an action on the configuration with an id: 404 when it does not
	 * exist; any other failure is logged and answered by $failed.
	 *
	 * @param int      $id      The configuration id
	 * @param string   $failure The verb of the failure log ("Failed to <verb> configuration <id>")
	 * @param callable $action  The action, given the configuration
	 * @param callable $failed  The response to a failure, given the exception
	 *
	 * @return JSONResponse The action's response, 404 or the failure response
	 */
	private function onConfiguration(int $id, string $failure, callable $action, callable $failed): JSONResponse {
		try {
			return $action($this->configurationMapper->find($id));
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(data: ['error' => 'Configuration not found'], statusCode: 404);
		} catch (Exception $e) {
			$this->logger->error(
				message: "[ConfigurationController] Failed to {$failure} configuration {$id}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $failed($e);
		}
	}//end onConfiguration()

	/**
	 * Enrich configuration details by fetching actual file contents
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with enriched configuration details
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function enrichDetails(): JSONResponse {
		// SEC-CTRL: admin-only — reaches external repo content via the
		// instance-wide admin-configured GitHub/GitLab credential (matches the
		// guard on this controller's create/update/import/publish siblings).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		try {
			return $this->enrich(data: $this->request->getParams());
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ConfigurationController] Configuration enrichment failed: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($e), 'trace' => $e->getTraceAsString()]
			);
			return $this->errorResponse(e: $e, context: 'Failed to enrich configuration');
		}
	}//end enrichDetails()

	/**
	 * Fetch the details of a configuration file on a source.
	 *
	 * @param array<string, mixed> $data The request parameters (source, owner, repo, path, branch)
	 *
	 * @return JSONResponse The details, 400 without owner, repo and path, or 404 when there are none
	 */
	private function enrich(array $data): JSONResponse {
		$defaults = ['source' => 'github', 'owner' => '', 'repo' => '', 'path' => '', 'branch' => 'main'];
		$meta     = $this->paramsWithDefaults(params: $data, defaults: $defaults);
		$source = strtolower($meta['source']);
		if (empty($meta['owner']) === true || empty($meta['repo']) === true || empty($meta['path']) === true) {
			return new JSONResponse(data: ['error' => 'Missing required parameters: owner, repo, path'], statusCode: 400);
		}

		$context = ['file' => __FILE__, 'line' => __LINE__, 'source' => $source] + array_intersect_key($meta, ['owner' => 0, 'repo' => 0, 'path' => 0]);
		$this->logger->debug(message: '[ConfigurationController] Enriching configuration details', context: $context);
		$details = $this->detailsFrom(source: $source, meta: $meta);
		if ($details === null) {
			return new JSONResponse(data: ['error' => 'Failed to fetch configuration details'], statusCode: 404);
		}

		return new JSONResponse(data: $details, statusCode: 200);
	}//end enrich()

	/**
	 * The details of a configuration file: GitHub only; GitLab enrichment is
	 * not implemented yet and any other source has none.
	 *
	 * @param string               $source The lower-cased source
	 * @param array<string, mixed> $meta   The owner, repo, path and branch
	 *
	 * @return array|null The details, or null
	 */
	private function detailsFrom(string $source, array $meta): ?array {
		if ($source === 'gitlab') {
			$context = ['file' => __FILE__, 'line' => __LINE__];
			$this->logger->warning(message: '[ConfigurationController] GitLab enrichment not yet implemented', context: $context);
			return null;
		}

		if ($source !== 'github') {
			return null;
		}

		return $this->githubHandler->enrichConfigurationDetails(owner: $meta['owner'], repo: $meta['repo'], path: $meta['path'], branch: $meta['branch']);
	}//end detailsFrom()

	/**
	 * Create a new configuration.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with created configuration
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function create(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		try {
			$created = $this->configurationMapper->insert($this->newConfiguration(data: $this->request->getParams()));
			$this->logger->debug(
				message: "[ConfigurationController] Created configuration: {$created->getTitle()} (ID: {$created->getId()})",
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return new JSONResponse($created->jsonSerialize(), Http::STATUS_CREATED);
		} catch (Exception $e) {
			return $this->createFailure(e: $e);
		}
	}//end create()

	/**
	 * Log a failed create and answer it with a generic 500.
	 *
	 * @param Exception $e The failure
	 *
	 * @return JSONResponse The error response
	 */
	private function createFailure(Exception $e): JSONResponse {
		$context = ['file' => __FILE__, 'line' => __LINE__];
		$this->logger->error(message: '[ConfigurationController] Failed to create configuration: ' . $e->getMessage(), context: $context);

		return $this->errorResponse(e: $e, context: 'Failed to create configuration');
	}//end createFailure()

	/**
	 * A new configuration from request data: each field falls back to its
	 * default, and a local configuration's localVersion defaults to its version.
	 *
	 * @param array<string, mixed> $data The request parameters
	 *
	 * @return Configuration The configuration, not yet inserted
	 */
	private function newConfiguration(array $data): Configuration {
		$configuration = new Configuration();
		foreach (self::CREATE_DEFAULTS as $field => $default) {
			$setter = 'set' . ucfirst($field);
			$configuration->$setter($data[$field] ?? $default);
		}

		if ($configuration->getIsLocal() === true) {
			$configuration->setLocalVersion($data['localVersion'] ?? $configuration->getVersion());
		}

		return $configuration;
	}//end newConfiguration()

	/**
	 * Update an existing configuration.
	 *
	 * @param int $id The configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated configuration
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity) Already refactored — NPath from try/catch + field mapping
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function update(int $id): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->onConfiguration(
			id: $id,
			failure: 'update',
			action: fn (Configuration $configuration): JSONResponse => $this->saveUpdate(configuration: $configuration),
			failed: fn (Exception $e): JSONResponse => $this->errorResponse(e: $e, context: 'Failed to update configuration')
		);
	}//end update()

	/**
	 * Apply the request to a configuration and save it.
	 *
	 * @param Configuration $configuration The configuration
	 *
	 * @return JSONResponse The saved configuration
	 */
	private function saveUpdate(Configuration $configuration): JSONResponse {
		$this->applyConfigurationUpdates(configuration: $configuration, data: $this->request->getParams());
		$updated = $this->configurationMapper->update($configuration);
		$this->logger->debug(
			message: "[ConfigurationController] Updated configuration: {$updated->getTitle()} (ID: {$updated->getId()})",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return new JSONResponse(data: $updated, statusCode: 200);
	}//end saveUpdate()

	/**
	 * Apply configuration updates from request data.
	 *
	 * @param Configuration $configuration Configuration entity to update
	 * @param array $data Request data with field updates
	 *
	 * @return void
	 */
	private function applyConfigurationUpdates(Configuration $configuration, array $data): void {
		foreach (self::UPDATE_SETTERS as $field => $setter) {
			if (($data[$field] ?? null) !== null) {
				$configuration->$setter($data[$field]);
			}
		}

		// The version of a local configuration is mirrored to its localVersion.
		if (($data['version'] ?? null) !== null) {
			$configuration->setVersion($data['version']);
			if ($configuration->getIsLocal() === true) {
				$configuration->setLocalVersion($data['version']);
			}
		}
	}//end applyConfigurationUpdates()

	/**
	 * Delete a configuration.
	 *
	 * @param int $id The configuration ID
	 *
	 * @return JSONResponse Success response
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function destroy(int $id): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->onConfiguration(
			id: $id,
			failure: 'delete',
			action: fn (Configuration $configuration): JSONResponse => $this->deleteConfiguration(configuration: $configuration, id: $id),
			failed: fn (): JSONResponse => new JSONResponse(data: ['error' => 'Failed to delete configuration'], statusCode: 500)
		);
	}//end destroy()

	/**
	 * Delete a configuration.
	 *
	 * @param Configuration $configuration The configuration
	 * @param int           $id            The configuration id, for the log
	 *
	 * @return JSONResponse The success response
	 */
	private function deleteConfiguration(Configuration $configuration, int $id): JSONResponse {
		$this->configurationMapper->delete($configuration);
		$this->logger->debug(
			message: "[ConfigurationController] Deleted configuration: {$configuration->getTitle()} (ID: {$id})",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return new JSONResponse(data: ['success' => true], statusCode: 200);
	}//end deleteConfiguration()

	/**
	 * Check remote version of a configuration.
	 *
	 * @param int $id The configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with version comparison
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function versionStatus(int $id): JSONResponse {
		return $this->onConfiguration(
			id: $id,
			failure: 'check version for',
			action: fn (Configuration $configuration): JSONResponse => $this->versionComparison(configuration: $configuration, id: $id),
			failed: fn (): JSONResponse => new JSONResponse(data: ['error' => 'Failed to check version'], statusCode: 500)
		);
	}//end versionStatus()

	/**
	 * Compare a configuration with its remote version.
	 *
	 * @param Configuration $configuration The configuration
	 * @param int           $id            The configuration id, for the log
	 *
	 * @return JSONResponse The comparison, or 500 when the remote cannot be read
	 */
	private function versionComparison(Configuration $configuration, int $id): JSONResponse {
		try {
			if ($this->configurationService->checkRemoteVersion($configuration) === null) {
				return new JSONResponse(data: ['error' => 'Could not check remote version'], statusCode: 500);
			}

			return new JSONResponse(data: $this->configurationService->compareVersions($configuration), statusCode: 200);
		} catch (GuzzleException $e) {
			$this->logger->error(
				message: "[ConfigurationController] Failed to check version for configuration {$id}: " . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->errorResponse(e: $e, context: 'Failed to fetch remote version');
		}
	}//end versionComparison()

	/**
	 * Preview configuration changes.
	 *
	 * @param int $id The configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress InvalidReturnStatement
	 * @psalm-suppress InvalidReturnType
	 *
	 * @return JSONResponse JSON response with configuration preview
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function preview(int $id): JSONResponse {
		// SEC-CTRL-3: prevent IDOR — only admins may preview a configuration by id.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->onConfiguration(
			id: $id,
			failure: 'preview',
			action: fn (Configuration $configuration): JSONResponse => $this->previewChanges(configuration: $configuration),
			failed: fn (): JSONResponse => new JSONResponse(data: ['error' => 'Failed to preview configuration changes'], statusCode: 500)
		);
	}//end preview()

	/**
	 * The changes importing a configuration would make.
	 *
	 * @param Configuration $configuration The configuration
	 *
	 * @return JSONResponse The preview, or the service's own response
	 */
	private function previewChanges(Configuration $configuration): JSONResponse {
		$preview = $this->configurationService->previewConfigurationChanges($configuration);
		if ($preview instanceof JSONResponse) {
			return $preview;
		}

		return new JSONResponse(data: $preview, statusCode: 200);
	}//end previewChanges()

	/**
	 * Import configuration with user selection.
	 *
	 * @param int $id The configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with import result
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function import(int $id): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->onConfiguration(
			id: $id,
			failure: 'import',
			action: fn (Configuration $configuration): JSONResponse => $this->importSelection(configuration: $configuration),
			failed: fn (Exception $e): JSONResponse => $this->errorResponse(e: $e, context: 'Failed to import configuration')
		);
	}//end import()

	/**
	 * Import the requested selection of a configuration and mark its
	 * update notifications processed.
	 *
	 * @param Configuration $configuration The configuration
	 *
	 * @return JSONResponse The imported counts
	 */
	private function importSelection(Configuration $configuration): JSONResponse {
		$selection = $this->request->getParams()['selection'] ?? [];
		$result    = $this->configurationService->importConfigurationWithSelection(configuration: $configuration, selection: $selection);
		$this->notificationService->markConfigurationUpdated($configuration);

		$counts = ['registers' => count($result['registers']), 'schemas' => count($result['schemas']), 'objects' => count($result['objects'])];
		$this->logger->debug(
			message: "[ConfigurationController] Imported configuration {$configuration->getTitle()}",
			context: ['file' => __FILE__, 'line' => __LINE__] + $counts
		);

		return new JSONResponse(
			data: ['success' => true, 'registersCount' => $counts['registers'], 'schemasCount' => $counts['schemas'], 'objectsCount' => $counts['objects']],
			statusCode: 200
		);
	}//end importSelection()

	/**
	 * Export configuration to download or GitHub.
	 *
	 * @param int $id The configuration ID
	 *
	 * @return JSONResponse JSON response with configuration data
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|403|404|500, array, array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function export(int $id): JSONResponse {
		// SEC-CTRL-3: prevent IDOR — export (incl. ?includeObjects=true) serialises a
		// configuration's objects; restrict to admins to block bulk exfiltration by id.
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->onConfiguration(
			id: $id,
			failure: 'export',
			action: fn (Configuration $configuration): JSONResponse => $this->exported(configuration: $configuration),
			failed: fn (Exception $e): JSONResponse => $this->errorResponse(e: $e, context: 'Failed to export configuration')
		);
	}//end export()

	/**
	 * Export a configuration; its objects only when `includeObjects` is
	 * exactly true.
	 *
	 * @param Configuration $configuration The configuration
	 *
	 * @return JSONResponse The export data, for download
	 */
	private function exported(Configuration $configuration): JSONResponse {
		$includeObjects = ($this->request->getParams()['includeObjects'] ?? false) === true;
		$exportData     = $this->configurationService->exportConfig(input: $configuration, includeObjects: $includeObjects);

		return new JSONResponse(data: $exportData, statusCode: 200);
	}//end exported()

	/**
	 * Discover OpenRegister configurations on GitHub or GitLab
	 *
	 * @return JSONResponse JSON response with search results from GitHub
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400|403|500,
	 *     array{error?: string, total_count?: int<0, max>|mixed,
	 *     results?: list{0?: array{repository?: mixed, owner?: string,
	 *     repo?: string, path: mixed|string, url: ''|mixed, stars?: 0|mixed,
	 *     description?: ''|mixed, name: string, branch?: string,
	 *     raw_url?: string, sha?: null|string,
	 *     organization?: array{name: string, avatar_url: ''|mixed,
	 *     type: 'User'|mixed, url: ''|mixed}, config: array,
	 *     project_id?: mixed, ref?: 'main'|mixed}|mixed,...},
	 *     page?: int, per_page?: int}, array<never, never>>
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function discover(): JSONResponse {
		// SEC-CTRL: admin-only — reaches external repo content via the
		// instance-wide admin-configured GitHub/GitLab credential (matches the
		// guard on this controller's create/update/import/publish siblings).
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		$source = 'unknown';
		try {
			$data   = $this->request->getParams();
			$source = strtolower($data['source'] ?? 'github');
			return $this->discoverIn(source: $source, data: $data);
		} catch (Exception $e) {
			$this->logDiscoveryFailure(e: $e, source: $source);
			return $this->errorResponse(e: $e, context: 'Failed to discover configurations');
		}
	}//end discover()

	/**
	 * Search configurations on a source. The GitLab search always runs; a
	 * GitHub request then replaces its result with the GitHub search.
	 *
	 * @param string               $source The lower-cased source
	 * @param array<string, mixed> $data   The request parameters
	 *
	 * @return JSONResponse The search result, or 400 for an unknown source
	 */
	private function discoverIn(string $source, array $data): JSONResponse {
		$search = $data['_search'] ?? '';
		$page   = (int)($data['page'] ?? 1);
		$context = ['file' => __FILE__, 'line' => __LINE__, 'source' => $source, '_search' => $search, 'page' => $page];
		$this->logger->debug(message: '[ConfigurationController] Discovering configurations', context: $context);
		if (in_array($source, ['github', 'gitlab']) === false) {
			return new JSONResponse(data: ['error' => 'Invalid source. Must be "github" or "gitlab"'], statusCode: 400);
		}

		$results = $this->searchGitLab(search: $search, page: $page);
		if ($source === 'github') {
			$results = $this->searchGitHub(search: $search, page: $page);
		}

		return new JSONResponse(data: $results, statusCode: 200);
	}//end discoverIn()

	/**
	 * Search configurations on GitLab.
	 *
	 * @param mixed $search The search text
	 * @param int   $page   The page
	 *
	 * @return array The search result
	 */
	private function searchGitLab(mixed $search, int $page): array {
		$this->logger->debug(message: '[ConfigurationController] About to call GitLab search service', context: ['file' => __FILE__, 'line' => __LINE__]);
		$results = $this->gitlabHandler->searchConfigurations(search: $search, page: $page);
		$this->logger->debug(
			message: '[ConfigurationController] GitLab search completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'result_count' => count($results['results'] ?? [])]
		);

		return $results;
	}//end searchGitLab()

	/**
	 * Search configurations on GitHub.
	 *
	 * @param mixed $search The search text
	 * @param int   $page   The page
	 *
	 * @return array The search result
	 */
	private function searchGitHub(mixed $search, int $page): array {
		$this->logger->debug(message: '[ConfigurationController] About to call GitHub search service', context: ['file' => __FILE__, 'line' => __LINE__]);
		$results = $this->githubHandler->searchConfigurations(search: $search, page: $page);
		$this->logger->debug(
			message: '[ConfigurationController] GitHub search completed',
			context: ['file' => __FILE__, 'line' => __LINE__, 'result_count' => count($results['results'] ?? [])]
		);

		return $results;
	}//end searchGitHub()

	/**
	 * Log a failed discovery.
	 *
	 * @param Exception $e      The failure
	 * @param string    $source The requested source, or 'unknown'
	 *
	 * @return void
	 */
	private function logDiscoveryFailure(Exception $e, string $source): void {
		$this->logger->error(
			message: '[ConfigurationController] Configuration discovery failed: ' . $e->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__, 'source' => $source, 'exception' => get_class($e), 'trace' => $e->getTraceAsString()]
		);
	}//end logDiscoveryFailure()

	/**
	 * The 403 response for a caller who is not an admin.
	 *
	 * @return JSONResponse The refusal
	 */
	private function adminRequired(): JSONResponse {
		return new JSONResponse(data: ['error' => 'Admin privileges required'], statusCode: 403);
	}//end adminRequired()

	/**
	 * Run an admin-only repository action: anyone but an admin is refused
	 * before the action runs; a failure is logged and answered with a
	 * generic 500.
	 *
	 * @param string   $failure      The log message of a failure, without prefix and reason
	 * @param string   $errorContext The context label of the 500
	 * @param callable $action       The action, given the request parameters
	 *
	 * @return JSONResponse The action's response, the refusal or the 500
	 */
	private function asAdmin(string $failure, string $errorContext, callable $action): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		try {
			return $action($this->request->getParams());
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ConfigurationController] ' . $failure . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return $this->errorResponse(e: $e, context: $errorContext);
		}
	}//end asAdmin()

	/**
	 * Get branches from a GitHub repository
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with branches list
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function getGitHubBranches(): JSONResponse {
		// SEC-CTRL: admin-only — reaches external repo content via the
		// instance-wide admin-configured GitHub/GitLab credential (matches the
		// guard on this controller's create/update/import/publish siblings).
		return $this->asAdmin(
			failure: 'Failed to get GitHub branches',
			errorContext: 'Failed to fetch branches',
			action: fn (array $data): JSONResponse => $this->gitHubBranches(data: $data)
		);
	}//end getGitHubBranches()

	/**
	 * The branches of a GitHub repository.
	 *
	 * @param array<string, mixed> $data The request parameters (owner, repo)
	 *
	 * @return JSONResponse The branches, or 400 without owner and repo
	 */
	private function gitHubBranches(array $data): JSONResponse {
		$owner = $data['owner'] ?? '';
		$repo  = $data['repo'] ?? '';
		if (empty($owner) === true || empty($repo) === true) {
			return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
		}

		$this->logger->debug(
			message: '[ConfigurationController] Fetching GitHub branches',
			context: ['file' => __FILE__, 'line' => __LINE__, 'owner' => $owner, 'repo' => $repo]
		);

		return new JSONResponse(data: ['branches' => $this->githubHandler->getBranches(owner: $owner, repo: $repo)], statusCode: 200);
	}//end gitHubBranches()

	/**
	 * Get repositories that the authenticated user has access to
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with repositories list
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function getGitHubRepositories(): JSONResponse {
		// SEC-CTRL: admin-only — enumerates repositories reachable by the
		// instance-wide admin-configured GitHub credential (matches the guard
		// on this controller's create/update/import/publish siblings).
		return $this->asAdmin(
			failure: 'Failed to get GitHub repositories',
			errorContext: 'Failed to fetch repositories',
			action: fn (array $data): JSONResponse => $this->gitHubRepositories(data: $data)
		);
	}//end getGitHubRepositories()

	/**
	 * One page of the GitHub repositories the credential reaches.
	 *
	 * @param array<string, mixed> $data The request parameters (page, per_page)
	 *
	 * @return JSONResponse The repositories
	 */
	private function gitHubRepositories(array $data): JSONResponse {
		$page    = (int)($data['page'] ?? 1);
		$perPage = (int)($data['per_page'] ?? 100);
		$this->logger->debug(
			message: '[ConfigurationController] Fetching GitHub repositories',
			context: ['file' => __FILE__, 'line' => __LINE__, 'page' => $page, 'per_page' => $perPage]
		);

		return new JSONResponse(
			data: ['repositories' => $this->githubHandler->getRepositories(page: $page, perPage: $perPage)],
			statusCode: 200
		);
	}//end gitHubRepositories()

	/**
	 * Get configuration files from a GitHub repository
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with configuration files
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function getGitHubConfigurations(): JSONResponse {
		// SEC-CTRL: admin-only — reaches external repo content via the
		// instance-wide admin-configured GitHub credential (matches the guard
		// on this controller's create/update/import/publish siblings).
		return $this->asAdmin(
			failure: 'Failed to get GitHub configurations',
			errorContext: 'Failed to fetch configurations',
			action: fn (array $data): JSONResponse => $this->gitHubConfigurations(data: $data)
		);
	}//end getGitHubConfigurations()

	/**
	 * The configuration files on a branch of a GitHub repository.
	 *
	 * @param array<string, mixed> $data The request parameters (owner, repo, branch)
	 *
	 * @return JSONResponse The files, or 400 without owner and repo
	 */
	private function gitHubConfigurations(array $data): JSONResponse {
		$owner  = $data['owner'] ?? '';
		$repo   = $data['repo'] ?? '';
		$branch = $data['branch'] ?? 'main';
		if (empty($owner) === true || empty($repo) === true) {
			return new JSONResponse(data: ['error' => 'Owner and repo parameters are required'], statusCode: 400);
		}

		$this->logger->debug(
			message: '[ConfigurationController] Fetching GitHub configurations',
			context: ['file' => __FILE__, 'line' => __LINE__, 'owner' => $owner, 'repo' => $repo, 'branch' => $branch]
		);
		$files = $this->githubHandler->listConfigurationFiles(owner: $owner, repo: $repo, branch: $branch);

		return new JSONResponse(data: ['files' => $files], statusCode: 200);
	}//end gitHubConfigurations()

	/**
	 * Get branches from a GitLab project
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with branches list
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function getGitLabBranches(): JSONResponse {
		// SEC-CTRL: admin-only — reaches external repo content via the
		// instance-wide admin-configured GitLab credential (matches the guard
		// on this controller's create/update/import/publish siblings).
		return $this->asAdmin(
			failure: 'Failed to get GitLab branches',
			errorContext: 'Failed to fetch branches',
			action: fn (array $data): JSONResponse => $this->gitLabBranches(data: $data)
		);
	}//end getGitLabBranches()

	/**
	 * The branches of a GitLab project.
	 *
	 * @param array<string, mixed> $data The request parameters (namespace, project)
	 *
	 * @return JSONResponse The branches, or 400 without namespace and project
	 */
	private function gitLabBranches(array $data): JSONResponse {
		$namespace = $data['namespace'] ?? '';
		$project   = $data['project'] ?? '';
		if (empty($namespace) === true || empty($project) === true) {
			return new JSONResponse(data: ['error' => 'Namespace and project parameters are required'], statusCode: 400);
		}

		$projectId = $this->gitlabHandler->getProjectByPath(namespace: $namespace, project: $project)['id'];
		$this->logger->debug(
			message: '[ConfigurationController] Fetching GitLab branches',
			context: ['file' => __FILE__, 'line' => __LINE__, 'namespace' => $namespace, 'project' => $project, 'project_id' => $projectId]
		);

		return new JSONResponse(data: ['branches' => $this->gitlabHandler->getBranches($projectId)], statusCode: 200);
	}//end gitLabBranches()

	/**
	 * Get configuration files from a GitLab project
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with configuration files
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function getGitLabConfigurations(): JSONResponse {
		// SEC-CTRL: admin-only — reaches external repo content via the
		// instance-wide admin-configured GitLab credential (matches the guard
		// on this controller's create/update/import/publish siblings).
		return $this->asAdmin(
			failure: 'Failed to get GitLab configurations',
			errorContext: 'Failed to fetch configurations',
			action: fn (array $data): JSONResponse => $this->gitLabConfigurations(data: $data)
		);
	}//end getGitLabConfigurations()

	/**
	 * The configuration files on a ref of a GitLab project.
	 *
	 * @param array<string, mixed> $data The request parameters (namespace, project, ref)
	 *
	 * @return JSONResponse The files, or 400 without namespace and project
	 */
	private function gitLabConfigurations(array $data): JSONResponse {
		$namespace = $data['namespace'] ?? '';
		$project   = $data['project'] ?? '';
		$ref       = $data['ref'] ?? 'main';
		if (empty($namespace) === true || empty($project) === true) {
			return new JSONResponse(data: ['error' => 'Namespace and project parameters are required'], statusCode: 400);
		}

		$projectId = $this->gitlabHandler->getProjectByPath(namespace: $namespace, project: $project)['id'];
		$this->logger->debug(
			message: '[ConfigurationController] Fetching GitLab configurations',
			context: ['file' => __FILE__, 'line' => __LINE__, 'namespace' => $namespace, 'project' => $project,
				'project_id' => $projectId, 'ref' => $ref]
		);

		return new JSONResponse(data: ['files' => $this->gitlabHandler->listConfigurationFiles(projectId: $projectId, ref: $ref)], statusCode: 200);
	}//end gitLabConfigurations()

	/**
	 * Fetch configuration data from GitHub repository.
	 *
	 * @param array $params Request parameters containing owner, repo, path, branch
	 *
	 * @return array Config data with source URL and metadata.
	 *
	 * @throws Exception If parameters are missing or GitHub API call fails.
	 */
	private function fetchConfigFromGitHub(array $params): array {
		$meta = $this->paramsWithDefaults(params: $params, defaults: ['owner' => '', 'repo' => '', 'path' => '', 'branch' => 'main']);
		$this->requireNonEmpty(values: $meta, keys: ['owner', 'repo', 'path'], message: 'Owner, repo, and path parameters are required');

		return [
			'configData' => $this->githubHandler->getFileContent(owner: $meta['owner'], repo: $meta['repo'], path: $meta['path'], branch: $meta['branch']),
			'sourceUrl' => "https://github.com/{$meta['owner']}/{$meta['repo']}/blob/{$meta['branch']}/{$meta['path']}",
			'metadata' => $meta,
		];
	}//end fetchConfigFromGitHub()

	/**
	 * Read request parameters, each falling back to its default.
	 *
	 * @param array<string, mixed> $params   The request parameters
	 * @param array<string, mixed> $defaults The wanted keys with their defaults, in order
	 *
	 * @return array<string, mixed> The values, keyed and ordered like $defaults
	 */
	private function paramsWithDefaults(array $params, array $defaults): array {
		$values = [];
		foreach ($defaults as $key => $default) {
			$values[$key] = $params[$key] ?? $default;
		}

		return $values;
	}//end paramsWithDefaults()

	/**
	 * Refuse values in which any of the given keys is empty.
	 *
	 * @param array<string, mixed> $values  The values
	 * @param array<string>        $keys    The keys that must not be empty
	 * @param string               $message The message of the refusal
	 *
	 * @return void
	 *
	 * @throws Exception With code 400 when a key is empty
	 */
	private function requireNonEmpty(array $values, array $keys, string $message): void {
		foreach ($keys as $key) {
			if (empty($values[$key]) === true) {
				throw new Exception($message, 400);
			}
		}
	}//end requireNonEmpty()

	/**
	 * Fetch configuration data from GitLab repository.
	 *
	 * @param array $params Request parameters containing namespace, project, path, ref
	 *
	 * @return array Configuration data, source URL, and metadata
	 *
	 * @throws Exception If parameters are missing or GitLab API call fails
	 */
	private function fetchConfigFromGitLab(array $params): array {
		$meta = $this->paramsWithDefaults(params: $params, defaults: ['namespace' => '', 'project' => '', 'path' => '', 'ref' => 'main']);
		$this->requireNonEmpty(values: $meta, keys: ['namespace', 'project', 'path'], message: 'Namespace, project, and path parameters are required');

		$projectId  = $this->gitlabHandler->getProjectByPath(namespace: $meta['namespace'], project: $meta['project'])['id'];
		$configData = $this->gitlabHandler->getFileContent(projectId: $projectId, path: $meta['path'], ref: $meta['ref']);
		$webBase    = str_replace('/api/v4', '', $this->gitlabHandler->getApiBase());

		return [
			'configData' => $configData,
			'sourceUrl' => "{$webBase}/{$meta['namespace']}/{$meta['project']}/-/blob/{$meta['ref']}/{$meta['path']}",
			'metadata' => ['namespace' => $meta['namespace'], 'project' => $meta['project'], 'projectId' => $projectId,
				'path' => $meta['path'], 'ref' => $meta['ref']],
		];
	}//end fetchConfigFromGitLab()

	/**
	 * Fetch configuration data from URL.
	 *
	 * @param array $params Request parameters containing url
	 *
	 * @return array Configuration data, source URL, and metadata
	 *
	 * @throws Exception If URL is missing, invalid, or fetch fails
	 *
	 * @psalm-return array{configData: array, sourceUrl: string, metadata: array{url: string}}
	 */
	private function fetchConfigFromUrl(array $params): array {
		$url = $this->safeFetchUrl(url: $params['url'] ?? '');

		// Redirects are disabled so a public host cannot 302 us to an internal
		// address after the point-in-time DNS validation.
		$client     = new Client();
		$response   = $client->request('GET', $url, ['allow_redirects' => false]);
		$configData = json_decode($response->getBody()->getContents(), true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			throw new Exception('Invalid JSON in URL response: ' . json_last_error_msg());
		}

		return [
			'configData' => $configData,
			'sourceUrl' => $url,
			'metadata' => ['url' => $url],
		];
	}//end fetchConfigFromUrl()

	/**
	 * Validate a URL to import from: present, well-formed, and not resolving
	 * to a loopback, private, reserved or link-local address.
	 *
	 * SEC-SVC-6: the shared anti-SSRF check resolves every A and AAAA record
	 * (no DNS-rebinding or IPv6 bypass).
	 *
	 * @param mixed $url The requested URL
	 *
	 * @return string The URL
	 *
	 * @throws Exception With code 400 when the URL may not be fetched
	 */
	private function safeFetchUrl(mixed $url): string {
		if (empty($url) === true) {
			throw new Exception('URL parameter is required', 400);
		}

		if (filter_var($url, FILTER_VALIDATE_URL) === false) {
			throw new Exception('Invalid URL provided', 400);
		}

		try {
			SafeFetchUrl::assert(url: $url);
		} catch (\InvalidArgumentException $e) {
			throw new Exception($e->getMessage(), 400);
		}

		return $url;
	}//end safeFetchUrl()

	/**
	 * Common import pipeline for all configuration sources.
	 *
	 * This method handles the standard import flow:
	 * 1. Fetch configuration data from source (via callback)
	 * 2. Extract metadata
	 * 3. Check for existing configuration
	 * 4. Create configuration entity
	 * 5. Import using standard flow
	 * 6. Update sync status
	 * 7. Return success response
	 *
	 * @param callable $fetchConfig Function that fetches config data from the request parameters
	 * @param string   $sourceType  Source type (github, gitlab, url)
	 *
	 * @return JSONResponse JSON response with import result
	 */
	private function importFromSource(callable $fetchConfig, string $sourceType): JSONResponse {
		$params = $this->request->getParams();
		try {
			return $this->importWith(fetchConfig: $fetchConfig, sourceType: $sourceType, params: $params);
		} catch (Exception $e) {
			return $this->importFailure(exception: $e, sourceType: $sourceType);
		}
	}//end importFromSource()

	/**
	 * Fetch a configuration from a source and import it as a new remote
	 * configuration, unless its app already has one.
	 *
	 * @param callable $fetchConfig The source-specific fetch, given the request parameters
	 * @param string   $sourceType  The source type
	 * @param array    $params      The request parameters
	 *
	 * @return JSONResponse The 201 response or the 409 conflict
	 *
	 * @throws Exception When fetching or importing fails
	 */
	private function importWith(callable $fetchConfig, string $sourceType, array $params): JSONResponse {
		$context = ['file' => __FILE__, 'line' => __LINE__, 'fields' => array_keys($params)];
		$this->logger->debug(message: "[ConfigurationController] Importing configuration from {$sourceType}", context: $context);

		$plan = $this->importPlan(fetchResult: $fetchConfig($params), sourceType: $sourceType);
		$plan['syncEnabled']  = ($params['syncEnabled'] ?? true) === true;
		$plan['syncInterval'] = (int)($params['syncInterval'] ?? 24);
		$conflict = $this->existingConfigurationConflict(appId: $plan['appId']);
		if ($conflict !== null) {
			return $conflict;
		}

		$configuration = $this->insertImportedConfiguration(plan: $plan);
		$result        = $this->runImport(configuration: $configuration, plan: $plan);

		return $this->importedResponse(configuration: $configuration, result: $result, sourceType: $sourceType);
	}//end importWith()

	/**
	 * The 409 response when the app of an import already has a configuration.
	 *
	 * @param mixed $appId The app id of the import
	 *
	 * @return JSONResponse|null The conflict, or null when there is none
	 */
	private function existingConfigurationConflict(mixed $appId): ?JSONResponse {
		$existingConfigs = $this->configurationMapper->findByApp($appId);
		if (count($existingConfigs) === 0) {
			return null;
		}

		return new JSONResponse(
			data: ['error' => $this->getExistingConfigErrorMessage(appId: $appId), 'existingConfigurationId' => $existingConfigs[0]->getId()],
			statusCode: 409
		);
	}//end existingConfigurationConflict()

	/**
	 * The 201 response of a finished import.
	 *
	 * @param Configuration $configuration The imported configuration
	 * @param array         $result        The import result
	 * @param string        $sourceType    The source type
	 *
	 * @return JSONResponse The response
	 */
	private function importedResponse(Configuration $configuration, array $result, string $sourceType): JSONResponse {
		return new JSONResponse(
			data: [
				'success' => true,
				'message' => "Configuration imported successfully from {$sourceType}",
				'configurationId' => $configuration->getId(),
				'result' => [
					'registersCount' => count($result['registers']),
					'schemasCount' => count($result['schemas']),
					'objectsCount' => count($result['objects']),
				],
			],
			statusCode: 201
		);
	}//end importedResponse()

	/**
	 * What an import from a source creates: the fetched data plus the app id, version and labels it carries.
	 *
	 * @param array{configData: array, sourceUrl: string, metadata: array} $fetchResult The fetched source.
	 * @param string                                                       $sourceType  Source type (github, gitlab, url).
	 *
	 * @return array<string, mixed> The import plan.
	 */
	private function importPlan(array $fetchResult, string $sourceType): array {
		$info          = $fetchResult['configData']['info'] ?? [];
		$xOpenregister = $fetchResult['configData']['x-openregister'] ?? [];

		return [
			'configData' => $fetchResult['configData'],
			'sourceUrl' => $fetchResult['sourceUrl'],
			'metadata' => $fetchResult['metadata'],
			'sourceType' => $sourceType,
			'appId' => $this->importAppId(configData: $fetchResult['configData']),
			'version' => $info['version'] ?? $xOpenregister['version'] ?? '1.0.0',
			'title' => $info['title'] ?? $xOpenregister['title'] ?? "Configuration from {$sourceType}",
			'description' => $info['description'] ?? $xOpenregister['description'] ?? "Imported from {$sourceType}",
			'type' => $xOpenregister['type'] ?? $sourceType,
		];
	}//end importPlan()

	/**
	 * The app id an imported configuration belongs to.
	 *
	 * Prefers the explicit x-openregister.app; otherwise derives a stable id from the
	 * first register's slug (or key), so two independent imports of different registers
	 * do not both collapse onto the generic 'imported' bucket and 409 the second import.
	 * Re-importing the same register yields the same id, so it updates instead of colliding.
	 *
	 * @param array $configData The fetched configuration.
	 *
	 * @return mixed The explicit app id as given, or the derived string id.
	 */
	private function importAppId(array $configData) {
		$appId = $configData['x-openregister']['app'] ?? null;
		if (empty($appId) === false) {
			return $appId;
		}

		$firstSlug = $this->firstRegisterSlug(registers: $configData['components']['registers'] ?? []);
		if (empty($firstSlug) === false) {
			return 'imported-' . preg_replace('/[^a-z0-9_-]+/', '', strtolower((string)$firstSlug));
		}

		return 'imported';
	}//end importAppId()

	/**
	 * The slug of the first register in a configuration, or its key when it has none.
	 *
	 * @param mixed $registers The configuration's components.registers.
	 *
	 * @return mixed The slug or key, or null.
	 */
	private function firstRegisterSlug($registers) {
		if (is_array($registers) === false || empty($registers) === true) {
			return null;
		}

		return $this->slugOfFirst(registers: $registers);
	}//end firstRegisterSlug()

	/**
	 * The slug of the first register of a non-empty list: its `slug`, else
	 * its string key, else none.
	 *
	 * @param array $registers The registers
	 *
	 * @return mixed The slug, or null
	 */
	private function slugOfFirst(array $registers): mixed {
		$first = reset($registers);
		if (is_array($first) === true && ($first['slug'] ?? null) !== null) {
			return $first['slug'];
		}

		$key = array_key_first($registers);
		if (is_string($key) === true) {
			return $key;
		}

		return null;
	}//end slugOfFirst()

	/**
	 * Create and insert the configuration entity for an import plan.
	 *
	 * @param array<string, mixed> $plan The import plan, with its sync settings.
	 *
	 * @return Configuration The inserted configuration.
	 */
	private function insertImportedConfiguration(array $plan): Configuration {
		$configuration = $this->importedConfiguration(plan: $plan);
		$this->applyGithubSource(configuration: $configuration, plan: $plan);
		$configuration = $this->configurationMapper->insert($configuration);
		$this->logger->debug(
			message: "[ConfigurationController] Created configuration entity with ID {$configuration->getId()} for app {$plan['appId']}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		return $configuration;
	}//end insertImportedConfiguration()

	/**
	 * Record the GitHub location of a configuration imported from GitHub
	 * with complete owner, repo, path and branch metadata.
	 *
	 * @param Configuration $configuration The configuration
	 * @param array         $plan          The import plan
	 *
	 * @return void
	 */
	private function applyGithubSource(Configuration $configuration, array $plan): void {
		$metadata      = $plan['metadata'];
		$hasGithubMeta = isset($metadata['owner'], $metadata['repo'], $metadata['path'], $metadata['branch']);
		if ($plan['sourceType'] === 'github' && $hasGithubMeta === true) {
			$configuration->setGithubRepo("{$metadata['owner']}/{$metadata['repo']}");
			$configuration->setGithubBranch($metadata['branch']);
			$configuration->setGithubPath($metadata['path']);
		}
	}//end applyGithubSource()

	/**
	 * A remote configuration for an import plan; its local version is set
	 * after the import, and it starts without registers, schemas or objects.
	 *
	 * @param array $plan The import plan
	 *
	 * @return Configuration The configuration, not yet inserted
	 */
	private function importedConfiguration(array $plan): Configuration {
		$configuration = new Configuration();
		$configuration->setTitle($plan['title']);
		$configuration->setDescription($plan['description']);
		$configuration->setType($plan['type']);
		$configuration->setSourceType($plan['sourceType']);
		$configuration->setSourceUrl($plan['sourceUrl']);
		$configuration->setApp($plan['appId']);
		$configuration->setVersion($plan['version']);
		$configuration->setLocalVersion(null);
		$configuration->setIsLocal(false);

		return $this->withSyncDefaults(configuration: $configuration, plan: $plan);
	}//end importedConfiguration()

	/**
	 * Set the sync settings of an imported configuration and empty its contents.
	 *
	 * @param Configuration $configuration The configuration
	 * @param array         $plan          The import plan
	 *
	 * @return Configuration The configuration
	 */
	private function withSyncDefaults(Configuration $configuration, array $plan): Configuration {
		$configuration->setSyncEnabled($plan['syncEnabled']);
		$configuration->setSyncInterval($plan['syncInterval']);
		$configuration->setAutoUpdate(false);
		$configuration->setRegisters([]);
		$configuration->setSchemas([]);
		$configuration->setObjects([]);

		return $configuration;
	}//end withSyncDefaults()

	/**
	 * Import a plan's data into its configuration and record the sync status.
	 *
	 * The import already stores the imported entity ids on the configuration; this saves the sync status.
	 *
	 * @param Configuration        $configuration The inserted configuration.
	 * @param array<string, mixed> $plan          The import plan.
	 *
	 * @return array The import result with registers, schemas and objects.
	 */
	private function runImport(Configuration $configuration, array $plan): array {
		$result = $this->configurationService->importFromJson(
			data: $plan['configData'],
			configuration: $configuration,
			owner: $plan['appId'],
			appId: $plan['appId'],
			version: $plan['version'],
			force: false
		);
		$this->markImported(configuration: $configuration, plan: $plan);

		return $result;
	}//end runImport()

	/**
	 * Record a successful import on its configuration.
	 *
	 * @param Configuration $configuration The configuration
	 * @param array         $plan          The import plan
	 *
	 * @return void
	 */
	private function markImported(Configuration $configuration, array $plan): void {
		$configuration->setLocalVersion($plan['version']);
		$configuration->setSyncStatus('success');
		$configuration->setLastSyncDate(new DateTime());
		$this->configurationMapper->update($configuration);

		$this->logger->debug(
			message: "[ConfigurationController] Successfully imported configuration {$configuration->getTitle()} from {$plan['sourceType']}",
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end markImported()

	/**
	 * The response for a failed import: the exception's 4xx code with its message, otherwise a generic 500.
	 *
	 * @param Exception $exception  The failure.
	 * @param string    $sourceType Source type (github, gitlab, url).
	 *
	 * @return JSONResponse The error response.
	 */
	private function importFailure(Exception $exception, string $sourceType): JSONResponse {
		$statusCode = $this->errorStatus(exception: $exception);
		$this->logger->error(
			message: "[ConfigurationController] Failed to import from {$sourceType}: " . $exception->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// SEC-CTRL-7: only 4xx validation responses keep the specific message;
		// 500s return a generic envelope (the real error is logged above).
		if ($statusCode >= 500) {
			return new JSONResponse(data: ['error' => 'Internal server error'], statusCode: $statusCode);
		}

		return new JSONResponse(data: ['error' => 'Failed to import configuration: ' . $exception->getMessage()], statusCode: $statusCode);
	}//end importFailure()

	/**
	 * The HTTP status of a failure: its code when that is an error status, else 500.
	 *
	 * @param Exception $exception The failure
	 *
	 * @return int The status
	 */
	private function errorStatus(Exception $exception): int {
		$statusCode = (int)$exception->getCode();
		if ($statusCode < 400 || $statusCode >= 600) {
			return 500;
		}

		return $statusCode;
	}//end errorStatus()

	/**
	 * Import configuration from GitHub
	 *
	 * This method creates a Configuration entity and then imports it using the standard import flow.
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with import result
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromGitHub(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->importFromSource(
			fetchConfig: fn (array $params) => $this->fetchConfigFromGitHub(params: $params),
			sourceType: 'github'
		);
	}//end importFromGitHub()

	/**
	 * Import configuration from GitLab
	 *
	 * This method creates a Configuration entity and then imports it using the standard import flow.
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with import result
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromGitLab(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->importFromSource(
			fetchConfig: fn (array $params) => $this->fetchConfigFromGitLab(params: $params),
			sourceType: 'gitlab'
		);
	}//end importFromGitLab()

	/**
	 * Import configuration from URL
	 *
	 * This method creates a Configuration entity and then imports it using the standard import flow.
	 *
	 * @since 0.2.10
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with import result
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function importFromUrl(): JSONResponse {
		if ($this->isCurrentUserAdmin() === false) {
			return $this->adminRequired();
		}

		return $this->importFromSource(
			fetchConfig: fn (array $params) => $this->fetchConfigFromUrl(params: $params),
			sourceType: 'url'
		);
	}//end importFromUrl()

	/**
	 * Publish a local configuration to GitHub
	 *
	 * Exports the configuration and publishes it to the specified GitHub repository.
	 * Updates the configuration with GitHub source information.
	 *
	 * @param int $id Configuration ID
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-suppress InvalidReturnType
	 * @psalm-suppress InvalidReturnStatement
	 *
	 * @return JSONResponse JSON response with publish result
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public function publishToGitHub(int $id): JSONResponse {
		// Authorization: publishToGitHub uses the shared app-level
		// `github_api_token` to push to any repo that token can write. Restrict
		// to administrators (mirror the importFromGitHub gate). Ideally
		// callers would use a per-user token, but until that lands the
		// endpoint must be admin-only.
		if ($this->isCurrentUserAdmin() === false) {
			return new JSONResponse(data: ['error' => 'Only administrators may publish configurations to GitHub'], statusCode: 403);
		}

		try {
			$configuration      = $this->configurationMapper->find($id);
			$validationResponse = $this->validateConfigurationForPublishing(configuration: $configuration);
			if ($validationResponse !== null) {
				return $validationResponse;
			}

			return $this->publish(configuration: $configuration, id: $id);
		} catch (Exception $e) {
			return $this->handlePublishingError(exception: $e);
		}
	}//end publishToGitHub()

	/**
	 * Publish a local configuration to the requested GitHub location and
	 * record that location on it.
	 *
	 * @param Configuration $configuration The configuration
	 * @param int           $id            The configuration id, for the log
	 *
	 * @return JSONResponse The success response, or 400 without owner and repo
	 *
	 * @throws Exception When exporting or publishing fails
	 */
	private function publish(Configuration $configuration, int $id): JSONResponse {
		$params = $this->extractGitHubPublishParams(configuration: $configuration);
		if (isset($params['error']) === true) {
			return new JSONResponse(data: ['error' => $params['error']], statusCode: 400);
		}

		$this->logPublishingAttempt(id: $id, params: $params);
		$jsonContent = $this->prepareConfigurationForGitHub(configuration: $configuration, params: $params);
		$fileSha     = $this->getExistingFileSha(params: $params);
		$result      = $this->publishConfigurationToGitHub(params: $params, content: $jsonContent, fileSha: $fileSha);
		$this->updateConfigurationWithGitHubInfo(configuration: $configuration, params: $params);
		$this->logPublishingSuccess(configuration: $configuration, params: $params, result: $result);

		return $this->buildPublishSuccessResponse(configuration: $configuration, params: $params, result: $result);
	}//end publish()

	/**
	 * Get error message for existing configuration.
	 *
	 * @param string $appId Application ID
	 *
	 * @return string Error message
	 */
	private function getExistingConfigErrorMessage(string $appId): string {
		$message = "Configuration for app '{$appId}' already exists. ";
		$message .= 'Please update the existing configuration instead.';

		return $message;
	}//end getExistingConfigErrorMessage()

	/**
	 * Validate configuration can be published
	 *
	 * Checks if configuration is local and can be published to GitHub.
	 *
	 * @param Configuration $configuration Configuration entity.
	 *
	 * @return JSONResponse|null Error response if validation fails, null if valid.
	 *
	 * @psalm-return JSONResponse<400, array{error: 'Only local configurations can be published'}, array<never, never>>|null
	 */
	private function validateConfigurationForPublishing(Configuration $configuration): ?JSONResponse {
		if ($configuration->getIsLocal() !== true) {
			return new JSONResponse(data: ['error' => 'Only local configurations can be published'], statusCode: 400);
		}

		return null;
	}//end validateConfigurationForPublishing()

	/**
	 * Extract and validate GitHub publishing parameters
	 *
	 * Extracts owner, repo, path, branch, and commit message from request.
	 * Validates required parameters and normalizes path.
	 *
	 * @param Configuration $configuration Configuration entity.
	 *
	 * @return array<string, string> Parameters array or error array.
	 */
	private function extractGitHubPublishParams(Configuration $configuration): array {
		$params = $this->paramsWithDefaults(
			params: $this->request->getParams(),
			defaults: ['owner' => '', 'repo' => '', 'path' => '', 'branch' => 'main', 'commitMessage' => "Update configuration: {$configuration->getTitle()}"]
		);
		if (empty($params['owner']) === true || empty($params['repo']) === true) {
			return ['error' => 'Owner and repo parameters are required'];
		}

		$params['path'] = $this->publishPath(path: $params['path'], configuration: $configuration);

		return $params;
	}//end extractGitHubPublishParams()

	/**
	 * The repository path to publish to: the requested path without a
	 * leading slash, or `<snake_case_title>_openregister.json` when empty.
	 *
	 * @param mixed         $path          The requested path
	 * @param Configuration $configuration The configuration
	 *
	 * @return string The path
	 */
	private function publishPath(mixed $path, Configuration $configuration): string {
		$path = ltrim($path, '/');
		if (empty($path) === true) {
			return $this->toSnakeCase(string: $configuration->getTitle() ?? 'configuration') . '_openregister.json';
		}

		return $path;
	}//end publishPath()

	/**
	 * Log publishing attempt
	 *
	 * Logs configuration publishing details for debugging.
	 *
	 * @param int $id Configuration ID.
	 * @param array<string, string> $params Publishing parameters.
	 *
	 * @return void
	 */
	private function logPublishingAttempt(int $id, array $params): void {
		$this->logger->debug(
			message: '[ConfigurationController] Publishing configuration to GitHub',
			context: ['file' => __FILE__, 'line' => __LINE__, 'configuration_id' => $id, 'owner' => $params['owner'],
				'repo' => $params['repo'], 'path' => $params['path'], 'branch' => $params['branch']]
		);
	}//end logPublishingAttempt()

	/**
	 * Prepare configuration for GitHub publishing
	 *
	 * Exports configuration and adds GitHub metadata.
	 *
	 * @param Configuration $configuration Configuration entity.
	 * @param array<string, string> $params Publishing parameters.
	 *
	 * @return false|string JSON content ready for GitHub.
	 */
	private function prepareConfigurationForGitHub(Configuration $configuration, array $params): string|false {
		$configData = $this->configurationService->exportConfig(input: $configuration, includeObjects: false);

		// Drop the local source information; add the OpenRegister version and the GitHub location.
		$metadata = $configData['x-openregister'] ?? [];
		unset($metadata['sourceType'], $metadata['sourceUrl']);
		$metadata['openregister']     = $this->appManager->getAppVersion('openregister');
		$metadata['github']           = ['repo' => "{$params['owner']}/{$params['repo']}", 'branch' => $params['branch'], 'path' => $params['path']];
		$configData['x-openregister'] = $metadata;

		return json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}//end prepareConfigurationForGitHub()

	/**
	 * Get existing file SHA for updates
	 *
	 * Retrieves the SHA of existing file on GitHub for update operations.
	 *
	 * @param array<string, string> $params Publishing parameters.
	 *
	 * @return string|null File SHA if exists, null for new files.
	 */
	private function getExistingFileSha(array $params): ?string {
		try {
			return $this->githubHandler->getFileSha(owner: $params['owner'], repo: $params['repo'], path: $params['path'], branch: $params['branch']);
		} catch (Exception $e) {
			// File doesn't exist, which is fine for new files.
			$this->logger->debug(
				message: '[ConfigurationController] File does not exist, will create new file',
				context: ['file' => __FILE__, 'line' => __LINE__, 'path' => $params['path']]
			);
			return null;
		}
	}//end getExistingFileSha()

	/**
	 * Publish configuration to GitHub
	 *
	 * Calls GitHub handler to publish/update the configuration file.
	 *
	 * @param array<string, string> $params Publishing parameters.
	 * @param string $content JSON content to publish.
	 * @param string|null $fileSha Existing file SHA for updates.
	 *
	 * @return array Result from GitHub API with commit info.
	 */
	private function publishConfigurationToGitHub(array $params, string $content, ?string $fileSha): array {
		return $this->githubHandler->publishConfiguration(
			owner: $params['owner'],
			repo: $params['repo'],
			path: $params['path'],
			branch: $params['branch'],
			content: $content,
			commitMessage: $params['commitMessage'],
			fileSha: $fileSha
		);
	}//end publishConfigurationToGitHub()

	/**
	 * Update configuration with GitHub information
	 *
	 * Updates local configuration entity with GitHub publishing details.
	 *
	 * @param Configuration $configuration Configuration entity.
	 * @param array<string, string> $params Publishing parameters.
	 *
	 * @return void
	 */
	private function updateConfigurationWithGitHubInfo(Configuration $configuration, array $params): void {
		$githubRepo = "{$params['owner']}/{$params['repo']}";
		$sourceUrl = "https://github.com/{$githubRepo}/blob/{$params['branch']}/{$params['path']}";

		$configuration->setGithubRepo($githubRepo);
		$configuration->setGithubBranch($params['branch']);
		$configuration->setGithubPath($params['path']);
		$configuration->setSourceUrl($sourceUrl);
		// Don't change isLocal - it stays local, but now has a published source.
		$this->configurationMapper->update($configuration);
	}//end updateConfigurationWithGitHubInfo()

	/**
	 * Log publishing success
	 *
	 * Logs successful GitHub publishing operation.
	 *
	 * @param Configuration $configuration Configuration entity.
	 * @param array<string, string> $params Publishing parameters.
	 * @param array<string, mixed> $result GitHub API result.
	 *
	 * @return void
	 */
	private function logPublishingSuccess(Configuration $configuration, array $params, array $result): void {
		$this->logger->debug(
			message: "[ConfigurationController] Successfully published configuration {$configuration->getTitle()} to GitHub",
			context: ['file' => __FILE__, 'line' => __LINE__, 'owner' => $params['owner'], 'repo' => $params['repo'],
				'branch' => $params['branch'], 'path' => $params['path'], 'file_url' => $result['file_url'] ?? null]
		);
	}//end logPublishingSuccess()

	/**
	 * Build success response with indexing information
	 *
	 * Creates success response including GitHub URLs and indexing notes.
	 *
	 * @param Configuration $configuration Configuration entity.
	 * @param array<string, string> $params Publishing parameters.
	 * @param array<string, mixed> $result GitHub API result.
	 *
	 * @return JSONResponse JSON response with publish success data
	 */
	private function buildPublishSuccessResponse(Configuration $configuration, array $params, array $result): JSONResponse {
		$defaultBranch = $this->getRepositoryDefaultBranch(params: $params);

		return new JSONResponse(
			data: [
				'success' => true,
				'message' => $this->publishMessage(defaultBranch: $defaultBranch, branch: $params['branch']),
				'configurationId' => $configuration->getId(),
				'commit_sha' => $result['commit_sha'], 'commit_url' => $result['commit_url'], 'file_url' => $result['file_url'],
				'branch' => $params['branch'],
				'default_branch' => $defaultBranch,
				'indexing_note' => $this->getIndexingNote(defaultBranch: $defaultBranch, branch: $params['branch']),
			],
			statusCode: 200
		);
	}//end buildPublishSuccessResponse()

	/**
	 * The success message of a publish, with a note on GitHub indexing.
	 *
	 * @param string|null $defaultBranch The repository default branch, if known
	 * @param string      $branch        The branch published to
	 *
	 * @return string The message
	 */
	private function publishMessage(?string $defaultBranch, string $branch): string {
		$message = 'Configuration published successfully to GitHub';
		if ($defaultBranch !== null && $branch !== $defaultBranch) {
			return $message . ". Note: Published to branch '{$branch}' (default is '{$defaultBranch}'). "
				. 'GitHub Code Search primarily indexes the default branch, '
				. 'so this configuration may not appear in search results immediately.';
		}

		return $message . '. Note: GitHub Code Search may take a few minutes to index new files.';
	}//end publishMessage()

	/**
	 * Get repository default branch
	 *
	 * Fetches the default branch name from GitHub repository.
	 *
	 * @param array<string, string> $params Publishing parameters.
	 *
	 * @return string|null Default branch name or null if unable to fetch.
	 */
	private function getRepositoryDefaultBranch(array $params): ?string {
		try {
			$repoInfo = $this->githubHandler->getRepositoryInfo(owner: $params['owner'], repo: $params['repo']);
			return $repoInfo['default_branch'] ?? 'main';
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[ConfigurationController] Could not fetch repository default branch',
				context: ['file' => __FILE__, 'line' => __LINE__, 'owner' => $params['owner'], 'repo' => $params['repo'], 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end getRepositoryDefaultBranch()

	/**
	 * Handle publishing error
	 *
	 * Logs error and returns error response.
	 *
	 * @param Exception $exception The exception that occurred.
	 *
	 * @return JSONResponse JSON response with error message
	 */
	private function handlePublishingError(Exception $exception): JSONResponse {
		$this->logger->error(
			message: '[ConfigurationController] Failed to publish to GitHub: ' . $exception->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

		// SEC-CTRL-7: generic 500 envelope; the real error is logged above.
		return new JSONResponse(data: ['error' => 'Internal server error'], statusCode: 500);
	}//end handlePublishingError()

	/**
	 * Get indexing note based on branch information.
	 *
	 * @param string|null $defaultBranch Default branch name
	 * @param string $branch Current branch name
	 *
	 * @return string Indexing note message
	 */
	private function getIndexingNote(?string $defaultBranch, string $branch): string {
		if ($defaultBranch !== null && $branch !== $defaultBranch) {
			return "Published to non-default branch. For discovery, publish to '{$defaultBranch}' branch.";
		}

		return 'File published successfully. GitHub Code Search indexing may take a few minutes.';
	}//end getIndexingNote()

	/**
	 * Convert a string to snake_case
	 *
	 * @param string $string The string to convert
	 *
	 * @return string The snake_case version
	 */
	private function toSnakeCase(string $string): string {
		// Convert to lowercase.
		$string = strtolower($string);

		// Replace spaces and hyphens with underscores.
		$string = preg_replace('/[\s\-]+/', '_', $string);

		// Remove any non-alphanumeric characters except underscores.
		$string = preg_replace('/[^a-z0-9_]/', '', $string);

		// Remove multiple consecutive underscores.
		$string = preg_replace('/_+/', '_', $string);

		// Trim underscores from start and end.
		$string = trim($string, '_');

		return $string;
	}//end toSnakeCase()
}//end class
