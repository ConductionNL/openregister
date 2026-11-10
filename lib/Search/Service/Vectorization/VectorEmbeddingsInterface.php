<?php

/**
 * VectorEmbeddingsInterface: the public surface of VectorEmbeddings, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Vectorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization;

use OCA\OpenRegister\Search\Service\Vectorization\Handlers\StoredVector;
/**
 * Every public method of VectorEmbeddings. Callers depend on this interface, not on the class.
 */
interface VectorEmbeddingsInterface {

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
	public function generateEmbedding(string $text, ?string $provider = null): array;

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex config validation logic
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function generateEmbeddingWithCustomConfig(string $text, array $config): array;

	/**
	 * Test embedding generation with custom configuration
	 *
	 * @param EmbeddingProbe $probe The provider ('openai', 'fireworks', 'ollama'), its configuration and the text to embed.
	 *
	 * @return array
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function testEmbedding(EmbeddingProbe $probe): array;

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
	public function generateBatchEmbeddings(array $texts, ?string $provider = null): array;

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
	public function storeVector(StoredVector $vector): int;

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
	public function semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null): array;

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
	public function hybridSearch(HybridSearchRequest $request): array;

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
	public function getVectorStats(): array;

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
	public function checkEmbeddingModelMismatch(): array;

	/**
	 * Clear all embeddings from the database
	 *
	 * @return (bool|int|string)[]
	 *
	 * @psalm-return array{success: bool, error?: string, message: string, deleted?: int}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function clearAllEmbeddings(): array;
}//end interface
