<?php

/**
 * OpenRegister Settings Controller
 *
 * This file contains the controller class for handling settings in the OpenRegister application.
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

use OCP\IAppConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Exception;
use RuntimeException;
use ReflectionClass;
use DateTime;
use stdClass;
use OCA\OpenRegister\Service\IndexService;
use OCA\OpenRegister\Service\Index\SetupHandler;
use OCP\App\IAppManager;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\VectorizationService;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Psr\Log\LoggerInterface;

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
 * - Delegate SOLR testing to IndexService
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
 * SOLR SETTINGS:
 * - GET  /api/settings/solr         - Get SOLR settings
 * - PUT  /api/settings/solr         - Update SOLR settings
 * - PATCH /api/settings/solr        - Patch SOLR settings
 * - POST /api/settings/solr/test    - Test SOLR connection (delegates to IndexService)
 * - POST /api/settings/solr/warmup  - Warmup SOLR index
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
 * - SOLR testing → IndexService
 * - Cache operations → Cache services
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 */

/**
 * SettingsController class
 *
 * Thin controller layer for settings management.
 *
 * @psalm-suppress UnusedClass
 */
class SettingsController extends Controller
{

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
     * @param string               $appName              The name of the app.
     * @param IRequest             $request              The request object.
     * @param IAppConfig           $config               The app configuration.
     * @param IDBConnection        $db                   The database connection.
     * @param ContainerInterface   $container            The container.
     * @param IAppManager          $appManager           The app manager.
     * @param SettingsService      $settingsService      The settings service.
     * @param VectorizationService $vectorizationService The vectorization service.
     * @param LoggerInterface      $logger               The logger.
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
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Attempts to retrieve the OpenRegister service from the container.
     *
     * @return null The OpenRegister service if available, null otherwise.
     *
     * @throws \RuntimeException If the service is not available.
     */
    public function getObjectService()
    {
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
     */
    public function getConfigurationService(): ?\OCA\OpenRegister\Service\ConfigurationService
    {
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
     * @return JSONResponse JSON response containing the current settings.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function index(): JSONResponse
    {
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
     * @return JSONResponse JSON response containing the updated settings.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function update(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updateSettings($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end update()

    /**
     * Load the settings from the publication_register.json file.
     *
     * @return JSONResponse JSON response containing the settings.
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function load(): JSONResponse
    {
        try {
            $result = $this->settingsService->getSettings();
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end load()

    /**
     * Update the publishing options.
     *
     * @return JSONResponse JSON response containing the updated publishing options.
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function updatePublishingOptions(): JSONResponse
    {
        try {
            $data   = $this->request->getParams();
            $result = $this->settingsService->updatePublishingOptions($data);
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end updatePublishingOptions()

    /**
     * Rebase all objects and logs with current retention settings.
     *
     * This method recalculates deletion times for all objects and logs based on current retention settings.
     * It also assigns default owners and organizations to objects that don't have them assigned.
     *
     * @return JSONResponse JSON response containing the rebase operation result.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function rebase(): JSONResponse
    {
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
     * @return JSONResponse JSON response containing statistics data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|422, array, array<never, never>>
     */
    public function stats(): JSONResponse
    {
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
     * @return JSONResponse JSON response containing statistics data.
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @psalm-return JSONResponse<200|422, array, array<never, never>>
     */
    public function getStatistics(): JSONResponse
    {
        return $this->stats();
    }//end getStatistics()

    /**
     * Test SOLR setup directly (bypassing SolrService)
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @return JSONResponse The SOLR setup test results
     */
    public function testSetupHandler(): JSONResponse
    {
        try {
            // Get SOLR settings directly.
            $solrSettings = $this->settingsService->getSolrSettings();

            if (($solrSettings['enabled'] === false)) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'SOLR is disabled',
                    ],
                    statusCode: 400
                );
            }

            // Create SolrSetup using IndexService for authenticated HTTP client.
            $logger            = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $guzzleSolrService = $this->container->get(IndexService::class);
            $setup = new SetupHandler(solrService: $guzzleSolrService, logger: $logger);

            // Run setup.
            $result = $setup->setupSolr();

            if ($result === true) {
                return new JSONResponse(
                    data: [
                        'success' => true,
                        'message' => 'SOLR setup completed successfully',
                        'config'  => [
                            'host'   => $solrSettings['host'],
                            'port'   => $solrSettings['port'],
                            'scheme' => $solrSettings['scheme'],
                        ],
                    ]
                );
            } else {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'SOLR setup failed - check logs',
                    ],
                    statusCode: 422
                );
            }//end if
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'SOLR setup error: '.$e->getMessage(),
                ],
                statusCode: 422
            );
        }//end try
    }//end testSetupHandler()

    /**
     * Reindex a specific SOLR collection by name
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string $name The name of the collection to reindex
     *
     * @return JSONResponse The reindex result
     *
     * @psalm-return JSONResponse<200|400|422, array{success: bool, message: mixed|string, collection: string, stats?: array<never, never>|mixed}, array<never, never>>
     */
    public function reindexSpecificCollection(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Get optional parameters from request body.
            $maxObjects = (int) ($this->request->getParam('maxObjects', 0));
            $batchSize  = (int) ($this->request->getParam('batchSize', 1000));

            // Validate parameters.
            if ($batchSize < 1 || $batchSize > 5000) {
                return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Invalid batch size. Must be between 1 and 5000',
                        'collection' => $name,
                    ],
                    statusCode: 400
                );
            }

            if ($maxObjects < 0) {
                return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Invalid maxObjects. Must be 0 (all) or positive number',
                        'collection' => $name,
                    ],
                    statusCode: 400
                );
            }

            // Reindex the specified collection.
            $result = $guzzleSolrService->reindexAll(maxObjects: $maxObjects, batchSize: $batchSize, collectionName: $name);

            if ($result['success'] === true) {
                return new JSONResponse(
                    data: [
                        'success'    => true,
                        'message'    => 'Reindex completed successfully',
                        'stats'      => $result['stats'] ?? [],
                        'collection' => $name,
                    ],
                    statusCode: 200
                );
            } else {
                return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => $result['message'] ?? 'Failed to reindex collection',
                        'collection' => $name,
                    ],
                    statusCode: 422
                );
            }
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success'    => false,
                    'message'    => 'Reindex failed: '.$e->getMessage(),
                    'collection' => $name,
                ],
                statusCode: 422
            );
        }//end try
    }//end reindexSpecificCollection()

    /**
     * Get search backend configuration.
     *
     * Returns which search backend is currently active (solr, elasticsearch, etc).
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Backend configuration
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getSearchBackend(): JSONResponse
    {
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Updated backend configuration
     *
     * @psalm-return JSONResponse<200|400|500, array{error?: mixed|string, message?: 'Backend updated successfully. Please reload the application.', reload_required?: true,...}, array<never, never>>
     */
    public function updateSearchBackend(): JSONResponse
    {
        try {
            $data    = $this->request->getParams();
            $backend = $data['backend'] ?? $data['active'] ?? '';

            if (empty($backend) === true) {
                return new JSONResponse(
                    data: ['error' => 'Backend parameter is required'],
                    statusCode: 400
                );
            }

            $result = $this->settingsService->updateSearchBackendConfig($backend);

            return new JSONResponse(
                data: array_merge(
                    $result,
                    [
                        'message'         => 'Backend updated successfully. Please reload the application.',
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
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with database information
     *
     * @psalm-return JSONResponse<200|500, array{success: bool, error?: string, database?: array{type: string, version: string, platform: string, vectorSupport: bool, recommendedPlugin: null|string, performanceNote: null|string}}, array<never, never>>
     */
    public function getDatabaseInfo(): JSONResponse
    {
        try {
            // Get database platform information.
            // Note: getDatabasePlatform() returns a platform instance, but we avoid type hinting it.
            $platform = $this->db->getDatabasePlatform();
            // Get platform name as string.
            if (method_exists($platform, 'getName') === true) {
                $platformName = $platform->getName();
            } else {
                $platformName = 'unknown';
            }

            // Determine database type and version.
            $dbType            = 'Unknown';
            $dbVersion         = 'Unknown';
            $vectorSupport     = false;
            $recommendedPlugin = null;
            $performanceNote   = null;

            if (strpos($platformName, 'mysql') !== false || strpos($platformName, 'mariadb') !== false) {
                // Check if it's MariaDB or MySQL.
                try {
                    $stmt    = $this->db->prepare('SELECT VERSION()');
                    $result  = $stmt->execute();
                    $version = $result->fetchOne();

                    if (stripos($version, 'MariaDB') !== false) {
                        $dbType = 'MariaDB';
                        preg_match('/\d+\.\d+\.\d+/', $version, $matches);
                        $dbVersion = $matches[0] ?? $version;
                    } else {
                        $dbType = 'MySQL';
                        preg_match('/\d+\.\d+\.\d+/', $version, $matches);
                        $dbVersion = $matches[0] ?? $version;
                    }
                } catch (Exception $e) {
                    $dbType    = 'MySQL/MariaDB';
                    $dbVersion = 'Unknown';
                }

                // MariaDB/MySQL do not support native vector operations.
                $vectorSupport     = false;
                $recommendedPlugin = 'pgvector for PostgreSQL';
                $performanceNote   = 'Current: Similarity calculated in PHP (slow). Recommended: Migrate to PostgreSQL + pgvector for 10-100x speedup.';
            } else if (strpos($platformName, 'postgres') !== false) {
                $dbType = 'PostgreSQL';

                try {
                    $stmt    = $this->db->prepare('SELECT VERSION()');
                    $result  = $stmt->execute();
                    $version = $result->fetchOne();
                    preg_match('/PostgreSQL (\d+\.\d+)/', $version, $matches);
                    $dbVersion = $matches[1] ?? 'Unknown';
                } catch (Exception $e) {
                    $dbVersion = 'Unknown';
                }

                // Check if pgvector extension is installed.
                try {
                    $stmt      = $this->db->prepare("SELECT COUNT(*) FROM pg_extension WHERE extname = 'vector'");
                    $result    = $stmt->execute();
                    $hasVector = $result->fetchOne() > 0;

                    if ($hasVector === true) {
                        $vectorSupport     = true;
                        $recommendedPlugin = 'pgvector (installed ✓)';
                        $performanceNote   = 'Optimal: Using database-level vector operations for fast semantic search.';
                    } else {
                        $vectorSupport     = false;
                        $recommendedPlugin = 'pgvector (not installed)';
                        $performanceNote   = 'Install pgvector extension: CREATE EXTENSION vector;';
                    }
                } catch (Exception $e) {
                    $vectorSupport     = false;
                    $recommendedPlugin = 'pgvector (not found)';
                    $performanceNote   = 'Unable to detect pgvector. Install with: CREATE EXTENSION vector;';
                }
            } else if (strpos($platformName, 'sqlite') !== false) {
                $dbType            = 'SQLite';
                $vectorSupport     = false;
                $recommendedPlugin = 'sqlite-vss or migrate to PostgreSQL';
                $performanceNote   = 'SQLite not recommended for production vector search.';
            }//end if

            return new JSONResponse(
                data: [
                    'success'  => true,
                    'database' => [
                        'type'              => $dbType,
                        'version'           => $dbVersion,
                        'platform'          => $platformName,
                        'vectorSupport'     => $vectorSupport,
                        'recommendedPlugin' => $recommendedPlugin,
                        'performanceNote'   => $performanceNote,
                    ],
                ]
            );
        } catch (Exception $e) {
            $this->logger->error(
                '[SettingsController] Failed to get database info',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]
            );

            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => 'Failed to get database information: '.$e->getMessage(),
                ],
                statusCode: 500
            );
        }//end try
    }//end getDatabaseInfo()

    /**
     * Get version information only
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Version information
     *
     * @psalm-return JSONResponse<200|500, array, array<never, never>>
     */
    public function getVersionInfo(): JSONResponse
    {
        try {
            $data = $this->settingsService->getVersionInfoOnly();
            return new JSONResponse(data: $data);
        } catch (Exception $e) {
            return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
        }
    }//end getVersionInfo()

    /**
     * Test schema-aware SOLR mapping by indexing sample objects
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse Test results
     *
     * @psalm-return JSONResponse<200, array<array-key, mixed>, array<never, never>>|JSONResponse<422, array{success: false, error: string}, array<never, never>>
     */
    public function testSchemaMapping(): JSONResponse
    {
        try {
            // Get IndexService from container.
            $solrService = $this->container->get(IndexService::class);

            // Get required dependencies from container.
            $objectMapper = $this->container->get(\OCA\OpenRegister\Db\ObjectEntityMapper::class);
            $schemaMapper = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);

            // Run the test.
            $results = $solrService->testSchemaAwareMapping(objectMapper: $objectMapper, schemaMapper: $schemaMapper);

            return new JSONResponse(data: $results);
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                ],
                statusCode: 422
            );
        }//end try
    }//end testSchemaMapping()

    /**
     * Debug endpoint for type filtering issue
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with type filtering debug information
     *
     * @psalm-return JSONResponse<200|500, array{error?: string, trace?: string, all_organizations?: array{count: int<0, max>, organizations: array<array{id: int, name: null|string, type: 'NO TYPE'|mixed, object_data: array|null}>}, type_samenwerking?: array{count: int<0, max>, organizations: array<array{id: int, name: null|string, type: 'NO TYPE'|mixed}>}, type_community?: array{count: int<0, max>, organizations: array<array{id: int, name: null|string, type: 'NO TYPE'|mixed}>}, type_both?: array{count: int<0, max>, organizations: array<array{id: int, name: null|string, type: 'NO TYPE'|mixed}>}, direct_database_query?: array{count: int<0, max>, organizations: array<array{id: mixed, name: mixed, type: 'NO TYPE'|mixed, object_json: mixed}>}}, array<never, never>>
     */
    public function debugTypeFiltering(): JSONResponse
    {
        try {
            // Get services.
            $objectService = null;
            // CIRCULAR FIX.
            $connection = $this->container->get(\OCP\IDBConnection::class);

            // Set register and schema context.
            $objectService->setRegister('voorzieningen');
            $objectService->setSchema('organisatie');

            $results = [];

            // Test 1: Get all organizations.
            $query1  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
            ];
            $result1 = $objectService->searchObjectsPaginated($query1);
            $results['all_organizations'] = [
                'count'         => count($result1['results']),
                'organizations' => array_map(
                        /*
                         * @return (array|int|mixed|null|string)[]
                         *
                         * @psalm-return array{id: int, name: null|string, type: 'NO TYPE'|mixed, object_data: array|null}
                         */

                    function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
                        $objectData = $org->getObject();
                        return [
                            'id'          => $org->getId(),
                            'name'        => $org->getName(),
                            'type'        => $objectData['type'] ?? 'NO TYPE',
                            'object_data' => $objectData,
                        ];
                    },
                    $result1['results']
                ),
            ];

            // Test 2: Try type filtering with samenwerking.
            $query2  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
                'type'    => ['samenwerking'],
            ];
            $result2 = $objectService->searchObjectsPaginated($query2);
            $results['type_samenwerking'] = [
                'count'         => count($result2['results']),
                'organizations' => array_map(
                        /*
                         * @return (int|mixed|null|string)[]
                         *
                         * @psalm-return array{id: int, name: null|string, type: 'NO TYPE'|mixed}
                         */

                    function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
                        $objectData = $org->getObject();
                        return [
                            'id'   => $org->getId(),
                            'name' => $org->getName(),
                            'type' => $objectData['type'] ?? 'NO TYPE',
                        ];
                    },
                    $result2['results']
                ),
            ];

            // Test 3: Try type filtering with community.
            $query3  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
                'type'    => ['community'],
            ];
            $result3 = $objectService->searchObjectsPaginated($query3);
            $results['type_community'] = [
                'count'         => count($result3['results']),
                'organizations' => array_map(
                        /*
                         * @return (int|mixed|null|string)[]
                         *
                         * @psalm-return array{id: int, name: null|string, type: 'NO TYPE'|mixed}
                         */
                    function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
                        $objectData = $org->getObject();
                        return [
                            'id'   => $org->getId(),
                            'name' => $org->getName(),
                            'type' => $objectData['type'] ?? 'NO TYPE',
                        ];
                    },
                    $result3['results']
                ),
            ];

            // Test 4: Try type filtering with both types.
            $query4  = [
                '_limit'  => 10,
                '_page'   => 1,
                '_source' => 'database',
                'type'    => ['samenwerking', 'community'],
            ];
            $result4 = $objectService->searchObjectsPaginated($query4);
            $results['type_both'] = [
                'count'         => count($result4['results']),
                'organizations' => array_map(
                        /*
                         * @return (int|mixed|null|string)[]
                         *
                         * @psalm-return array{id: int, name: null|string, type: 'NO TYPE'|mixed}
                         */
                    function (\OCA\OpenRegister\Db\ObjectEntity $org): array {
                        $objectData = $org->getObject();
                        return [
                            'id'   => $org->getId(),
                            'name' => $org->getName(),
                            'type' => $objectData['type'] ?? 'NO TYPE',
                        ];
                    },
                    $result4['results']
                ),
            ];

            // Test 5: Direct database query to check type field.
            $qb = $connection->getQueryBuilder();
            $qb->select('o.id', 'o.name', 'o.object')
                ->from('openregister_objects', 'o')
                ->where($qb->expr()->like('o.name', $qb->createNamedParameter('%Samenwerking%')))
                ->orWhere($qb->expr()->like('o.name', $qb->createNamedParameter('%Community%')));

            $stmt = $qb->executeQuery();
            $rows = $stmt->fetchAllAssociative();

            $results['direct_database_query'] = [
                'count'         => count($rows),
                'organizations' => array_map(
                        /*
                         * @return (mixed|string)[]
                         *
                         * @psalm-return array{id: mixed, name: mixed, type: 'NO TYPE'|mixed, object_json: mixed}
                         */
                    function (array $row): array {
                        $objectData = json_decode($row['object'], true);
                        return [
                            'id'          => $row['id'],
                            'name'        => $row['name'],
                            'type'        => $objectData['type'] ?? 'NO TYPE',
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
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string      $query    Search query text
     * @param int         $limit    Maximum number of results (default: 10)
     * @param array       $filters  Optional filters (entity_type, entity_id, etc.)
     * @param string|null $provider Embedding provider override
     *
     * @return JSONResponse JSON response with semantic search results
     *
     * @psalm-return JSONResponse<200|400|500, array{success: bool, error?: string, trace?: string, query?: string, results?: array<int, array<string, mixed>>, total?: int<0, max>, limit?: int, filters?: array, timestamp?: string}, array<never, never>>
     */
    public function semanticSearch(string $query, int $limit=10, array $filters=[], ?string $provider=null): JSONResponse
    {
        try {
            if (empty(trim($query)) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Query parameter is required',
                    ],
                    statusCode: 400
                );
            }

            // Use VectorizationService for semantic search.
            $vectorService = $this->vectorizationService;

            // Perform semantic search.
            $results = $vectorService->semanticSearch(query: $query, limit: $limit, filters: $filters, provider: $provider);

            return new JSONResponse(
                data: [
                    'success'   => true,
                    'query'     => $query,
                    'results'   => $results,
                    'total'     => count($results),
                    'limit'     => $limit,
                    'filters'   => $filters,
                    'timestamp' => date('c'),
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ],
                statusCode: 500
            );
        }//end try
    }//end semanticSearch()

    /**
     * Perform hybrid search combining SOLR keyword and vector semantic search
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string      $query       Search query text
     * @param int         $limit       Maximum number of results (default: 20)
     * @param array       $solrFilters SOLR-specific filters
     * @param array       $weights     Search type weights ['solr' => 0.5, 'vector' => 0.5]
     * @param string|null $provider    Embedding provider override
     *
     * @return JSONResponse Combined search results
     *
     * @psalm-return JSONResponse<200|400|500, array{success: bool|mixed, error?: mixed|string, trace?: mixed|string, query?: mixed|string, timestamp?: string,...}, array<never, never>>
     */
    public function hybridSearch(
        string $query,
        int $limit=20,
        array $solrFilters=[],
        array $weights=['solr' => 0.5, 'vector' => 0.5],
        ?string $provider=null
    ): JSONResponse {
        try {
            if (empty(trim($query)) === true) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => 'Query parameter is required',
                    ],
                    statusCode: 400
                );
            }

            // Use VectorizationService for hybrid search.
            $vectorService = $this->vectorizationService;

            // Perform hybrid search.
            $result = $vectorService->hybridSearch(query: $query, solrFilters: $solrFilters, limit: $limit, weights: $weights, provider: $provider);

            // Ensure result is an array for spread operator.
            if (is_array($result) === true) {
                $resultArray = $result;
            } else {
                $resultArray = [];
            }

            return new JSONResponse(
                data: [
                    'success'   => true,
                    'query'     => $query,
                    ...$resultArray,
                    'timestamp' => date('c'),
                ]
            );
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'error'   => $e->getMessage(),
                    'trace'   => $e->getTraceAsString(),
                ],
                statusCode: 500
            );
        }//end try
    }//end hybridSearch()
}//end class
