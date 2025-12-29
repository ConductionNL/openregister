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
     * @param TransformationHandler $transformationHandler Handler for object transformation.
     * @param ObjectEntityMapper    $objectEntityMapper    Mapper for database operations.
     * @param LoggerInterface       $logger                Logger for logging operations.
     */
    public function __construct(
        private readonly TransformationHandler $transformationHandler,
        private readonly ObjectEntityMapper $objectEntityMapper,
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
     * @param array $objects       Objects to process.
     * @param array $schemaCache   Schema cache for metadata field resolution.
     * @param bool  $_rbac         RBAC flag (reserved for future use).
     * @param bool  $_multitenancy Multitenancy flag (reserved for future use).
     * @param bool  $_validation   Validation flag (reserved for future use).
     * @param bool  $_events       Events flag (reserved for future use).
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @psalm-param   array<int|string, Schema> $schemaCache
     * @phpstan-param array<int, array<string, mixed>> $objects
     * @phpstan-param array<int|string, Schema> $schemaCache
     *
     * @return array Array containing saved, updated, invalid objects and statistics.
     *
     * @psalm-return   array{saved: list<array<string, mixed>>, updated: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, invalid: list<array<string, mixed>>, errors: list<array<string, mixed>>, statistics: array{saved: int, updated: int, unchanged: int, invalid: int, errors: int, processingTimeMs: float}}
     * @phpstan-return array{saved: list<array<string, mixed>>, updated: list<array<string, mixed>>, unchanged: list<array<string, mixed>>, invalid: list<array<string, mixed>>, errors: list<array<string, mixed>>, statistics: array<string, int|float>}
     */
    public function processObjectsChunk(
        array $objects,
        array $schemaCache,
        bool $_rbac,
        bool $_multitenancy,
        bool $_validation,
        bool $_events
    ): array {
        $startTime = microtime(true);

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
        $transformationResult = $this->transformationHandler->transformObjectsToDatabaseFormatInPlace(
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
        $bulkResult = $this->objectEntityMapper->ultraFastBulkSave(
            insertObjects: $transformedObjects,
            updateObjects: []
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
                && (($firstItem['created'] ?? null) !== null)
                && (($firstItem['updated'] ?? null) !== null)
            ) {
                // NEW APPROACH: Complete objects with database-computed classification returned.
                $this->logger->info("[SaveObjects] Processing complete objects with database-computed classification");

                foreach ($bulkResult as $completeObject) {
                    $savedObjectIds[] = $completeObject['uuid'];

                    // DATABASE-COMPUTED CLASSIFICATION: Use the object_status calculated by database.
                    $objectStatus = $completeObject['object_status'] ?? 'unknown';

                    switch ($objectStatus) {
                        case 'created':
                            // 🆕 CREATED: Object was created during this operation (database-computed).
                            $createdObjects[] = $completeObject;
                            $result['statistics']['saved']++;
                            break;

                        case 'updated':
                            // 📝 UPDATED: Existing object was modified during this operation (database-computed).
                            $updatedObjects[] = $completeObject;
                            $result['statistics']['updated']++;
                            break;

                        case 'unchanged':
                            // ⏸️ UNCHANGED: Existing object was not modified (database-computed).
                            $unchangedObjects[] = $completeObject;
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
                            $unchangedObjects[] = $completeObject;
                            $result['statistics']['unchanged']++;
                    }//end switch

                    // Convert to ObjectEntity for consistent response format.
                    $objEntity = new ObjectEntity();
                    $objEntity->hydrate($completeObject);
                    $reconstructedObjects[] = $objEntity;
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

        // STEP 5: ENHANCED OBJECT RESPONSE - Use pre-classified objects or reconstruct.
        if (empty($reconstructedObjects) === false) {
            // NEW APPROACH: Use already reconstructed objects from timestamp classification.
            foreach ($createdObjects as $createdObj) {
                $result['saved'][] = $createdObj;
            }

            foreach ($updatedObjects as $updatedObj) {
                $result['updated'][] = $updatedObj;
            }

            foreach ($unchangedObjects as $unchangedObj) {
                $result['unchanged'][] = $unchangedObj;
            }

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
