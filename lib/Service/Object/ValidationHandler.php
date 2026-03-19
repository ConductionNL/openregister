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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Service\Object\ValidateObject;
use Psr\Log\LoggerInterface;
use Exception;

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
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Validation requires multiple exception and entity dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Validation orchestration requires multiple validation strategy methods
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ValidationHandler
{
    /**
     * Constructor for ValidationHandler.
     *
     * @param ValidateObject  $validateHandler    Handler for object validation.
     * @param MagicMapper     $objectEntityMapper Mapper for object entities.
     * @param RegisterMapper  $registerMapper     Mapper for registers.
     * @param SchemaMapper    $schemaMapper       Mapper for schemas.
     * @param MagicMapper     $magicMapper        Mapper for magic tables.
     * @param LoggerInterface $logger             Logger for logging operations.
     */
    public function __construct(
        private readonly ValidateObject $validateHandler,
        private readonly MagicMapper $objectEntityMapper,
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
            $objectData = [];
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
                    message: '[ValidationHandler] Unexpected error during validation',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
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
     * @param array    $saveCallback Array-callable to save objects, e.g. [$objectService, 'saveObject']
     * @param int|null $limit        Maximum number of objects to process (null = all)
     * @param int      $offset       Number of objects to skip before processing
     *
     * @return array{processed: int, updated: int, failed: int, total: int, errors: array}
     *               Statistics about the validation and save operation:
     *               - processed: Total number of objects processed in this batch
     *               - updated: Number of objects successfully updated
     *               - failed: Number of objects that failed validation/save
     *               - total: Total number of objects in the schema (for pagination)
     *               - errors: Array of error details (currently empty)
     *
     * @throws \Exception If schema/register loading fails or object retrieval fails
     */
    public function validateAndSaveObjectsBySchema(
        int $registerId,
        int $schemaId,
        array $saveCallback,
        ?int $limit=null,
        int $offset=0
    ): array {
        // Get the schema and register entities.
        $loaded = $this->loadSchemaAndRegister(registerId: $registerId, schemaId: $schemaId);
        if ($loaded === null) {
            return [
                'processed' => 0,
                'updated'   => 0,
                'failed'    => 0,
                'errors'    => [['error' => 'Failed to load schema or register']],
            ];
        }

        $schema   = $loaded['schema'];
        $register = $loaded['register'];

        // All objects are stored in magic tables.
        $storageType = 'magic_table';

        $this->logger->info(
            message: '[ValidationHandler] Loading objects for validation',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'schema_id'    => $schemaId,
                'storage_type' => $storageType,
                'limit'        => $limit,
                'offset'       => $offset,
            ]
        );

        $allObjects = $this->loadObjectsForValidation(
            register: $register,
            schema: $schema,
            schemaId: $schemaId
        );
        if ($allObjects === null) {
            return [
                'processed' => 0,
                'updated'   => 0,
                'failed'    => 0,
                'total'     => 0,
                'errors'    => [['error' => 'Failed to load objects for validation']],
            ];
        }

        $totalObjects = count($allObjects);

        // Apply limit/offset for API-level chunking.
        $allObjects = $this->applyLimitOffset(
            allObjects: $allObjects,
            schemaId: $schemaId,
            totalObjects: $totalObjects,
            limit: $limit,
            offset: $offset
        );

        $objectsToProcess = count($allObjects);
        $chunkSize        = $this->calculateChunkSize(objectsToProcess: $objectsToProcess);

        if ($objectsToProcess > 0) {
            $estimatedChunks = ceil($objectsToProcess / $chunkSize);
        } else {
            $estimatedChunks = 0;
        }//end if

        $this->logger->info(
            message: '[ValidationHandler] Starting chunked validation',
            context: [
                'file'               => __FILE__,
                'line'               => __LINE__,
                'schema_id'          => $schemaId,
                'total_objects'      => $totalObjects,
                'objects_to_process' => $objectsToProcess,
                'chunk_size'         => $chunkSize,
                'estimated_chunks'   => $estimatedChunks,
            ]
        );

        // Process all chunks.
        $totals = $this->processAllChunks(
            allObjects: $allObjects,
            objectsToProcess: $objectsToProcess,
            chunkSize: $chunkSize,
            estimatedChunks: $estimatedChunks,
            schemaId: $schemaId,
            registerId: $registerId,
            saveCallback: $saveCallback
        );

        // Final cleanup.
        unset($allObjects);
        gc_collect_cycles();

        $this->logger->info(
            message: '[ValidationHandler] Validation and save completed',
            context: [
                'file'              => __FILE__,
                'line'              => __LINE__,
                'schema_id'         => $schemaId,
                'total_in_schema'   => $totalObjects,
                'objects_processed' => $totals['processed'],
                'objects_updated'   => $totals['updated'],
                'objects_failed'    => $totals['failed'],
            ]
        );

        return [
            'processed' => $totals['processed'],
            'updated'   => $totals['updated'],
            'failed'    => $totals['failed'],
            'total'     => $totalObjects,
            'errors'    => [],
        ];
    }//end validateAndSaveObjectsBySchema()

    /**
     * Load schema and register entities by their IDs.
     *
     * @param int $registerId The register ID.
     * @param int $schemaId   The schema ID.
     *
     * @return array|null Array with 'schema' and 'register' keys, or null on failure.
     *
     * @psalm-return   array{schema: \OCA\OpenRegister\Db\Schema, register: \OCA\OpenRegister\Db\Register}|null
     * @phpstan-return array{schema: \OCA\OpenRegister\Db\Schema, register: \OCA\OpenRegister\Db\Register}|null
     */
    private function loadSchemaAndRegister(int $registerId, int $schemaId): ?array
    {
        try {
            $schema   = $this->schemaMapper->find($schemaId);
            $register = $this->registerMapper->find($registerId);

            return [
                'schema'   => $schema,
                'register' => $register,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ValidationHandler] Failed to load schema or register',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'register_id' => $registerId,
                    'schema_id'   => $schemaId,
                    'error'       => $e->getMessage(),
                ]
            );

            return null;
        }//end try
    }//end loadSchemaAndRegister()

    /**
     * Load objects for validation from magic tables.
     *
     * @param mixed $register The register entity.
     * @param mixed $schema   The schema entity.
     * @param int   $schemaId The schema ID (for logging).
     *
     * @return array|null Array of objects, or null on failure.
     */
    private function loadObjectsForValidation(mixed $register, mixed $schema, int $schemaId): ?array
    {
        try {
            return $this->magicMapper->findAllInRegisterSchemaTable($register, $schema);
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ValidationHandler] Failed to get objects from magic table',
                context: [
                    'file'      => __FILE__,
                    'line'      => __LINE__,
                    'schema_id' => $schemaId,
                    'error'     => $e->getMessage(),
                ]
            );

            return null;
        }//end try
    }//end loadObjectsForValidation()

    /**
     * Apply limit and offset to the objects array for API-level chunking.
     *
     * @param array    $allObjects   The full array of objects.
     * @param int      $schemaId     The schema ID (for logging).
     * @param int      $totalObjects Total object count before slicing.
     * @param int|null $limit        Maximum number of objects to return (null = all).
     * @param int      $offset       Number of objects to skip.
     *
     * @return array The sliced array of objects.
     */
    private function applyLimitOffset(array $allObjects, int $schemaId, int $totalObjects, ?int $limit, int $offset): array
    {
        if ($limit !== null || $offset > 0) {
            $allObjects = array_slice($allObjects, $offset, $limit);
            $this->logger->info(
                message: '[ValidationHandler] Applied limit/offset for chunked validation',
                context: [
                    'file'               => __FILE__,
                    'line'               => __LINE__,
                    'schema_id'          => $schemaId,
                    'total_objects'      => $totalObjects,
                    'offset'             => $offset,
                    'limit'              => $limit,
                    'objects_to_process' => count($allObjects),
                ]
            );
        }

        return $allObjects;
    }//end applyLimitOffset()

    /**
     * Calculate the optimal chunk size based on the number of objects to process.
     *
     * @param int $objectsToProcess The total number of objects to process.
     *
     * @return int The chunk size to use.
     */
    private function calculateChunkSize(int $objectsToProcess): int
    {
        if ($objectsToProcess <= 1000) {
            return $objectsToProcess;
            // Process all at once.
        } else if ($objectsToProcess <= 10000) {
            return 2000;
        } else if ($objectsToProcess <= 50000) {
            return 3000;
        } else if ($objectsToProcess <= 200000) {
            return 2000;
            // Smaller chunks for better memory management.
        }

        return 1000;
        // Very small chunks for 671K+ datasets.
    }//end calculateChunkSize()

    /**
     * Process all validation chunks and return aggregated totals.
     *
     * @param array $allObjects       All objects to process.
     * @param int   $objectsToProcess Total number of objects to process.
     * @param int   $chunkSize        Size of each processing chunk.
     * @param float $estimatedChunks  Estimated number of chunks.
     * @param int   $schemaId         The schema ID.
     * @param int   $registerId       The register ID.
     * @param array $saveCallback     Array-callable for saving objects.
     *
     * @return array{processed: int, updated: int, failed: int} Aggregated totals.
     */
    private function processAllChunks(
        array $allObjects,
        int $objectsToProcess,
        int $chunkSize,
        float $estimatedChunks,
        int $schemaId,
        int $registerId,
        array $saveCallback
    ): array {
        $totalProcessed = 0;
        $totalUpdated   = 0;
        $totalFailed    = 0;

        // Process in chunks with aggressive memory cleanup.
        for ($chunkOffset = 0; $chunkOffset < $objectsToProcess; $chunkOffset += $chunkSize) {
            $currentChunk = ($chunkOffset / $chunkSize) + 1;
            $objectsChunk = array_slice($allObjects, $chunkOffset, $chunkSize);

            if (empty($objectsChunk) === true) {
                break;
            }

            $chunkResult = $this->processValidationChunk(
                objectsChunk: $objectsChunk,
                currentChunk: $currentChunk,
                estimatedChunks: $estimatedChunks,
                objectsToProcess: $objectsToProcess,
                chunkOffset: $chunkOffset,
                schemaId: $schemaId,
                registerId: $registerId,
                saveCallback: $saveCallback
            );

            $totalProcessed += $chunkResult['processed'];
            $totalUpdated   += $chunkResult['updated'];
            $totalFailed    += $chunkResult['failed'];

            // Aggressive memory cleanup after each chunk.
            unset($objectsChunk);
            gc_collect_cycles();
        }//end for

        return [
            'processed' => $totalProcessed,
            'updated'   => $totalUpdated,
            'failed'    => $totalFailed,
        ];
    }//end processAllChunks()

    /**
     * Process a single validation chunk of objects.
     *
     * @param array $objectsChunk     The chunk of objects to process.
     * @param float $currentChunk     The current chunk number (1-based).
     * @param float $estimatedChunks  Total estimated chunks.
     * @param int   $objectsToProcess Total objects being processed.
     * @param int   $chunkOffset      The current offset within the full array.
     * @param int   $schemaId         The schema ID.
     * @param int   $registerId       The register ID.
     * @param array $saveCallback     Array-callable for saving objects.
     *
     * @return array{processed: int, updated: int, failed: int} Chunk processing results.
     */
    private function processValidationChunk(
        array $objectsChunk,
        float $currentChunk,
        float $estimatedChunks,
        int $objectsToProcess,
        int $chunkOffset,
        int $schemaId,
        int $registerId,
        array $saveCallback
    ): array {
        // Convert objects to arrays for bulk processing.
        $objectsData = $this->convertChunkToArrays(objectsChunk: $objectsChunk);

        $progressPct = 100;
        if ($objectsToProcess > 0) {
            $progressPct = round(($chunkOffset / $objectsToProcess) * 100, 1);
        }

        $this->logger->info(
            message: '[ValidationHandler] Processing validation chunk',
            context: [
                'file'         => __FILE__,
                'line'         => __LINE__,
                'schema_id'    => $schemaId,
                'chunk'        => $currentChunk.'/'.$estimatedChunks,
                'chunk_size'   => count($objectsChunk),
                'progress_pct' => $progressPct,
                'memory_usage' => round(memory_get_usage(true) / 1024 / 1024).' MB',
            ]
        );

        // Use bulk save operation for this chunk.
        $result = null;
        try {
            // Get the ObjectService instance from the saveCallback.
            $objectService = $saveCallback[0] ?? null;

            if ($objectService === null || method_exists($objectService, 'saveObjects') === false) {
                throw new Exception('Cannot access bulk save method');
            }

            // Use bulk saveObjects method for this chunk.
            $result = $objectService->saveObjects(
                objects: $objectsData,
                register: $registerId,
                schema: $schemaId,
                _rbac: false,
                _multitenancy: false,
                validation: true,
            // Enable validation.
                events: false,
            // Disable events for performance.
                deduplicateIds: false,
                enrich: true
            // Enable enrichment to update metadata like _name.
            );

            $statistics     = $result['statistics'] ?? [];
            $chunkProcessed = count($objectsData);
            $chunkUpdated   = ($statistics['saved'] ?? 0) + ($statistics['updated'] ?? 0);
            $chunkFailed    = $statistics['failed'] ?? 0;

            $this->logger->info(
                message: '[ValidationHandler] Chunk validation completed',
                context: [
                    'file'            => __FILE__,
                    'line'            => __LINE__,
                    'schema_id'       => $schemaId,
                    'chunk'           => $currentChunk.'/'.$estimatedChunks,
                    'chunk_processed' => $chunkProcessed,
                    'chunk_updated'   => $chunkUpdated,
                    'chunk_failed'    => $chunkFailed,
                    'total_progress'  => $chunkProcessed.'/'.$objectsToProcess,
                    'memory_after'    => round(memory_get_usage(true) / 1024 / 1024).' MB',
                ]
            );

            unset($objectsData, $result);

            return [
                'processed' => $chunkProcessed,
                'updated'   => $chunkUpdated,
                'failed'    => $chunkFailed,
            ];
        } catch (\Exception $e) {
            $this->logger->error(
                message: '[ValidationHandler] Chunk validation failed',
                context: [
                    'file'        => __FILE__,
                    'line'        => __LINE__,
                    'schema_id'   => $schemaId,
                    'chunk'       => $currentChunk.'/'.$estimatedChunks,
                    'chunkOffset' => $chunkOffset,
                    'error'       => $e->getMessage(),
                ]
            );

            unset($objectsData, $result);

            // Continue with next chunk despite error.
            return [
                'processed' => 0,
                'updated'   => 0,
                'failed'    => count($objectsChunk),
            ];
        }//end try
    }//end processValidationChunk()

    /**
     * Convert a chunk of objects to plain arrays for bulk processing.
     *
     * Handles both magic table arrays (already arrays) and ObjectEntity instances.
     *
     * @param array $objectsChunk The chunk of objects to convert.
     *
     * @return array Array of object data arrays.
     */
    private function convertChunkToArrays(array $objectsChunk): array
    {
        $objectsData = [];
        foreach ($objectsChunk as $object) {
            if (is_array($object) === true) {
                // Already an array from magic table.
                $objectsData[] = $object;
            } else {
                // ObjectEntity - get the object data.
                $objectsData[] = $object->getObject();
            }
        }

        return $objectsData;
    }//end convertChunkToArrays()

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
            $objectData = [];
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
