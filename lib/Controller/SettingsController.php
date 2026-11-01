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
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\Search\SearchIndexMaintenance;
use OCP\App\IAppManager;
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
 *   AppFramework types (IRequest, IAppConfig, IDBConnection, ContainerInterface, IAppManager)
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
	 * SettingsController constructor.
	 *
	 * @param string $appName The name of the app.
	 * @param IRequest $request The request object.
	 * @param IAppConfig $config The app configuration.
	 * @param IDBConnection $db The database connection.
	 * @param ContainerInterface $container The container.
	 * @param IAppManager $appManager The app manager.
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger The logger.
	 * @param IL10N|null $l10n The localization service.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Nextcloud DI injects all controller dependencies via constructor
	 */
	public function __construct(
		$appName,
		IRequest $request,
		private readonly IAppConfig $config,
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
		private readonly IAppManager $appManager,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?IL10N $l10n = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Attempts to retrieve the OpenRegister service from the container.
	 *
	 * @return null The OpenRegister service if available, null otherwise.
	 *
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude DI service accessor (not a routed endpoint): returns the OpenRegister ObjectService from the container.
	 */
	public function getObjectService() {
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			// CIRCULAR FIX: returning the service here would close a container
			// cycle, so callers resolve it themselves.
			return null;
		}

		throw new RuntimeException('OpenRegister service is not available.');
	}//end getObjectService()

	/**
	 * Attempts to retrieve the Configuration service from the container.
	 *
	 * @return \OCA\OpenRegister\Service\ConfigurationService|null The Configuration service if available, null otherwise.
	 * @throws \RuntimeException If the service is not available.
	 *
	 * @spec exclude DI service accessor (not a routed endpoint): returns the ConfigurationService from the container.
	 */
	public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService {
		// Check if the 'openregister' app is installed.
		if (in_array(needle: 'openregister', haystack: $this->appManager->getInstalledApps()) === true) {
			// Retrieve the ConfigurationService from the container.
			$configurationService = $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
			return $configurationService;
		}

		// Throw an exception if the service is not available.
		throw new RuntimeException('Configuration service is not available.');
	}//end getConfigurationService()

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
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The index inventory and the last rebuild report.
	 *
	 * @psalm-return JSONResponse<200|500, array, array<never, never>>
	 *
	 * @spec openspec/changes/search-quality-operators-and-facets/specs/zoeken-filteren/spec.md
	 */
	public function getSearchIndexStatus(): JSONResponse {
		try {
			$maintenance = $this->container->get(SearchIndexMaintenance::class);

			$tables = $maintenance->indexNamesFor(tableNames: $maintenance->tablesInScope());
			$indexCount = 0;
			foreach ($tables as $indexes) {
				$indexCount += count($indexes);
			}

			return new JSONResponse(
				data: [
					'concurrentRebuildSupported' => $maintenance->supportsConcurrentRebuild(),
					'tables' => $tables,
					'tableCount' => count($tables),
					'indexCount' => $indexCount,
					'lastRun' => $maintenance->lastRun(),
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}
	}//end getSearchIndexStatus()

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

			$platformName = $this->databasePlatformName();
			$profile = $this->databaseProfile(platformName: $platformName);

			// Build the database info array.
			$databaseInfo = [
				'type' => $profile['type'],
				'version' => $profile['version'],
				'platform' => $platformName,
				'vectorSupport' => $profile['vectorSupport'],
				'recommendedPlugin' => $profile['recommendedPlugin'],
				'performanceNote' => $profile['performanceNote'],
				'extensions' => $profile['extensions'],
				'hybridSearch' => $this->getHybridSearchDiagnostics(isPostgres: $profile['type'] === 'PostgreSQL'),
				'lastUpdated' => (new DateTime())->format('c'),
			];

			// Build the response data.
			$responseData = [
				'success' => true,
				'database' => $databaseInfo,
				'fromCache' => false,
			];

			// Store in app config for later use.
			$this->config->setValueString(
				'openregister',
				'databaseInfo',
				json_encode($responseData)
			);

			return new JSONResponse(data: $responseData);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[SettingsController] Failed to get database info',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				]
			);

			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $this->l10n->t('Failed to get database information: %s', [$e->getMessage()]),
				],
				statusCode: 500
			);
		}//end try
	}//end getDatabaseInfo()

	/**
	 * The cached database info, unless the caller asked for a refresh.
	 *
	 * @return array<string, mixed>|null The cached response data marked as from cache, or null.
	 */
	private function cachedDatabaseInfo(): ?array {
		$refresh = filter_var(
			$this->request->getParam('refresh', false),
			FILTER_VALIDATE_BOOLEAN
		);
		if ($refresh === true) {
			return null;
		}

		$cachedInfo = $this->config->getValueString('openregister', 'databaseInfo', '');
		if (empty($cachedInfo) === true) {
			return null;
		}

		$cached = json_decode($cachedInfo, true);
		if ($cached === null || isset($cached['database']) === false) {
			return null;
		}

		$cached['fromCache'] = true;
		return $cached;
	}//end cachedDatabaseInfo()

	/**
	 * The name of the active database platform.
	 *
	 * The platform instance from getDatabasePlatform() is deliberately not type hinted.
	 *
	 * @return string The platform name, or 'unknown'.
	 */
	private function databasePlatformName(): string {
		$platform = $this->db->getDatabasePlatform();
		if (method_exists($platform, 'getName') === true) {
			return $platform->getName();
		}

		return 'unknown';
	}//end databasePlatformName()

	/**
	 * The database type, version, extensions and vector-search advice for a platform.
	 *
	 * @param string $platformName The active platform's name.
	 *
	 * @return array{type: string, version: string, vectorSupport: bool, recommendedPlugin: string|null,
	 *     performanceNote: string|null, extensions: list<array{name: mixed, version: mixed}>}
	 */
	private function databaseProfile(string $platformName): array {
		if (strpos($platformName, 'mysql') !== false || strpos($platformName, 'mariadb') !== false) {
			return $this->mysqlProfile();
		}

		if (strpos($platformName, 'postgres') !== false) {
			return $this->postgresProfile();
		}

		$profile = [
			'type' => 'Unknown',
			'version' => 'Unknown',
			'vectorSupport' => false,
			'recommendedPlugin' => null,
			'performanceNote' => null,
			'extensions' => [],
		];
		if (strpos($platformName, 'sqlite') !== false) {
			$profile['type'] = 'SQLite';
			$profile['recommendedPlugin'] = 'sqlite-vss or migrate to PostgreSQL';
			$profile['performanceNote'] = 'SQLite not recommended for production vector search.';
		}

		return $profile;
	}//end databaseProfile()

	/**
	 * The profile of a MySQL or MariaDB server, which has no native vector operations.
	 *
	 * @return array{type: string, version: string, vectorSupport: bool, recommendedPlugin: string,
	 *     performanceNote: string, extensions: list<array{name: mixed, version: mixed}>}
	 */
	private function mysqlProfile(): array {
		$profile = [
			'type' => 'MySQL/MariaDB',
			'version' => 'Unknown',
			'vectorSupport' => false,
			'recommendedPlugin' => 'pgvector for PostgreSQL',
			'performanceNote' => 'Current: Similarity calculated in PHP (slow).'
				. ' Recommended: Migrate to PostgreSQL + pgvector for 10-100x speedup.',
			'extensions' => [],
		];

		try {
			$version = $this->serverVersion();

			$profile['type'] = 'MySQL';
			if (stripos($version, 'MariaDB') !== false) {
				$profile['type'] = 'MariaDB';
			}

			preg_match('/\d+\.\d+\.\d+/', $version, $matches);
			$profile['version'] = $matches[0] ?? $version;
		} catch (Exception $e) {
			// Reported as MySQL/MariaDB of unknown version.
		}

		return $profile;
	}//end mysqlProfile()

	/**
	 * The profile of a PostgreSQL server, with its installed extensions and pgvector status.
	 *
	 * @return array{type: string, version: string, vectorSupport: bool, recommendedPlugin: string,
	 *     performanceNote: string, extensions: list<array{name: mixed, version: mixed}>}
	 */
	private function postgresProfile(): array {
		$version = 'Unknown';
		try {
			preg_match('/PostgreSQL (\d+\.\d+)/', $this->serverVersion(), $matches);
			$version = $matches[1] ?? 'Unknown';
		} catch (Exception $e) {
			// Reported as an unknown version.
		}

		$extensions = $this->postgresExtensions();
		$profile = [
			'type' => 'PostgreSQL',
			'version' => $version,
			'vectorSupport' => false,
			'recommendedPlugin' => 'pgvector (not installed)',
			'performanceNote' => 'Install pgvector extension: CREATE EXTENSION vector;',
			'extensions' => $extensions,
		];
		if (in_array('vector', array_column($extensions, 'name'), true) === true) {
			$profile['vectorSupport'] = true;
			$profile['recommendedPlugin'] = 'pgvector (installed)';
			$profile['performanceNote'] = 'Optimal: Using database-level vector operations for fast semantic search.';
		}

		return $profile;
	}//end postgresProfile()

	/**
	 * The database server's version string.
	 *
	 * @return mixed The result of SELECT VERSION().
	 */
	private function serverVersion() {
		$stmt = $this->db->prepare('SELECT VERSION()');
		$result = $stmt->execute();
		return $result->fetchOne();
	}//end serverVersion()

	/**
	 * The installed PostgreSQL extensions; empty when the catalog cannot be read.
	 *
	 * @return list<array{name: mixed, version: mixed}>
	 */
	private function postgresExtensions(): array {
		$extensions = [];
		try {
			$stmt = $this->db->prepare('SELECT extname, extversion FROM pg_extension ORDER BY extname');
			$result = $stmt->execute();
			while (is_array($row = $result->fetch()) === true) {
				$extensions[] = [
					'name' => $row['extname'],
					'version' => $row['extversion'],
				];
			}
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[SettingsController] Failed to fetch PostgreSQL extensions',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return $extensions;
	}//end postgresExtensions()

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
	 * Hybrid-document-search readiness diagnostics.
	 *
	 * Reports whether the hybrid-search schema surface exists — the
	 * `openregister_vec_ann` pgvector ANN sidecar table + HNSW index (the
	 * sidecar replaces the originally-designed in-table column, which broke
	 * Doctrine schema introspection) and the functional tsvector GIN index on
	 * openregister_chunks — plus vectorization/backfill progress so an
	 * operator can watch the job-only warm-up converge (DECIDED 2026-07-06).
	 *
	 * All lookups are tolerant: a failed catalog query reports `false`/zero
	 * rather than failing the database-info panel.
	 *
	 * @param bool $isPostgres Whether the active platform is PostgreSQL
	 *
	 * @return array<string, mixed> Diagnostics payload
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#6.1
	 */
	private function getHybridSearchDiagnostics(bool $isPostgres): array {
		$diagnostics = [
			'annSidecarTable' => false,
			'embeddingVectorDimension' => null,
			'hnswIndex' => false,
			'textSearchGinIndex' => false,
			'chunks' => [
				'total' => 0,
				'vectorized' => 0,
			],
			'vectors' => [
				'total' => 0,
				'pgvectorPopulated' => 0,
			],
		];

		$sidecar = \OCA\OpenRegister\Service\Vectorization\Handlers\PgVectorPlatform::SIDECAR_TABLE;

		if ($isPostgres === true) {
			$diagnostics = array_replace(
				$diagnostics,
				$this->annSidecarDiagnostics(sidecar: $sidecar),
				$this->searchIndexDiagnostics()
			);
			$diagnostics['vectors'] = $this->vectorCounts(sidecar: $sidecar);
		}

		// Chunk vectorization progress (platform-agnostic).
		$diagnostics['chunks'] = $this->chunkVectorizationProgress();

		return $diagnostics;
	}//end getHybridSearchDiagnostics()

	/**
	 * Whether the pgvector ANN sidecar table exists, and its embedding dimension.
	 *
	 * @param string $sidecar The sidecar table name.
	 *
	 * @return array{annSidecarTable?: true, embeddingVectorDimension?: int} Empty when the sidecar is unavailable.
	 */
	private function annSidecarDiagnostics(string $sidecar): array {
		try {
			$result = $this->db->executeQuery(
				'SELECT a.atttypmod FROM pg_attribute a '
				. "WHERE a.attrelid = '" . $sidecar . "'::regclass "
				. "AND a.attname = 'embedding' AND NOT a.attisdropped"
			);
			$typmod = $result->fetchOne();
			$result->closeCursor();

			if ($typmod !== false && (int)$typmod > 0) {
				return ['annSidecarTable' => true, 'embeddingVectorDimension' => (int)$typmod];
			}
		} catch (\Throwable $e) {
			// Sidecar unavailable — reported as false.
		}

		return [];
	}//end annSidecarDiagnostics()

	/**
	 * Whether the HNSW and the text-search GIN indexes exist.
	 *
	 * @return array{hnswIndex: bool, textSearchGinIndex: bool}
	 */
	private function searchIndexDiagnostics(): array {
		$found = ['hnswIndex' => false, 'textSearchGinIndex' => false];
		try {
			$result = $this->db->executeQuery(
				'SELECT indexname FROM pg_indexes WHERE indexname IN '
				. "('idx_or_vec_ann_hnsw', 'idx_or_chunks_text_search_gin')"
			);
			while (is_array($row = $result->fetch()) === true) {
				if ($row['indexname'] === 'idx_or_vec_ann_hnsw') {
					$found['hnswIndex'] = true;
				}

				if ($row['indexname'] === 'idx_or_chunks_text_search_gin') {
					$found['textSearchGinIndex'] = true;
				}
			}

			$result->closeCursor();
		} catch (\Throwable $e) {
			// Reported as false.
		}

		return $found;
	}//end searchIndexDiagnostics()

	/**
	 * The vector row count and how many of them have a pgvector sidecar row.
	 *
	 * @param string $sidecar The sidecar table name.
	 *
	 * @return array{total: int, pgvectorPopulated: int}
	 */
	private function vectorCounts(string $sidecar): array {
		$counts = ['total' => 0, 'pgvectorPopulated' => 0];
		try {
			$result = $this->db->executeQuery(
				'SELECT COUNT(*) AS total, COUNT(a.vector_id) AS populated '
				. 'FROM *PREFIX*openregister_vectors v '
				. "LEFT JOIN $sidecar a ON a.vector_id = v.id"
			);
			$row = $result->fetch();
			$result->closeCursor();

			// An is_array check, not `!== false`: fetch() can answer NULL
			// as well as false, which passes a `!== false` guard and then
			// warns on every offset read.
			if (is_array($row) === true) {
				$counts['total'] = (int)$row['total'];
				$counts['pgvectorPopulated'] = (int)$row['populated'];
			}
		} catch (\Throwable $e) {
			// The ANN sidecar may not exist yet — plain row count only.
			$counts['total'] = $this->plainVectorCount();
		}

		return $counts;
	}//end vectorCounts()

	/**
	 * The vector row count without the sidecar join.
	 *
	 * @return int The count, or zero when it cannot be read.
	 */
	private function plainVectorCount(): int {
		try {
			$result = $this->db->executeQuery(
				'SELECT COUNT(*) AS total FROM *PREFIX*openregister_vectors'
			);
			$total = $result->fetchOne();
			$result->closeCursor();

			if ($total !== false) {
				return (int)$total;
			}
		} catch (\Throwable $inner) {
			// Reported as zero.
		}

		return 0;
	}//end plainVectorCount()

	/**
	 * How many chunks exist and how many are vectorized.
	 *
	 * @return array{total: int, vectorized: int} Zeros for what cannot be read.
	 */
	private function chunkVectorizationProgress(): array {
		$progress = ['total' => 0, 'vectorized' => 0];
		try {
			$chunkMapper = $this->container->get(\OCA\OpenRegister\Db\ChunkMapper::class);
			if ($chunkMapper instanceof \OCA\OpenRegister\Db\ChunkMapper) {
				$progress['total'] = $chunkMapper->countAll();
				$progress['vectorized'] = $chunkMapper->countVectorized();
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[SettingsController] Failed to fetch chunk vectorization progress',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return $progress;
	}//end chunkVectorizationProgress()

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

	/**
	 * Debug endpoint for type filtering issue
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with type filtering debug information
	 *
	 * @psalm-return JSONResponse<200|404|500,
	 *     array{error?: string, trace?: string,
	 *     all_organizations?: array{count: int<0, max>,
	 *     organizations: array<array{id: int, name: null|string,
	 *     type: 'NO TYPE'|mixed, object_data: array|null}>},
	 *     type_samenwerking?: array{count: int<0, max>,
	 *     organizations: array<array{id: int, name: null|string,
	 *     type: 'NO TYPE'|mixed}>},
	 *     type_community?: array{count: int<0, max>,
	 *     organizations: array<array{id: int, name: null|string,
	 *     type: 'NO TYPE'|mixed}>},
	 *     type_both?: array{count: int<0, max>,
	 *     organizations: array<array{id: int, name: null|string,
	 *     type: 'NO TYPE'|mixed}>},
	 *     direct_database_query?: array{count: int<0, max>,
	 *     organizations: array<array{id: mixed, name: mixed,
	 *     type: 'NO TYPE'|mixed, object_json: mixed}>}},
	 *     array<never, never>>
	 *
	 * @spec exclude Debug/test scaffolding endpoint ("Debug endpoint for type filtering issue"): dumps
	 *              organisation/object data; not a product contract (see proposal Notes — routed debug surface,
	 *              information-disclosure risk).
	 *
	 * @NoAdminRequired
	 */
	public function debugTypeFiltering(): JSONResponse {
		try {
			// Get services.
			$objectService = $this->container->get(\OCA\OpenRegister\Service\ObjectService::class);

			// Set register and schema context. The register slug is RESOLVED,
			// not written: stackiq's repair step renames it from
			// `voorzieningen` to `stackiq` per instance, and reading with the
			// name this instance does not carry returns an empty set that this
			// endpoint would have rendered as "no organisations". That is the
			// wrong answer for a diagnostic whose whole job is to say whether
			// filtering works.
			$slugResolver = $this->container->get(\OCA\OpenRegister\Contract\RegisterSlugResolverInterface::class);
			$register = $slugResolver->slugOrNull(canonical: 'stackiq');
			if ($register === null) {
				return new JSONResponse(
					data: ['error' => 'No stackiq register on this instance under any of its known slugs.'],
					statusCode: 404
				);
			}

			$objectService->setRegister($register);
			$objectService->setSchema('organisation');

			$results = [
				'all_organizations' => $this->organisationsOfType(objectService: $objectService, types: []),
				'type_samenwerking' => $this->organisationsOfType(objectService: $objectService, types: ['samenwerking']),
				'type_community' => $this->organisationsOfType(objectService: $objectService, types: ['community']),
				'type_both' => $this->organisationsOfType(
					objectService: $objectService,
					types: ['samenwerking', 'community']
				),
				'direct_database_query' => $this->organisationsByNameInDatabase(),
			];

			return new JSONResponse(data: $results);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				],
				statusCode: 500
			);
		}//end try
	}//end debugTypeFiltering()

	/**
	 * Search the organisation schema in the database, optionally filtered on type.
	 *
	 * The unfiltered search also returns each organisation's object data.
	 *
	 * @param \OCA\OpenRegister\Service\ObjectService $objectService The object service, set to the organisation schema.
	 * @param list<string>  $types         The types to filter on; empty for no filter.
	 *
	 * @return array{count: int<0, max>, organizations: list<array<string, mixed>>}
	 */
	private function organisationsOfType(\OCA\OpenRegister\Service\ObjectService $objectService, array $types): array {
		$query = [
			'_limit' => 10,
			'_page' => 1,
			'_source' => 'database',
		];
		if ($types !== []) {
			$query['type'] = $types;
		}

		$found = $objectService->searchObjectsPaginated($query);
		$organisations = [];
		foreach ($found['results'] as $org) {
			$objectData = $org->getObject();
			$summary = [
				'id' => $org->getId(),
				'name' => $org->getName(),
				'type' => $objectData['type'] ?? 'NO TYPE',
			];
			if ($types === []) {
				$summary['object_data'] = $objectData;
			}

			$organisations[] = $summary;
		}

		return ['count' => count($found['results']), 'organizations' => $organisations];
	}//end organisationsOfType()

	/**
	 * Read the objects named like Samenwerking or Community straight from the objects table.
	 *
	 * @return array{count: int<0, max>, organizations: list<array<string, mixed>>}
	 */
	private function organisationsByNameInDatabase(): array {
		$connection = $this->container->get(\OCP\IDBConnection::class);
		$qb = $connection->getQueryBuilder();
		$qb->select('o.id', 'o.name', 'o.object')
			->from('openregister_objects', 'o')
			->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
			->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

		// NC's IResult does not expose Doctrine's fetchAllAssociative() on every
		// supported server (absent on NC 32), and a fetch() that answers NULL never
		// satisfies `!== false`, so the loop ends on anything that is not a row.
		$stmt = $qb->executeQuery();
		$organisations = [];
		$row = $stmt->fetch();
		while (is_array($row) === true) {
			$objectData = json_decode($row['object'], true);
			$organisations[] = [
				'id' => $row['id'],
				'name' => $row['name'],
				'type' => $objectData['type'] ?? 'NO TYPE',
				'object_json' => $row['object'],
			];
			$row = $stmt->fetch();
		}

		$stmt->closeCursor();

		return ['count' => count($organisations), 'organizations' => $organisations];
	}//end organisationsByNameInDatabase()
}//end class
