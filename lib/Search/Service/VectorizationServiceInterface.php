<?php

/**
 * VectorizationServiceInterface: the public surface of VectorizationService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service
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

namespace OCA\OpenRegister\Search\Service;

use OCA\OpenRegister\Search\Service\Vectorization\Strategies\VectorizationStrategyInterface;

/**
 * Every public method of VectorizationService. Callers depend on this interface, not on the class.
 */
interface VectorizationServiceInterface {

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
	public function checkEmbeddingModelMismatch(): array;

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
	public function clearAllEmbeddings(): array;

	/**
	 * Get vector statistics
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @return array Vector statistics with totals and breakdowns by type and model.
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function getVectorStats(): array;

	/**
	 * Perform hybrid search combining keyword and semantic (vector) results
	 *
	 * Delegates to VectorEmbeddings. Caller supplies keyword search results;
	 * vector search runs against the PostgreSQL database.
	 *
	 * @param string $query Query text
	 * @param array $keywordResults Pre-fetched keyword search results to fuse
	 * @param int $limit Maximum results
	 * @param array $weights Weights for each search type ['keyword' => 0.5, 'vector' => 0.5]
	 *
	 * @return array Hybrid search results with combined scores and source breakdown.
	 *
	 * @throws \Exception If hybrid search fails.
	 *
	 * @spec exclude One-line facade delegation to VectorEmbeddings::hybridSearch; no logic of its own.
	 */
	public function hybridSearch(
		string $query, array $keywordResults = [], int $limit = 20, array $weights = ['keyword' => 0.5, 'vector' => 0.5]
	): array;

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
	public function registerStrategy(string $entityType, VectorizationStrategyInterface $strategy): void;

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
	public function semanticSearch(string $query, int $limit = 10, array $filters = [], ?string $provider = null): array;

	/**
	 * Test embedding generation with custom configuration
	 *
	 * Delegates to VectorEmbeddings.
	 *
	 * @param string $provider Provider name ('openai', 'fireworks', 'ollama')
	 * @param array $config Provider-specific configuration
	 * @param string $testText Optional test text to embed
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
	public function testEmbedding(string $provider, array $config, string $testText = 'Test.'): array;

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex batch processing with error handling
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple processing paths with exceptions
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive batch processing with progress tracking
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function vectorizeBatch(string $entityType, array $options = []): array;
}//end interface
