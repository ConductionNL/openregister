<?php

/**
 * ChunkProcessingHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object\SaveObjects;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\UnifiedObjectMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Schema;
use Psr\Log\LoggerInterface;

/**
 * Handles processing of object chunks for bulk operations.
 *
 * This handler is responsible for:
 * - Transforming objects to database format
 * - Executing ultra-fast bulk save operations
 * - Classifying objects (created/updated/unchanged) using database-computed status
 * - Reconstructing saved objects
 * - Aggregating results and statistics
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class ChunkProcessingHandler
{
    /**
     * Constructor for ChunkProcessingHandler.
     *
     * @param TransformationHandler $transformHandler    Handler for object transformation.
     * @param ObjectEntityMapper    $objectEntityMapper  Mapper for database operations (blob storage).
     * @param UnifiedObjectMapper   $unifiedObjectMapper Mapper for routing to magic/blob storage.
     * @param RegisterMapper        $registerMapper      Mapper for register operations.
     * @param SchemaMapper          $schemaMapper        Mapper for schema operations.
     * @param LoggerInterface       $logger              Logger for logging operations.
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) Many dependencies required for chunk processing
     */
    public function __construct(
        private readonly TransformationHandler $transformHandler,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly UnifiedObjectMapper $unifiedObjectMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Process a chunk of objects for bulk save operations.
     *
     * This method orchestrates the complete chunk processing pipeline:
     * 1. Transform objects to database format
     * 2. Handle invalid objects
     * 3. Execute ultra-fast bulk database save
     * 4. Classify objects using database-computed status
     * 5. Reconstruct objects for response
     * 6. Return aggregated results and statistics
     *
     * @param array                                         $objects       Objects to process.
     * @param array                                         $schemaCache   Schema cache for metadata.
     * @param bool                                          $_rbac         RBAC flag (reserved).
     * @param bool                                          $_multitenancy Multitenancy flag (reserved).
     * @param bool                                          $_validation   Validation flag (reserved).
     * @param bool                                          $_events       Events flag (reserved).
     * @param \OCA\OpenRegister\Db\Register|string|int|null $register      The register.
     * @param \OCA\OpenRegister\Db\Schema|string|int|null   $schema        The schema.
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @psalm-param   array<int|string, Schema> $schemaCache
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @phpstan-param array<int|string, Schema> $schemaCache
     *
     * @return array Array containing saved, updated, invalid objects and statistics.
     *
     * @psalm-return   array{saved: list<array<string, mixed>>,
     *     updated: list<array<string, mixed>>,
     *     unchanged: list<array<string, mixed>>,
     *     invalid: list<array<string, mixed>>,
     *     errors: list<array<string, mixed>>,
     *     statistics: array{saved: int, updated: int, unchanged: int,
     *     invalid: int, errors: int, processingTimeMs: float}}
     * @phpstan-return array{saved: list<array<string, mixed>>,
     *     updated: list<array<string, mixed>>,
     *     unchanged: list<array<string, mixed>>,
     *     invalid: list<array<string, mixed>>,
     *     errors: list<array<string, mixed>>,
     *     statistics: array<string, int|float>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex bulk processing with multiple classification paths
     * @SuppressWarnings(PHPMD.NPathComplexity)       Many paths due to database-computed classification handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complete chunk processing pipeline in single method
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Boolean flags for feature toggles in bulk operations
     * @SuppressWarnings(PHPMD.ElseExpression)        Multiple conditional paths for object classification and reconstruction
     */
    public function processObjectsChunk(
        array $objects,
        array $schemaCache,
        bool $_rbac,
        bool $_multitenancy,
        bool $_validation,
        bool $_events,
        \OCA\OpenRegister\Db\Register|string|int|null $register=null,
        \OCA\OpenRegister\Db\Schema|string|int|null $schema=null
    ): array {
        $startTime = microtime(true);

        // Resolve register/schema if they are IDs.
        if (is_int($register) === true || is_string($register) === true) {
            try {
                $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
                $register       = $registerMapper->find((int) $register, _multitenancy: false);
            } catch (\Exception $e) {
                $this->logger->warning('[ChunkProcessingHandler] Failed to resolve register', ['id' => $register]);
                $register = null;
            }
        }

        if (is_int($schema) === true || is_string($schema) === true) {
            try {
                $schemaMapper = \OC::$server->get(\OCA\OpenRegister\Db\SchemaMapper::class);
                $schema       = $schemaMapper->find((int) $schema, _multitenancy: false);
            } catch (\Exception $e) {
                $this->logger->warning('[ChunkProcessingHandler] Failed to resolve schema', ['id' => $schema]);
                $schema = null;
            }
        }

        $result = [
            'saved'      => [],
            'updated'    => [],
            'unchanged'  => [],
            'invalid'    => [],
            'errors'     => [],
            'statistics' => [
                'saved'     => 0,
                'updated'   => 0,
                'unchanged' => 0,
                'invalid'   => 0,
                'errors'    => 0,
            ],
        ];

        // STEP 1: Transform objects for database format with metadata hydration.
        $transformationResult = $this->transformHandler->transformObjectsToDatabaseFormatInPlace(
            objects: $objects,
            schemaCache: $schemaCache
        );
        $transformedObjects   = $transformationResult['valid'];

        // PERFORMANCE OPTIMIZATION: Batch error processing.
        if (empty($transformationResult['invalid']) === false) {
            $invalidCount      = count($transformationResult['invalid']);
            $result['invalid'] = array_merge($result['invalid'], $transformationResult['invalid']);
            $result['statistics']['invalid'] += $invalidCount;

            // Initialize errors counter if needed.
            if (array_key_exists('errors', $result['statistics']) === false) {
                $result['statistics']['errors'] = 0;
            }

            $result['statistics']['errors'] += $invalidCount;
        }

        if (empty($transformedObjects) === true) {
            $endTime = microtime(true);
            $result['statistics']['processingTimeMs'] = round(($endTime - $startTime) * 1000, 2);
            return $result;
        }

        // REVOLUTIONARY APPROACH: Skip database lookup entirely and use single-call processing.
        $this->logger->info(
            "[SaveObjects] Using single-call bulk processing (no pre-lookup needed)",
            [
                'objects_to_process' => count($transformedObjects),
                'approach'           => 'INSERT...ON DUPLICATE KEY UPDATE with database-computed classification',
            ]
        );

        // STEP 2: DIRECT BULK PROCESSING - No categorization needed upfront.
        $unchangedObjects = [];

        // Update statistics for unchanged objects (skipped because content was unchanged).
        $result['statistics']['unchanged'] = count($unchangedObjects);
        $result['unchanged'] = array_map(
            function ($obj) {
                if (is_array($obj) === true) {
                    return $obj;
                } else {
                    return $obj->jsonSerialize();
                }
            },
            $unchangedObjects
        );

        // STEP 3: ULTRA-FAST BULK DATABASE OPERATIONS.
        // Register & schema are now passed as parameters (already resolved in function entry).
        // Route through UnifiedObjectMapper for automatic magic/blob routing.
        $bulkResult = $this->unifiedObjectMapper->ultraFastBulkSave(
            insertObjects: $transformedObjects,
            updateObjects: [],
            register: $register,
            schema: $schema
        );

        // STEP 4: ENHANCED PROCESSING - Handle complete objects with timestamp-based classification.
        $savedObjectIds       = [];
        $createdObjects       = [];
        $updatedObjects       = [];
        $unchangedObjects     = [];
        $reconstructedObjects = [];

        if (is_array($bulkResult) === true) {
            // Check if we got complete objects (new approach) or just UUIDs (fallback).
            $firstItem = reset($bulkResult);

            if (is_array($firstItem) === true
                && isset($firstItem['object_status'])
            ) {
                // NEW APPROACH: Complete objects with database-computed classification returned.
                $this->logger->info("[SaveObjects] Processing complete objects with database-computed classification");

                foreach ($bulkResult as $completeObject) {
                    $savedObjectIds[] = $completeObject['_uuid'];

                    // Convert to ObjectEntity for consistent response format.
                    $objEntity = new ObjectEntity();
                    $objEntity->hydrate($completeObject);
                    $reconstructedObjects[] = $objEntity;

                    // DATABASE-COMPUTED CLASSIFICATION: Use the object_status calculated by database.
                    $objectStatus = $completeObject['object_status'] ?? 'unknown';

                    switch ($objectStatus) {
                        case 'created':
                            // 🆕 CREATED: Object was created during this operation (database-computed).
                            $createdObjects[] = $completeObject;
                            $result['saved'][] = $objEntity->jsonSerialize();
                            $result['statistics']['saved']++;
                            break;

                        case 'updated':
                            // 📝 UPDATED: Existing object was modified during this operation (database-computed).
                            $updatedObjects[] = $completeObject;
                            $result['updated'][] = $objEntity->jsonSerialize();
                            $result['statistics']['updated']++;
                            break;

                        case 'unchanged':
                            // ⏸️ UNCHANGED: Existing object was not modified (database-computed).
                            $unchangedObjects[] = $completeObject;
                            $result['unchanged'][] = $objEntity->jsonSerialize();
                            $result['statistics']['unchanged']++;
                            break;

                        default:
                            // Fallback for unexpected status.
                            $this->logger->warning(
                                "Unexpected object status: {$objectStatus}",
                                [
                                    'uuid'          => $completeObject['uuid'],
                                    'object_status' => $objectStatus,
                                ]
                            );
                            $unchangedObjects[] = $completeObject->jsonSerialize();
                            $result['unchanged'][] = $objEntity;
                            $result['statistics']['unchanged']++;
                    }//end switch
                }//end foreach

                $this->logger->info(
                    "[SaveObjects] Database-computed classification completed",
                    [
                        'total_processed'       => count($bulkResult),
                        'created_objects'       => count($createdObjects),
                        'updated_objects'       => count($updatedObjects),
                        'unchanged_objects'     => count($unchangedObjects),
                        'classification_method' => 'database_computed_sql',
                    ]
                );
            } else {
                // FALLBACK: UUID array returned (legacy behavior).
                $this->logger->info("[SaveObjects] Processing UUID array (legacy mode)");
                $savedObjectIds = $bulkResult;

                // Fallback counting (less precise).
                foreach ($transformedObjects ?? [] as $objData) {
                    if (in_array($objData['uuid'], $bulkResult, true) === true) {
                        $result['statistics']['saved']++;
                    }
                }
            }//end if
        } else {
            // Fallback for unexpected return format.
            $this->logger->warning("[SaveObjects] Unexpected bulk result format, using fallback");
            foreach ($transformedObjects ?? [] as $objData) {
                $savedObjectIds[] = $objData['uuid'];
                $result['statistics']['saved']++;
            }
        }//end if

        // STEP 5: ENHANCED OBJECT RESPONSE - Already populated in STEP 4.
        // The result arrays (saved, updated, unchanged) were populated during the classification loop above.
        if (empty($reconstructedObjects) === false) {
            $this->logger->info(
                "[SaveObjects] Using database-computed pre-classified objects for response",
                [
                    'saved_objects'     => count($result['saved']),
                    'updated_objects'   => count($result['updated']),
                    'unchanged_objects' => count($result['unchanged']),
                ]
            );
        } else {
            // FALLBACK: Use traditional object reconstruction (placeholder).
            // This would need the reconstructSavedObjects method implementation.
            $this->logger->info("[SaveObjects] Using fallback object reconstruction");

            // Fallback classification (less precise).
            foreach ($transformedObjects ?? [] as $objData) {
                if (in_array($objData['uuid'], $savedObjectIds, true) === true) {
                    $result['saved'][] = $objData;
                }
            }
        }//end if

        // STEP 6: Calculate processing time.
        $endTime        = microtime(true);
        $processingTime = round(($endTime - $startTime) * 1000, 2);

        // Add processing time to the result for transparency and performance monitoring.
        $result['statistics']['processingTimeMs'] = $processingTime;

        return $result;
    }//end processObjectsChunk()
}//end class
