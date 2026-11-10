<?php

/**
 * OpenRegister Unified Vectorization Service
 *
 * Generic service for vectorizing any entity type (objects, files, etc).
 * Uses strategy pattern for entity-specific logic.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Search\Service;

use Exception;
use OCA\OpenRegister\Service\Vectorization\Strategies\VectorizationStrategyInterface;
use OCA\OpenRegister\Search\Service\Vectorization\VectorizationRun;
use OCA\OpenRegister\Search\Service\Vectorization\VectorizedEntity;
use OCA\OpenRegister\Search\Service\Vectorization\VectorEmbeddingsInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorChunkPosition;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorPayload;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorSubject;
use OCA\OpenRegister\Search\Service\Vectorization\EmbeddingProbe;
use OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest;

/**
 * VectorizationService
 *
 * Unified service for vectorizing entities using pluggable strategies.
 *
 * ARCHITECTURE:
 * - Generic vectorization logic (batch processing, error handling, progress)
 * - Entity-specific logic delegated to Strategy implementations
 * - Strategies handle: fetching entities, extracting text, preparing metadata
 *
 * BENEFITS:
 * - Single service to maintain
 * - Easy to add new entity types (just add a strategy)
 * - Consistent vectorization API across all entities
 * - Reduced code duplication
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 */
class VectorizationService implements VectorizationServiceInterface {

	/**
	 * The counts of an entity, or run, with nothing processed yet.
	 *
	 * @var array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private const NO_ITEMS = [
		'total_items' => 0,
		'vectorized' => 0,
		'failed' => 0,
		'errors' => [],
	];

	/**
	 * Vector embeddings coordinator
	 *
	 * @var VectorEmbeddingsInterface
	 */
	private VectorEmbeddingsInterface $vectorService;

	/**
	 * Logger
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Registered strategies by entity type
	 *
	 * @var array<string, VectorizationStrategyInterface>
	 */
	private array $strategies = [];

	/**
	 * Constructor
	 *
	 * @param VectorEmbeddingsInterface $vectorService Vector embeddings coordinator
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		VectorEmbeddingsInterface $vectorService,
		LoggerInterface $logger,
	) {
		$this->vectorService = $vectorService;
		$this->logger = $logger;
	}//end __construct()

	/**
	 * Register a vectorization strategy for an entity type
	 *
	 * @param string $entityType Entity type identifier
	 * @param VectorizationStrategyInterface $strategy Strategy implementation
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function registerStrategy(string $entityType, VectorizationStrategyInterface $strategy): void {
		$this->strategies[$entityType] = $strategy;
		$this->logger->debug(
			message: '[VectorizationService] Strategy registered',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityType' => $entityType,
				'strategyClass' => get_class($strategy),
			]
		);
	}//end registerStrategy()

	/**
	 * Vectorize entities in batch
	 *
	 * Generic batch vectorization that works for any entity type.
	 * Delegates entity-specific logic to registered strategy.
	 *
	 * @param string $entityType Entity type ('object', 'file', etc)
	 * @param array $options Strategy-specific options
	 *
	 * @return array Vectorization result with success, stats, and optional errors.
	 *
	 * @throws \Exception If strategy not found or vectorization fails.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function vectorizeBatch(string $entityType, array $options = []): array {
		$this->logger->info(
			message: '[VectorizationService] Starting batch vectorization',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityType' => $entityType,
				'options' => $options,
			]
		);

		// Get strategy for entity type.
		$strategy = $this->getStrategy(entityType: $entityType);

		try {
			// Strategy fetches entities to process.
			$entities = $strategy->fetchEntities($options);

			if ($entities === []) {
				return [
					'success' => true,
					'message' => 'No entities found to vectorize',
					'entity_type' => $entityType,
					'total_entities' => 0,
					'total_items' => 0,
					'vectorized' => 0,
					'failed' => 0,
				];
			}

			$tally = $this->vectorizeEntities(
				entities: $entities,
				run: new VectorizationRun(strategy: $strategy, options: $options, entityType: $entityType)
			);

			return $this->completedBatch(entityType: $entityType, tally: $tally);
		} catch (\Exception $e) {
			$this->logger->error(
				message: '[VectorizationService] Batch vectorization failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'entityType' => $entityType,
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}//end try
	}//end vectorizeBatch()

	/**
	 * Vectorize every entity of a batch and tally the outcome.
	 *
	 * @param array            $entities The entities to vectorize
	 * @param VectorizationRun $run      Strategy, processing options and entity type
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>, total_entities: int}
	 */
	private function vectorizeEntities(array $entities, VectorizationRun $run): array {
		$this->logger->info(
			message: '[VectorizationService] Processing entities',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityType' => $run->entityType,
				'entityCount' => count($entities),
			]
		);

		$tally = self::NO_ITEMS;
		foreach ($entities as $entity) {
			$tally = $this->addTally(
				tally: $tally,
				part: $this->vectorizeEntitySafely(entity: $entity, run: $run)
			);
		}

		$tally['total_entities'] = count($entities);
		return $tally;
	}//end vectorizeEntities()

	/**
	 * Log a completed batch and build its result.
	 *
	 * @param string $entityType Entity type
	 * @param array  $tally      The batch's tally, with how many entities it held
	 *
	 * @return array The vectorization result.
	 */
	private function completedBatch(string $entityType, array $tally): array {
		$this->logger->info(
			message: '[VectorizationService] Batch vectorization completed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'entityType' => $entityType,
				'totalEntities' => $tally['total_entities'],
				'totalItems' => $tally['total_items'],
				'vectorized' => $tally['vectorized'],
				'failed' => $tally['failed'],
			]
		);

		return [
			'success' => true,
			'message' => "Batch vectorization completed: {$tally['vectorized']} vectorized, {$tally['failed']} failed",
			'entity_type' => $entityType,
			'total_entities' => $tally['total_entities'],
			'total_items' => $tally['total_items'],
			'vectorized' => $tally['vectorized'],
			'failed' => $tally['failed'],
			'errors' => $tally['errors'],
		];
	}//end completedBatch()

	/**
	 * Vectorize a single entity
	 *
	 * An entity may produce multiple vectors (e.g., file with multiple chunks).
	 *
	 * @param mixed            $entity Entity to vectorize
	 * @param VectorizationRun $run    Strategy and processing options
	 *
	 * @return ((int|string)[][]|int)[] Processing results
	 *
	 * @psalm-return array{
	 *     total_items: int<0, max>,
	 *     vectorized: int<0, max>,
	 *     failed: int<0, max>,
	 *     errors: list<array{
	 *         entity_id: int|string,
	 *         error: string,
	 *         item_index: array-key
	 *     }>
	 * }
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function vectorizeEntity($entity, VectorizationRun $run): array {
		$strategy = $run->strategy;
		$target   = new VectorizedEntity(entity: $entity, strategy: $strategy, entityId: $strategy->getEntityIdentifier($entity));

		// Strategy extracts vectorization items from entity.
		// For objects: usually 1 item (serialized object).
		// For files: N items (one per chunk).
		$items = $strategy->extractVectorizationItems($entity);

		if ($items === []) {
			return self::NO_ITEMS;
		}

		// Batch processing for efficiency; otherwise one item at a time.
		if ($this->runsInBatches(items: $items, options: $run->options) === true) {
			$tally = $this->vectorizeInBatches(target: $target, batches: array_chunk($items, $run->options['batch_size'] ?? 50));
		} else {
			$tally = $this->vectorizeEachItem(target: $target, items: $items);
		}

		$tally['total_items'] = count($items);
		return $tally;
	}//end vectorizeEntity()

	/**
	 * Whether an entity's items are embedded in batches: parallel mode, a batch size above one and more than one item.
	 *
	 * @param array $items   The entity's items.
	 * @param array $options Processing options.
	 *
	 * @return bool True for batched embedding.
	 */
	private function runsInBatches(array $items, array $options): bool {
		$mode      = $options['mode'] ?? 'serial';
		$batchSize = $options['batch_size'] ?? 50;

		return $mode === 'parallel' && $batchSize > 1 && count($items) > 1;
	}//end runsInBatches()

	/**
	 * Embed an entity's items in batches and tally them.
	 *
	 * @param VectorizedEntity  $target  The entity, its strategy and identifier.
	 * @param array<int, array> $batches The item batches.
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function vectorizeInBatches(VectorizedEntity $target, array $batches): array {
		$tally = self::NO_ITEMS;
		foreach ($batches as $batch) {
			$tally = $this->addTally(tally: $tally, part: $this->vectorizeItemBatch(target: $target, batch: $batch));
		}

		return $tally;
	}//end vectorizeInBatches()

	/**
	 * Embed an entity's items one at a time and tally them.
	 *
	 * @param VectorizedEntity $target The entity, its strategy and identifier.
	 * @param array            $items  The items.
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function vectorizeEachItem(VectorizedEntity $target, array $items): array {
		$tally = self::NO_ITEMS;
		foreach ($items as $position => $item) {
			$tally = $this->addTally(tally: $tally, part: $this->vectorizeSerially(target: $target, item: [$position, $item]));
		}

		return $tally;
	}//end vectorizeEachItem()

	/**
	 * Vectorize one entity, turning a failure into one listed error.
	 *
	 * @param mixed            $entity Entity to vectorize
	 * @param VectorizationRun $run    Strategy, processing options and entity type
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function vectorizeEntitySafely($entity, VectorizationRun $run): array {
		try {
			return $this->vectorizeEntity(entity: $entity, run: $run);
		} catch (\Exception $e) {
			$entityId = $run->strategy->getEntityIdentifier($entity);
			$this->logger->error(
				message: '[VectorizationService] Failed to vectorize entity',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'entityType' => $run->entityType,
					'entityId' => $entityId,
					'error' => $e->getMessage(),
				]
			);
			$failure = self::NO_ITEMS;
			$failure['errors'][] = [
				'entity_id' => $entityId,
				'error' => $e->getMessage(),
			];
			return $failure;
		}//end try
	}//end vectorizeEntitySafely()

	/**
	 * Add one part's counts and errors to a running tally.
	 *
	 * @param array $tally The running tally.
	 * @param array $part  The part to add.
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function addTally(array $tally, array $part): array {
		$tally['total_items'] += $part['total_items'];
		$tally['vectorized']  += $part['vectorized'];
		$tally['failed']      += $part['failed'];
		$tally['errors']       = array_merge($tally['errors'], $part['errors']);
		return $tally;
	}//end addTally()

	/**
	 * Embed and store one item; a failure is one listed error.
	 *
	 * @param VectorizedEntity               $target The entity, its strategy and identifier
	 * @param array{0: int|string, 1: array} $item   The item's index and the item
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function vectorizeSerially(VectorizedEntity $target, array $item): array {
		$part = self::NO_ITEMS;
		try {
			$embeddingData = $this->vectorService->generateEmbedding($item[1]['text']);
			$this->storeVector(target: $target, embedded: ['item' => $item[1], 'embeddingData' => $embeddingData]);
			$part['vectorized'] = 1;
		} catch (\Exception $e) {
			$part['failed']   = 1;
			$part['errors'][] = [
				'entity_id' => $target->entityId,
				'item_index' => $item[0],
				'error' => $e->getMessage(),
			];
		}

		return $part;
	}//end vectorizeSerially()

	/**
	 * Embed one batch of items in one request and store each embedding.
	 *
	 * An item without an embedding is a listed failure; a failing request
	 * (or store) counts the whole batch as failed and is only logged.
	 *
	 * @param VectorizedEntity  $target The entity, its strategy and identifier
	 * @param array<int, array> $batch  The items
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function vectorizeItemBatch(VectorizedEntity $target, array $batch): array {
		$part = self::NO_ITEMS;
		try {
			$texts      = array_map(fn (array $item): string => $item['text'], $batch);
			$embeddings = $this->vectorService->generateBatchEmbeddings($texts);

			foreach ($batch as $index => $item) {
				$part = $this->addTally(
					tally: $part,
					part: $this->storeBatchItem(
						target: $target,
						embedded: ['index' => $index, 'item' => $item, 'embeddingData' => ($embeddings[$index] ?? null)]
					)
				);
			}
		} catch (\Exception $e) {
			$part['failed'] += count($batch);
			$this->logger->error(
				message: '[VectorizationService] Batch processing failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'entityId' => $target->entityId,
					'error' => $e->getMessage(),
				]
			);
		}//end try

		return $part;
	}//end vectorizeItemBatch()

	/**
	 * Store one item of a batch, or list it as failed when it has no embedding.
	 *
	 * @param VectorizedEntity                                     $target   The entity, its strategy and identifier
	 * @param array{index: int, item: array, embeddingData: mixed} $embedded The item, its index and its batch embedding
	 *
	 * @return array{total_items: int, vectorized: int, failed: int, errors: array<int, array<string, mixed>>}
	 */
	private function storeBatchItem(VectorizedEntity $target, array $embedded): array {
		$part = self::NO_ITEMS;
		$embeddingData = $embedded['embeddingData'];
		if ($embeddingData !== null && (($embeddingData['embedding'] ?? null) !== null)) {
			$this->storeVector(target: $target, embedded: $embedded);
			$part['vectorized']++;
			return $part;
		}

		$part['failed']++;
		$part['errors'][] = [
			'entity_id' => $target->entityId,
			'item_index' => $embedded['index'],
			'error' => $this->embeddingError(embeddingData: $embeddingData),
		];
		return $part;
	}//end storeBatchItem()

	/**
	 * The error a batch embedding carries, or the generic message.
	 *
	 * EmbeddingData may contain an 'error' key even if not in the type definition.
	 *
	 * @param mixed $embeddingData The batch embedding entry.
	 *
	 * @return mixed The error.
	 */
	private function embeddingError(mixed $embeddingData): mixed {
		if (is_array($embeddingData) === true && array_key_exists('error', $embeddingData) === true) {
			return $embeddingData['error'];
		}

		return 'Embedding generation failed';
	}//end embeddingError()

	/**
	 * Store a vector using strategy-provided metadata
	 *
	 * @param VectorizedEntity                     $target   Original entity and its strategy
	 * @param array{item: array, embeddingData: array} $embedded The vectorization item (with text) and its embedding result
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function storeVector(VectorizedEntity $target, array $embedded): void {
		$metadata = $target->strategy->prepareVectorMetadata(entity: $target->entity, item: $embedded['item']);
		$this->vectorService->storeVector(vector: $this->storedVector(metadata: $metadata, embeddingData: $embedded['embeddingData']));
	}//end storeVector()

	/**
	 * The vector to store for one item: its subject and chunk position from the strategy's metadata, its payload from the embedding.
	 *
	 * @param array $metadata      The strategy's vector metadata for the item
	 * @param array $embeddingData Embedding result
	 *
	 * @return StoredVector
	 */
	private function storedVector(array $metadata, array $embeddingData): StoredVector {
		return new StoredVector(
			subject: new VectorSubject(entityType: $metadata['entity_type'], entityId: $metadata['entity_id']),
			payload: new VectorPayload(
				embedding: $embeddingData['embedding'],
				model: $embeddingData['model'],
				dimensions: $embeddingData['dimensions']
			),
			position: new VectorChunkPosition(
				chunkIndex: $metadata['chunk_index'] ?? 0,
				totalChunks: $metadata['total_chunks'] ?? 1,
				chunkText: $metadata['chunk_text'] ?? null
			),
			metadata: $metadata['additional_metadata'] ?? []
		);
	}//end storedVector()

	/**
	 * Get strategy for entity type
	 *
	 * @param string $entityType Entity type identifier
	 *
	 * @return VectorizationStrategyInterface
	 *
	 * @throws \Exception If strategy not registered
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function getStrategy(string $entityType): VectorizationStrategyInterface {
		if (isset($this->strategies[$entityType]) === false) {
			throw new Exception("No vectorization strategy registered for entity type: {$entityType}");
		}

		return $this->strategies[$entityType];
	}//end getStrategy()

	// =============================================================================
	// PUBLIC API FACADE METHODS - Delegate to VectorEmbeddingService
	// =============================================================================
	// These methods provide a single entry point for all vector operations.
	// Other services should call VectorizationService instead of
	// VectorEmbeddingService directly.
	// =============================================================================

	/**
	 * Generate embedding for a single text
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @param string $text Text to embed
	 * @param string|null $provider Embedding provider (null = use default from settings)
	 *
	 * @return (float[]|int|string)[] Embedding data
	 *
	 * @throws \Exception If embedding generation fails
	 *
	 * @psalm-return array{embedding: array<float>, model: string, dimensions: int<0, max>}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function generateEmbedding(string $text, ?string $provider = null): array {
		return $this->vectorService->generateEmbedding(text: $text, provider: $provider);
	}//end generateEmbedding()

	/**
	 * Perform semantic similarity search
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @param string $query Query text to search for
	 * @param int $limit Maximum number of results
	 * @param array $filters Additional filters (entity_type, etc.)
	 * @param string|null $provider Embedding provider
	 *
	 * @return array<int,array<string,mixed>> Search results
	 *
	 * @throws \Exception If search fails
	 *
	 * @spec exclude One-line facade delegation to VectorEmbeddings::semanticSearch; no logic of its own.
	 */
	public function semanticSearch(
		string $query,
		int $limit = 10,
		array $filters = [],
		?string $provider = null,
	): array {
		return $this->vectorService->semanticSearch(query: $query, limit: $limit, filters: $filters, provider: $provider);
	}//end semanticSearch()

	/**
	 * Perform hybrid search combining keyword and semantic (vector) results
	 *
	 * Delegates to VectorEmbeddings. Caller supplies keyword search results;
	 * vector search runs against the PostgreSQL database.
	 *
	 * @param HybridSearchRequest $request The query text, the pre-fetched keyword results to fuse, the maximum
	 *                                     results and the weight per search type.
	 *
	 * @return array Hybrid search results with combined scores and source breakdown.
	 *
	 * @throws \Exception If hybrid search fails.
	 *
	 * @spec exclude One-line facade delegation to VectorEmbeddings::hybridSearch; no logic of its own.
	 */
	public function hybridSearch(HybridSearchRequest $request): array {
		return $this->vectorService->hybridSearch(request: $request);
	}//end hybridSearch()

	/**
	 * Get vector statistics
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @return array Vector statistics with totals and breakdowns by type and model.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getVectorStats(): array {
		return $this->vectorService->getVectorStats();
	}//end getVectorStats()

	/**
	 * Test embedding generation with custom configuration
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @param EmbeddingProbe $probe The provider ('openai', 'fireworks', 'ollama'), its configuration and the text to embed.
	 *
	 * @return ((float[]|int|mixed|string)[]|bool|string)[] Test results
	 *
	 * @psalm-return array{success: bool, error?: string, message: string,
	 *     data?: array{provider: string, model: 'unknown'|mixed,
	 *     vectorLength: int<0, max>, sampleValues: array<float>,
	 *     testText: string}}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function testEmbedding(EmbeddingProbe $probe): array {
		return $this->vectorService->testEmbedding(probe: $probe);
	}//end testEmbedding()

	/**
	 * Check if embedding model has changed since vectors were created
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @return (array|bool|int|mixed|string)[] Model mismatch information
	 *
	 * @psalm-return array{has_vectors: bool, mismatch: bool, error?: string,
	 *     message?: string, current_model?: mixed,
	 *     existing_models?: list{0?: mixed,...}, total_vectors?: int,
	 *     null_model_count?: int, mismatched_models?: list<mixed>}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function checkEmbeddingModelMismatch(): array {
		return $this->vectorService->checkEmbeddingModelMismatch();
	}//end checkEmbeddingModelMismatch()

	/**
	 * Clear all embeddings from the database
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @return (bool|int|string)[] Deletion results
	 *
	 * @psalm-return array{success: bool, error?: string, message: string, deleted?: int}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function clearAllEmbeddings(): array {
		return $this->vectorService->clearAllEmbeddings();
	}//end clearAllEmbeddings()
}//end class
