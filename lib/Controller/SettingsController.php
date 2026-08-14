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
use OCA\OpenRegister\Service\VectorizationService;
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
 *   endpoints (general/rbac/multitenancy/retention/solr/llm/file/object/cache) in one class per
 *   the thin-controller architecture; splitting would require multiple route groups and controllers
 *   for a natural single responsibility surface.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is distributed across 15 thin
 *   delegation methods each containing a single try/catch; the overall score exceeds the threshold
 *   purely from method count, not from deep conditional logic in any single method.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   NC AppFramework controller requires DI for
 *   AppFramework types (IRequest, IAppConfig, IDBConnection, ContainerInterface, IAppManager)
 *   plus SettingsService, VectorizationService, LoggerInterface, and IL10N — all are single-call
 *   dependencies that cannot be cohesively grouped without hiding the NC DI contract.
 */
class SettingsController extends Controller {

	/**
	 * The OpenRegister object service
	 *
	 * Lazily loaded from container when needed.
	 *
	 * @var \OCA\OpenRegister\Service\ObjectService|null OpenRegister object service or null
	 */
	private ?\OCA\OpenRegister\Service\ObjectService $objectService = null;

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
	 * @param VectorizationService $vectorizationService The vectorization service.
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
		private readonly VectorizationService $vectorizationService,
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
			$this->objectService = null;
			// CIRCULAR FIX.
			return $this->objectService;
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
	 * Returns which search backend is currently active (solr, elasticsearch, etc).
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
	 * Update search backend configuration.
	 *
	 * Sets which search backend should be active (requires app reload).
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with updated backend config
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function updateSearchBackend(): JSONResponse {
		try {
			$data = $this->request->getParams();
			$backend = $data['backend'] ?? $data['active'] ?? '';

			if (empty($backend) === true) {
				return new JSONResponse(
					data: ['error' => $this->l10n->t('Backend parameter is required')],
					statusCode: 400
				);
			}

			$result = $this->settingsService->updateSearchBackendConfig($backend);

			return new JSONResponse(
				data: array_merge(
					$result,
					[
						'message' => $this->l10n->t('Backend updated successfully. Please reload the application.'),
						'reload_required' => true,
					]
				)
			);
		} catch (Exception $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
		}//end try
	}//end updateSearchBackend()

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
	 * @suppressWarnings(PHPMD.ExcessiveMethodLength)
	 * @suppressWarnings(PHPMD.CyclomaticComplexity)
	 * @suppressWarnings(PHPMD.NPathComplexity)
	 *
	 * @spec openspec/specs/production-observability/spec.md
	 */
	public function getDatabaseInfo(): JSONResponse {
		try {
			// Check if refresh is requested or if we should use cached data.
			$refresh = filter_var(
				$this->request->getParam('refresh', false),
				FILTER_VALIDATE_BOOLEAN
			);

			// Try to get cached database info if not refreshing.
			if ($refresh === false) {
				$cachedInfo = $this->config->getValueString('openregister', 'databaseInfo', '');
				if (empty($cachedInfo) === false) {
					$cached = json_decode($cachedInfo, true);
					if ($cached !== null && isset($cached['database']) === true) {
						$cached['fromCache'] = true;
						return new JSONResponse(data: $cached);
					}
				}
			}

			// Get database platform information.
			// Note: getDatabasePlatform() returns a platform instance, but we avoid type hinting it.
			$platform = $this->db->getDatabasePlatform();
			// Get platform name as string.
			$platformName = 'unknown';
			if (method_exists($platform, 'getName') === true) {
				$platformName = $platform->getName();
			}

			// Determine database type and version.
			$dbType = 'Unknown';
			$dbVersion = 'Unknown';
			$vectorSupport = false;
			$recommendedPlugin = null;
			$performanceNote = null;
			$extensions = [];

			if (strpos($platformName, 'mysql') !== false || strpos($platformName, 'mariadb') !== false) {
				// Check if it's MariaDB or MySQL.
				try {
					$stmt = $this->db->prepare('SELECT VERSION()');
					$result = $stmt->execute();
					$version = $result->fetchOne();

					$dbType = 'MySQL';
					if (stripos($version, 'MariaDB') !== false) {
						$dbType = 'MariaDB';
					}

					preg_match('/\d+\.\d+\.\d+/', $version, $matches);
					$dbVersion = $matches[0] ?? $version;
				} catch (Exception $e) {
					$dbType = 'MySQL/MariaDB';
					$dbVersion = 'Unknown';
				}

				// MariaDB/MySQL do not support native vector operations.
				$vectorSupport = false;
				$recommendedPlugin = 'pgvector for PostgreSQL';
				$performanceNote = 'Current: Similarity calculated in PHP (slow).'
					. ' Recommended: Migrate to PostgreSQL + pgvector for 10-100x speedup.';
			} elseif (strpos($platformName, 'postgres') !== false) {
				$dbType = 'PostgreSQL';

				try {
					$stmt = $this->db->prepare('SELECT VERSION()');
					$result = $stmt->execute();
					$version = $result->fetchOne();
					preg_match('/PostgreSQL (\d+\.\d+)/', $version, $matches);
					$dbVersion = $matches[1] ?? 'Unknown';
				} catch (Exception $e) {
					$dbVersion = 'Unknown';
				}

				// Fetch all installed PostgreSQL extensions.
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

				// Check if pgvector extension is installed.
				$hasVector = false;
				foreach ($extensions as $ext) {
					if ($ext['name'] === 'vector') {
						$hasVector = true;
						break;
					}
				}

				$vectorSupport = false;
				$recommendedPlugin = 'pgvector (not installed)';
				$performanceNote = 'Install pgvector extension: CREATE EXTENSION vector;';
				if ($hasVector === true) {
					$vectorSupport = true;
					$recommendedPlugin = 'pgvector (installed)';
					$performanceNote = 'Optimal: Using database-level vector operations for fast semantic search.';
				}
			} elseif (strpos($platformName, 'sqlite') !== false) {
				$dbType = 'SQLite';
				$vectorSupport = false;
				$recommendedPlugin = 'sqlite-vss or migrate to PostgreSQL';
				$performanceNote = 'SQLite not recommended for production vector search.';
			}//end if

			// Build the database info array.
			$databaseInfo = [
				'type' => $dbType,
				'version' => $dbVersion,
				'platform' => $platformName,
				'vectorSupport' => $vectorSupport,
				'recommendedPlugin' => $recommendedPlugin,
				'performanceNote' => $performanceNote,
				'extensions' => $extensions,
				'hybridSearch' => $this->getHybridSearchDiagnostics(isPostgres: $dbType === 'PostgreSQL'),
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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Independent tolerant lookups
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#6.1
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
			try {
				$result = $this->db->executeQuery(
					'SELECT a.atttypmod FROM pg_attribute a '
					. "WHERE a.attrelid = '" . $sidecar . "'::regclass "
					. "AND a.attname = 'embedding' AND NOT a.attisdropped"
				);
				$typmod = $result->fetchOne();
				$result->closeCursor();

				if ($typmod !== false && (int)$typmod > 0) {
					$diagnostics['annSidecarTable'] = true;
					$diagnostics['embeddingVectorDimension'] = (int)$typmod;
				}
			} catch (\Throwable $e) {
				// Sidecar unavailable — reported as false.
			}

			try {
				$result = $this->db->executeQuery(
					'SELECT indexname FROM pg_indexes WHERE indexname IN '
					. "('idx_or_vec_ann_hnsw', 'idx_or_chunks_text_search_gin')"
				);
				while (is_array($row = $result->fetch()) === true) {
					if ($row['indexname'] === 'idx_or_vec_ann_hnsw') {
						$diagnostics['hnswIndex'] = true;
					}

					if ($row['indexname'] === 'idx_or_chunks_text_search_gin') {
						$diagnostics['textSearchGinIndex'] = true;
					}
				}

				$result->closeCursor();
			} catch (\Throwable $e) {
				// Reported as false.
			}

			try {
				$result = $this->db->executeQuery(
					'SELECT COUNT(*) AS total, COUNT(a.vector_id) AS populated '
					. 'FROM *PREFIX*openregister_vectors v '
					. "LEFT JOIN $sidecar a ON a.vector_id = v.id"
				);
				$row = $result->fetch();
				$result->closeCursor();

				if ($row !== false) {
					$diagnostics['vectors']['total'] = (int)$row['total'];
					$diagnostics['vectors']['pgvectorPopulated'] = (int)$row['populated'];
				}
			} catch (\Throwable $e) {
				// The ANN sidecar may not exist yet — plain row count only.
				try {
					$result = $this->db->executeQuery(
						'SELECT COUNT(*) AS total FROM *PREFIX*openregister_vectors'
					);
					$total = $result->fetchOne();
					$result->closeCursor();

					if ($total !== false) {
						$diagnostics['vectors']['total'] = (int)$total;
					}
				} catch (\Throwable $inner) {
					// Reported as zero.
				}
			}//end try
		}//end if

		// Chunk vectorization progress (platform-agnostic).
		try {
			$chunkMapper = $this->container->get(\OCA\OpenRegister\Db\ChunkMapper::class);
			if ($chunkMapper instanceof \OCA\OpenRegister\Db\ChunkMapper) {
				$diagnostics['chunks']['total'] = $chunkMapper->countAll();
				$diagnostics['chunks']['vectorized'] = $chunkMapper->countVectorized();
			}
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[SettingsController] Failed to fetch chunk vectorization progress',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
		}

		return $diagnostics;
	}//end getHybridSearchDiagnostics()

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
	 * @psalm-return JSONResponse<200|500,
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
	 * @suppressWarnings(PHPMD.ExcessiveMethodLength)
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

			// Set register and schema context.
			$objectService->setRegister('voorzieningen');
			$objectService->setSchema('organisation');

			$results = [];

			// Test 1: Get all organizations.
			$query1 = [
				'_limit' => 10,
				'_page' => 1,
				'_source' => 'database',
			];
			$result1 = $objectService->searchObjectsPaginated($query1);
			$results['all_organizations'] = [
				'count' => count($result1['results']),
				'organizations' => array_map(
					// Maps ObjectEntity to simplified array.
					function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
						$objectData = $org->getObject();
						return [
							'id' => $org->getId(),
							'name' => $org->getName(),
							'type' => $objectData['type'] ?? 'NO TYPE',
							'object_data' => $objectData,
						];
					},
					$result1['results']
				),
			];

			// Test 2: Try type filtering with samenwerking.
			$query2 = [
				'_limit' => 10,
				'_page' => 1,
				'_source' => 'database',
				'type' => ['samenwerking'],
			];
			$result2 = $objectService->searchObjectsPaginated($query2);
			$results['type_samenwerking'] = [
				'count' => count($result2['results']),
				'organizations' => array_map(
					// Maps ObjectEntity to simplified array with type.
					function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
						$objectData = $org->getObject();
						return [
							'id' => $org->getId(),
							'name' => $org->getName(),
							'type' => $objectData['type'] ?? 'NO TYPE',
						];
					},
					$result2['results']
				),
			];

			// Test 3: Try type filtering with community.
			$query3 = [
				'_limit' => 10,
				'_page' => 1,
				'_source' => 'database',
				'type' => ['community'],
			];
			$result3 = $objectService->searchObjectsPaginated($query3);
			$results['type_community'] = [
				'count' => count($result3['results']),
				'organizations' => array_map(
					// Maps ObjectEntity to simplified array with type.
					function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
						$objectData = $org->getObject();
						return [
							'id' => $org->getId(),
							'name' => $org->getName(),
							'type' => $objectData['type'] ?? 'NO TYPE',
						];
					},
					$result3['results']
				),
			];

			// Test 4: Try type filtering with both types.
			$query4 = [
				'_limit' => 10,
				'_page' => 1,
				'_source' => 'database',
				'type' => ['samenwerking', 'community'],
			];
			$result4 = $objectService->searchObjectsPaginated($query4);
			$results['type_both'] = [
				'count' => count($result4['results']),
				'organizations' => array_map(
					// Maps ObjectEntity to simplified array with type.
					function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
						$objectData = $org->getObject();
						return [
							'id' => $org->getId(),
							'name' => $org->getName(),
							'type' => $objectData['type'] ?? 'NO TYPE',
						];
					},
					$result4['results']
				),
			];

			// Test 5: Direct database query to check type field.
			$connection = $this->container->get(\OCP\IDBConnection::class);
			$qb = $connection->getQueryBuilder();
			$qb->select('o.id', 'o.name', 'o.object')
				->from('openregister_objects', 'o')
				->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
				->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

			// NC's IResult does not expose Doctrine's fetchAllAssociative() on
			// every supported server (absent on NC 32) — iterate fetch() instead
			// (see RegisterMapper::getAllRegisterIdsWithSchema / MarkerLookupTrait).
			$stmt = $qb->executeQuery();
			$rows = [];
			$row = $stmt->fetch();
			while ($row !== false) {
				$rows[] = $row;
				$row = $stmt->fetch();
			}

			$stmt->closeCursor();

			$results['direct_database_query'] = [
				'count' => count($rows),
				'organizations' => array_map(
					// Maps row to simplified array with type from JSON.
					function (array $row): array {
						$objectData = json_decode($row['object'], true);
						return [
							'id' => $row['id'],
							'name' => $row['name'],
							'type' => $objectData['type'] ?? 'NO TYPE',
							'object_json' => $row['object'],
						];
					},
					$rows
				),
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
	 * Perform semantic search using vector embeddings
	 *
	 * @param string $query Search query text
	 * @param int $limit Maximum number of results (default: 10)
	 * @param array $filters Optional filters (entity_type, entity_id, etc.)
	 * @param string|null $provider Embedding provider override
	 *
	 * @return JSONResponse JSON response with semantic search results
	 *
	 * @NoCSRFRequired
	 *
	 * @psalm-return JSONResponse<200|400|500,
	 *     array{success: bool, error?: string, trace?: string, query?: string,
	 *     results?: array<int, array<string, mixed>>, total?: int<0, max>,
	 *     limit?: int, filters?: array, timestamp?: string},
	 *     array<never, never>>
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null): JSONResponse {
		try {
			if (empty(trim($query)) === true) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => $this->l10n->t('Query parameter is required'),
					],
					statusCode: 400
				);
			}

			$results = $this->vectorizationService->semanticSearch(query: $query, limit: $limit, filters: $filters, provider: $provider);

			return new JSONResponse(
				data: [
					'success' => true,
					'query' => $query,
					'results' => $results,
					'total' => count($results),
					'limit' => $limit,
					'filters' => $filters,
					'timestamp' => date('c'),
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				],
				statusCode: 500
			);
		}//end try
	}//end semanticSearch()

	/**
	 * Perform hybrid search combining keyword and vector semantic search
	 *
	 * @param string $query Search query text
	 * @param int $limit Maximum number of results (default: 20)
	 * @param array $keywordResults Pre-fetched keyword search results to fuse
	 * @param array $weights Search type weights ['keyword' => 0.5, 'vector' => 0.5]
	 * @param string|null $provider Embedding provider override
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse JSON response with hybrid search results
	 *
	 * @spec openspec/specs/chat-ai/spec.md
	 */
	public function hybridSearch(
		string $query,
		int $limit = 20,
		array $keywordResults = [],
		array $weights = ['keyword' => 0.5, 'vector' => 0.5],
		?string $provider = null,
	): JSONResponse {
		try {
			if (empty(trim($query)) === true) {
				return new JSONResponse(
					data: [
						'success' => false,
						'error' => $this->l10n->t('Query parameter is required'),
					],
					statusCode: 400
				);
			}

			$result = $this->vectorizationService->hybridSearch(
				query: $query,
				keywordResults: $keywordResults,
				limit: $limit,
				weights: $weights,
				provider: $provider
			);
			// Ensure result is an array for the spread operator.
			$resultArray = [];
			if (is_array($result) === true) {
				$resultArray = $result;
			}

			return new JSONResponse(
				data: [
					'success' => true,
					'query' => $query,
					...$resultArray,
					'timestamp' => date('c'),
				]
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: [
					'success' => false,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString(),
				],
				statusCode: 500
			);
		}//end try
	}//end hybridSearch()
}//end class
