<?php

/**
 * OpenRegister SOLR Management Controller
 *
 * @category  Controller
 * @package   OCA\OpenRegister\Controller\Settings
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Service\SettingsService;
use OCA\OpenRegister\Service\IndexService;
use Psr\Log\LoggerInterface;

/**
 * Controller for SOLR field and collection management.
 *
 * Handles:
 * - Field discovery, creation, and deletion
 * - Field validation and fixing
 * - Collection listing, creation, and deletion
 * - Collection copying and assignments
 * - Config set management
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 */
class SolrManagementController extends Controller
{
    /**
     * Constructor.
     *
     * @param string             $appName         The app name.
     * @param IRequest           $request         The request.
     * @param IDBConnection      $db              Database connection.
     * @param ContainerInterface $container       DI container.
     * @param SettingsService    $settingsService Settings service.
     * @param IndexService       $indexService    Index service.
     * @param LoggerInterface    $logger          Logger.
     */
    public function __construct(
        $appName,
        IRequest $request,
        private readonly IDBConnection $db,
        private readonly ContainerInterface $container,
        private readonly SettingsService $settingsService,
        private readonly IndexService $indexService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(appName: $appName, request: $request);

    }//end __construct()

    /**
     * Get SOLR field configuration and schema information
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with Solr fields comparison
     *
     * @psalm-return JSONResponse<200|422, array{success: bool, message?: string, details?: array{error: string}, comparison?: array{total_differences: int<0, max>, missing_count: int<0, max>, extra_count: int<0, max>, missing: list<array{collection: 'files'|'objects', collectionLabel: 'File Collection'|'Object Collection', config: mixed, name: mixed, type: mixed}>, extra: list<array{collection: 'files'|'objects', collectionLabel: 'File Collection'|'Object Collection', name: mixed}>, object_collection: array{missing: int<0, max>, extra: int<0, max>}, file_collection: array{missing: int<0, max>, extra: int<0, max>}}, object_collection_status?: mixed, file_collection_status?: mixed}, array<never, never>>
     */
    public function getSolrFields(): JSONResponse
    {
        try {
            // Use IndexService to get field status for both collections.
            $solrSchemaService = $this->container->get(\OCA\OpenRegister\Service\IndexService::class);
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Check if SOLR is available first.
            if ($guzzleSolrService->isAvailable() === false) {
                return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'SOLR is not available or not configured',
                        'details' => ['error' => 'SOLR service is not enabled or connection failed'],
                    ],
                    statusCode: 422
                    );
            }

            // Get field status for both collections.
            $objectFieldStatus = $solrSchemaService->getObjectCollectionFieldStatus();
            $fileFieldStatus   = $solrSchemaService->getFileCollectionFieldStatus();

            // Combine missing fields from both collections with collection identifier.
            $missingFields = [];

            foreach ($objectFieldStatus['missing'] as $fieldName => $fieldInfo) {
                $missingFields[] = [
                    'name'            => $fieldName,
                    'type'            => $fieldInfo['type'],
                    'config'          => $fieldInfo,
                    'collection'      => 'objects',
                    'collectionLabel' => 'Object Collection',
                ];
            }

            foreach ($fileFieldStatus['missing'] as $fieldName => $fieldInfo) {
                $missingFields[] = [
                    'name'            => $fieldName,
                    'type'            => $fieldInfo['type'],
                    'config'          => $fieldInfo,
                    'collection'      => 'files',
                    'collectionLabel' => 'File Collection',
                ];
            }

            // Combine extra fields from both collections.
            $extraFields = [];

            foreach ($objectFieldStatus['extra'] as $fieldName) {
                $extraFields[] = [
                    'name'            => $fieldName,
                    'collection'      => 'objects',
                    'collectionLabel' => 'Object Collection',
                ];
            }

            foreach ($fileFieldStatus['extra'] as $fieldName) {
                $extraFields[] = [
                    'name'            => $fieldName,
                    'collection'      => 'files',
                    'collectionLabel' => 'File Collection',
                ];
            }

            // Build comparison result.
            $comparison = [
                'total_differences' => count($missingFields) + count($extraFields),
                'missing_count'     => count($missingFields),
                'extra_count'       => count($extraFields),
                'missing'           => $missingFields,
                'extra'             => $extraFields,
                'object_collection' => [
                    'missing' => count($objectFieldStatus['missing']),
                    'extra'   => count($objectFieldStatus['extra']),
                ],
                'file_collection'   => [
                    'missing' => count($fileFieldStatus['missing']),
                    'extra'   => count($fileFieldStatus['extra']),
                ],
            ];

            return new JSONResponse(
                data: [
                    'success'                  => true,
                    'comparison'               => $comparison,
                    'object_collection_status' => $objectFieldStatus,
                    'file_collection_status'   => $fileFieldStatus,
                ]
                );
        } catch (Exception $e) {
            return new JSONResponse(
                data: [
                    'success' => false,
                    'message' => 'Failed to retrieve SOLR field configuration: '.$e->getMessage(),
                    'details' => ['error' => $e->getMessage()],
                ],
                statusCode: 422
            );
        }//end try

    }//end getSolrFields()

    /**
     * Create missing SOLR fields based on schema analysis
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse JSON response with field creation results
     *
     * @psalm-return JSONResponse<200|422, array{success: bool, message: string, details?: array{error: string}, total_created?: 0|mixed, total_errors?: 0|mixed, results?: array{objects: array{success: false, message: string}|mixed|null, files: array{success: false, message: string}|mixed|null}, execution_time_ms?: float, dry_run?: bool}, array<never, never>>
     */
    public function createMissingSolrFields(): JSONResponse
    {
        try {
            // Get services.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $solrSchemaService = $this->container->get(IndexService::class);

            // Check if SOLR is available first.
            if ($guzzleSolrService->isAvailable() === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'details' => ['error' => 'SOLR service is not enabled or connection failed'],
                        ],
                        statusCode: 422
                        );
            }

            // Get dry run parameter.
            $dryRun = $this->request->getParam('dry_run', false);
            $dryRun = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);

            $startTime    = microtime(true);
            $totalCreated = 0;
            $totalErrors  = 0;
            $results      = [
                'objects' => null,
                'files'   => null,
            ];

            // Create missing fields for OBJECT collection.
            try {
                $objectStatus = $solrSchemaService->getObjectCollectionFieldStatus();
                if (empty($objectStatus['missing']) === false) {
                    $objectResult       = $solrSchemaService->createMissingFields(
                        collectionType: 'objects',
                        missingFields: $objectStatus['missing'],
                        dryRun: $dryRun
                    );
                    $results['objects'] = $objectResult;
                    if (($objectResult['created_count'] ?? null) !== null) {
                        $totalCreated += $objectResult['created_count'];
                    }

                    if (($objectResult['error_count'] ?? null) !== null) {
                        $totalErrors += $objectResult['error_count'];
                    }
                }
            } catch (Exception $e) {
                $results['objects'] = [
                    'success' => false,
                    'message' => 'Failed to create object fields: '.$e->getMessage(),
                ];
                $totalErrors++;
            }//end try

            // Create missing fields for FILE collection.
            try {
                $fileStatus = $solrSchemaService->getFileCollectionFieldStatus();
                if (empty($fileStatus['missing']) === false) {
                    $fileResult       = $solrSchemaService->createMissingFields(
                        collectionType: 'files',
                        missingFields: $fileStatus['missing'],
                        dryRun: $dryRun
                    );
                    $results['files'] = $fileResult;
                    if (($fileResult['created_count'] ?? null) !== null) {
                        $totalCreated += $fileResult['created_count'];
                    }

                    if (($fileResult['error_count'] ?? null) !== null) {
                        $totalErrors += $fileResult['error_count'];
                    }
                }
            } catch (Exception $e) {
                $results['files'] = [
                    'success' => false,
                    'message' => 'Failed to create file fields: '.$e->getMessage(),
                ];
                $totalErrors++;
            }//end try

            $executionTime = round((microtime(true) - $startTime) * 1000, 2);

            return new JSONResponse(
                    data: [
                        'success'           => $totalErrors === 0,
                        'message'           => sprintf(
                    'Field creation completed: %d total fields created across both collections',
                                    $totalCreated
                                    ),
                        'total_created'     => $totalCreated,
                        'total_errors'      => $totalErrors,
                        'results'           => $results,
                        'execution_time_ms' => $executionTime,
                        'dry_run'           => $dryRun,
                    ]
                    );
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to create missing SOLR fields: '.$e->getMessage(),
                        'details' => ['error' => $e->getMessage()],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end createMissingSolrFields()

    /**
     * Fix mismatched SOLR field configurations
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse The field fix results
     *
     * @psalm-return JSONResponse<
     *     200,
     *     array<array-key, mixed>,
     *     array<never, never>
     * >|JSONResponse<
     *     200|422,
     *     array{
     *         success: bool,
     *         message: string,
     *         details?: array{error: mixed|string},
     *         fixed?: array<never, never>,
     *         errors?: array<never, never>
     *     },
     *     array<never, never>
     * >
     */
    public function fixMismatchedSolrFields(): JSONResponse
    {
        try {
            // Get IndexService for field operations.
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Check if SOLR is available first.
            if ($guzzleSolrService->isAvailable() === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'SOLR is not available or not configured',
                            'details' => ['error' => 'SOLR service is not enabled or connection failed'],
                        ],
                        statusCode: 422
                        );
            }

            // Get dry run parameter.
            $dryRun = $this->request->getParam('dry_run', false);
            $dryRun = filter_var($dryRun, FILTER_VALIDATE_BOOLEAN);

            // Get expected fields and current SOLR fields for comparison.
            $schemaMapper   = $this->container->get(\OCA\OpenRegister\Db\SchemaMapper::class);
            $expectedFields = $this->settingsService->getExpectedSchemaFields($schemaMapper, $guzzleSolrService);
            $fieldsInfo     = $guzzleSolrService->getFieldsConfiguration();

            if (($fieldsInfo['success'] === false)) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Failed to get SOLR field configuration',
                            'details' => ['error' => $fieldsInfo['message'] ?? 'Unknown error'],
                        ],
                        statusCode: 422
                        );
            }

            // Compare fields to find mismatched ones.
            $comparison = $this->settingsService->compareFields(
                actualFields: $fieldsInfo['fields'] ?? [],
                expectedFields: $expectedFields
            );

            if (empty($comparison['mismatched']) === true) {
                return new JSONResponse(
                        data: [
                            'success' => true,
                            'message' => 'No mismatched fields found - SOLR schema is properly configured',
                            'fixed'   => [],
                            'errors'  => [],
                        ]
                        );
            }

            // Prepare fields to fix from mismatched fields.
            $fieldsToFix = [];
            foreach ($comparison['mismatched'] as $mismatch) {
                $fieldsToFix[$mismatch['field']] = $mismatch['expected_config'];
            }

            // Debug: Log field count for troubleshooting.
            // Fix the mismatched fields using the dedicated method.
            $result = $guzzleSolrService->fixMismatchedFields($fieldsToFix, $dryRun);

            // The fixMismatchedFields method already returns the correct format.
            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to fix mismatched SOLR fields: '.$e->getMessage(),
                        'details' => ['error' => $e->getMessage()],
                    ],
                    statusCode: 422
                );
        }//end try

    }//end fixMismatchedSolrFields()

    /**
     * Delete a SOLR field
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param  string $fieldName Name of the field to delete
     * @return JSONResponse
     */
    public function deleteSolrField(string $fieldName): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->info(
                    message: '🗑️ Deleting SOLR field via API',
                    context: [
                        'field_name' => $fieldName,
                        'user'       => $this->userId,
                    ]
                    );

            // Validate field name.
            if (empty($fieldName) === true || is_string($fieldName) === false) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => 'Invalid field name provided',
                        ],
                        statusCode: 400
                    );
            }

            // Prevent deletion of critical system fields.
            $protectedFields = ['id', '_version_', '_root_', '_text_'];
            if (in_array($fieldName, $protectedFields) === true) {
                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => "Cannot delete protected system field: {$fieldName}",
                        ],
                        statusCode: 403
                        );
            }

            // Get IndexService from container.
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->deleteField($fieldName);

            if ($result['success'] === true) {
                $logger->info(
                        message: '✅ SOLR field deleted successfully via API',
                        context: [
                            'field_name' => $fieldName,
                            'user'       => $this->userId,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => $result['message'],
                            'field_name' => $fieldName,
                        ]
                        );
            } else {
                $logger->warning(
                        '❌ Failed to delete SOLR field via API',
                        [
                            'field_name' => $fieldName,
                            'error'      => $result['message'],
                            'user'       => $this->userId,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success' => false,
                            'message' => $result['message'],
                            'error'   => $result['error'] ?? null,
                        ],
                        statusCode: 422
                        );
            }//end if
        } catch (Exception $e) {
            $logger = $logger ?? \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error(
                    message: 'Exception deleting SOLR field via API',
                    context: [
                        'field_name' => $fieldName,
                        'error'      => $e->getMessage(),
                        'user'       => $this->userId,
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success' => false,
                        'message' => 'Failed to delete SOLR field: '.$e->getMessage(),
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 500
                );
        }//end try

    }//end deleteSolrField()

    /**
     * List all SOLR collections with statistics
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse List of collections with metadata
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: string,
     *         trace?: string,
     *         collections?: mixed,
     *         count?: int<0, max>,
     *         timestamp?: string
     *     },
     *     array<never, never>
     * >
     */
    public function listSolrCollections(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $collections       = $guzzleSolrService->listCollections();

            return new JSONResponse(
                    data: [
                        'success'     => true,
                        'collections' => $collections,
                        'count'       => count($collections),
                        'timestamp'   => date('c'),
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

    }//end listSolrCollections()

    /**
     * List all SOLR ConfigSets
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @return JSONResponse List of ConfigSets with metadata
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: string,
     *         trace?: string,
     *         configSets?: mixed,
     *         count?: int<0, max>,
     *         timestamp?: string
     *     },
     *     array<never, never>
     * >
     */
    public function listSolrConfigSets(): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $configSets        = $guzzleSolrService->listConfigSets();

            return new JSONResponse(
                    data: [
                        'success'    => true,
                        'configSets' => $configSets,
                        'count'      => count($configSets),
                        'timestamp'  => date('c'),
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

    }//end listSolrConfigSets()

    /**
     * Create a new SOLR ConfigSet by copying an existing one
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string $name          Name for the new ConfigSet
     * @param string $baseConfigSet Base ConfigSet to copy from (default: _default)
     *
     * @return JSONResponse Creation result
     *
     * @psalm-return JSONResponse<
     *     200,
     *     array<array-key, mixed>,
     *     array<never, never>
     * >|JSONResponse<400, array{success: false, error: string}, array<never, never>>
     */
    public function createSolrConfigSet(string $name, string $baseConfigSet='_default'): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->createConfigSet($name, $baseConfigSet);

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 400
                );
        }

    }//end createSolrConfigSet()

    /**
     * Delete a SOLR ConfigSet
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string $name Name of the ConfigSet to delete
     *
     * @return JSONResponse Deletion result
     *
     * @psalm-return JSONResponse<
     *     200,
     *     array<array-key, mixed>,
     *     array<never, never>
     * >|JSONResponse<400, array{success: false, error: string}, array<never, never>>
     */
    public function deleteSolrConfigSet(string $name): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->deleteConfigSet($name);

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                    ],
                    statusCode: 400
                );
        }

    }//end deleteSolrConfigSet()

    /**
     * Create a new SOLR collection from a ConfigSet
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string $collectionName    Name for the new collection
     * @param string $configName        ConfigSet to use
     * @param int    $numShards         Number of shards (default: 1)
     * @param int    $replicationFactor Number of replicas (default: 1)
     * @param int    $maxShardsPerNode  Maximum shards per node (default: 1)
     *
     * @return JSONResponse Creation result
     *
     * @psalm-return JSONResponse<
     *     200,
     *     array<array-key, mixed>,
     *     array<never, never>
     * >|JSONResponse<500, array{success: false, error: string, trace: string}, array<never, never>>
     */
    public function createSolrCollection(
        string $collectionName,
        string $configName,
        int $numShards=1,
        int $replicationFactor=1,
        int $maxShardsPerNode=1
    ): JSONResponse {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->createCollection(
                $collectionName,
                $configName,
                $numShards,
                $replicationFactor,
                $maxShardsPerNode
            );

            return new JSONResponse(data: $result);
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

    }//end createSolrCollection()

    /**
     * Copy a SOLR collection
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string $sourceCollection Source collection name
     * @param string $targetCollection Target collection name
     * @param bool   $copyData         Whether to copy data (default: false)
     *
     * @return JSONResponse Copy operation result
     *
     * @psalm-return JSONResponse<
     *     200,
     *     array<array-key, mixed>,
     *     array<never, never>
     * >|JSONResponse<500, array{success: false, error: string, trace: string}, array<never, never>>
     */
    public function copySolrCollection(string $sourceCollection, string $targetCollection, bool $copyData=false): JSONResponse
    {
        try {
            $guzzleSolrService = $this->container->get(IndexService::class);
            $result            = $guzzleSolrService->copyCollection(
                sourceCollection: $sourceCollection,
                targetCollection: $targetCollection,
                copyData: $copyData
            );

            return new JSONResponse(data: $result);
        } catch (Exception $e) {
            return new JSONResponse(
                    data: [
                        'success' => false,
                        'error'   => $e->getMessage(),
                        'trace'   => $e->getTraceAsString(),
                    ],
                    statusCode: 500
                );
        }

    }//end copySolrCollection()

    /**
     * Delete a specific SOLR collection by name
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @param string $name The name of the collection to delete
     *
     * @return JSONResponse The deletion result
     */
    public function deleteSpecificSolrCollection(string $name): JSONResponse
    {
        try {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);

            $logger->warning(
                    message: '🚨 SOLR collection deletion requested',
                    context: [
                        'timestamp'  => date('c'),
                        'user_id'    => $this->userId ?? 'unknown',
                        'collection' => $name,
                        'request_id' => $this->request->getId() ?? 'unknown',
                    ]
                    );

            // Get IndexService.
            $guzzleSolrService = $this->container->get(IndexService::class);

            // Delete the specific collection.
            $result = $guzzleSolrService->deleteCollection($name);

            if ($result['success'] === true) {
                $logger->info(
                        message: '✅ SOLR collection deleted successfully',
                        context: [
                            'collection' => $name,
                            'user_id'    => $this->userId ?? 'unknown',
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success'    => true,
                            'message'    => 'Collection deleted successfully',
                            'collection' => $name,
                        ],
                        statusCode: 200
                        );
            } else {
                $logger->error(
                        '❌ SOLR collection deletion failed',
                        [
                            'error'      => $result['message'],
                            'error_code' => $result['error_code'] ?? 'unknown',
                            'collection' => $name,
                        ]
                        );

                return new JSONResponse(
                        data: [
                            'success'    => false,
                            'message'    => $result['message'],
                            'error_code' => $result['error_code'] ?? 'unknown',
                            'collection' => $name,
                            'solr_error' => $result['solr_error'] ?? null,
                        ],
                        statusCode: 422
                        );
            }//end if
        } catch (Exception $e) {
            $logger = \OC::$server->get(\Psr\Log\LoggerInterface::class);
            $logger->error(
                    message: 'Exception during SOLR collection deletion',
                    context: [
                        'error'      => $e->getMessage(),
                        'collection' => $name,
                        'trace'      => $e->getTraceAsString(),
                    ]
                    );

            return new JSONResponse(
                    data: [
                        'success'    => false,
                        'message'    => 'Collection deletion failed: '.$e->getMessage(),
                        'error_code' => 'EXCEPTION',
                        'collection' => $name,
                    ],
                    statusCode: 422
                    );
        }//end try

    }//end deleteSpecificSolrCollection()

    /**
     * Update SOLR collection assignments (Object Collection and File Collection)
     *
     * @NoAdminRequired
     *
     * @NoCSRFRequired
     *
     * @param string|null $objectCollection Collection name for objects
     * @param string|null $fileCollection   Collection name for files
     *
     * @return JSONResponse Update result
     *
     * @psalm-return JSONResponse<
     *     200|500,
     *     array{
     *         success: bool,
     *         error?: string,
     *         trace?: string,
     *         message?: 'Collection assignments updated successfully',
     *         objectCollection?: mixed|null,
     *         fileCollection?: mixed|null,
     *         timestamp?: string
     *     },
     *     array<never, never>
     * >
     */
    public function updateSolrCollectionAssignments(?string $objectCollection=null, ?string $fileCollection=null): JSONResponse
    {
        try {
            // Get current SOLR settings.
            $solrSettings = $this->settingsService->getSolrSettingsOnly();

            // Update collection assignments.
            if ($objectCollection !== null) {
                $solrSettings['objectCollection'] = $objectCollection;
            }

            if ($fileCollection !== null) {
                $solrSettings['fileCollection'] = $fileCollection;
            }

            // Save updated settings.
            $this->settingsService->updateSolrSettingsOnly($solrSettings);

            return new JSONResponse(
                    data: [
                        'success'          => true,
                        'message'          => 'Collection assignments updated successfully',
                        'objectCollection' => $solrSettings['objectCollection'] ?? null,
                        'fileCollection'   => $solrSettings['fileCollection'] ?? null,
                        'timestamp'        => date('c'),
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

    }//end updateSolrCollectionAssignments()
}//end class
