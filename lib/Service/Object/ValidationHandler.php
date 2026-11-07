<?php

/**
 * ValidationHandler
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
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
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 * @version  1.0.0
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Validation requires multiple exception and entity dependencies
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Validation orchestration requires multiple validation strategy methods
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ValidationHandler {
	/**
	 * Constructor for ValidationHandler.
	 *
	 * @param ValidateObjectInterface $validateHandler Handler for object validation.
	 * @param MagicMapperInterface $objectEntityMapper Mapper for object entities.
	 * @param RegisterMapperInterface $registerMapper Mapper for registers.
	 * @param SchemaMapperInterface $schemaMapper Mapper for schemas.
	 * @param MagicMapperInterface $magicMapper Mapper for magic tables.
	 * @param LoggerInterface $logger Logger for logging operations.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ValidateObjectInterface $validateHandler,
		private readonly MagicMapperInterface $objectEntityMapper,
		private readonly RegisterMapperInterface $registerMapper,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly MagicMapperInterface $magicMapper,
		private readonly LoggerInterface $logger,
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
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handleValidationException(ValidationException|CustomValidationException $exception): mixed {
		return $this->validateHandler->handleValidationException($exception);
	}//end handleValidationException()

	/**
	 * Validates all objects for a given schema.
	 *
	 * This method retrieves all objects for a schema and validates them
	 * without actually saving. It returns arrays of valid and invalid objects.
	 *
	 * @param int $schemaId The schema ID to validate objects for.
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
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validateObjectsBySchema(int $schemaId, callable $saveCallback): array {
		$result = ['valid' => [], 'invalid' => []];

		// The mapper's findBySchema() bypasses RBAC and multi-tenancy.
		foreach ($this->objectEntityMapper->findBySchema($schemaId) as $object) {
			$data = $object->getObject();
			$error = $this->silentSaveError(
				object: $object,
				save: static fn () => $saveCallback($data, [], $object->getRegister(), $schemaId, $object->getUuid(), false, false, true)
			);
			$entry = ['id' => $object->getId(), 'uuid' => $object->getUuid(), 'name' => $object->getName(), 'data' => $data];
			if ($error === null) {
				$result['valid'][] = $entry;
				continue;
			}

			$result['invalid'][] = $entry + ['error' => $error];
		}

		return $result;
	}//end validateObjectsBySchema()

	/**
	 * Run a silent save (no RBAC, no multitenancy) of one object and return why it failed.
	 *
	 * @param ObjectEntity $object The object, for the log line
	 * @param callable     $save   The save of the object's data
	 *
	 * @return string|null The validation message, 'Unexpected error: ...' for any other failure, or null when valid
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function silentSaveError(ObjectEntity $object, callable $save): ?string {
		try {
			$save();
			return null;
		} catch (ValidationException|CustomValidationException $e) {
			return $e->getMessage();
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ValidationHandler] Unexpected error during validation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'app' => 'openregister', 'objectId' => $object->getId(), 'error' => $e->getMessage()]
			);
			return 'Unexpected error: ' . $e->getMessage();
		}
	}//end silentSaveError()

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
	 * Returns statistics about the validation and save operation:
	 * - processed: Total number of objects processed in this batch
	 * - updated: Number of objects successfully updated
	 * - failed: Number of objects that failed validation/save
	 * - total: Total number of objects in the schema (for pagination)
	 * - errors: Array of error details (currently empty)
	 *
	 * @param int $registerId The ID of the register containing the schema
	 * @param int $schemaId The ID of the schema whose objects should be validated
	 * @param array $saveCallback Array-callable to save objects, e.g. [$objectService, 'saveObject']
	 * @param int|null $limit Maximum number of objects to process (null = all)
	 * @param int $offset Number of objects to skip before processing
	 *
	 * @return array{processed: int, updated: int, failed: int, total: int, errors: array}
	 *
	 * @throws \Exception If schema/register loading fails or object retrieval fails
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function validateAndSaveObjectsBySchema(int $registerId, int $schemaId, array $saveCallback, ?int $limit = null, int $offset = 0): array {
		$loaded = $this->loadSchemaAndRegister(registerId: $registerId, schemaId: $schemaId);
		if ($loaded === null) {
			return ['processed' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [['error' => 'Failed to load schema or register']]];
		}

		$run = new ValidationRun(registerId: $registerId, schemaId: $schemaId);
		$run->saveCallback = $saveCallback;
		$run->limit        = $limit;
		$run->offset       = $offset;

		return $this->validateLoaded(loaded: $loaded, run: $run);
	}//end validateAndSaveObjectsBySchema()

	/**
	 * Load the schema's objects, then validate and save them chunk by chunk.
	 *
	 * @param array         $loaded The loaded schema and register.
	 * @param ValidationRun $run    The run: schema, save callback, limit and offset.
	 *
	 * @return array{processed: int, updated: int, failed: int, total: int, errors: array}
	 */
	private function validateLoaded(array $loaded, ValidationRun $run): array {
		// All objects are stored in magic tables.
		$this->logger->info(
			message: '[ValidationHandler] Loading objects for validation',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'schema_id' => $run->schemaId,
				'storage_type' => 'magic_table', 'limit' => $run->limit, 'offset' => $run->offset,
			]
		);

		$allObjects = $this->loadObjectsForValidation(loaded: $loaded, schemaId: $run->schemaId);
		if ($allObjects === null) {
			$errors = [['error' => 'Failed to load objects for validation']];
			return ['processed' => 0, 'updated' => 0, 'failed' => 0, 'total' => 0, 'errors' => $errors];
		}

		$run->totalObjects = count($allObjects);
		$allObjects        = $this->applyLimitOffset(allObjects: $allObjects, run: $run);
		$this->planChunks(allObjects: $allObjects, run: $run);
		return $this->completed(totals: $this->processAllChunks(allObjects: $allObjects, run: $run), run: $run);
	}//end validateLoaded()

	/**
	 * Log and answer the totals of a finished run.
	 *
	 * @param array{processed: int, updated: int, failed: int} $totals The chunk totals.
	 * @param ValidationRun                                     $run    The run.
	 *
	 * @return array{processed: int, updated: int, failed: int, total: int, errors: array}
	 */
	private function completed(array $totals, ValidationRun $run): array {
		gc_collect_cycles();
		$this->logger->info(
			message: '[ValidationHandler] Validation and save completed',
			context: [
				'file' => __FILE__, 'line' => __LINE__, 'schema_id' => $run->schemaId, 'total_in_schema' => $run->totalObjects,
				'objects_processed' => $totals['processed'], 'objects_updated' => $totals['updated'], 'objects_failed' => $totals['failed'],
			]
		);

		$result = ['processed' => $totals['processed'], 'updated' => $totals['updated'], 'failed' => $totals['failed']];
		return $result + ['total' => $run->totalObjects, 'errors' => []];
	}//end completed()

	/**
	 * Load schema and register entities by their IDs.
	 *
	 * @param int $registerId The register ID.
	 * @param int $schemaId The schema ID.
	 *
	 * @return array|null Array with 'schema' and 'register' keys, or null on failure.
	 *
	 * @psalm-return   array{schema: \OCA\OpenRegister\Db\Schema, register: \OCA\OpenRegister\Db\Register}|null
	 * @phpstan-return array{schema: \OCA\OpenRegister\Db\Schema, register: \OCA\OpenRegister\Db\Register}|null
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadSchemaAndRegister(int $registerId, int $schemaId): ?array {
		try {
			$schema = $this->schemaMapper->find($schemaId);
			$register = $this->registerMapper->find($registerId);

			return [
				'schema' => $schema,
				'register' => $register,
			];
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ValidationHandler] Failed to load schema or register',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'register_id' => $registerId,
					'schema_id' => $schemaId,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try
	}//end loadSchemaAndRegister()

	/**
	 * Load objects for validation from magic tables.
	 *
	 * @param array{schema: \OCA\OpenRegister\Db\Schema, register: \OCA\OpenRegister\Db\Register} $loaded   The schema and register.
	 * @param int                                                                                $schemaId The schema ID (for logging).
	 *
	 * @return array|null Array of objects, or null on failure.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function loadObjectsForValidation(array $loaded, int $schemaId): ?array {
		try {
			return $this->magicMapper->findAllInRegisterSchemaTable($loaded['register'], $loaded['schema']);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ValidationHandler] Failed to get objects from magic table',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $schemaId,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}//end try
	}//end loadObjectsForValidation()

	/**
	 * Apply the run's limit and offset to the objects array for API-level chunking.
	 *
	 * @param array        $allObjects The full array of objects.
	 * @param ValidationRun $run       The run.
	 *
	 * @return array The sliced array of objects.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function applyLimitOffset(array $allObjects, ValidationRun $run): array {
		if ($run->limit !== null || $run->offset > 0) {
			$allObjects = array_slice($allObjects, $run->offset, $run->limit);
			$this->logger->info(
				message: '[ValidationHandler] Applied limit/offset for chunked validation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $run->schemaId,
					'total_objects' => $run->totalObjects,
					'offset' => $run->offset,
					'limit' => $run->limit,
					'objects_to_process' => count($allObjects),
				]
			);
		}

		return $allObjects;
	}//end applyLimitOffset()

	/**
	 * Fix the run's chunk size and expected chunk count, and log the plan.
	 *
	 * @param array         $allObjects The objects to process.
	 * @param ValidationRun $run        The run.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function planChunks(array $allObjects, ValidationRun $run): void {
		$objectsToProcess = count($allObjects);
		$run->plan(objectsToProcess: $objectsToProcess, chunkSize: $this->calculateChunkSize(objectsToProcess: $objectsToProcess));

		$this->logger->info(
			message: '[ValidationHandler] Starting chunked validation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_id' => $run->schemaId,
				'total_objects' => $run->totalObjects,
				'objects_to_process' => $run->objectsToProcess,
				'chunk_size' => $run->chunkSize,
				'estimated_chunks' => $run->estimatedChunks,
			]
		);
	}//end planChunks()

	/**
	 * Calculate the optimal chunk size based on the number of objects to process.
	 *
	 * @param int $objectsToProcess The total number of objects to process.
	 *
	 * @return int The chunk size to use.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function calculateChunkSize(int $objectsToProcess): int {
		if ($objectsToProcess <= 1000) {
			return $objectsToProcess;
			// Process all at once.
		} elseif ($objectsToProcess <= 10000) {
			return 2000;
		} elseif ($objectsToProcess <= 50000) {
			return 3000;
		} elseif ($objectsToProcess <= 200000) {
			return 2000;
			// Smaller chunks for better memory management.
		}

		return 1000;
		// Very small chunks for 671K+ datasets.
	}//end calculateChunkSize()

	/**
	 * Process all validation chunks and return aggregated totals.
	 *
	 * Memory is collected after every chunk.
	 *
	 * @param array         $allObjects All objects to process.
	 * @param ValidationRun $run        The run.
	 *
	 * @return array{processed: int, updated: int, failed: int} Aggregated totals.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function processAllChunks(array $allObjects, ValidationRun $run): array {
		$totals = ['processed' => 0, 'updated' => 0, 'failed' => 0];

		for ($chunkOffset = 0; $chunkOffset < $run->objectsToProcess; $chunkOffset += $run->chunkSize) {
			$objectsChunk = array_slice($allObjects, $chunkOffset, $run->chunkSize);
			$chunkResult = $this->processValidationChunk(
				chunk: new ValidationChunk(objects: $objectsChunk, offset: $chunkOffset),
				run: $run
			);

			$totals['processed'] += $chunkResult['processed'];
			$totals['updated'] += $chunkResult['updated'];
			$totals['failed'] += $chunkResult['failed'];

			unset($objectsChunk);
			gc_collect_cycles();
		}

		return $totals;
	}//end processAllChunks()

	/**
	 * Bulk-save one chunk with validation and enrichment, and count the outcome.
	 *
	 * A failing chunk counts all its objects as failed, and the run continues.
	 *
	 * @param ValidationChunk $chunk The chunk.
	 * @param ValidationRun   $run   The run.
	 *
	 * @return array{processed: int, updated: int, failed: int} Chunk processing results.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function processValidationChunk(ValidationChunk $chunk, ValidationRun $run): array {
		$objectsData = $this->convertChunkToArrays(objectsChunk: $chunk->objects);
		$context = $this->chunkLogContext(chunk: $chunk, run: $run);
		$started = $this->chunkStartDetails(chunk: $chunk, run: $run);
		$this->logger->info(message: '[ValidationHandler] Processing validation chunk', context: $context + $started);

		try {
			$counts = $this->bulkSaveCounts(objectsData: $objectsData, run: $run);
			$done = $this->chunkDoneDetails(counts: $counts, run: $run);
			$this->logger->info(message: '[ValidationHandler] Chunk validation completed', context: $context + $done);
			return $counts;
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[ValidationHandler] Chunk validation failed',
				context: $context + ['chunkOffset' => $chunk->offset, 'error' => $e->getMessage()]
			);
			return ['processed' => 0, 'updated' => 0, 'failed' => count($chunk->objects)];
		}
	}//end processValidationChunk()

	/**
	 * The log context every line about one chunk starts with.
	 *
	 * @param ValidationChunk $chunk The chunk.
	 * @param ValidationRun   $run   The run.
	 *
	 * @return array{file: string, line: int, schema_id: int, chunk: string}
	 */
	private function chunkLogContext(ValidationChunk $chunk, ValidationRun $run): array {
		return ['file' => __FILE__, 'line' => __LINE__, 'schema_id' => $run->schemaId, 'chunk' => $run->chunkLabel(chunkOffset: $chunk->offset)];
	}//end chunkLogContext()

	/**
	 * Size, progress and memory logged when a chunk starts.
	 *
	 * Only called from the chunk loop, so there is always at least one object to process.
	 *
	 * @param ValidationChunk $chunk The chunk.
	 * @param ValidationRun   $run   The run.
	 *
	 * @return array{chunk_size: int, progress_pct: float, memory_usage: string}
	 */
	private function chunkStartDetails(ValidationChunk $chunk, ValidationRun $run): array {
		return [
			'chunk_size' => count($chunk->objects),
			'progress_pct' => round(($chunk->offset / $run->objectsToProcess) * 100, 1),
			'memory_usage' => round(memory_get_usage(true) / 1024 / 1024) . ' MB',
		];
	}//end chunkStartDetails()

	/**
	 * Bulk-save one chunk and count it: processed objects, saved plus updated, failed.
	 *
	 * @param array         $objectsData The chunk's object data.
	 * @param ValidationRun $run         The run.
	 *
	 * @return array{processed: int, updated: int, failed: int}
	 */
	private function bulkSaveCounts(array $objectsData, ValidationRun $run): array {
		$statistics = ($this->bulkSave(objectsData: $objectsData, run: $run)['statistics'] ?? []);

		return [
			'processed' => count($objectsData),
			'updated' => ($statistics['saved'] ?? 0) + ($statistics['updated'] ?? 0),
			'failed' => $statistics['failed'] ?? $statistics['invalid'] ?? 0,
		];
	}//end bulkSaveCounts()

	/**
	 * Counts, overall progress and memory logged when a chunk is done.
	 *
	 * @param array{processed: int, updated: int, failed: int} $counts The chunk's counts.
	 * @param ValidationRun                                    $run    The run.
	 *
	 * @return array<string, int|string>
	 */
	private function chunkDoneDetails(array $counts, ValidationRun $run): array {
		return [
			'chunk_processed' => $counts['processed'],
			'chunk_updated' => $counts['updated'],
			'chunk_failed' => $counts['failed'],
			'total_progress' => $counts['processed'] . '/' . $run->objectsToProcess,
			'memory_after' => round(memory_get_usage(true) / 1024 / 1024) . ' MB',
		];
	}//end chunkDoneDetails()

	/**
	 * Hand one chunk to the bulk save of the run's save callback, without RBAC, multitenancy or events.
	 *
	 * @param array<int, mixed> $objectsData The chunk as arrays.
	 * @param ValidationRun     $run         The run.
	 *
	 * @return mixed The bulk save result.
	 *
	 * @throws Exception When the callback's object has no bulk save.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function bulkSave(array $objectsData, ValidationRun $run): mixed {
		$objectService = $run->saveCallback[0] ?? null;
		if ($objectService === null || method_exists($objectService, 'saveObjects') === false) {
			throw new Exception('Cannot access bulk save method');
		}

		// Validation and enrichment update metadata like _name; events are off for performance.
		return $objectService->saveObjects(
			objects: $objectsData,
			register: $run->registerId,
			schema: $run->schemaId,
			_rbac: false,
			_multitenancy: false,
			validation: true,
			events: false,
			deduplicateIds: false,
			enrich: true
		);
	}//end bulkSave()

	/**
	 * Convert a chunk of objects to plain arrays for bulk processing.
	 *
	 * Handles both magic table arrays (already arrays) and ObjectEntity instances.
	 *
	 * @param array $objectsChunk The chunk of objects to convert.
	 *
	 * @return array Array of object data arrays.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function convertChunkToArrays(array $objectsChunk): array {
		$objectsData = [];
		foreach ($objectsChunk as $object) {
			if (is_array($object) === true) {
				$objectsData[] = $object;
				continue;
			}

			$objectsData[] = $object->getObject();
		}

		return $objectsData;
	}//end convertChunkToArrays()

	/**
	 * Apply inversedBy filter to query filters.
	 *
	 * This method resolves inversedBy relationships in filters and returns the matching object IDs.
	 * It handles nested property filters (using underscore delimiters) and performs reverse lookups.
	 *
	 * @param array $_filters Query filters to process (passed by reference).
	 *
	 * @return array|null Matching object IDs or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function applyInversedByFilter(array &$_filters): ?array {
		// This method requires additional dependencies - placeholder for now.
		// Full implementation requires SchemaMapper, ObjectService->findAll, and Dot utilities.
		return [];
	}//end applyInversedByFilter()
}//end class
