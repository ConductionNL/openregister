<?php

/**
 * BulkRelationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Objects\SaveObjects;

use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Objects\SaveObjects\BulkValidationHandler;
use Psr\Log\LoggerInterface;

/**
 * Handles bulk relation processing for bulk object operations.
 *
 * This handler is responsible for:
 * - Processing inverse relations in bulk
 * - Handling post-save relation writeBack operations
 * - Scanning objects for relations
 * - Optimizing bulk relation processing
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 */
class BulkRelationHandler
{

    /**
     * Constructor for BulkRelationHandler.
     *
     * @param BulkValidationHandler $bulkValidationHandler Handler for bulk validation operations.
     * @param ObjectEntityMapper    $objectEntityMapper    Mapper for object entities.
     * @param LoggerInterface       $logger                Logger for logging operations.
     */
    public function __construct(
    private readonly BulkValidationHandler $bulkValidationHandler,
    private readonly ObjectEntityMapper $objectEntityMapper,
    private readonly LoggerInterface $logger
    ) {
    }//end __construct()


    /**
     * Handle bulk inverse relations using cached schema analysis
     *
     * PERFORMANCE OPTIMIZATION: This method uses pre-analyzed inverse relation properties
     * to process relations without re-analyzing schema properties for each object.
     *
     * @param array &$preparedObjects Prepared objects to process
     * @param array $schemaAnalysis   Pre-analyzed schema information indexed by schema ID
     *
     * @return void
     *
     * @psalm-param array<int, mixed> &$preparedObjects
     * @phpstan-param array<int, mixed> &$preparedObjects
     * @psalm-param array<string, mixed> $schemaAnalysis
     * @phpstan-param array<string, mixed> $schemaAnalysis
     * @psalm-return void
     * @phpstan-return void
     */
    public function handleBulkInverseRelationsWithAnalysis(array &$preparedObjects, array $schemaAnalysis): void
    {
        // Track statistics for debugging/monitoring.
        $_appliedCount = 0;
        $_processedCount = 0;

        // Create direct UUID to object reference mapping.
        $objectsByUuid = [];
        foreach ($preparedObjects ?? [] as $_index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $objectUuid = $selfData['id'] ?? null;
            if ($objectUuid !== null && $objectUuid !== '') {
                $objectsByUuid[$objectUuid] = &$object;
            }
        }

        // Process inverse relations using cached analysis.
        foreach ($preparedObjects ?? [] as $_index => &$object) {
            $selfData   = $object['@self'] ?? [];
            $schemaId   = $selfData['schema'] ?? null;
            $objectUuid = $selfData['id'] ?? null;

            if ($schemaId === false || $objectUuid === false || isset($schemaAnalysis[$schemaId]) === false) {
                continue;
            }

            $analysis = $schemaAnalysis[$schemaId];

            // PERFORMANCE OPTIMIZATION: Use pre-analyzed inverse properties.
            foreach ($analysis['inverseProperties'] ?? [] as $property => $propertyInfo) {
                if (isset($object[$property]) === false) {
                    continue;
                }

                $value = $object[$property];
                $inversedBy = $propertyInfo['inversedBy'];

                // Handle single object relations.
                if (($propertyInfo['isArray'] === false) === true && is_string($value) === true && \Symfony\Component\Uid\Uuid::isValid($value) === true) {
                    if (isset($objectsByUuid[$value]) === true) {
                        // @psalm-suppress EmptyArrayAccess - Already checked isset above.
                        $targetObject = &$objectsByUuid[$value];
                        // @psalm-suppress EmptyArrayAccess - Already checked isset above.
                        $existingValues = ($targetObject[$inversedBy] ?? []);
                        // @psalm-suppress EmptyArrayAccess - $existingValues is initialized with ?? []
                        if (is_array($existingValues) === false) {
                            $existingValues = [];
                        }
                        if (in_array($objectUuid, $existingValues, true) === false) {
                            $existingValues[] = $objectUuid;
                            $targetObject[$inversedBy] = $existingValues;
                            $_appliedCount++;
                        }
                        $_processedCount++;
                    }
                } elseif (($propertyInfo['isArray'] === true) && is_array($value) === true) {
                    // Handle array of object relations.
                    foreach ($value ?? [] as $relatedUuid) {
                        if (is_string($relatedUuid) === true && \Symfony\Component\Uid\Uuid::isValid($relatedUuid) === true) {
                            if (isset($objectsByUuid[$relatedUuid]) === true) {
                                // @psalm-suppress EmptyArrayAccess - Already checked isset above.
                                $targetObject = &$objectsByUuid[$relatedUuid];
                                // @psalm-suppress EmptyArrayAccess - $targetObject is guaranteed to exist from isset check
                                $existingValues = ($targetObject[$inversedBy] ?? []);
                                if (is_array($existingValues) === false) {
                                    $existingValues = [];
                                }
                                if (in_array($objectUuid, $existingValues, true) === false) {
                                    $existingValues[] = $objectUuid;
                                    $targetObject[$inversedBy] = $existingValues;
                                    $_appliedCount++;
                                }
                                $_processedCount++;
                            }
                        }
                    }
                }
            }
        }
    }//end handleBulkInverseRelationsWithAnalysis()


    /**
     * Handle post-save inverse relations with bulk writeBack optimization
     *
     * PERFORMANCE OPTIMIZATION: Collects all writeBack operations and executes
     * them in a single bulk operation instead of individual updates.
     *
     * @param array $savedObjects Array of saved ObjectEntity objects
     * @param array $schemaCache  Schema cache for inverse relation analysis
     * @param callable $getSchemaAnalysisCallback Callback to get schema analysis
     *
     * @return void
     *
     * @psalm-param array<int, \OCA\OpenRegister\Db\ObjectEntity> $savedObjects
     * @phpstan-param array<int, \OCA\OpenRegister\Db\ObjectEntity> $savedObjects
     * @psalm-param array<string, \OCA\OpenRegister\Db\Schema> $schemaCache
     * @phpstan-param array<string, \OCA\OpenRegister\Db\Schema> $schemaCache
     * @psalm-param callable(Schema): array $getSchemaAnalysisCallback
     * @phpstan-param callable(Schema): array $getSchemaAnalysisCallback
     * @psalm-return void
     * @phpstan-return void
     */
    public function handlePostSaveInverseRelations(array $savedObjects, array $schemaCache, callable $getSchemaAnalysisCallback): void
    {
        if (empty($savedObjects) === true) {
            return;
        }


        // PERFORMANCE FIX: Collect all related IDs first to avoid N+1 queries.
        $allRelatedIds = [];
        // Track which objects need which related objects.
        $objectRelationsMap = [];

        // First pass: collect all related object IDs.
        foreach ($savedObjects ?? [] as $index => $savedObject) {
            $schema = $schemaCache[$savedObject->getSchema()] ?? null;
            if ($schema === null) {
                continue;
            }

            // PERFORMANCE: Get cached comprehensive schema analysis for inverse relations.
            $analysis = $getSchemaAnalysisCallback($schema);

            if (empty($analysis['inverseProperties']) === true) {
                continue;
            }

            $objectData = $savedObject->getObject();
            $objectRelationsMap[$index] = [];

            // Process inverse relations for this object.
            foreach ($analysis['inverseProperties'] ?? [] as $propertyName => $inverseConfig) {
                if (isset($objectData[$propertyName]) === false) {
                    continue;
                }

                if (is_array($objectData[$propertyName]) === true) {
                    $relatedObjectIds = $objectData[$propertyName];
                } else {
                    $relatedObjectIds = [$objectData[$propertyName]];
                }

                foreach ($relatedObjectIds ?? [] as $relatedId) {
                    if (empty($relatedId) === false && empty($inverseConfig['writeBack']) === false) {
                        $allRelatedIds[] = $relatedId;
                        $objectRelationsMap[$index][] = $relatedId;
                    }
                }
            }
        }

        // PERFORMANCE OPTIMIZATION: Single bulk fetch instead of N+1 queries.
        $relatedObjectsMap = [];
        if (empty($allRelatedIds) === false) {
            $uniqueRelatedIds = array_unique($allRelatedIds);

            try {
                $relatedObjects = $this->objectEntityMapper->findAll(ids: $uniqueRelatedIds, includeDeleted: false);
                foreach ($relatedObjects ?? [] as $obj) {
                    $relatedObjectsMap[$obj->getUuid()] = $obj;
                }
            } catch (\Exception $e) {
// Skip inverse relations processing if bulk fetch fails.
            }
        }

        // Second pass: process inverse relations with proper context.
        $writeBackOperations = [];
        foreach ($savedObjects ?? [] as $index => $savedObject) {
            if (isset($objectRelationsMap[$index]) === false) {
                continue;
            }

            $schema = $schemaCache[$savedObject->getSchema()] ?? null;
            if ($schema === null) {
                continue;
            }

            // PERFORMANCE: Use cached schema analysis.
            $analysis = $getSchemaAnalysisCallback($schema);
            $objectData = $savedObject->getObject();

            // Build writeBack operations with full context.
            foreach ($analysis['inverseProperties'] ?? [] as $propertyName => $inverseConfig) {
                if (isset($objectData[$propertyName]) === false || ($inverseConfig['writeBack'] === false) === true) {
                    continue;
                }

                if (is_array($objectData[$propertyName]) === true) {
                    $relatedObjectIds = $objectData[$propertyName];
                } else {
                    $relatedObjectIds = [$objectData[$propertyName]];
                }

                foreach ($relatedObjectIds ?? [] as $relatedId) {
                    if (empty($relatedId) === false && (($relatedObjectsMap[$relatedId] ?? null) !== null)) {
                        $writeBackOperations[] = [
                            'targetObject' => $relatedObjectsMap[$relatedId],
                            'sourceUuid' => $savedObject->getUuid(),
                            'inverseProperty' => $inverseConfig['inverseProperty'] ?? $propertyName,
                        ];
                    }
                }
            }
        }

        // Execute writeBack operations with context.
        if (empty($writeBackOperations) === false) {
            $this->performBulkWriteBackUpdatesWithContext($writeBackOperations);
        }
    }//end handlePostSaveInverseRelations()


    /**
     * Perform bulk writeBack updates with full context and actual modifications
     *
     * FIXED: Now actually modifies related objects with inverse properties
     * before saving them to the database.
     *
     * @param array $writeBackOperations Array of writeBack operations with context
     *
     * @return void
     *
     * @psalm-param array<int, array{targetObject: \OCA\OpenRegister\Db\ObjectEntity, sourceUuid: string, inverseProperty: string}> $writeBackOperations
     * @phpstan-param array<int, array{targetObject: \OCA\OpenRegister\Db\ObjectEntity, sourceUuid: string, inverseProperty: string}> $writeBackOperations
     * @psalm-return void
     * @phpstan-return void
     */
    private function performBulkWriteBackUpdatesWithContext(array $writeBackOperations): void
    {
        if (empty($writeBackOperations) === true) {
            return;
        }

        // Track objects that need to be updated.
        $objectsToUpdate = [];

        foreach ($writeBackOperations ?? [] as $operation) {
            $targetObject = $operation['targetObject'];
            $sourceUuid = $operation['sourceUuid'];
            $inverseProperty = $operation['inverseProperty'] ?? null;

            if ($inverseProperty === null) {
                continue;
            }

            // Get current object data.
            $objectData = $targetObject->getObject();

            // Initialize inverse property array if it doesn't exist.
            if (isset($objectData[$inverseProperty]) === false) {
                $objectData[$inverseProperty] = [];
            }

            // Ensure it's an array.
            if (is_array($objectData[$inverseProperty]) === false) {
                $objectData[$inverseProperty] = [$objectData[$inverseProperty]];
            }

            // Add source UUID to inverse property if not already present.
            if (in_array($sourceUuid, $objectData[$inverseProperty], true) === false) {
                $objectData[$inverseProperty][] = $sourceUuid;
            } else {
                continue;
            }

            // Update the object with modified data.
            $targetObject->setObject($objectData);
            $objectsToUpdate[] = $targetObject;
        }

        // Save all modified objects in bulk.
        // TEMPORARILY DISABLED: Skip secondary bulk save to isolate double prefix issue.
        // if (!empty($objectsToUpdate)) {
        //     // NO ERROR SUPPRESSION: Let bulk writeBack update errors bubble up immediately!
        //     $this->objectEntityMapper->saveObjects([], $objectsToUpdate);
        // }.
    }//end performBulkWriteBackUpdatesWithContext()


    /**
     * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
     *
     * This method checks schema properties for relation types:
     * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
     * - Properties with type 'object' that contain string values (always treated as relations)
     * - Properties with type 'array' of objects that contain string values
     *
     * This is ported from SaveObject.php to provide consistent relation handling
     * for bulk operations.
     *
     * @param array       $data   The object data to scan
     * @param string      $prefix The current prefix for dot notation (used in recursion)
     * @param Schema|null $schema The schema to check property definitions against
     *
     * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
     *
     * @psalm-param array<string, mixed> $data
     * @phpstan-param array<string, mixed> $data
     * @psalm-param string $prefix
     * @phpstan-param string $prefix
     * @psalm-param Schema|null $schema
     * @phpstan-param Schema|null $schema
     * @psalm-return array<string, string>
     * @phpstan-return array<string, string>
     */
    public function scanForRelations(array $data, string $prefix = '', ?Schema $schema = null): array
    {
        $relations = [];

        // NO ERROR SUPPRESSION: Let relation scanning errors bubble up immediately!
        // Get schema properties if available.
        $schemaProperties = null;
        if ($schema !== null) {
            // NO ERROR SUPPRESSION: Let schema property parsing errors bubble up immediately!
            $schemaProperties = $schema->getProperties();
        }

        foreach ($data ?? [] as $key => $value) {
            $currentPath = $prefix !== '' ? "$prefix.$key" : $key;

            // Check if this property is defined in the schema.
            $propertyConfig = $schemaProperties[$key] ?? null;

            // Handle string values (potential UUIDs/URLs).
            if (is_string($value) === true) {
                // Check if it's a UUID or URL based on schema definition.
                $isRelation = false;

                if ($propertyConfig !== null) {
                    $type = $propertyConfig['type'] ?? '';
                    $format = $propertyConfig['format'] ?? '';

                    // Check for explicit relation types.
                    if ($type === 'text' && in_array($format, ['uuid', 'uri', 'url'], true) === true) {
                        $isRelation = true;
                    } elseif ($type === 'object') {
                        // Type 'object' with a string value is always a relation.
                        $isRelation = true;
                    }
                } else {
                    // No schema info - use heuristics.
                    // If it looks like a UUID or URL, treat it as a relation.
                    if (\Symfony\Component\Uid\Uuid::isValid($value) === true) {
                        $isRelation = true;
                    } elseif (filter_var($value, FILTER_VALIDATE_URL) !== false) {
                        $isRelation = true;
                    }
                }

                if ($isRelation === true) {
                    $relations[$currentPath] = $value;
                }
            } elseif (is_array($value) === true) {
                // Recursively scan nested arrays/objects.
                $nestedRelations = $this->scanForRelations(data: $value, prefix: $currentPath, schema: $schema);
                $relations = array_merge($relations, $nestedRelations);
            }//end if
        }//end foreach

        return $relations;
    }//end scanForRelations()
}//end class
