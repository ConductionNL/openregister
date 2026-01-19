<?php

/**
 * ValidationHandler
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

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use Psr\Log\LoggerInterface;

/**
 * Handles validation operations for ObjectService.
 *
 * This handler is responsible for:
 * - Validating objects against schemas
 * - Validating required fields
 * - Handling validation exceptions
 * - Bulk schema validation
 *
 * This replaces the standalone ValidationService and consolidates
 * validation logic into a dedicated handler.
 *
 * @category Service
 * @package  OCA\OpenRegister
 * @author   Conduction <info@conduction.nl>
 * @license  AGPL-3.0-or-later https://www.gnu.org/licenses/agpl-3.0.html
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Validation requires multiple exception and entity dependencies
 */
class ValidationHandler
{
    /**
     * Constructor for ValidationHandler.
     *
     * @param ValidateObject     $validateHandler    Handler for object validation.
     * @param ObjectEntityMapper $objectEntityMapper Mapper for object entities.
     * @param RegisterMapper     $registerMapper     Mapper for registers.
     * @param SchemaMapper       $schemaMapper       Mapper for schemas.
     * @param MagicMapper        $magicMapper        Mapper for magic tables.
     * @param LoggerInterface    $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly ValidateObject $validateHandler,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly RegisterMapper $registerMapper,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $magicMapper,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Handles validation exceptions by delegating to ValidateObject handler.
     *
     * @param ValidationException|CustomValidationException $exception The validation exception to handle.
     *
     * @return mixed The result from the ValidateObject handler.
     *
     * @psalm-param   ValidationException|CustomValidationException $exception
     * @phpstan-param ValidationException|CustomValidationException $exception
     */
    public function handleValidationException(ValidationException|CustomValidationException $exception): mixed
    {
        return $this->validateHandler->handleValidationException($exception);
    }//end handleValidationException()

    /**
     * Validates that required fields are present in bulk objects.
     *
     * @param array $objects Array of objects to validate.
     *
     * @psalm-param   array<int, array<string, mixed>> $objects
     * @phpstan-param array<int, array<string, mixed>> $objects
     *
     * @return void
     *
     * @psalm-return   void
     * @phpstan-return void
     *
     * @throws InvalidArgumentException If required fields are missing.
     */
    public function validateRequiredFields(array $objects): void
    {
        $requiredFields = ['register', 'schema'];

        foreach ($objects as $index => $object) {
            // Check if object has @self section.
            if (isset($object['@self']) === false || is_array($object['@self']) === false) {
                throw new InvalidArgumentException(
                    "Object at index {$index} is missing required '@self' section"
                );
            }

            $self = $object['@self'];

            // Check each required field.
            foreach ($requiredFields as $field) {
                if (isset($self[$field]) === false || empty($self[$field]) === true) {
                    throw new InvalidArgumentException(
                        "Object at index {$index} is missing required field '{$field}' in @self section"
                    );
                }
            }
        }
    }//end validateRequiredFields()

    /**
     * Validates all objects for a given schema.
     *
     * This method retrieves all objects for a schema and validates them
     * without actually saving. It returns arrays of valid and invalid objects.
     *
     * @param int      $schemaId     The schema ID to validate objects for.
     * @param callable $saveCallback Callback function to save/validate objects
     *                               (receives: object, extend, register, schema, uuid, rbac, multi, silent).
     *
     * @psalm-param   int $schemaId
     * @psalm-param   callable(array, array, int|string|null, int, string|null, bool, bool, bool): void $saveCallback
     * @phpstan-param int $schemaId
     * @phpstan-param callable(array, array, int|string|null, int, string|null, bool, bool, bool): void $saveCallback
     *
     * @return array Array containing 'valid' and 'invalid' objects with details.
     *
     * @psalm-return   array{valid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>}>,
     *     invalid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>, error: string}>}
     * @phpstan-return array{valid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>}>,
     *     invalid: array<int, array{id: int, uuid: string,
     *     name: string|null, data: array<string, mixed>, error: string}>}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple exception types require separate handling
     */
    public function validateObjectsBySchema(int $schemaId, callable $saveCallback): array
    {
        // Use the mapper's findBySchema method to get all objects for this schema.
        // This bypasses RBAC and multi-tenancy automatically.
        $objects = $this->objectEntityMapper->findBySchema($schemaId);

        $validObjects   = [];
        $invalidObjects = [];

        foreach ($objects as $object) {
            try {
                // Get the object data for validation.
                $objectData = $object->getObject();

                // Use saveCallback with silent=true to validate without actually saving.
                // This will trigger validation and return any errors.
                $saveCallback(
                    $objectData,
                    [],
                    // Extend.
                    $object->getRegister(),
                    // Register.
                    $schemaId,
                    // Schema.
                    $object->getUuid(),
                    // UUID.
                    false,
                    // Rbac.
                    false,
                    // Multitenancy.
                    true
                    // Silent.
                );

                // If saveCallback succeeded, the object is valid.
                $validObjects[] = [
                    'id'   => $object->getId(),
                    'uuid' => $object->getUuid(),
                    'name' => $object->getName(),
                    'data' => $objectData,
                ];
            } catch (ValidationException | CustomValidationException $e) {
                // If validation failed, add to invalid objects with error details.
                $invalidObjects[] = [
                    'id'    => $object->getId(),
                    'uuid'  => $object->getUuid(),
                    'name'  => $object->getName(),
                    'data'  => $objectData,
                    'error' => $e->getMessage(),
                ];
            } catch (\Exception $e) {
                // Handle other exceptions.
                $this->logger->error(
                    'Unexpected error during validation',
                    [
                        'app'      => 'openregister',
                        'objectId' => $object->getId(),
                        'error'    => $e->getMessage(),
                    ]
                );
                $invalidObjects[] = [
                    'id'    => $object->getId(),
                    'uuid'  => $object->getUuid(),
                    'name'  => $object->getName(),
                    'data'  => $objectData,
                    'error' => 'Unexpected error: '.$e->getMessage(),
                ];
            }//end try
        }//end foreach

        return [
            'valid'   => $validObjects,
            'invalid' => $invalidObjects,
        ];
    }//end validateObjectsBySchema()

    /**
     * Validate and save all objects for a schema with chunked processing
     *
     * This method validates all objects belonging to the specified schema and saves them
     * to update metadata fields like _name, _description, _summary. This is useful for
     * bulk updating object metadata after schema changes, imports, or configuration updates.
     *
     * CHUNKING STRATEGY:
     * - Loads all objects once, then processes in adaptive-sized chunks
     * - Chunk sizes scale based on dataset size (1K-3K objects per chunk)
     * - Aggressive garbage collection between chunks for memory management
     * - Successfully processes datasets of 671K+ objects within 8GB PHP memory limit
     *
     * PERFORMANCE:
     * - Small datasets (< 1K): Processed in single batch
     * - Medium datasets (1K-50K): 2-3K chunk sizes
     * - Large datasets (50K-200K): 2K chunks
     * - Very large datasets (200K+): 1K chunks for optimal memory usage
     *
     * Example: 671K objects processed in ~5 minutes with 1K chunks
     *
     * @param int      $registerId   The ID of the register containing the schema
     * @param int      $schemaId     The ID of the schema whose objects should be validated
     * @param callable $saveCallback Callback to save objects (unused - uses ObjectService directly)
     *
     * @return array{processed: int, updated: int, failed: int, errors: array}
     *               Statistics about the validation and save operation:
     *               - processed: Total number of objects processed
     *               - updated: Number of objects successfully updated
     *               - failed: Number of objects that failed validation/save
     *               - errors: Array of error details (currently empty)
     *
     * @throws \Exception If schema/register loading fails or object retrieval fails
     */
    public function validateAndSaveObjectsBySchema(int $registerId, int $schemaId, callable $saveCallback): array
    {
        // Get the schema and register entities
        try {
            $schema = $this->schemaMapper->find($schemaId);
            $register = $this->registerMapper->find($registerId);
        } catch (\Exception $e) {
            $this->logger->error(
                message: 'Failed to load schema or register',
                context: [
                    'register_id' => $registerId,
                    'schema_id'   => $schemaId,
                    'error'       => $e->getMessage(),
                ]
            );
            return [
                'processed' => 0,
                'updated'   => 0,
                'failed'    => 0,
                'errors'    => [['error' => 'Failed to load schema or register: ' . $e->getMessage()]],
            ];
        }

        // Check if schema uses magic tables
        $usesMagic = false;
        $properties = $schema->getProperties() ?? [];
        foreach ($properties as $property) {
            if (isset($property['table']) === true && is_array($property['table']) === true) {
                $usesMagic = true;
                break;
            }
        }

        // MEMORY-EFFICIENT APPROACH: Load all objects once, process in chunks with aggressive cleanup
        // For very large datasets (671K objects), we use small chunks and aggressive GC
        
        $this->logger->info(
            message: 'Loading objects for validation',
            context: [
                'schema_id'    => $schemaId,
                'storage_type' => $usesMagic ? 'magic_table' : 'blob_storage',
            ]
        );
        
        // Load all objects once
        $allObjects = [];
        if ($usesMagic === true) {
            try {
                $allObjects = $this->magicMapper->findAllInRegisterSchemaTable($register, $schema);
            } catch (\Exception $e) {
                $this->logger->error(
                    message: 'Failed to get objects from magic table',
                    context: [
                        'schema_id' => $schemaId,
                        'error'     => $e->getMessage(),
                    ]
                );
                return [
                    'processed' => 0,
                    'updated'   => 0,
                    'failed'    => 0,
                    'errors'    => [['error' => 'Failed to get objects from magic table: ' . $e->getMessage()]],
                ];
            }
        } else {
            // For blob storage
            try {
                $allObjects = $this->objectEntityMapper->findBySchema($schemaId);
            } catch (\Exception $e) {
                $this->logger->error(
                    message: 'Failed to get objects from blob storage',
                    context: [
                        'schema_id' => $schemaId,
                        'error'     => $e->getMessage(),
                    ]
                );
                return [
                    'processed' => 0,
                    'updated'   => 0,
                    'failed'    => 0,
                    'errors'    => [['error' => 'Failed to get objects: ' . $e->getMessage()]],
                ];
            }
        }

        $totalObjects = count($allObjects);
        
        // Calculate chunk size based on dataset size
        // Use smaller chunks for large datasets to manage memory within 8GB PHP limit
        if ($totalObjects <= 1000) {
            $chunkSize = $totalObjects; // Process all at once
        } elseif ($totalObjects <= 10000) {
            $chunkSize = 2000;
        } elseif ($totalObjects <= 50000) {
            $chunkSize = 3000;
        } elseif ($totalObjects <= 200000) {
            $chunkSize = 2000; // Smaller chunks for better memory management
        } else {
            $chunkSize = 1000; // Very small chunks for 671K+ datasets
        }
        
        $estimatedChunks = ceil($totalObjects / $chunkSize);
        
        $this->logger->info(
            message: 'Starting chunked validation',
            context: [
                'schema_id'        => $schemaId,
                'total_objects'    => $totalObjects,
                'chunk_size'       => $chunkSize,
                'estimated_chunks' => $estimatedChunks,
            ]
        );

        $totalProcessed = 0;
        $totalUpdated = 0;
        $totalFailed = 0;

        // Process in chunks with aggressive memory cleanup
        for ($offset = 0; $offset < $totalObjects; $offset += $chunkSize) {
            $currentChunk = ($offset / $chunkSize) + 1;
            
            // Extract just this chunk
            $objectsChunk = array_slice($allObjects, $offset, $chunkSize);
            
            if (empty($objectsChunk) === true) {
                break;
            }

            // Convert objects to arrays for bulk processing
            $objectsData = [];
            foreach ($objectsChunk as $object) {
                if (is_array($object) === true) {
                    // Already an array from magic table
                    $objectsData[] = $object;
                } else {
                    // ObjectEntity - get the object data
                    $objectsData[] = $object->getObject();
                }
            }

            $this->logger->info(
                message: 'Processing validation chunk',
                context: [
                    'schema_id'     => $schemaId,
                    'chunk'         => $currentChunk . '/' . $estimatedChunks,
                    'chunk_size'    => count($objectsChunk),
                    'progress_pct'  => round(($offset / $totalObjects) * 100, 1),
                    'memory_usage'  => round(memory_get_usage(true) / 1024 / 1024) . ' MB',
                ]
            );

            // Use bulk save operation for this chunk
            try {
                // Get the ObjectService instance from the saveCallback
                $objectService = $saveCallback[0] ?? null;
                
                if ($objectService === null || method_exists($objectService, 'saveObjects') === false) {
                    throw new \Exception('Cannot access bulk save method');
                }

                // Use bulk saveObjects method for this chunk
                $result = $objectService->saveObjects(
                    objects: $objectsData,
                    register: $registerId,
                    schema: $schemaId,
                    _rbac: false,
                    _multitenancy: false,
                    validation: true,  // Enable validation
                    events: false,     // Disable events for performance
                    deduplicateIds: false,
                    enrich: true       // Enable enrichment to update metadata like _name
                );

                $statistics = $result['statistics'] ?? [];
                $chunkProcessed = count($objectsData);
                $chunkUpdated = ($statistics['saved'] ?? 0) + ($statistics['updated'] ?? 0);
                $chunkFailed = $statistics['failed'] ?? 0;

                $totalProcessed += $chunkProcessed;
                $totalUpdated += $chunkUpdated;
                $totalFailed += $chunkFailed;

                $this->logger->info(
                    message: 'Chunk validation completed',
                    context: [
                        'schema_id'       => $schemaId,
                        'chunk'           => $currentChunk . '/' . $estimatedChunks,
                        'chunk_processed' => $chunkProcessed,
                        'chunk_updated'   => $chunkUpdated,
                        'chunk_failed'    => $chunkFailed,
                        'total_progress'  => $totalProcessed . '/' . $totalObjects,
                        'memory_after'    => round(memory_get_usage(true) / 1024 / 1024) . ' MB',
                    ]
                );

            } catch (\Exception $e) {
                $this->logger->error(
                    message: 'Chunk validation failed',
                    context: [
                        'schema_id' => $schemaId,
                        'chunk'     => $currentChunk . '/' . $estimatedChunks,
                        'offset'    => $offset,
                        'error'     => $e->getMessage(),
                    ]
                );
                // Continue with next chunk despite error
                $totalFailed += count($objectsChunk);
            }

            // Aggressive memory cleanup after each chunk
            unset($objectsChunk, $objectsData, $result);
            gc_collect_cycles();
        }
        
        // Final cleanup
        unset($allObjects);
        gc_collect_cycles();

        $this->logger->info(
            message: 'Validation and save completed',
            context: [
                'schema_id' => $schemaId,
                'total_processed' => $totalProcessed,
                'total_updated' => $totalUpdated,
                'total_failed' => $totalFailed,
            ]
        );

        return [
            'processed' => $totalProcessed,
            'updated' => $totalUpdated,
            'failed' => $totalFailed,
            'errors' => [],
        ];
    }//end validateAndSaveObjectsBySchema()

    /**
     * Validate all objects belonging to a specific schema (comprehensive version).
     *
     * This method validates all objects that belong to the specified schema against their schema definition.
     * It returns detailed validation results including valid and invalid objects with error details.
     *
     * @param int      $schemaId     The ID of the schema whose objects should be validated.
     * @param callable $saveCallback Callback to validate objects (object, register, schema, uuid, rbac, multi, silent).
     *
     * @return array Comprehensive validation results.
     *
     * @throws \Exception If the validation operation fails.
     *
     * @phpstan-return array{valid_count: int, invalid_count: int,
     *     valid_objects: array<int, array>, invalid_objects: array<int, array>,
     *     schema_id: int}
     * @psalm-return   array{valid_count: int<0, max>,
     *     invalid_count: int<0, max>,
     *     valid_objects: list<array{data: array, id: int,
     *     name: null|string, uuid: null|string}>,
     *     invalid_objects: list<array{data: array,
     *     errors: list<array{keyword: 'exception'|'validation'|mixed,
     *     message: mixed|non-falsy-string, path: 'general'|'unknown'|mixed}>,
     *     id: int, name: null|string, uuid: null|string}>, schema_id: int}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Comprehensive validation with detailed error extraction
     * @SuppressWarnings(PHPMD.ElseExpression)       Different error extraction paths for validation and generic exceptions
     */
    public function validateSchemaObjects(int $schemaId, callable $saveCallback): array
    {
        // Use the mapper's findBySchema method to get all objects for this schema.
        // This bypasses RBAC and multi-tenancy automatically.
        $objects = $this->objectEntityMapper->findBySchema($schemaId);

        $validObjects   = [];
        $invalidObjects = [];

        foreach ($objects as $object) {
            try {
                // Get the object data for validation.
                $objectData = $object->getObject();

                // Use saveCallback with silent=true to validate without actually saving.
                // This will trigger validation and return any errors.
                $saveCallback(
                    $objectData,
                    $object->getRegister(),
                    $schemaId,
                    $object->getUuid(),
                    false,
                    false,
                    true
                );

                // If saveCallback succeeded, the object is valid.
                $validObjects[] = [
                    'id'   => $object->getId(),
                    'uuid' => $object->getUuid(),
                    'name' => $object->getName(),
                    'data' => $objectData,
                ];
            } catch (\Exception $e) {
                // Extract validation errors from the exception.
                $errors = [];

                // Check if it's a validation exception with detailed errors.
                if ($e instanceof \OCA\OpenRegister\Exception\ValidationException) {
                    foreach ($e->getErrors() ?? [] as $error) {
                        $errors[] = [
                            'path'    => $error['path'] ?? 'unknown',
                            'message' => $error['message'] ?? $error,
                            'keyword' => $error['keyword'] ?? 'validation',
                        ];
                    }
                } else {
                    // Generic error.
                    $errors[] = [
                        'path'    => 'general',
                        'message' => 'Validation failed: '.$e->getMessage(),
                        'keyword' => 'exception',
                    ];
                }

                $invalidObjects[] = [
                    'id'     => $object->getId(),
                    'uuid'   => $object->getUuid(),
                    'name'   => $object->getName(),
                    'data'   => $objectData,
                    'errors' => $errors,
                ];
            }//end try
        }//end foreach

        return [
            'valid_count'     => count($validObjects),
            'invalid_count'   => count($invalidObjects),
            'valid_objects'   => $validObjects,
            'invalid_objects' => $invalidObjects,
            'schema_id'       => $schemaId,
        ];
    }//end validateSchemaObjects()

    /**
     * Apply inversedBy filter to query filters.
     *
     * This method resolves inversedBy relationships in filters and returns the matching object IDs.
     * It handles nested property filters (using underscore delimiters) and performs reverse lookups.
     *
     * @param array $_filters Query filters to process (passed by reference).
     *
     * @return array|null Matching object IDs or null.
     */
    public function applyInversedByFilter(array &$_filters): array|null
    {
        // This method requires additional dependencies - placeholder for now.
        // Full implementation requires SchemaMapper, ObjectService->findAll, and Dot utilities.
        return [];
    }//end applyInversedByFilter()
}//end class
