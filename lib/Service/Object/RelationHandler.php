<?php

/**
 * RelationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
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
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex relationship resolution logic
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
     * @param array    $filters         The filters array (passed by reference).
     * @param callable $findAllCallback Callback to findAll method.
     *
     * @psalm-param array<string, mixed> &$filters
     * @psalm-param callable(array): array $findAllCallback
     *
     * @phpstan-param array<string, mixed> &$filters
     * @phpstan-param callable(array): array $findAllCallback
     *
     * @return ((mixed|null|string)[]|mixed|null|string)[]|null
     *
     * @psalm-return   array<array{name: mixed|null|string,...}|mixed|null|string>|null
     * @phpstan-return array<int, string>|null
     *
     * @SuppressWarnings(PHPMD.StaticAccess)          Uuid::isValid is standard Symfony UID pattern
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex inverse relation filter logic with multiple conditions
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple conditional paths for schema property handling
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Inverse filter resolution requires comprehensive handling
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
                    $idRaw      = null;
                    if (is_array($property) === true
                        && is_array($serialized) === true
                        && isset($property['inversedBy']) === true
                    ) {
                        $idRaw = $serialized[$property['inversedBy']];
                    }

                    if (Uuid::isValid($idRaw) === true) {
                        return $idRaw;
                    }

                    if (filter_var($idRaw, FILTER_VALIDATE_URL) !== false) {
                        $path = explode(separator: '/', string: parse_url($idRaw, PHP_URL_PATH));

                        return end($path);
                    }

                    return null;
                },
                $objects
            );

            if ($ids === []) {
                $ids = $foundIds;
            }

            if ($ids !== []) {
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
     * @return string[][]
     *
     * @psalm-param array<string, mixed> $results
     *
     * @phpstan-param array<string, mixed> $results
     *
     * @psalm-return   array{related?: list<string>, relatedNames?: array<string, string>}
     * @phpstan-return array<string, mixed>
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flags control optional extraction features
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
     * @psalm-param array<int, ObjectEntity> $objects
     * @psalm-param array<int, string> $_extend
     *
     * @phpstan-param array<int, ObjectEntity> $objects
     * @phpstan-param array<int, string> $_extend
     *
     * @return string[]
     *
     * @psalm-return   array<int<0, max>, string>
     * @phpstan-return array<int, string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Nested loops with circuit breaker logic for performance protection
     * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple execution paths for relationship extraction limits
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Circuit breaker logic requires comprehensive safeguards
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
     * @return array<int|string, ObjectEntity>
     *
     * @psalm-param array<string> $relationshipIds
     *
     * @phpstan-param array<string> $relationshipIds
     *
     * @psalm-return   array<int|string, ObjectEntity>
     * @phpstan-return array<string, ObjectEntity>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Batch processing with error handling requires multiple conditions
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
     * @return ObjectEntity[]
     *
     * @psalm-param array<string> $relationshipIds
     *
     * @phpstan-param array<string> $relationshipIds
     *
     * @psalm-return   list<ObjectEntity>
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

    /**
     * Get object contracts.
     *
     * This method retrieves contracts associated with an object.
     * Contracts are typically stored as relations in the object's data.
     *
     * @param string $objectId Object ID or UUID.
     * @param array  $filters  Optional filters for pagination.
     *
     * @return (array|int|mixed)[] Contracts data with pagination info.
     *
     * @psalm-return array{results: array|mixed, total: int<0, max>, limit: 30|mixed, offset: 0|mixed}
     */
    public function getContracts(string $objectId, array $filters=[]): array
    {
        try {
            // Find the object.
            $object     = $this->objectEntityMapper->find(identifier: $objectId);
            $objectData = $object->getObject();

            // Extract contracts from object data (typically stored in 'contracts' property).
            $contracts = $objectData['contracts'] ?? [];

            // Apply pagination.
            $limit  = $filters['_limit'] ?? 30;
            $offset = $filters['_offset'] ?? 0;
            $total  = 0;
            if (is_array($contracts) === true) {
                $total     = count($contracts);
                $contracts = array_slice($contracts, $offset, $limit);
            }

            return [
                'results' => $contracts,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to get contracts',
                context: [
                    'error'    => $e->getMessage(),
                    'objectId' => $objectId,
                ]
            );
            return [
                'results' => [],
                'total'   => 0,
                'limit'   => $filters['_limit'] ?? 30,
                'offset'  => $filters['_offset'] ?? 0,
            ];
        }//end try
    }//end getContracts()

    /**
     * Get objects that this object uses (outgoing relations).
     *
     * This method finds all objects that are referenced by the given object.
     *
     * @param string   $objectId      Object ID or UUID.
     * @param array    $query         Search query parameters.
     * @param bool     $_rbac         Apply RBAC filters.
     * @param bool     $_multitenancy Apply multitenancy filters.
     * @param int|null $_registerId   Register ID for magic table lookup.
     * @param int|null $_schemaId     Schema ID for magic table lookup.
     *
     * @return array{results: ObjectEntity[], total: int, limit: int|mixed, offset: int|mixed}
     *
     * @psalm-return array{results: list<ObjectEntity>, total: int<0, max>, limit: 30|mixed, offset: 0|mixed}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
     */
    public function getUses(
        string $objectId,
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?int $_registerId=null,
        ?int $_schemaId=null
    ): array {
        try {
            // Get register and schema for magic table lookup if provided.
            $register = null;
            $schema   = null;
            if ($_registerId !== null && $_schemaId !== null) {
                try {
                    $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
                    $register       = $registerMapper->find($_registerId);
                    $schema         = $this->schemaMapper->find($_schemaId);
                } catch (\Exception $e) {
                    $this->logger->debug(
                        '[RelationHandler::getUses] Could not load register/schema for magic table lookup',
                        ['registerId' => $_registerId, 'schemaId' => $_schemaId, 'error' => $e->getMessage()]
                    );
                }
            }

            // Find the object (with magic table support if register/schema available).
            $object = $this->objectEntityMapper->find(
                identifier: $objectId,
                register: $register,
                schema: $schema
            );

            // Get pre-scanned relations from the object entity.
            // These are populated during save/import by scanForRelations().
            $relations = $object->getRelations() ?? [];

            // Extract just the UUID values from the relations array.
            // Relations can be stored as ['field' => 'uuid'] or as flat array of UUIDs.
            $relationshipIds = [];
            foreach ($relations as $key => $value) {
                if (is_string($value) === true && empty($value) === false) {
                    $relationshipIds[] = $value;
                } else if (is_array($value) === true) {
                    foreach ($value as $subValue) {
                        if (is_string($subValue) === true && empty($subValue) === false) {
                            $relationshipIds[] = $subValue;
                        }
                    }
                }
            }


            if (empty($relationshipIds) === true) {
                return [
                    'results' => [],
                    'total'   => 0,
                    'limit'   => $query['_limit'] ?? 30,
                    'offset'  => $query['_offset'] ?? 0,
                ];
            }

            // Load the related objects from magic tables using cross-table search.
            $uniqueIds = array_unique($relationshipIds);

            // Get all register+schema pairs that have magic mapping enabled.
            $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
            $magicMapper    = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);
            $registers      = $registerMapper->findAll();

            $registerSchemaPairs = [];
            foreach ($registers as $reg) {
                $schemaIds = $reg->getSchemas() ?? [];
                foreach ($schemaIds as $schemaId) {
                    try {
                        $sch        = $this->schemaMapper->find((int) $schemaId);
                        $schemaSlug = $sch->getSlug();
                        if ($reg->isMagicMappingEnabledForSchema((int) $schemaId, $schemaSlug) === true) {
                            $registerSchemaPairs[] = ['register' => $reg, 'schema' => $sch];
                        }
                    } catch (\Exception $e) {
                        // Schema not found, skip.
                    }
                }
            }

            // Search each magic table individually for the UUIDs.
            // This avoids UNION column mismatch issues.
            $relatedObjects = [];
            $foundUuids     = [];
            foreach ($registerSchemaPairs as $pair) {
                // Skip if we've found all the UUIDs already.
                if (count($foundUuids) >= count($uniqueIds)) {
                    break;
                }

                // Only search for UUIDs not yet found.
                $remainingUuids = array_diff($uniqueIds, $foundUuids);
                if (empty($remainingUuids) === true) {
                    break;
                }

                try {
                    $results = $magicMapper->findAllInRegisterSchemaTable(
                        register: $pair['register'],
                        schema: $pair['schema'],
                        filters: ['_ids' => array_values($remainingUuids), '_limit' => 200]
                    );

                    foreach ($results as $obj) {
                        $uuid = $obj->getUuid();
                        if (in_array($uuid, $uniqueIds, true) === true && in_array($uuid, $foundUuids, true) === false) {
                            $relatedObjects[] = $obj;
                            $foundUuids[]     = $uuid;
                        }
                    }
                } catch (\Exception $e) {
                    // Table might not exist or query failed, continue.
                }
            }

            // Also check main objects table as fallback for any missing UUIDs.
            $missingUuids = array_diff($uniqueIds, $foundUuids);
            if (empty($missingUuids) === false) {
                $fallbackObjects = $this->objectEntityMapper->findMultiple(ids: $missingUuids);
                $relatedObjects  = array_merge($relatedObjects, $fallbackObjects);
            }

            $this->logger->debug(
                '[RelationHandler::getUses] Found related objects',
                [
                    'searchedIds' => $uniqueIds,
                    'foundCount'  => count($relatedObjects),
                ]
            );

            // Apply pagination.
            $limit  = $query['_limit'] ?? 30;
            $offset = $query['_offset'] ?? 0;
            $total  = count($relatedObjects);

            $relatedObjects = array_slice($relatedObjects, $offset, $limit);

            return [
                'results' => $relatedObjects,
                'total'   => $total,
                'limit'   => $limit,
                'offset'  => $offset,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to get uses',
                context: [
                    'error'    => $e->getMessage(),
                    'objectId' => $objectId,
                ]
            );
            return [
                'results' => [],
                'total'   => 0,
                'limit'   => $query['_limit'] ?? 30,
                'offset'  => $query['_offset'] ?? 0,
            ];
        }//end try
    }//end getUses()

    /**
     * Get objects that use this object (incoming relations).
     *
     * This method finds all objects that reference the given object.
     *
     * @param string $objectId      Object ID or UUID.
     * @param array  $query         Search query parameters.
     * @param bool   $_rbac         Apply RBAC filters.
     * @param bool   $_multitenancy Apply multitenancy filters.
     *
     * @return (array|int|mixed|string)[] Paginated results with referencing objects.
     *
     * @psalm-return array{results: array<never, never>, total: 0,
     *     limit: 30|mixed, offset: 0|mixed,
     *     message?: 'Reverse relationship lookup not yet implemented'}
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns
     */
    public function getUsedBy(
        string $objectId,
        array $query=[],
        bool $_rbac=true,
        bool $_multitenancy=true,
        ?int $_registerId=null,
        ?int $_schemaId=null
    ): array {
        try {
            // Get register and schema for magic table lookup if provided.
            $register = null;
            $schema   = null;
            if ($_registerId !== null && $_schemaId !== null) {
                try {
                    $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
                    $register       = $registerMapper->find($_registerId);
                    $schema         = $this->schemaMapper->find($_schemaId);
                } catch (\Exception $e) {
                    $this->logger->warning(
                        message: 'Failed to load register/schema for getUsedBy magic table support',
                        context: ['error' => $e->getMessage()]
                    );
                }
            }

            // Find the object (with magic table support if register/schema available).
            $object     = $this->objectEntityMapper->find(
                identifier: $objectId,
                register: $register,
                schema: $schema
            );
            $targetUuid = $object->getUuid();

            // Search across all magic tables for objects that reference this UUID in their _relations.
            $results      = [];
            $magicMapper  = \OC::$server->get(\OCA\OpenRegister\Db\MagicMapper::class);
            $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
            $magicTables  = $magicMapper->getExistingRegisterSchemaTables();
            $limit        = $query['_limit'] ?? 30;
            $offset       = $query['_offset'] ?? 0;
            $totalResults = 0;

            // Search each magic table for objects that have this UUID in their _relations.
            foreach ($magicTables as $tableInfo) {
                if (count($results) >= $limit) {
                    break;
                }

                try {
                    // Get register and schema for this table.
                    $tableRegister = $registerMapper->find($tableInfo['registerId']);
                    $tableSchema   = $this->schemaMapper->find($tableInfo['schemaId']);

                    // Search for objects where _relations contains the target UUID.
                    // Use JSON contains search on the _relations column.
                    $searchResults = $magicMapper->findAllInRegisterSchemaTable(
                        register: $tableRegister,
                        schema: $tableSchema,
                        limit: $limit - count($results),
                        offset: max(0, $offset - $totalResults),
                        filters: ['_relations_contains' => $targetUuid],
                        sort: ['_updated' => 'DESC']
                    );

                    foreach ($searchResults as $resultObject) {
                        // Skip the object itself.
                        if ($resultObject->getUuid() === $targetUuid) {
                            continue;
                        }

                        $results[] = $resultObject->jsonSerialize();
                    }

                    $totalResults += count($searchResults);
                } catch (\Exception $e) {
                    $this->logger->debug(
                        message: 'Error searching magic table for usedBy',
                        context: [
                            'table' => $tableInfo['tableName'] ?? 'unknown',
                            'error' => $e->getMessage(),
                        ]
                    );
                    continue;
                }
            }

            return [
                'results' => $results,
                'total'   => count($results),
                'limit'   => $limit,
                'offset'  => $offset,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to get used by',
                context: [
                    'error'    => $e->getMessage(),
                    'objectId' => $objectId,
                ]
            );
            return [
                'results' => [],
                'total'   => 0,
                'limit'   => $query['_limit'] ?? 30,
                'offset'  => $query['_offset'] ?? 0,
            ];
        }//end try
    }//end getUsedBy()
}//end class
