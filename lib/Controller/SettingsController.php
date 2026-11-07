<?php

/**
 * OpenRegister Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use DateTime;
use Exception;
use OCA\OpenRegister\Controller\Support\DatabaseServerProfile;
use OCA\OpenRegister\Controller\Support\HybridSearchDiagnostics;
use OCA\OpenRegister\Controller\Support\SettingsControllerServices;
use OCA\OpenRegister\Kernel\Contracts\AppSettingsInterface;
use OCA\OpenRegister\Search\Service\Search\SearchIndexMaintenance;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Controller for handling settings-related operations in the OpenRegister.
 *
 * This controller serves as a THIN LAYER that validates HTTP requests and delegates
 * to the appropriate service for business logic execution. It does NOT contain
 * business logic itself.
 *
 * RESPONSIBILITIES:
 * - Validate HTTP request parameters
 * - Delegate settings CRUD operations to SettingsService
 * - Delegate LLM testing to VectorizationService and ChatService
 * - Return appropriate JSONResponse with correct HTTP status codes
 * - Handle HTTP-level concerns (authentication, CSRF, etc.)
 *
 * ARCHITECTURE PATTERN:
 * - Thin controller: minimal logic, delegates to services
 * - Services handle business logic and return structured arrays
 * - Controller converts service responses to JSONResponse
 * - Service errors are caught and converted to appropriate HTTP responses
 *
 * ENDPOINTS ORGANIZED BY CATEGORY:
 *
 * GENERAL SETTINGS:
 * - GET  /api/settings              - Get all settings
 * - POST /api/settings              - Update all settings
 * - GET  /api/settings/stats        - Get statistics
 * - POST /api/settings/rebase       - Rebase objects and logs
 *
 * RBAC SETTINGS:
 * - GET  /api/settings/rbac         - Get RBAC settings
 * - PUT  /api/settings/rbac         - Update RBAC settings
 * - PATCH /api/settings/rbac        - Patch RBAC settings
 *
 * MULTITENANCY SETTINGS:
 * - GET  /api/settings/multitenancy - Get multitenancy settings
 * - PUT  /api/settings/multitenancy - Update multitenancy settings
 * - PATCH /api/settings/multitenancy - Patch multitenancy settings
 *
 * RETENTION SETTINGS:
 * - GET  /api/settings/retention    - Get retention settings
 * - PUT  /api/settings/retention    - Update retention settings
 * - PATCH /api/settings/retention   - Patch retention settings
 *
 *
 * LLM SETTINGS:
 * - GET  /api/settings/llm          - Get LLM settings
 * - PUT  /api/settings/llm          - Update LLM settings
 * - PATCH /api/settings/llm         - Patch LLM settings
 * - POST /api/vectors/test-embedding - Test embedding generation (delegates to VectorizationService)
 * - POST /api/llm/test-chat         - Test chat functionality (delegates to ChatService)
 *
 * FILE SETTINGS:
 * - GET  /api/settings/files        - Get file settings
 * - PUT  /api/settings/files        - Update file settings
 * - PATCH /api/settings/files       - Patch file settings
 *
 * OBJECT SETTINGS:
 * - GET  /api/settings/objects      - Get object settings
 * - PUT  /api/settings/objects      - Update object settings
 * - PATCH /api/settings/objects     - Patch object settings
 *
 * CACHE MANAGEMENT:
 * - GET  /api/settings/cache/stats  - Get cache statistics
 * - POST /api/settings/cache/clear  - Clear cache
 * - POST /api/settings/cache/warmup - Warmup cache
 *
 * DELEGATION PATTERN:
 * - Settings storage/retrieval → SettingsService
 * - LLM embedding testing → VectorizationService
 * - LLM chat testing → ChatService
 * - Cache operations → Cache services
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     NC AppFramework controller groups all settings
 *   endpoints (general/rbac/multitenancy/retention/llm/file/object/cache) in one class per
 *   the thin-controller architecture; splitting would require multiple route groups and controllers
 *   for a natural single responsibility surface.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is distributed across 15 thin
 *   delegation methods each containing a single try/catch; the overall score exceeds the threshold
 *   purely from method count, not from deep conditional logic in any single method.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   NC AppFramework controller requires DI for
 *   AppFramework types (IRequest, IAppConfig, IDBConnection, ContainerInterface)
 *   plus SettingsService, LoggerInterface, and IL10N — all are single-call
 *   dependencies that cannot be cohesively grouped without hiding the NC DI contract.
 */
class SettingsController extends Controller {

	/*
	 * There is no $objectService property: getObjectService() below assigned it
	 * null and returned that (the "CIRCULAR FIX"), so it was never anything but
	 * null. Code that needs the service resolves it from the container at the
	 * point of use instead — see the $objectService locals further down.
	 */

	/**
	 * The app configuration.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $config;

	/**
	 * The database connection.
	 *
	 * @var IDBConnection
	 */
	private readonly IDBConnection $db;

	/**
	 * The container.
	 *
	 * @var ContainerInterface
	 */
	private readonly ContainerInterface $container;

	/**
	 * The settings service.
	 *
	 * @var AppSettingsInterface
	 */
	private readonly AppSettingsInterface $settingsService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * The localization service.
	 *
	 * @var ?IL10N
	 */
	private readonly ?IL10N $l10n;

	/**
	 * SettingsController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param SettingsControllerServices $services The settings controller services.
	 */
	public function __construct(
		$appName,
		IRequest $request,
		SettingsControllerServices $services,
	) {
		parent::__construct(appName: $appName, request: $request);
		$this->config = $services->store->config;
		$this->db = $services->store->db;
		$this->container = $services->store->container;
		$this->settingsService = $services->store->settingsService;
		$this->logger = $services->runtime->logger;
		$this->l10n = $services->runtime->l10n;
	}//end __construct()

	/**
	 * Retrieve the current settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with settings data
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function index(): JSONResponse {
		try {
			$data = $this->settingsService->getSettings();
			return new JSONResponse(data: $data);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end index()

	/**
	 * Handle the PUT request to update settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated settings
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function update(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$result = $this->settingsService->updateSettings($data);
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end update()

	/**
	 * Load the settings from the publication_register.json file.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with loaded settings
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function load(): JSONResponse {
		try {
			$result = $this->settingsService->getSettings();
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end load()

	/**
	 * Rebase all objects and logs with current retention settings.
	 *
	 * This method recalculates deletion times for all objects and logs based on current retention settings.
	 * It also assigns default owners and organizations to objects that don't have them assigned.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with rebase result
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function rebase(): JSONResponse {
		try {
			$result = $this->settingsService->rebase();
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end rebase()

	/**
	 * Get statistics for the settings dashboard.
	 *
	 * This method provides warning counts for objects and logs that need attention,
	 * as well as total counts for all objects, audit trails, and search trails.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with statistics
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function stats(): JSONResponse {
		try {
			$result = $this->settingsService->getStats();
			return new JSONResponse(data: $result);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}
	}//end stats()

	/**
	 * Get statistics for the settings dashboard (alias for stats method).
	 *
	 * This method provides warning counts for objects and logs that need attention,
	 * as well as total counts for all objects, audit trails, and search trails.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with statistics
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getStatistics(): JSONResponse {
		return $this->stats();
	}//end getStatistics()

	/**
	 * Get search backend configuration.
	 *
	 * Reports the search backend, which is always the database.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse Backend configuration
	 *
	 * @psalm-return JSONResponse<200|500, array, array<never, never>>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function getSearchBackend(): JSONResponse {
		try {
			$data = $this->settingsService->getSearchBackendConfig();
			return new JSONResponse(data: $data);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getSearchBackend()

	/**
	 * Read the state of the indexes behind object search.
	 *
	 * Admin-only by default: no `@NoAdminRequired` here, so Nextcloud's
	 * middleware refuses an ordinary account before the method runs. The index
	 * inventory of a magic table names the schema's own columns, which is more
	 * than a reader of that register is entitled to.
	 *
	 * There is no operations console in this app. This is the endpoint one
	 * reads: the index inventory per table, whether this platform can rebuild
	 * without taking the index away, and the report of the last rebuild that
	 * actually ran. `occ openregister:tables:search-index status` prints the
	 * same report.
	 *
	 * It also says how many rows the tables in scope hold (`rowsInTables`) and
	 * how many per schema slug (`indexed`); a count that cannot be made is left
	 * out rather than answered too low.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The index inventory and the last rebuild report.
	 *
	 * @psalm-return JSONResponse<200|500, array, array<never, never>>
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-the-search-index-status-names-how-many-rows-it-covers
	 */
	public function getSearchIndexStatus(): JSONResponse {
		try {
			return new JSONResponse(data: $this->searchIndexReport());
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getSearchIndexStatus()

	/**
	 * The search indexes in scope, their row counts and the last rebuild.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function searchIndexReport(): array {
		$maintenance = $this->container->get(SearchIndexMaintenance::class);
		$tables = $maintenance->indexNamesFor(tableNames: $maintenance->tablesInScope());

		return [
			'concurrentRebuildSupported' => $maintenance->supportsConcurrentRebuild(),
			'tables' => $tables,
			'tableCount' => count($tables),
			'indexCount' => array_sum(array_map('count', $tables)),
			'lastRun' => $maintenance->lastRun(),
		] + $maintenance->rowCounts();
	}//end searchIndexReport()

	/**
	 * Get database information and vector search capabilities
	 *
	 * Returns information about the current database system and whether it
	 * supports native vector operations for optimal semantic search performance.
	 * Results are cached in app config and can be refreshed with ?refresh=true.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with database info
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getDatabaseInfo(): JSONResponse {
		try {
			$cached = $this->cachedDatabaseInfo();
			if ($cached !== null) {
				return new JSONResponse(data: $cached);
			}

			$responseData = ['success' => true, 'database' => $this->freshDatabaseInfo(), 'fromCache' => false];
			$this->config->setValueString('openregister', 'databaseInfo', json_encode($responseData));

			return new JSONResponse(data: $responseData);
		} catch (Exception $e) {
			return $this->databaseInfoFailure(e: $e);
		}
	}//end getDatabaseInfo()

	/**
	 * The database info as read now.
	 *
	 * @return array<string, mixed> The database info.
	 */
	private function freshDatabaseInfo(): array {
		$info = (new DatabaseServerProfile(db: $this->db, logger: $this->logger))->describe();
		$diagnostics = new HybridSearchDiagnostics(db: $this->db, container: $this->container, logger: $this->logger);
		$info['hybridSearch'] = $diagnostics->report(isPostgres: $info['type'] === 'PostgreSQL');
		$info['lastUpdated'] = (new DateTime())->format('c');

		return $info;
	}//end freshDatabaseInfo()

	/**
	 * The response when the database info could not be read.
	 *
	 * @param Exception $e What went wrong.
	 *
	 * @return JSONResponse The error response.
	 */
	private function databaseInfoFailure(Exception $e): JSONResponse {
		$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
		$this->logger->error(message: '[SettingsController] Failed to get database info', context: $context);

		return new JSONResponse(
			data: [
				'success' => false,
				'error' => $this->l10n->t('Failed to get database information: %s', [$e->getMessage()]),
			],
			statusCode: 500
		);
	}//end databaseInfoFailure()

	/**
	 * The cached database info, unless the caller asked for a refresh.
	 *
	 * @return array<string, mixed>|null The cached response data marked as from cache, or null.
	 */
	private function cachedDatabaseInfo(): ?array {
		$refresh = filter_var($this->request->getParam('refresh', false), FILTER_VALIDATE_BOOLEAN);
		if ($refresh === true) {
			return null;
		}

		$cached = json_decode($this->config->getValueString('openregister', 'databaseInfo', ''), true);
		if ($cached === null || isset($cached['database']) === false) {
			return null;
		}

		$cached['fromCache'] = true;
		return $cached;
	}//end cachedDatabaseInfo()

	/**
	 * Refresh database information
	 *
	 * Forces a refresh of the cached database information including
	 * PostgreSQL extensions. This clears the cache and re-queries the database.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with refreshed database info
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function refreshDatabaseInfo(): JSONResponse {
		// Clear the cached database info to force a refresh.
		$this->config->deleteKey('openregister', 'databaseInfo');

		// The getDatabaseInfo method will now fetch fresh data since cache is empty.
		return $this->getDatabaseInfo();
	}//end refreshDatabaseInfo()

	/**
	 * Get version information only
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with version info
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getVersionInfo(): JSONResponse {
		try {
			$data = $this->settingsService->getVersionInfoOnly();
			return new JSONResponse(data: $data);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getVersionInfo()
}//end class
