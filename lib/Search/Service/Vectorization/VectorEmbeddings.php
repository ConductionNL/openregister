<?php

/**
 * Vector Embeddings Coordinator
 *
 * Main entry point for all vector embedding operations.
 * Coordinates handlers for generation, storage, search, and statistics.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization;

use Exception;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
use OCA\OpenRegister\Kernel\Contracts\EmbeddingGeneratorInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorEmbeddingHandlers;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\VectorQuery;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\KeywordFusion;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * VectorEmbeddings
 *
 * Facade/Coordinator for all vector embedding operations.
 * Delegates to specialized handlers for generation, storage, search, and statistics.
 *
 * ARCHITECTURE:
 * - This is the public API for vector operations
 * - All operations are delegated to specialized handlers
 * - Handlers are: EmbeddingGeneratorHandler, VectorStorageHandler, VectorSearchHandler, VectorStatsHandler
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization
 */
class VectorEmbeddings implements VectorEmbeddingsInterface, EmbeddingGeneratorInterface {
	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 * @param SettingsServiceInterface $settingsService Settings service
	 * @param VectorEmbeddingHandlers $handlers The generation, storage, search and statistics handlers
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly SettingsServiceInterface $settingsService,
		private readonly VectorEmbeddingHandlers $handlers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	// =============================================================================
	// EMBEDDING GENERATION
	// =============================================================================

	/**
	 * Generate embedding for a single text
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
		$config = $this->getEmbeddingConfig(provider: $provider);

		$this->logger->debug(
			message: '[VectorEmbeddings] Generating embedding',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'text_length' => strlen($text),
				'provider' => $config['provider'],
				'model' => $config['model'],
			]
		);

		try {
			$generator = $this->handlers->generator->getGenerator($config);
			$embedding = $generator->embedText($text);
			$dimensions = count($embedding);

			$this->logger->debug(
				message: '[VectorEmbeddings] Embedding generated successfully',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'dimensions' => $dimensions,
					'model' => $config['model'],
				]
			);

			return [
				'embedding' => $embedding,
				'model' => $config['model'],
				'dimensions' => $dimensions,
			];
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorEmbeddings] Failed to generate embedding',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'provider' => $config['provider'],
					'text_length' => strlen($text),
				]
			);
			throw new Exception('Embedding generation failed: ' . $e->getMessage());
		}//end try
	}//end generateEmbedding()

	/**
	 * Generate embedding with custom configuration (for testing)
	 *
	 * @param string $text Text to embed
	 * @param array $config Custom configuration array
	 *
	 * @return float[] Embedding vector (array of floats)
	 *
	 * @throws \Exception If embedding generation fails
	 *
	 * @psalm-return array<float>
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function generateEmbeddingWithCustomConfig(string $text, array $config): array {
		$this->logCustomConfigStart(text: $text, config: $config);

		try {
			$normalizedConfig = $this->normalizeCustomConfig(config: $config);
			$this->assertUsableCustomConfig(normalizedConfig: $normalizedConfig);

			return $this->embedWithCustomConfig(text: $text, normalizedConfig: $normalizedConfig);
		} catch (Exception $e) {
			throw $this->customConfigFailure(
				error: $e->getMessage(),
				attempt: ['provider' => $config['provider'] ?? 'unknown', 'text_length' => strlen($text)]
			);
		}
	}//end generateEmbeddingWithCustomConfig()

	/**
	 * Log the start of a custom-config embedding.
	 *
	 * @param string $text   Text to embed
	 * @param array  $config Custom configuration array
	 *
	 * @return void
	 */
	private function logCustomConfigStart(string $text, array $config): void {
		$this->logger->debug(
			message: '[VectorEmbeddings] Generating embedding with custom config',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'text_length' => strlen($text),
				'provider' => $config['provider'] ?? 'unknown',
				'model' => $config['model'] ?? 'unknown',
			]
		);
	}//end logCustomConfigStart()

	/**
	 * The custom configuration in the generator's format.
	 *
	 * @param array $config Custom configuration array
	 *
	 * @return array{provider: mixed, model: mixed, api_key: mixed, base_url: mixed} The normalised config.
	 */
	private function normalizeCustomConfig(array $config): array {
		return [
			'provider' => $config['provider'],
			'model' => $config['model'] ?? null,
			'api_key' => $config['apiKey'] ?? null,
			'base_url' => $config['baseUrl'] ?? $config['url'] ?? null,
		];
	}//end normalizeCustomConfig()

	/**
	 * Refuse a normalised config without a provider, a model, or the API key its provider needs.
	 *
	 * @param array $normalizedConfig The normalised config.
	 *
	 * @return void
	 *
	 * @throws Exception When a required field is missing.
	 */
	private function assertUsableCustomConfig(array $normalizedConfig): void {
		if (empty($normalizedConfig['provider']) === true) {
			throw new Exception('Provider is required');
		}

		if (empty($normalizedConfig['model']) === true) {
			throw new Exception('Model is required');
		}

		// Validate API keys for providers that need them.
		if (in_array($normalizedConfig['provider'], ['openai', 'fireworks'], true) === true
			&& empty($normalizedConfig['api_key']) === true
		) {
			throw new Exception("API key is required for {$normalizedConfig['provider']}");
		}
	}//end assertUsableCustomConfig()

	/**
	 * Create the embedding generator for a normalised config and embed the text.
	 *
	 * @param string $text             Text to embed
	 * @param array  $normalizedConfig The normalised config.
	 *
	 * @return float[] Embedding vector (array of floats)
	 */
	private function embedWithCustomConfig(string $text, array $normalizedConfig): array {
		$generator = $this->handlers->generator->getGenerator($normalizedConfig);
		$embedding = $generator->embedText($text);

		$this->logger->debug(
			message: '[VectorEmbeddings] Embedding generated successfully with custom config',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'dimensions' => count($embedding),
				'model' => $normalizedConfig['model'],
			]
		);

		return $embedding;
	}//end embedWithCustomConfig()

	/**
	 * Log a failed custom-config embedding and build the exception that reports it.
	 *
	 * @param string $error   The failure message.
	 * @param array  $attempt The provider and text length of the attempt, as logged.
	 *
	 * @return Exception The exception to throw.
	 */
	private function customConfigFailure(string $error, array $attempt): Exception {
		$this->logger->error(
			message: '[VectorEmbeddings] Failed to generate embedding with custom config',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error] + $attempt
		);

		return new Exception('Embedding generation failed: ' . $error);
	}//end customConfigFailure()

	/**
	 * Test embedding generation with custom configuration
	 *
	 * @param EmbeddingProbe $probe The provider ('openai', 'fireworks', 'ollama'), its configuration and the text to embed.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function testEmbedding(EmbeddingProbe $probe): array {
		return $this->probe(probe: $probe);
	}//end testEmbedding()

	/**
	 * Generate one embedding for a probe's text with its provider and config, and report the outcome.
	 *
	 * @param EmbeddingProbe $probe The provider, the config and the test text.
	 *
	 * @return array{success: bool, message: string, data?: array, error?: string}
	 */
	private function probe(EmbeddingProbe $probe): array {
		$this->logger->info(
			message: '[VectorEmbeddings] Testing embedding generation',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $probe->provider,
				'model' => $probe->model(),
				'testTextLength' => strlen($probe->testText),
			]
		);

		try {
			$embedding = $this->generateEmbeddingWithCustomConfig(text: $probe->testText, config: $probe->customConfig());
			return $this->probeSucceeded(probe: $probe, embedding: $embedding);
		} catch (Exception $e) {
			return $this->probeFailed(probe: $probe, error: $e);
		}
	}//end probe()

	/**
	 * Log and report a probe that produced an embedding.
	 *
	 * @param EmbeddingProbe $probe     The probe.
	 * @param array          $embedding The embedding it produced.
	 *
	 * @return array{success: true, message: string, data: array}
	 */
	private function probeSucceeded(EmbeddingProbe $probe, array $embedding): array {
		$this->logger->info(
			message: '[VectorEmbeddings] Embedding test successful',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $probe->provider,
				'model' => $probe->model(),
				'dimensions' => count($embedding),
			]
		);

		return [
			'success' => true,
			'message' => 'Embedding test successful',
			'data' => [
				'provider' => $probe->provider,
				'model' => $probe->model(),
				'vectorLength' => count($embedding),
				'sampleValues' => array_slice($embedding, 0, 5),
				'testText' => $probe->testText,
			],
		];
	}//end probeSucceeded()

	/**
	 * Log and report a probe that failed.
	 *
	 * @param EmbeddingProbe $probe The probe.
	 * @param Exception      $error Why it failed.
	 *
	 * @return array{success: false, error: string, message: string}
	 */
	private function probeFailed(EmbeddingProbe $probe, Exception $error): array {
		$this->logger->error(
			message: '[VectorEmbeddings] Embedding test failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'provider' => $probe->provider,
				'error' => $error->getMessage(),
			]
		);

		return [
			'success' => false,
			'error' => $error->getMessage(),
			'message' => 'Failed to generate embedding: ' . $error->getMessage(),
		];
	}//end probeFailed()

	/**
	 * Generate embeddings for multiple texts in batch
	 *
	 * Per-text failures are tolerated: the failing entry carries a null
	 * embedding and an error message instead of aborting the whole batch.
	 *
	 * @param array<string> $texts Array of texts to embed
	 * @param string|null $provider Embedding provider
	 *
	 * @return array<int, array{embedding: array<float>|null, model: string, dimensions: int, error?: string}> Array of embeddings
	 *
	 * @throws \Exception If batch embedding generation fails
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function generateBatchEmbeddings(array $texts, ?string $provider = null): array {
		$config = $this->getEmbeddingConfig(provider: $provider);

		$this->logger->info(
			message: '[VectorEmbeddings] Generating batch embeddings',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'count' => count($texts),
				'provider' => $config['provider'],
				'model' => $config['model'],
			]
		);

		try {
			$generator = $this->handlers->generator->getGenerator($config);

			// Generate embeddings individually for each text.
			$results = [];
			foreach ($texts as $index => $text) {
				try {
					$embedding = $generator->embedText($text);
					$results[] = [
						'embedding' => $embedding,
						'model' => $config['model'],
						'dimensions' => count($embedding),
					];
				} catch (Exception $e) {
					$this->logger->warning(
						message: '[VectorEmbeddings] Failed to generate embedding for text',
						context: [
							'file' => __FILE__,
							'line' => __LINE__,
							'index' => $index,
							'error' => $e->getMessage(),
						]
					);
					$results[] = [
						'embedding' => null,
						'model' => $config['model'],
						'dimensions' => 0,
						'error' => $e->getMessage(),
					];
				}//end try
			}//end foreach

			$successful = count(array_filter($results, fn ($r) => $r['embedding'] !== null));

			$this->logger->info(
				message: '[VectorEmbeddings] Batch embedding generation completed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'total' => count($texts),
					'successful' => $successful,
					'failed' => count($texts) - $successful,
				]
			);

			return $results;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorEmbeddings] Failed to generate batch embeddings',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
					'count' => count($texts),
				]
			);
			throw new Exception('Batch embedding generation failed: ' . $e->getMessage());
		}//end try
	}//end generateBatchEmbeddings()

	// =============================================================================
	// VECTOR STORAGE
	// =============================================================================

	/**
	 * Store vector embedding in the PostgreSQL database
	 *
	 * @param StoredVector $vector The entity, its embedding and its chunk position
	 *
	 * @return int The ID of the inserted vector
	 *
	 * @throws \Exception If storage fails
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function storeVector(StoredVector $vector): int {
		return $this->handlers->storage->storeVector(vector: $vector);
	}//end storeVector()

	// =============================================================================
	// VECTOR SEARCH
	// =============================================================================

	/**
	 * Perform semantic similarity search
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
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function semanticSearch(
		string $query,
		int $limit = 10,
		array $filters = [],
		?string $provider = null,
	): array {
		$embedding = $this->generateEmbedding(text: $query, provider: $provider)['embedding'];
		return $this->handlers->search->semanticSearch(query: new VectorQuery(embedding: $embedding, limit: $limit, filters: $filters));
	}//end semanticSearch()

	/**
	 * Perform hybrid search combining keyword and semantic (vector) results
	 *
	 * Caller supplies keyword search results; vector search runs against the database.
	 * Results are fused using Reciprocal Rank Fusion (RRF).
	 *
	 * @param HybridSearchRequest $request The query text, the pre-fetched keyword results to fuse, the maximum
	 *                                     results and the weight per search type.
	 *
	 * @return array Hybrid search results with combined scores and source breakdown
	 *
	 * @throws \Exception If hybrid search fails
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function hybridSearch(HybridSearchRequest $request): array {
		// The query embedding comes from the configured provider.
		$vectorQuery = new VectorQuery(embedding: $this->generateEmbedding(text: $request->query)['embedding'], limit: $request->limit);
		$keyword     = new KeywordFusion(results: $request->keywordResults, weights: $request->weights);
		return $this->handlers->search->hybridSearch(query: $vectorQuery, keyword: $keyword);
	}//end hybridSearch()

	// =============================================================================
	// STATISTICS
	// =============================================================================

	/**
	 * Get vector statistics from the PostgreSQL database
	 *
	 * @return ((int|mixed)[]|int|string)[] Statistics about stored vectors
	 *
	 * @psalm-return array{total_vectors: int, by_type: array<int>,
	 *     by_model: array<int|mixed>, object_vectors: int, file_vectors: int}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getVectorStats(): array {
		return $this->handlers->stats->getStats();
	}//end getVectorStats()

	// =============================================================================
	// MANAGEMENT
	// =============================================================================

	/**
	 * Check if embedding model has changed since vectors were created
	 *
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex model comparison logic
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple database queries and conditions
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Thorough model mismatch detection
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function checkEmbeddingModelMismatch(): array {
		try {
			$currentModel = $this->configuredEmbeddingModel(settings: $this->settingsService->getLLMSettingsOnly());
			if ($currentModel === null || $currentModel === '') {
				return [
					'has_vectors' => false,
					'mismatch' => false,
					'message' => 'No embedding model configured',
				];
			}

			$totalVectors = $this->countVectors();
			if ($totalVectors === 0) {
				return [
					'has_vectors' => false,
					'mismatch' => false,
					'current_model' => $currentModel,
					'message' => 'No vectors exist yet',
				];
			}

			return $this->modelReport(currentModel: $currentModel, totalVectors: $totalVectors);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorEmbeddings] Failed to check embedding model mismatch',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);

			return [
				'has_vectors' => false,
				'mismatch' => false,
				'error' => $e->getMessage(),
			];
		}//end try
	}//end checkEmbeddingModelMismatch()

	/**
	 * The embedding model the configured provider uses, or null.
	 *
	 * @param array<string, mixed> $settings The LLM settings.
	 *
	 * @return mixed The model.
	 */
	private function configuredEmbeddingModel(array $settings): mixed {
		return match ($settings['embeddingProvider'] ?? null) {
			'openai' => $settings['openaiConfig']['model'] ?? null,
			'ollama' => $settings['ollamaConfig']['model'] ?? null,
			'fireworks' => $settings['fireworksConfig']['embeddingModel'] ?? null,
			default => null,
		};
	}//end configuredEmbeddingModel()

	/**
	 * How many vectors are stored.
	 *
	 * @return int The count.
	 */
	private function countVectors(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'total'))
			->from('openregister_vectors');
		$result = $qb->executeQuery();
		$total  = (int)$result->fetchOne();
		$result->closeCursor();

		return $total;
	}//end countVectors()

	/**
	 * How many stored vectors carry no model.
	 *
	 * @return int The count.
	 */
	private function countVectorsWithoutModel(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'null_count'))
			->from('openregister_vectors')
			->where($qb->expr()->isNull('embedding_model'));
		$result = $qb->executeQuery();
		$count  = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countVectorsWithoutModel()

	/**
	 * The distinct models the stored vectors were embedded with.
	 *
	 * @return array<int, mixed> The models.
	 */
	private function distinctEmbeddingModels(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('embedding_model')
			->from('openregister_vectors')
			->where($qb->expr()->isNotNull('embedding_model'));
		$result = $qb->executeQuery();
		$models = [];
		while (($row = $result->fetch()) !== false) {
			$models[] = $row['embedding_model'];
		}

		$result->closeCursor();

		return $models;
	}//end distinctEmbeddingModels()

	/**
	 * The mismatch report over existing vectors: reads the distinct stored
	 * models, then how many vectors carry no model.
	 *
	 * @param mixed $currentModel The configured model.
	 * @param int   $totalVectors How many vectors exist.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function modelReport(mixed $currentModel, int $totalVectors): array {
		$existingModels = $this->distinctEmbeddingModels();
		$nullModelCount = $this->countVectorsWithoutModel();
		$mismatchDetails = array_values(array_filter($existingModels, static fn ($model): bool => $model !== $currentModel));
		$hasMismatch     = $mismatchDetails !== [];

		$message = 'All vectors use the same embedding model.';
		if ($hasMismatch === true) {
			$message = 'Multiple embedding models detected. Consider re-embedding all vectors with a single model.';
		} elseif ($nullModelCount > 0) {
			$message = sprintf('%d vectors have no model information.', $nullModelCount);
		}

		return [
			'has_vectors' => true,
			'mismatch' => $hasMismatch || $nullModelCount > 0,
			'current_model' => $currentModel,
			'existing_models' => $existingModels,
			'total_vectors' => $totalVectors,
			'null_model_count' => $nullModelCount,
			'mismatched_models' => $mismatchDetails,
			'message' => $message,
		];
	}//end modelReport()

	/**
	 * Clear all embeddings from the database
	 *
	 * @return (bool|int|string)[]
	 *
	 * @psalm-return array{success: bool, error?: string, message: string, deleted?: int}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function clearAllEmbeddings(): array {
		try {
			// Count vectors before deletion.
			$totalVectors = $this->countVectors();

			if ($totalVectors === 0) {
				return [
					'success' => true,
					'deleted' => 0,
					'message' => 'No vectors to delete',
				];
			}

			// Delete all vectors.
			$qb = $this->db->getQueryBuilder();
			$qb->delete('openregister_vectors');
			$deletedCount = $qb->executeStatement();

			$this->logger->info(
				message: '[VectorEmbeddings] All embeddings cleared',
				context: ['file' => __FILE__, 'line' => __LINE__, 'deleted_count' => $deletedCount]
			);

			return [
				'success' => true,
				'deleted' => $deletedCount,
				'message' => "Deleted {$deletedCount} vectors successfully",
			];
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorEmbeddings] Failed to clear embeddings',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);

			return [
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Failed to clear embeddings: ' . $e->getMessage(),
			];
		}//end try
	}//end clearAllEmbeddings()

	// =============================================================================
	// CONFIGURATION HELPERS
	// =============================================================================

	/**
	 * Get embedding configuration from settings
	 *
	 * @param string|null $provider Override provider (null = use default from settings)
	 *
	 * @return array{provider: string, model: string, dimensions: int,
	 *               api_key: string|null, base_url: string|null} Configuration
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Provider-specific configuration mapping
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function getEmbeddingConfig(?string $provider = null): array {
		$llmSettings = $this->settingsService->getLLMSettingsOnly();

		// Determine provider.
		$configuredProvider = $provider ?? ($llmSettings['embeddingProvider'] ?? 'openai');

		// Get provider-specific configuration.
		$providerConfig = match ($configuredProvider) {
			'fireworks' => $llmSettings['fireworksConfig'] ?? [],
			'ollama' => $llmSettings['ollamaConfig'] ?? [],
			'openai' => $llmSettings['openaiConfig'] ?? [],
			default => []
		};

		// Extract model and credentials.
		$model = match ($configuredProvider) {
			'fireworks' => $providerConfig['embeddingModel'] ?? 'thenlper/gte-base',
			'ollama' => $providerConfig['model'] ?? 'nomic-embed-text',
			'openai' => $providerConfig['model'] ?? 'text-embedding-ada-002',
			default => 'text-embedding-ada-002'
		};

		$apiKey = $providerConfig['apiKey'] ?? null;
		$baseUrl = $providerConfig['baseUrl'] ?? $providerConfig['url'] ?? null;

		return [
			'provider' => $configuredProvider,
			'model' => $model,
			'dimensions' => $this->handlers->generator->getDefaultDimensions($model),
			'api_key' => $apiKey,
			'base_url' => $baseUrl,
		];
	}//end getEmbeddingConfig()
}//end class
