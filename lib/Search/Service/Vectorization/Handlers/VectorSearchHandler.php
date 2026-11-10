<?php

/**
 * Vector Search Handler
 *
 * Handles semantic and hybrid search operations using vectors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

use Exception;
use InvalidArgumentException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * VectorSearchHandler
 *
 * Responsible for searching vectors using semantic search and hybrid search.
 * On PostgreSQL with a populated pgvector ANN sidecar (`openregister_vec_ann`)
 * the primary path is an index-backed SQL KNN query (`ORDER BY embedding <=> :query`);
 * the PHP cosine-similarity loop over serialized BLOBs remains as the explicit
 * fallback for MariaDB/SQLite, Postgres installs without the pgvector extension,
 * and rows whose stored dimension doesn't match the column.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class deliberately carries BOTH
 *   ranking paths (index-backed SQL KNN primary + PHP-cosine fallback) plus RRF fusion;
 *   splitting them would separate two implementations of one contract that must stay
 *   result-shape-identical.
 */
class VectorSearchHandler {

	/**
	 * The Reciprocal Rank Fusion constant k.
	 *
	 * @var integer
	 */
	private const RRF_K = 60;

	/**
	 * KNN filter columns and the SQL parameter name each one binds.
	 */
	private const KNN_PARAMETER_NAMES = [
		'entity_type' => 'entityType',
		'entity_id' => 'entityId',
	];

	/**
	 * The scoring fields of a fused result before either side ranks it.
	 *
	 * @var array<string, mixed>
	 */
	private const UNRANKED = [
		'combined_score' => 0,
		'in_vector' => false,
		'in_keyword' => false,
		'vector_rank' => null,
		'keyword_rank' => null,
	];
	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 * @param PgVectorPlatform $pgVector pgvector fast-path capability helper
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly PgVectorPlatform $pgVector,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Perform semantic similarity search
	 *
	 * Primary path: SQL K-nearest-neighbour on PostgreSQL via the pgvector
	 * ANN sidecar (`openregister_vec_ann`) and its HNSW index (index-backed
	 * `ORDER BY embedding <=> :query LIMIT :n`). Fallback path: fetch
	 * candidate rows and score them with a PHP cosine loop (MariaDB/SQLite,
	 * missing pgvector extension, dimension mismatch, or no converted rows
	 * yet during warm-up).
	 *
	 * @param VectorQuery $query The query embedding, limit and filters (entity_type, etc.)
	 *
	 * @return array<int,array<string,mixed>> Search results
	 *
	 * @throws \Exception If search fails
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#3.1
	 */
	public function semanticSearch(VectorQuery $query): array {
		$startTime = microtime(true);
		$this->logSemanticSearchStarted(query: $query);

		try {
			return $this->bestPathResults(query: $query, startTime: $startTime);
		} catch (Exception $e) {
			$this->failSemanticSearch(error: $e, startTime: $startTime);
		}
	}//end semanticSearch()

	/**
	 * Log the start of a semantic search.
	 *
	 * @param VectorQuery $query The query limit and filters.
	 *
	 * @return void
	 */
	private function logSemanticSearchStarted(VectorQuery $query): void {
		$this->logger->info(
			message: '[VectorSearchHandler] Performing semantic search',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'limit' => $query->limit,
				'filters' => $query->filters,
			]
		);
	}//end logSemanticSearchStarted()

	/**
	 * Answer a semantic search from the pgvector KNN path, falling back to the PHP cosine loop.
	 *
	 * @param VectorQuery $query     The query embedding, limit and filters.
	 * @param float       $startTime When the search started (microtime).
	 *
	 * @return array<int,array<string,mixed>> Search results
	 *
	 * @throws \Exception If a path fails
	 */
	private function bestPathResults(VectorQuery $query, float $startTime): array {
		// Primary path: index-backed SQL KNN on PostgreSQL/pgvector.
		$knnResults = $this->knnSearch(query: $query);

		if ($knnResults !== null && $knnResults !== []) {
			$this->logSemanticSearchCompleted(path: 'pgvector KNN path', run: ['results' => $knnResults, 'startTime' => $startTime]);
			return $knnResults;
		}

		return $this->fallbackResults(query: $query, startTime: $startTime);
	}//end bestPathResults()

	/**
	 * Fallback path: score candidate BLOB rows with the PHP cosine loop.
	 *
	 * @param VectorQuery $query     The query embedding, limit and filters.
	 * @param float       $startTime When the search started (microtime).
	 *
	 * @return array<int,array<string,mixed>> Search results, most similar first
	 *
	 * @throws \Exception If fetching the rows fails
	 */
	private function fallbackResults(VectorQuery $query, float $startTime): array {
		$vectors = $this->fetchVectors(filters: $query->filters);

		if ($vectors === []) {
			$this->logNoVectorsFound(filters: $query->filters);
			return [];
		}

		$results = array_slice($this->rankVectors(queryEmbedding: $query->embedding, vectors: $vectors), 0, $query->limit);

		$this->logSemanticSearchCompleted(path: 'PHP fallback path', run: ['results' => $results, 'startTime' => $startTime]);

		return $results;
	}//end fallbackResults()

	/**
	 * Log that the fallback found no vector rows.
	 *
	 * @param array<string, mixed> $filters The fetch filters.
	 *
	 * @return void
	 */
	private function logNoVectorsFound(array $filters): void {
		$this->logger->warning(
			message: '[VectorSearchHandler] No vectors found in database',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'filters' => $filters,
			]
		);
	}//end logNoVectorsFound()

	/**
	 * Log a failed semantic search and rethrow it wrapped.
	 *
	 * @param Exception $error     Why the search failed.
	 * @param float     $startTime When the search started (microtime).
	 *
	 * @return never
	 *
	 * @throws \Exception Always: "Semantic search failed: <reason>".
	 */
	private function failSemanticSearch(Exception $error, float $startTime): never {
		$this->logger->error(
			message: '[VectorSearchHandler] Semantic search failed',
			context: $this->failureContext(error: $error, startTime: $startTime)
		);
		throw new Exception('Semantic search failed: ' . $error->getMessage());
	}//end failSemanticSearch()

	/**
	 * The log context of a failed search: where, why, and how long it ran.
	 *
	 * @param Exception $error     Why the search failed.
	 * @param float     $startTime When the search started (microtime).
	 *
	 * @return array<string, mixed> The log context.
	 */
	private function failureContext(Exception $error, float $startTime): array {
		$searchTime = round((microtime(true) - $startTime) * 1000, 2);

		return [
			'file' => __FILE__,
			'line' => __LINE__,
			'error' => $error->getMessage(),
			'search_time_ms' => $searchTime,
		];
	}//end failureContext()

	/**
	 * Log a completed semantic search.
	 *
	 * @param string                                                     $path Which search path answered.
	 * @param array{results: array<int, array<string, mixed>>, startTime: float} $run  The results and the start time.
	 *
	 * @return void
	 */
	private function logSemanticSearchCompleted(string $path, array $run): void {
		$this->logger->info(
			message: "[VectorSearchHandler] Semantic search completed ($path)",
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'results_count' => count($run['results']),
				'top_similarity' => $run['results'][0]['similarity'] ?? 0,
				'search_time_ms' => round((microtime(true) - $run['startTime']) * 1000, 2),
			]
		);
	}//end logSemanticSearchCompleted()

	/**
	 * Score stored vector rows against the query, most similar first.
	 *
	 * @param array                            $queryEmbedding Query embedding vector.
	 * @param array<int, array<string, mixed>> $vectors        Stored vector rows.
	 *
	 * @return array<int, array<string, mixed>> One result per usable row.
	 */
	private function rankVectors(array $queryEmbedding, array $vectors): array {
		$results = [];
		foreach ($vectors as $vector) {
			$result = $this->scoreVectorRow(queryEmbedding: $queryEmbedding, vector: $vector);
			if ($result !== null) {
				$results[] = $result;
			}
		}

		usort($results, fn ($a, $b) => $b['similarity'] <=> $a['similarity']);

		return $results;
	}//end rankVectors()

	/**
	 * Score one stored vector row against the query.
	 *
	 * @param array                $queryEmbedding Query embedding vector.
	 * @param array<string, mixed> $vector         Stored vector row.
	 *
	 * @return array<string, mixed>|null The result, or null when the row holds no usable embedding.
	 */
	private function scoreVectorRow(array $queryEmbedding, array $vector): ?array {
		try {
			$storedEmbedding = $this->storedEmbedding(vector: $vector);
			if ($storedEmbedding === null) {
				return null;
			}

			$similarity = $this->cosineSimilarity(
				vector1: $queryEmbedding,
				vector2: $storedEmbedding
			);

			return $this->vectorResult(vector: $vector, similarity: $similarity);
		} catch (Exception $e) {
			$this->logUnusableVector(vector: $vector, error: $e);
			return null;
		}//end try
	}//end scoreVectorRow()

	/**
	 * The search result for one scored vector row.
	 *
	 * @param array<string, mixed> $vector     Stored vector row.
	 * @param float                $similarity Its cosine similarity to the query.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function vectorResult(array $vector, float $similarity): array {
		return [
			'vector_id' => $vector['id'],
			'entity_type' => $vector['entity_type'],
			'entity_id' => $vector['entity_id'],
			'similarity' => $similarity,
			'chunk_index' => $vector['chunk_index'],
			'total_chunks' => $vector['total_chunks'],
			'chunk_text' => $vector['chunk_text'],
			'metadata' => $this->decodedMetadata(vector: $vector),
			'model' => $vector['embedding_model'],
			'dimensions' => $vector['embedding_dimensions'],
		];
	}//end vectorResult()

	/**
	 * A vector row's metadata, decoded; empty when it has none.
	 *
	 * @param array<string, mixed> $vector Stored vector row.
	 *
	 * @return mixed The decoded metadata.
	 */
	private function decodedMetadata(array $vector): mixed {
		$metadata = [];
		if (empty($vector['metadata']) === false) {
			$metadata = json_decode($vector['metadata'], true) ?? [];
		}

		return $metadata;
	}//end decodedMetadata()

	/**
	 * Log a vector row that could not be scored.
	 *
	 * @param array<string, mixed> $vector Stored vector row.
	 * @param Exception            $error  Why scoring failed.
	 *
	 * @return void
	 */
	private function logUnusableVector(array $vector, Exception $error): void {
		$this->logger->warning(
			message: '[VectorSearchHandler] Failed to process vector',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'vector_id' => $vector['id'],
				'error' => $error->getMessage(),
			]
		);
	}//end logUnusableVector()

	/**
	 * The embedding stored in a vector row, or null when it holds none.
	 *
	 * PostgreSQL returns BLOB columns as stream resources (live-verified on
	 * PG16); they are read to a string first, because unserialize() on a
	 * resource is a TypeError. Objects are never instantiated (SEC-SVC-9):
	 * embeddings are plain float arrays, and a tampered blob must not inject
	 * PHP objects.
	 *
	 * @param array<string, mixed> $vector Stored vector row.
	 *
	 * @return array|null The embedding, or null.
	 */
	private function storedEmbedding(array $vector): ?array {
		$blob = $vector['embedding'];
		if (is_resource($blob) === true) {
			$blob = stream_get_contents($blob);
		}

		if (is_string($blob) === false) {
			return null;
		}

		$storedEmbedding = unserialize($blob, ['allowed_classes' => false]);
		if (is_array($storedEmbedding) === false) {
			return null;
		}

		return $storedEmbedding;
	}//end storedEmbedding()

	/**
	 * Index-backed SQL KNN search on PostgreSQL via pgvector.
	 *
	 * Executes `ORDER BY a.embedding <=> :queryVector LIMIT :limit`
	 * (cosine-distance operator, ascending = most similar first) against the
	 * HNSW index on the `openregister_vec_ann` sidecar table (joined to the
	 * main vectors table for entity data), honouring `entity_type` /
	 * `entity_id` filters as WHERE predicates. Returns null when the fast path
	 * is unavailable (non-Postgres platform, missing sidecar, or
	 * query/sidecar dimension mismatch) or when the KNN query itself fails —
	 * the caller then uses the PHP fallback.
	 *
	 * @param VectorQuery $query The query embedding, limit and filters (entity_type, entity_id)
	 *
	 * @return array<int,array<string,mixed>>|null Results, or null when unavailable
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#3.1
	 */
	private function knnSearch(VectorQuery $query): ?array {
		$columnDimension = $this->pgVector->getVectorColumnDimension();

		if ($columnDimension === null || count($query->embedding) !== $columnDimension) {
			return null;
		}

		$qvec    = $this->pgVector->formatVector($query->embedding);
		$clauses = $this->knnClauses(filters: $query->filters);

		$rows = $this->knnRows(
			statement: new KnnStatement(
				sql: $this->knnSql(where: $clauses['where'], limit: $query->limit),
				params: ['qvec' => $qvec] + $clauses['params'],
				types: $clauses['types']
			)
		);
		if ($rows === null) {
			return null;
		}

		return array_map(fn (array $row): array => $this->knnResult(row: $row), $rows);
	}//end knnSearch()

	/**
	 * The KNN WHERE clauses, parameters and types of the entity filters.
	 *
	 * @param array $filters Additional filters (entity_type, entity_id).
	 *
	 * @return array{where: array<int, string>, params: array<string, mixed>, types: array<string, mixed>}
	 */
	private function knnClauses(array $filters): array {
		$clauses = ['where' => [], 'params' => [], 'types' => []];
		foreach (array_keys(self::KNN_PARAMETER_NAMES) as $column) {
			$clause = $this->knnFilter(column: $column, value: ($filters[$column] ?? null));
			if ($clause !== null) {
				$clauses['where'][] = $clause['where'];
				$clauses['params'] += $clause['params'];
				$clauses['types']  += $clause['types'];
			}
		}

		return $clauses;
	}//end knnClauses()

	/**
	 * The KNN WHERE clause of one filter: IN for a list, = for a value,
	 * none for null.
	 *
	 * @param string $column The vector column (entity_type or entity_id).
	 * @param mixed  $value  The filter value.
	 *
	 * @return array{where: string, params: array<string, mixed>, types: array<string, mixed>}|null
	 */
	private function knnFilter(string $column, mixed $value): ?array {
		if ($value === null) {
			return null;
		}

		// The parameter name; a list takes it plural.
		$name = self::KNN_PARAMETER_NAMES[$column];
		if (is_array($value) === true) {
			return [
				'where' => 'v.' . $column . ' IN (:' . $name . 's)',
				'params' => [$name . 's' => $value],
				'types' => [$name . 's' => IQueryBuilder::PARAM_STR_ARRAY],
			];
		}

		return ['where' => 'v.' . $column . ' = :' . $name, 'params' => [$name => $value], 'types' => []];
	}//end knnFilter()

	/**
	 * The index-backed KNN statement.
	 *
	 * @param array<int, string> $where The WHERE clauses, joined with AND.
	 * @param int                $limit The result limit (at least 1).
	 *
	 * @return string The SQL.
	 */
	private function knnSql(array $where, int $limit): string {
		$whereSql = '';
		if ($where !== []) {
			$whereSql = 'WHERE ' . implode(' AND ', $where) . ' ';
		}

		return 'SELECT v.id, v.entity_type, v.entity_id, v.chunk_index, v.total_chunks, v.chunk_text, '
			. 'v.metadata, v.embedding_model, v.embedding_dimensions, '
			. '(a.embedding <=> :qvec::vector) AS distance '
			. 'FROM ' . PgVectorPlatform::SIDECAR_TABLE . ' a '
			. 'JOIN *PREFIX*openregister_vectors v ON v.id = a.vector_id '
			. $whereSql
			. 'ORDER BY a.embedding <=> :qvec::vector ASC '
			. 'LIMIT ' . max(1, $limit);
	}//end knnSql()

	/**
	 * Run the KNN statement; a failure is logged and yields null.
	 *
	 * @param KnnStatement $statement The SQL, parameters and parameter types.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null on failure.
	 */
	private function knnRows(KnnStatement $statement): ?array {
		try {
			$result = $this->db->executeQuery($statement->sql, $statement->params, $statement->types);
			$rows   = $result->fetchAll();
			$result->closeCursor();
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[VectorSearchHandler] pgvector KNN query failed, using PHP fallback',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}

		return $rows;
	}//end knnRows()

	/**
	 * One KNN row as a search result.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The result.
	 */
	private function knnResult(array $row): array {
		$metadata = [];
		if (empty($row['metadata']) === false) {
			$metadata = json_decode($row['metadata'], true) ?? [];
		}

		return [
			'vector_id' => $row['id'],
			'entity_type' => $row['entity_type'],
			'entity_id' => $row['entity_id'],
			// Cosine distance = 1 - cosine similarity.
			'similarity' => 1 - (float)$row['distance'],
			'chunk_index' => $row['chunk_index'],
			'total_chunks' => $row['total_chunks'],
			'chunk_text' => $row['chunk_text'],
			'metadata' => $metadata,
			'model' => $row['embedding_model'],
			'dimensions' => $row['embedding_dimensions'],
		];
	}//end knnResult()

	/**
	 * Perform hybrid search combining keyword and semantic (vectors)
	 *
	 * Uses Reciprocal Rank Fusion (RRF) to combine results.
	 * The keyword results set is passed in by the caller; vector search
	 * always runs against the PostgreSQL database backend.
	 *
	 * @param VectorQuery   $query   Query embedding vector and the maximum number of results
	 * @param KeywordFusion $keyword Keyword search results to fuse with vector results, and the
	 *                               weights for each search type ['keyword' => 0.5, 'vector' => 0.5]
	 *
	 * @return (((array|bool|float|int|mixed|null)[]|float|int)[]|float|int)[]
	 *
	 * @throws \Exception If hybrid search fails
	 *
	 * @psalm-return array{results: list<array{chunk_index: 0|mixed,
	 *     chunk_text: mixed|null, combined_score: 0|float, entity_id: mixed,
	 *     entity_type: mixed, in_keyword: bool, in_vector: bool,
	 *     metadata: array<never, never>|mixed, keyword_rank: int|null,
	 *     keyword_score: mixed|null, vector_rank: int|null,
	 *     vector_similarity: mixed|null}>, total: int<0, max>,
	 *     search_time_ms: float,
	 *     source_breakdown: array{vector_only: int<0, max>,
	 *     keyword_only: int<0, max>, both: int<0, max>},
	 *     weights: array{keyword: float, vector: float}}
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	public function hybridSearch(VectorQuery $query, KeywordFusion $keyword): array {
		$startTime = microtime(true);

		try {
			$weight = $this->normalisedWeights(keyword: $keyword);

			// Return top N results.
			$finalResults = array_slice($this->fusedResults(query: $query, keyword: $keyword), 0, $query->limit);

			return [
				'results' => $finalResults,
				'total' => count($finalResults),
				'search_time_ms' => round((microtime(true) - $startTime) * 1000, 2),
				'source_breakdown' => $this->sourceBreakdown(results: $finalResults),
				'weights' => $weight,
			];
		} catch (Exception $e) {
			$this->failHybridSearch(error: $e, startTime: $startTime);
		}//end try
	}//end hybridSearch()

	/**
	 * The vector and keyword results fused with Reciprocal Rank Fusion, best first.
	 *
	 * The weights are the same normalised weights the response reports.
	 *
	 * @param VectorQuery   $query   Query embedding vector and the maximum number of results.
	 * @param KeywordFusion $keyword Keyword results and the requested weights.
	 *
	 * @return array<int, array<string, mixed>> Every fused result.
	 */
	private function fusedResults(VectorQuery $query, KeywordFusion $keyword): array {
		$weight = $this->normalisedWeights(keyword: $keyword);

		// Combine results using Reciprocal Rank Fusion (RRF).
		return $this->reciprocalRankFusion(
			vector: $this->vectorSide(query: $query, weight: $weight['vector']),
			keyword: new RankedResults(results: $keyword->results, weight: $weight['keyword'])
		);
	}//end fusedResults()

	/**
	 * The vector side of a hybrid search, ranked with its weight; none when its weight is zero.
	 *
	 * @param VectorQuery $query  Query embedding vector and the hybrid limit.
	 * @param mixed       $weight The normalised vector weight.
	 *
	 * @return RankedResults The vector results and their weight.
	 */
	private function vectorSide(VectorQuery $query, mixed $weight): RankedResults {
		// No vector side when its weight is zero.
		$vectorResults = [];
		if ($weight > 0) {
			$vectorResults = $this->hybridVectorResults(query: $query);
		}

		return new RankedResults(results: $vectorResults, weight: $weight);
	}//end vectorSide()

	/**
	 * Log a failed hybrid search and rethrow it wrapped.
	 *
	 * @param Exception $error     Why the search failed.
	 * @param float     $startTime When the search started (microtime).
	 *
	 * @return never
	 *
	 * @throws \Exception Always: "Hybrid search failed: <reason>".
	 */
	private function failHybridSearch(Exception $error, float $startTime): never {
		$this->logger->error(
			message: '[VectorSearchHandler] Hybrid search failed',
			context: $this->failureContext(error: $error, startTime: $startTime)
		);
		throw new Exception('Hybrid search failed: ' . $error->getMessage());
	}//end failHybridSearch()

	/**
	 * The keyword and vector weights, scaled to sum to one when positive;
	 * a missing weight is 0.5.
	 *
	 * @param KeywordFusion $keyword The keyword side carrying the requested weights.
	 *
	 * @return array{keyword: mixed, vector: mixed} The weights.
	 */
	private function normalisedWeights(KeywordFusion $keyword): array {
		$weights       = $keyword->weights;
		$keywordWeight = $weights['keyword'] ?? 0.5;
		$vectorWeight  = $weights['vector'] ?? 0.5;

		$totalWeight = $keywordWeight + $vectorWeight;
		if ($totalWeight > 0) {
			$keywordWeight = $keywordWeight / $totalWeight;
			$vectorWeight  = $vectorWeight / $totalWeight;
		}

		return ['keyword' => $keywordWeight, 'vector' => $vectorWeight];
	}//end normalisedWeights()

	/**
	 * The vector side of a hybrid search: twice the limit, and none (logged)
	 * when the search fails.
	 *
	 * @param VectorQuery $query Query embedding vector and the hybrid limit.
	 *
	 * @return array<int, array<string, mixed>> The vector results.
	 */
	private function hybridVectorResults(VectorQuery $query): array {
		try {
			return $this->semanticSearch(query: new VectorQuery(embedding: $query->embedding, limit: $query->limit * 2));
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[VectorSearchHandler] Vector search failed in hybrid search',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}
	}//end hybridVectorResults()

	/**
	 * How many fused results came from the vector side, the keyword side, or both.
	 *
	 * @param array<int, array<string, mixed>> $results The fused results.
	 *
	 * @return array{vector_only: int, keyword_only: int, both: int}
	 */
	private function sourceBreakdown(array $results): array {
		$breakdown = ['vector_only' => 0, 'keyword_only' => 0, 'both' => 0];
		foreach ($results as $result) {
			$source = self::fusedSource(result: $result);
			if ($source !== null) {
				$breakdown[$source]++;
			}
		}

		return $breakdown;
	}//end sourceBreakdown()

	/**
	 * Which side(s) one fused result came from.
	 *
	 * @param array<string, mixed> $result The fused result.
	 *
	 * @return string|null `both`, `vector_only`, `keyword_only`, or null for neither.
	 */
	private static function fusedSource(array $result): ?string {
		if ($result['in_vector'] === true) {
			if ($result['in_keyword'] === true) {
				return 'both';
			}

			return 'vector_only';
		}

		if ($result['in_keyword'] === true) {
			return 'keyword_only';
		}

		return null;
	}//end fusedSource()

	/**
	 * Combine search results using Reciprocal Rank Fusion (RRF)
	 *
	 * @param RankedResults $vector  Results from vector search, with the vector weight (0-1)
	 * @param RankedResults $keyword Results from keyword search, with the keyword weight (0-1)
	 *
	 * @return (array|bool|float|int|mixed|null)[][]
	 *
	 * @psalm-return list<array{chunk_index: 0|mixed, chunk_text: mixed|null,
	 *     combined_score: 0|float, entity_id: mixed, entity_type: mixed,
	 *     in_keyword: bool, in_vector: bool,
	 *     metadata: array<never, never>|mixed, keyword_rank: int|null,
	 *     keyword_score: mixed|null, vector_rank: int|null,
	 *     vector_similarity: mixed|null}>
	 */
	private function reciprocalRankFusion(RankedResults $vector, RankedResults $keyword): array {
		$combinedScores = $this->addVectorRanks(combinedScores: [], vector: $vector);
		$combinedScores = $this->addKeywordRanks(combinedScores: $combinedScores, keyword: $keyword);

		// Convert to array and sort by combined score.
		$results = array_values($combinedScores);
		usort($results, fn ($a, $b) => $b['combined_score'] <=> $a['combined_score']);

		return $results;
	}//end reciprocalRankFusion()

	/**
	 * Add the vector ranks to the fused scores.
	 *
	 * @param array<string, array<string, mixed>> $combinedScores The fused scores so far, keyed by entity.
	 * @param RankedResults                       $vector         The vector results and weight.
	 *
	 * @return array<string, array<string, mixed>> The fused scores.
	 */
	private function addVectorRanks(array $combinedScores, RankedResults $vector): array {
		foreach ($vector->results as $rank => $result) {
			$key = $result['entity_type'] . '_' . $result['entity_id'];

			if (isset($combinedScores[$key]) === false) {
				$combinedScores[$key] = [
					'entity_type' => $result['entity_type'],
					'entity_id' => $result['entity_id'],
					'chunk_index' => $result['chunk_index'],
					'chunk_text' => $result['chunk_text'],
					'metadata' => $result['metadata'],
					'vector_similarity' => $result['similarity'],
					'keyword_score' => null,
				] + self::UNRANKED;
			}

			$combinedScores[$key]['combined_score'] += $vector->weight / (self::RRF_K + (int)$rank + 1);
			$combinedScores[$key]['in_vector']       = true;
			$combinedScores[$key]['vector_rank']     = (int)$rank + 1;
		}//end foreach

		return $combinedScores;
	}//end addVectorRanks()

	/**
	 * Add the keyword ranks to the fused scores.
	 *
	 * @param array<string, array<string, mixed>> $combinedScores The fused scores so far, keyed by entity.
	 * @param RankedResults                       $keyword        The keyword results and weight.
	 *
	 * @return array<string, array<string, mixed>> The fused scores.
	 */
	private function addKeywordRanks(array $combinedScores, RankedResults $keyword): array {
		foreach ($keyword->results as $rank => $result) {
			$key = $result['entity_type'] . '_' . $result['entity_id'];

			if (isset($combinedScores[$key]) === false) {
				$combinedScores[$key] = [
					'entity_type' => $result['entity_type'],
					'entity_id' => $result['entity_id'],
					'chunk_index' => $result['chunk_index'] ?? 0,
					'chunk_text' => $result['chunk_text'] ?? null,
					'metadata' => $result['metadata'] ?? [],
					'vector_similarity' => null,
					'keyword_score' => $result['score'] ?? null,
				] + self::UNRANKED;
			}

			$combinedScores[$key]['combined_score'] += $keyword->weight / (self::RRF_K + (int)$rank + 1);
			$combinedScores[$key]['in_keyword']      = true;
			$combinedScores[$key]['keyword_rank']    = (int)$rank + 1;
			$combinedScores[$key]['keyword_score']   = $result['score'] ?? null;
		}//end foreach

		return $combinedScores;
	}//end addKeywordRanks()

	/**
	 * Fetch vectors from database with optional filters
	 *
	 * @param array $filters Filters (entity_type, entity_id, etc.)
	 *
	 * @return array<int, array> Vector records from database
	 *
	 * @throws \Exception If query fails
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#3.2
	 */
	private function fetchVectors(array $filters = []): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from('openregister_vectors');

			// Apply filters.
			$this->applyFetchFilters(qb: $qb, filters: $filters);

			// Approximate-fallback bound (hybrid-document-search, decision 4):
			// max_vectors caps how many BLOB rows the O(n) PHP cosine fallback
			// will unserialize and score per request. It is a documented safety
			// cap, NOT a relevance mechanism — ordering is by primary key
			// (stable, non-biasing) instead of the former `created_at DESC`,
			// which silently substituted "newest" for "most relevant" past the
			// cap. The index-backed pgvector KNN path has no such cap.
			$maxVectors = $filters['max_vectors'] ?? 500;
			$qb->setMaxResults($maxVectors);
			$qb->orderBy('id', 'ASC');

			$result = $qb->executeQuery();
			$vectors = $result->fetchAll();

			return $vectors;
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorSearchHandler] Failed to fetch vectors',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			throw new Exception('Failed to fetch vectors: ' . $e->getMessage());
		}//end try
	}//end fetchVectors()

	/**
	 * Narrow the fallback fetch on each entity column (entity_type, entity_id):
	 * IN for a list, = for a value, nothing for null.
	 *
	 * @param IQueryBuilder        $qb      The fetch being built.
	 * @param array<string, mixed> $filters The filters (entity_type, entity_id, etc.).
	 *
	 * @return void
	 */
	private function applyFetchFilters(IQueryBuilder $qb, array $filters): void {
		foreach (array_keys(self::KNN_PARAMETER_NAMES) as $column) {
			$value = ($filters[$column] ?? null);
			if ($value === null) {
				continue;
			}

			if (is_array($value) === true) {
				$qb->andWhere($qb->expr()->in($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_STR_ARRAY)));
				continue;
			}

			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		}
	}//end applyFetchFilters()

	/**
	 * Calculate cosine similarity between two vectors
	 *
	 * @param array<float> $vector1 First vector
	 * @param array<float> $vector2 Second vector
	 *
	 * @return float Similarity score (0-1, where 1 is identical)
	 */
	private function cosineSimilarity(array $vector1, array $vector2): float {
		if (count($vector1) !== count($vector2)) {
			throw new InvalidArgumentException('Vectors must have same dimensions');
		}

		$dotProduct = 0.0;
		$magnitude1 = 0.0;
		$magnitude2 = 0.0;
		$vectorLength = count($vector1);

		for ($i = 0; $i < $vectorLength; $i++) {
			$dotProduct += $vector1[$i] * $vector2[$i];
			$magnitude1 += $vector1[$i] ** 2;
			$magnitude2 += $vector2[$i] ** 2;
		}

		$magnitude1 = sqrt($magnitude1);
		$magnitude2 = sqrt($magnitude2);

		if ($magnitude1 === 0.0 || $magnitude2 === 0.0) {
			return 0.0;
		}

		return $dotProduct / ($magnitude1 * $magnitude2);
	}//end cosineSimilarity()
}//end class
