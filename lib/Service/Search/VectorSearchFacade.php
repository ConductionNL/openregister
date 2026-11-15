<?php

/**
 * VectorSearchFacade — semantic and hybrid search over OpenRegister's vectors, for other apps.
 *
 * PUBLIC API — stable cross-app contract, like ToolRegistryFacade. Other apps
 * (hermiq `vector-rag`, buildiq) call these four methods by this class name.
 * A change to a signature or to the row shape needs an OpenSpec change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Search
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/search-file-text-and-vector-facade
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Search;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCA\OpenRegister\Search\Service\Vectorization\HybridSearchRequest;
use OCA\OpenRegister\Search\Service\Vectorization\SemanticSearchRequest;
use OCA\OpenRegister\Search\Service\VectorizationServiceInterface;
use OCA\OpenRegister\Service\SettingsServiceInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Vector search as the calling user: every row is one the caller can open.
 *
 * Rows have `entity_id`, `entity_type` (`object` or `file`), `chunk_text`,
 * `similarity` and `metadata`. `views` narrows object rows to the registers
 * and schemas of those views; file rows are not in a register and are not
 * narrowed by it.
 *
 * @spec openspec/changes/search-file-text-and-vector-facade
 */
class VectorSearchFacade {

	/**
	 * The most rows one search returns.
	 */
	public const MAX_LIMIT = 50;

	/**
	 * Wire the vector store, the read filter and the settings.
	 *
	 * @param VectorizationServiceInterface $vectors  Semantic and hybrid search, and embeddings.
	 * @param VectorResultFilter       $filter   Keeps the rows the caller may open, inside the views.
	 * @param ChunkMapperInterface     $chunks   The keyword arm of hybrid search.
	 * @param SettingsServiceInterface $settings Whether an embedding provider is configured.
	 * @param LoggerInterface          $logger   Logs why the facade reports itself unavailable.
	 */
	public function __construct(
		private readonly VectorizationServiceInterface $vectors,
		private readonly VectorResultFilter $filter,
		private readonly ChunkMapperInterface $chunks,
		private readonly SettingsServiceInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether vector search can answer: LLM features on and an embedding provider set.
	 *
	 * False lets a caller keep its own keyword search.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function isAvailable(): bool {
		try {
			$llm = $this->settings->getLLMSettingsOnly();
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[VectorSearchFacade] LLM settings unreadable, vector search unavailable',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return false;
		}

		$provider = ($llm['embeddingProvider'] ?? null);

		return ($llm['enabled'] ?? false) === true && is_string($provider) === true && $provider !== '';
	}//end isAvailable()

	/**
	 * The passages most similar to a query, as the caller.
	 *
	 * @param string                 $query The question.
	 * @param int                    $limit At most this many rows, capped at MAX_LIMIT.
	 * @param array<int, int|string> $views View ids narrowing object rows; empty for all.
	 *
	 * @return array<int, array{entity_id: int|string, entity_type: string, chunk_text: string|null, similarity: float, metadata: array}>
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function searchSemantic(string $query, int $limit = 10, array $views = []): array {
		$limit = self::cappedLimit(limit: $limit);
		$candidates = $this->vectors->semanticSearch(
			request: new SemanticSearchRequest(query: $query, limit: $limit * VectorResultFilter::OVER_FETCH)
		);

		return $this->rows(candidates: $candidates, views: $views, limit: $limit, score: 'similarity');
	}//end searchSemantic()

	/**
	 * The passages that best match a query by keyword and by meaning, as the caller.
	 *
	 * `similarity` holds the fused rank score.
	 *
	 * @param string                 $query The question.
	 * @param int                    $limit At most this many rows, capped at MAX_LIMIT.
	 * @param array<int, int|string> $views View ids narrowing object rows; empty for all.
	 *
	 * @return array<int, array{entity_id: int|string, entity_type: string, chunk_text: string|null, similarity: float, metadata: array}>
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function searchHybrid(string $query, int $limit = 10, array $views = []): array {
		$limit = self::cappedLimit(limit: $limit);
		$pool = $limit * VectorResultFilter::OVER_FETCH;

		// Scoped before fusion, so an unreadable hit cannot lift a readable one's rank.
		$keyword = $this->filter->readable(
			results: $this->chunks->searchByKeyword(search: new KeywordChunkSearch(query: $query, limit: $pool))
		);
		$fused = $this->vectors->hybridSearch(
			request: new HybridSearchRequest(query: $query, keywordResults: $keyword, limit: $pool)
		);

		return $this->rows(candidates: ($fused['results'] ?? []), views: $views, limit: $limit, score: 'combined_score');
	}//end searchHybrid()

	/**
	 * Embed texts with the configured provider, one vector per text, in order.
	 *
	 * @param array<int, string> $texts The texts.
	 *
	 * @return array<int, array<int, float>>
	 *
	 * @spec openspec/changes/search-file-text-and-vector-facade
	 */
	public function embedTexts(array $texts): array {
		$vectors = [];
		foreach (array_values($texts) as $text) {
			$vectors[] = $this->vectors->generateEmbedding(text: (string)$text)['embedding'];
		}

		return $vectors;
	}//end embedTexts()

	/**
	 * Candidates narrowed to the views and to what the caller may open, as rows.
	 *
	 * @param array<int, array<string, mixed>> $candidates Ranked candidates.
	 * @param array<int, int|string>           $views      View ids.
	 * @param int                              $limit      How many rows to keep.
	 * @param string                           $score      The candidate key holding the score.
	 *
	 * @return array<int, array{entity_id: int|string, entity_type: string, chunk_text: string|null, similarity: float, metadata: array}>
	 */
	private function rows(array $candidates, array $views, int $limit, string $score): array {
		$kept = $this->filter->readable(results: $this->filter->inViews(results: $candidates, viewIds: $views));

		$rows = [];
		foreach (array_slice($kept, 0, $limit) as $hit) {
			$metadata = ($hit['metadata'] ?? []);
			if (is_array($metadata) === false) {
				$metadata = [];
			}

			$rows[] = [
				'entity_id' => $hit['entity_id'],
				'entity_type' => (string)$hit['entity_type'],
				'chunk_text' => ($hit['chunk_text'] ?? null),
				'similarity' => (float)($hit[$score] ?? 0),
				'metadata' => $metadata,
			];
		}

		return $rows;
	}//end rows()

	/**
	 * A requested limit between 1 and MAX_LIMIT.
	 *
	 * @param int $limit The requested limit.
	 *
	 * @return int
	 */
	private static function cappedLimit(int $limit): int {
		return max(1, min($limit, self::MAX_LIMIT));
	}//end cappedLimit()
}//end class
