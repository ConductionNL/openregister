<?php

/**
 * RelationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Adbar\Dot;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\PerformanceHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Handles relationship operations for ObjectService.
 *
 * This handler is responsible for:
 * - Extracting relationship IDs from objects
 * - Bulk loading relationships with performance optimizations
 * - Applying inversedBy filters
 * - Managing relationship batching and circuit breakers
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class RelationHandler
{


    /**
     * Constructor for RelationHandler.
     *
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities.
     * @param SchemaMapper       $schemaMapper       Mapper for schemas.
     * @param PerformanceHandler $performanceHandler Handler for performance operations.
     * @param LoggerInterface    $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly PerformanceHandler $performanceHandler,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Apply inversedBy filter to find objects by their inverse relations.
     *
     * @param array    &$filters        The filters array (passed by reference).
     * @param callable $findAllCallback Callback to findAll method.
     *
     * @return array|null Array of IDs or null if no matches.
     *
     * @psalm-param    array<string, mixed> &$filters
     * @phpstan-param  array<string, mixed> &$filters
     * @psalm-param    callable(array): array $findAllCallback
     * @phpstan-param  callable(array): array $findAllCallback
     * @psalm-return   array<int, string>|null
     * @phpstan-return array<int, string>|null
     */
    public function applyInversedByFilter(array &$filters, callable $findAllCallback): array|null
    {
        if ($filters['schema'] === false) {
            return [];
        }

        $schema = $this->schemaMapper->find($filters['schema']);

        $filterKeysWithSub = array_filter(
            array_keys($filters),
            function ($filter) {
                if (str_contains($filter, '_') === true) {
                    return true;
                }

                return false;
            }
        );

        $filtersWithSub = array_intersect_key(array: $filters, array2: array_flip(array: $filterKeysWithSub));

        if (empty($filtersWithSub) === true) {
            return [];
        }

        $filterDot = new Dot(items: $filtersWithSub, parse: true, delimiter: '_');

        $ids = [];

        $iterator = 0;
        foreach ($filterDot as $key => $value) {
            if (isset($schema->getProperties()[$key]['inversedBy']) === false) {
                continue;
            }

            $iterator++;
            $schemaProperties = $schema->getProperties();
            if (is_array($schemaProperties) === false || isset($schemaProperties[$key]) === false) {
                continue;
            }

            $property = $schemaProperties[$key];

            $value = (new Dot($value))->flatten(delimiter: '_');

            // @TODO fix schema finder.
            $value['schema'] = $property['$ref'] ?? null;

            $objects  = $findAllCallback(['filters' => $value]);
            $foundIds = array_map(
                function (ObjectEntity $object) use ($property, $key) {
                    $serialized = $object->jsonSerialize();
                    $idRaw      = is_array($property) && is_array($serialized) && isset($property['inversedBy']) ? $serialized[$property['inversedBy']] : null;

                    if (Uuid::isValid($idRaw) === true) {
                        return $idRaw;
                    } else if (filter_var($idRaw, FILTER_VALIDATE_URL) !== false) {
                        $path = explode(separator: '/', string: parse_url($idRaw, PHP_URL_PATH));

                        return end($path);
                    }
                },
                $objects
            );

            if ($ids === []) {
                $ids = $foundIds;
            } else {
                $ids = array_intersect(array1: $ids, array2: $foundIds);
            }

            foreach (array_keys($value) as $k) {
                unset($filters[$key.'_'.$k]);
            }
        }//end foreach

        if ($iterator > 0 && $ids === []) {
            return null;
        }

        return $ids;

    }//end applyInversedByFilter()


    /**
     * Extract related data from results (delegates to PerformanceHandler).
     *
     * @param array $results             The search results.
     * @param bool  $includeRelated      Whether to include related objects.
     * @param bool  $includeRelatedNames Whether to include related names.
     *
     * @return array The processed results.
     *
     * @psalm-param    array<string, mixed> $results
     * @phpstan-param  array<string, mixed> $results
     * @psalm-return   array<string, mixed>
     * @phpstan-return array<string, mixed>
     */
    public function extractRelatedData(array $results, bool $includeRelated, bool $includeRelatedNames): array
    {
        return $this->performanceHandler->extractRelatedData(
            results: $results,
            includeRelated: $includeRelated,
            includeRelatedNames: $includeRelatedNames
        );

    }//end extractRelatedData()


    /**
     * Extract all relationship IDs from objects with circuit breaker.
     *
     * @param array $objects Objects to extract relationships from.
     * @param array $_extend Properties to extend.
     *
     * @return array Array of unique relationship IDs.
     *
     * @psalm-param    array<int, ObjectEntity> $objects
     * @phpstan-param  array<int, ObjectEntity> $objects
     * @psalm-param    array<int, string> $_extend
     * @phpstan-param  array<int, string> $_extend
     * @psalm-return   array<int, string>
     * @phpstan-return array<int, string>
     */
    public function extractAllRelationshipIds(array $objects, array $_extend): array
    {
        $allIds = [];
        $maxIds = 200;
        // **CIRCUIT BREAKER**: Hard limit to prevent massive relationship loading.
        $extractedCount = 0;

        foreach ($objects as $objectIndex => $object) {
            // **PERFORMANCE BYPASS**: Stop early if we've extracted enough.
            if ($extractedCount >= $maxIds) {
                $this->logger->info(
                    message: '🛑 RELATIONSHIP EXTRACTION: Stopped early to prevent timeout',
                    context: [
                        'extractedIds'     => $extractedCount,
                        'maxIds'           => $maxIds,
                        'processedObjects' => $objectIndex,
                        'totalObjects'     => count($objects),
                        'reason'           => 'performance_protection',
                    ]
                );
                break;
            }

            $objectData = $object->getObject();

            foreach ($_extend as $extendProperty) {
                if (isset($objectData[$extendProperty]) === true) {
                    $value = $objectData[$extendProperty];

                    if (is_array($value) === true) {
                        // **PERFORMANCE LIMIT**: Limit array relationships per object.
                        $limitedArray = array_slice(array: $value, offset: 0, length: 10);
                        // Max 10 relationships per array.
                        foreach ($limitedArray as $id) {
                            if (empty($id) === false && is_string($id) === true) {
                                $allIds[] = $id;
                                $extractedCount++;

                                // **CIRCUIT BREAKER**: Stop if we hit the limit.
                                if ($extractedCount >= $maxIds) {
                                    // Break out of all loops.
                                }
                            }
                        }

                        // Log if we had to limit the array.
                        if (count($value) > 10) {
                            $this->logger->debug(
                                message: '🔪 PERFORMANCE: Limited relationship array',
                                context: [
                                    'property'      => $extendProperty,
                                    'originalCount' => count($value),
                                    'limitedTo'     => count($limitedArray),
                                    'reason'        => 'prevent_timeout',
                                ]
                            );
                        }
                    } else if (is_string($value) === true && empty($value) === false) {
                        // Handle single relationship ID.
                        $allIds[] = $value;
                        $extractedCount++;

                        // **CIRCUIT BREAKER**: Stop if we hit the limit.
                        if ($extractedCount >= $maxIds) {
                            // Break out of both loops.
                        }
                    }//end if
                }//end if
            }//end foreach
        }//end foreach

        // Remove duplicates and return unique IDs.
        $uniqueIds = array_unique($allIds);

        $this->logger->info(
            message: '🔍 RELATIONSHIP EXTRACTION: Completed with limits',
            context: [
                'totalExtracted' => count($allIds),
                'uniqueIds'      => count($uniqueIds),
                'maxAllowed'     => $maxIds,
                'efficiency'     => 'limited_for_performance',
            ]
        );

        return $uniqueIds;

    }//end extractAllRelationshipIds()


    /**
     * Bulk load relationships in batches to prevent timeouts.
     *
     * @param array $relationshipIds Array of all relationship IDs to load.
     *
     * @return array Array of objects indexed by ID/UUID for instant lookup.
     *
     * @psalm-param    array<string> $relationshipIds
     * @phpstan-param  array<string> $relationshipIds
     * @psalm-return   array<string, ObjectEntity>
     * @phpstan-return array<string, ObjectEntity>
     */
    public function bulkLoadRelationshipsBatched(array $relationshipIds): array
    {
        if (count($relationshipIds) === 0) {
            return [];
        }

        // **HARD LIMIT**: Cap at 200 relationships total for safety.
        if (count($relationshipIds) > 200) {
            $this->logger->warning(
                message: '⚠️ RELATIONSHIP LOADING: Capping at 200 relationships',
                context: [
                    'requested' => count($relationshipIds),
                    'capped'    => 200,
                    'reason'    => 'prevent_timeout',
                ]
            );
            $relationshipIds = array_slice(array: $relationshipIds, offset: 0, length: 200);
        }

        $startTime = microtime(true);
        $batchSize = 50;
        // Load 50 relationships at a time.
        $batches       = array_chunk(array: $relationshipIds, length: $batchSize);
        $loadedObjects = [];

        $this->logger->info(
            message: '🔄 BULK RELATIONSHIP LOADING: Starting batched load',
            context: [
                'totalRelationships' => count($relationshipIds),
                'batchSize'          => $batchSize,
                'totalBatches'       => count($batches),
                'strategy'           => 'batched_sequential',
            ]
        );

        foreach ($batches as $batchIndex => $batch) {
            $batchStart = microtime(true);

            try {
                $chunkObjects = $this->loadRelationshipChunkOptimized($batch);

                foreach ($chunkObjects as $obj) {
                    // Index by both UUID and ID for flexible lookup.
                    $loadedObjects[$obj->getUuid()] = $obj;
                    $loadedObjects[$obj->getId()]   = $obj;
                }

                $batchTime = (microtime(true) - $batchStart) * 1000;

                $this->logger->debug(
                    message: '✅ Batch loaded',
                    context: [
                        'batch'         => ($batchIndex + 1),
                        'idsInBatch'    => count($batch),
                        'objectsLoaded' => count($chunkObjects),
                        'batchTime'     => round($batchTime, 2).'ms',
                    ]
                );
            } catch (\Exception $e) {
                $this->logger->error(
                    message: '❌ BATCH LOADING FAILED',
                    context: [
                        'batch'      => ($batchIndex + 1),
                        'error'      => $e->getMessage(),
                        'idsInBatch' => count($batch),
                    ]
                );
                // Continue with next batch instead of failing completely.
            }//end try
        }//end foreach

        $totalTime = (microtime(true) - $startTime) * 1000;

        $this->logger->info(
            message: '✅ BULK RELATIONSHIP LOADING: Completed',
            context: [
                'totalRequested' => count($relationshipIds),
                'totalLoaded'    => count($loadedObjects),
                'totalTime'      => round($totalTime, 2).'ms',
                'avgPerBatch'    => round($totalTime / count($batches), 2).'ms',
            ]
        );

        return $loadedObjects;

    }//end bulkLoadRelationshipsBatched()


    /**
     * Load a chunk of relationships optimized.
     *
     * @param array $relationshipIds Array of relationship IDs to load.
     *
     * @return array Array of loaded ObjectEntity objects.
     *
     * @psalm-param    array<string> $relationshipIds
     * @phpstan-param  array<string> $relationshipIds
     * @psalm-return   array<int, ObjectEntity>
     * @phpstan-return array<int, ObjectEntity>
     */
    public function loadRelationshipChunkOptimized(array $relationshipIds): array
    {
        if (empty($relationshipIds) === true) {
            return [];
        }

        try {
            // Use the mapper's optimized bulk fetch.
            return $this->objectEntityMapper->findAll(ids: $relationshipIds, includeDeleted: false);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to load relationship chunk',
                context: [
                    'error'    => $e->getMessage(),
                    'idsCount' => count($relationshipIds),
                ]
            );
            return [];
        }

    }//end loadRelationshipChunkOptimized()


}//end class
