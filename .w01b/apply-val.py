p='lib/Service/Object/ValidationHandler.php'; s=open(p).read()
S='@spec openspec/specs/object-lifecycle/spec.md'
def block(start_marker, end_marker):
    a=s.index(start_marker); b=s.index(end_marker)+len(end_marker); return s[a:b]
load_sr = block("\t/**\n\t * Load schema and register entities by their IDs.", "\t}//end loadSchemaAndRegister()")
chunk_size = block("\t/**\n\t * Calculate the optimal chunk size", "\t}//end calculateChunkSize()")
region_start = s.index("\tpublic function validateAndSaveObjectsBySchema(")
region_end = s.index("\t/**\n\t * Convert a chunk of objects to plain arrays")
new = '''	public function validateAndSaveObjectsBySchema(
		int $registerId,
		int $schemaId,
		array $saveCallback,
		?int $limit = null,
		int $offset = 0,
	): array {
		$loaded = $this->loadSchemaAndRegister(registerId: $registerId, schemaId: $schemaId);
		if ($loaded === null) {
			return [
				'processed' => 0,
				'updated' => 0,
				'failed' => 0,
				'errors' => [['error' => 'Failed to load schema or register']],
			];
		}

		$run = new ValidationRun(registerId: $registerId, schemaId: $schemaId);
		$run->saveCallback = $saveCallback;
		$run->limit = $limit;
		$run->offset = $offset;

		// All objects are stored in magic tables.
		$this->logger->info(
			message: '[ValidationHandler] Loading objects for validation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_id' => $schemaId,
				'storage_type' => 'magic_table',
				'limit' => $limit,
				'offset' => $offset,
			]
		);

		$allObjects = $this->loadObjectsForValidation(loaded: $loaded, schemaId: $schemaId);
		if ($allObjects === null) {
			return [
				'processed' => 0,
				'updated' => 0,
				'failed' => 0,
				'total' => 0,
				'errors' => [['error' => 'Failed to load objects for validation']],
			];
		}

		$run->totalObjects = count($allObjects);
		$allObjects = $this->applyLimitOffset(allObjects: $allObjects, run: $run);
		$this->planChunks(allObjects: $allObjects, run: $run);
		$totals = $this->processAllChunks(allObjects: $allObjects, run: $run);

		unset($allObjects);
		gc_collect_cycles();

		$this->logger->info(
			message: '[ValidationHandler] Validation and save completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_id' => $schemaId,
				'total_in_schema' => $run->totalObjects,
				'objects_processed' => $totals['processed'],
				'objects_updated' => $totals['updated'],
				'objects_failed' => $totals['failed'],
			]
		);

		return [
			'processed' => $totals['processed'],
			'updated' => $totals['updated'],
			'failed' => $totals['failed'],
			'total' => $run->totalObjects,
			'errors' => [],
		];
	}//end validateAndSaveObjectsBySchema()

''' + load_sr + f'''

	/**
	 * Load objects for validation from magic tables.
	 *
	 * @param array{{schema: \\OCA\\OpenRegister\\Db\\Schema, register: \\OCA\\OpenRegister\\Db\\Register}} $loaded   The schema and register.
	 * @param int                                                                                $schemaId The schema ID (for logging).
	 *
	 * @return array|null Array of objects, or null on failure.
	 *
	 * {S}
	 */
	private function loadObjectsForValidation(array $loaded, int $schemaId): ?array {{
		try {{
			return $this->magicMapper->findAllInRegisterSchemaTable($loaded['register'], $loaded['schema']);
		}} catch (\\Exception $e) {{
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
		}}//end try
	}}//end loadObjectsForValidation()

	/**
	 * Apply the run's limit and offset to the objects array for API-level chunking.
	 *
	 * @param array        $allObjects The full array of objects.
	 * @param ValidationRun $run       The run.
	 *
	 * @return array The sliced array of objects.
	 *
	 * {S}
	 */
	private function applyLimitOffset(array $allObjects, ValidationRun $run): array {{
		if ($run->limit !== null || $run->offset > 0) {{
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
		}}

		return $allObjects;
	}}//end applyLimitOffset()

	/**
	 * Fix the run's chunk size and expected chunk count, and log the plan.
	 *
	 * @param array         $allObjects The objects to process.
	 * @param ValidationRun $run        The run.
	 *
	 * @return void
	 *
	 * {S}
	 */
	private function planChunks(array $allObjects, ValidationRun $run): void {{
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
	}}//end planChunks()

''' + chunk_size + f'''

	/**
	 * Process all validation chunks and return aggregated totals.
	 *
	 * Memory is collected after every chunk.
	 *
	 * @param array         $allObjects All objects to process.
	 * @param ValidationRun $run        The run.
	 *
	 * @return array{{processed: int, updated: int, failed: int}} Aggregated totals.
	 *
	 * {S}
	 */
	private function processAllChunks(array $allObjects, ValidationRun $run): array {{
		$totals = ['processed' => 0, 'updated' => 0, 'failed' => 0];

		for ($chunkOffset = 0; $chunkOffset < $run->objectsToProcess; $chunkOffset += $run->chunkSize) {{
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
		}}

		return $totals;
	}}//end processAllChunks()

	/**
	 * Bulk-save one chunk with validation and enrichment, and count the outcome.
	 *
	 * A failing chunk counts all its objects as failed, and the run continues.
	 *
	 * @param ValidationChunk $chunk The chunk.
	 * @param ValidationRun   $run   The run.
	 *
	 * @return array{{processed: int, updated: int, failed: int}} Chunk processing results.
	 *
	 * {S}
	 */
	private function processValidationChunk(ValidationChunk $chunk, ValidationRun $run): array {{
		$objectsData = $this->convertChunkToArrays(objectsChunk: $chunk->objects);

		// Only called from the chunk loop, so there is always at least one object to process.
		$progressPct = round(($chunk->offset / $run->objectsToProcess) * 100, 1);

		$this->logger->info(
			message: '[ValidationHandler] Processing validation chunk',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'schema_id' => $run->schemaId,
				'chunk' => $run->chunkLabel(chunkOffset: $chunk->offset),
				'chunk_size' => count($chunk->objects),
				'progress_pct' => $progressPct,
				'memory_usage' => round(memory_get_usage(true) / 1024 / 1024) . ' MB',
			]
		);

		try {{
			$statistics = ($this->bulkSave(objectsData: $objectsData, run: $run)['statistics'] ?? []);
			$chunkProcessed = count($objectsData);
			$chunkUpdated = ($statistics['saved'] ?? 0) + ($statistics['updated'] ?? 0);
			$chunkFailed = $statistics['failed'] ?? 0;

			$this->logger->info(
				message: '[ValidationHandler] Chunk validation completed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $run->schemaId,
					'chunk' => $run->chunkLabel(chunkOffset: $chunk->offset),
					'chunk_processed' => $chunkProcessed,
					'chunk_updated' => $chunkUpdated,
					'chunk_failed' => $chunkFailed,
					'total_progress' => $chunkProcessed . '/' . $run->objectsToProcess,
					'memory_after' => round(memory_get_usage(true) / 1024 / 1024) . ' MB',
				]
			);

			return [
				'processed' => $chunkProcessed,
				'updated' => $chunkUpdated,
				'failed' => $chunkFailed,
			];
		}} catch (\\Exception $e) {{
			$this->logger->error(
				message: '[ValidationHandler] Chunk validation failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'schema_id' => $run->schemaId,
					'chunk' => $run->chunkLabel(chunkOffset: $chunk->offset),
					'chunkOffset' => $chunk->offset,
					'error' => $e->getMessage(),
				]
			);

			return [
				'processed' => 0,
				'updated' => 0,
				'failed' => count($chunk->objects),
			];
		}}//end try
	}}//end processValidationChunk()

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
	 * {S}
	 */
	private function bulkSave(array $objectsData, ValidationRun $run): mixed {{
		$objectService = $run->saveCallback[0] ?? null;
		if ($objectService === null || method_exists($objectService, 'saveObjects') === false) {{
			throw new Exception('Cannot access bulk save method');
		}}

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
	}}//end bulkSave()

'''
s = s[:region_start] + new + s[region_end:]
open(p,'w').write(s)
