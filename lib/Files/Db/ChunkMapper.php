<?php

/**
 * Mapper for chunk entities.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use OCA\OpenRegister\Kernel\Contracts\KeywordChunkSearch;
use OCP\AppFramework\Db\Entity;
use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use OCA\OpenRegister\Db\QueryPage;
use OCA\OpenRegister\Db\Source;

/**
 * Class ChunkMapper
 *
 * @method Chunk insert(Entity $entity)
 * @method Chunk update(Entity $entity)
 * @method Chunk insertOrUpdate(Entity $entity)
 * @method Chunk delete(Entity $entity)
 * @method Chunk find(int|string $id)
 * @method Chunk findEntity(IQueryBuilder $query)
 * @method Chunk[] findAll(int|null $limit=null, int|null $offset=null)
 * @method list<Chunk> findEntities(IQueryBuilder $query)
 *
 * @template-extends QBMapper<Chunk>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Data-mapper surface: each method is one
 *   query over openregister_chunks (counts, source lookups, work queues, keyword search);
 *   splitting the mapper would scatter the table's query surface without reducing complexity.
 */
class ChunkMapper extends QBMapper implements ChunkMapperInterface, ChunkIndexOverviewInterface, ChunkStoreInterface {

	/**
	 * PSR-3 logger.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface|null $logger PSR-3 logger (nullable for BC with existing constructions).
	 */
	public function __construct(IDBConnection $db, ?LoggerInterface $logger = null) {
		parent::__construct(db: $db, tableName: 'openregister_chunks', entityClass: Chunk::class);
		$this->logger = $logger ?? new NullLogger();
	}//end __construct()

	/**
	 * Public wrapper for findEntities (parent protected method).
	 *
	 * @param IQueryBuilder $query The query builder.
	 *
	 * @return list<Chunk> Array of chunks.
	 */
	public function findEntitiesPublic(IQueryBuilder $query): array {
		return parent::findEntities(query: $query);
	}//end findEntitiesPublic()

	/**
	 * Find chunks by source reference.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param int $sourceId Source identifier.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 *
	 * @psalm-param non-empty-string   $sourceType
	 *
	 * @return Chunk[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Files\Db\Chunk>
	 */
	public function findBySource(string $sourceType, int $sourceId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)),
					$qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
				)
			)
			->orderBy('chunk_index', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findBySource()

	/**
	 * Delete chunks by source reference.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param int $sourceId Source identifier.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 * @psalm-param   non-empty-string   $sourceType
	 *
	 * @return void
	 */
	public function deleteBySource(string $sourceType, int $sourceId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)),
					$qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
				)
			)
			->executeStatement();
	}//end deleteBySource()

	/**
	 * Get the latest updated timestamp for a source's chunks.
	 *
	 * @param string $sourceType Source type identifier.
	 * @param int $sourceId Source identifier.
	 *
	 * @phpstan-param non-empty-string $sourceType
	 * @psalm-param   non-empty-string   $sourceType
	 *
	 * @return int|null Unix timestamp of the latest update or null when unavailable.
	 */
	public function getLatestUpdatedTimestamp(string $sourceType, int $sourceId): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->createFunction('MAX(updated_at)'), 'max_updated_at')
			->from($this->getTableName())
			->where(
				$qb->expr()->andX(
					$qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType, IQueryBuilder::PARAM_STR)),
					$qb->expr()->eq('source_id', $qb->createNamedParameter($sourceId, IQueryBuilder::PARAM_INT))
				)
			);

		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		if ($value === false || $value === null) {
			return null;
		}

		$timestamp = strtotime((string)$value);

		if ($timestamp === false) {
			return null;
		}

		return $timestamp;
	}//end getLatestUpdatedTimestamp()

	/**
	 * Count all chunks in the database.
	 *
	 * @return int Total chunk count
	 */
	public function countAll(): int {
		return $this->countChunks(flag: null);
	}//end countAll()

	/**
	 * Count indexed chunks.
	 *
	 * Chunks are considered indexed if they have been processed by the search engine.
	 *
	 * @return int Indexed chunk count
	 */
	public function countIndexed(): int {
		return $this->countChunks(flag: 'indexed');
	}//end countIndexed()

	/**
	 * Count vectorized chunks.
	 *
	 * Chunks that have been converted to vector embeddings.
	 *
	 * @return int Vectorized chunk count
	 */
	public function countVectorized(): int {
		return $this->countChunks(flag: 'vectorized');
	}//end countVectorized()

	/**
	 * Count the chunks, or only those with a boolean flag column set.
	 *
	 * @param string|null $flag The flag column (`indexed`, `vectorized`), or null for all chunks.
	 *
	 * @return int The count.
	 */
	private function countChunks(?string $flag): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName());
		if ($flag !== null) {
			$qb->where($qb->expr()->eq($flag, $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}//end countChunks()

	/**
	 * Get file source summaries grouped by source_id.
	 *
	 * Aggregates chunks where source_type='file', joining filecache and mimetypes
	 * to return file metadata alongside chunk statistics.
	 *
	 * @param int|null $limit Maximum number of results
	 * @param int|null $offset Offset for pagination
	 * @param string|null $search Optional search filter on file name
	 * @param FileSourceOrder $order The sort field and direction (default: last extracted, descending)
	 *
	 * @return array List of file source summaries
	 */
	public function getFileSourceSummaries(
		?int $limit = null,
		?int $offset = null,
		?string $search = null,
		FileSourceOrder $order = new FileSourceOrder(),
	): array {
		$qb = $this->fileSourceSummaryQuery(order: $order, search: $search);
		(new QueryPage(limit: $limit, offset: $offset))->applyTo(qb: $qb);

		return $this->fetchFileSourceSummaries(qb: $qb);
	}//end getFileSourceSummaries()

	/**
	 * The unpaged file-source summary query: file chunks grouped per file, sorted and filtered by name.
	 *
	 * @param FileSourceOrder $order  The sort field and direction.
	 * @param string|null     $search Optional search filter on file name.
	 *
	 * @return IQueryBuilder The query, not yet paged.
	 */
	private function fileSourceSummaryQuery(FileSourceOrder $order, ?string $search): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$this->selectFileSourceColumns(qb: $qb);
		$qb->from($this->getTableName(), 'ch')
			->innerJoin('ch', 'filecache', 'fc', $qb->expr()->eq('ch.source_id', 'fc.fileid'))
			->leftJoin('fc', 'mimetypes', 'mt', $qb->expr()->eq('fc.mimetype', 'mt.id'))
			->where($qb->expr()->eq('ch.source_type', $qb->createNamedParameter('file', IQueryBuilder::PARAM_STR)))
			->groupBy('ch.source_id', 'fc.name', 'mt.mimetype', 'fc.size')
			->orderBy($order->column(), $order->direction());

		$this->applyFileNameSearch(qb: $qb, search: $search);

		return $qb;
	}//end fileSourceSummaryQuery()

	/**
	 * Select the per-file chunk statistics and file metadata.
	 *
	 * @param IQueryBuilder $qb The query.
	 *
	 * @return void
	 */
	private function selectFileSourceColumns(IQueryBuilder $qb): void {
		$qb->select('ch.source_id')
			->selectAlias($qb->func()->count('ch.id'), 'chunk_count')
			->selectAlias($qb->createFunction('MIN(ch.created_at)'), 'first_extracted')
			->selectAlias($qb->createFunction('MAX(ch.created_at)'), 'last_extracted')
			->selectAlias('fc.name', 'file_name')
			->selectAlias('mt.mimetype', 'mime_type')
			->selectAlias('fc.size', 'file_size');
	}//end selectFileSourceColumns()

	/**
	 * Run the summary query and shape each row.
	 *
	 * @param IQueryBuilder $qb The paged query.
	 *
	 * @return array List of file source summaries.
	 */
	private function fetchFileSourceSummaries(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = [];

		$row = $result->fetch();
		while ($row !== false) {
			$rows[] = self::fileSourceSummary(row: $row);
			$row = $result->fetch();
		}

		$result->closeCursor();

		return $rows;
	}//end fetchFileSourceSummaries()

	/**
	 * Narrow a file-source query to file names containing the search term.
	 *
	 * @param IQueryBuilder $qb     The query being built.
	 * @param string|null   $search The search term; null or empty adds nothing.
	 *
	 * @return void
	 */
	private function applyFileNameSearch(IQueryBuilder $qb, ?string $search): void {
		if ($search === null || $search === '') {
			return;
		}

		$searchPattern = '%' . $this->db->escapeLikeParameter($search) . '%';
		$qb->andWhere(
			$qb->expr()->iLike(
				'fc.name',
				$qb->createNamedParameter($searchPattern, IQueryBuilder::PARAM_STR)
			)
		);
	}//end applyFileNameSearch()

	/**
	 * Shape one aggregated row as a file source summary.
	 *
	 * @param array $row The database row.
	 *
	 * @return array The summary.
	 */
	private static function fileSourceSummary(array $row): array {
		return [
			'sourceId' => (int)$row['source_id'],
			'chunkCount' => (int)$row['chunk_count'],
			'firstExtracted' => $row['first_extracted'],
			'lastExtracted' => $row['last_extracted'],
			'fileName' => $row['file_name'],
			'mimeType' => $row['mime_type'],
			'fileSize' => (int)$row['file_size'],
		];
	}//end fileSourceSummary()

	/**
	 * Count distinct file sources that have chunks.
	 *
	 * @param string|null $search Optional search filter on file name
	 *
	 * @return int Number of distinct file sources
	 */
	public function countFileSourceSummaries(?string $search = null): int {
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->createFunction('COUNT(DISTINCT ch.source_id) as count'))
			->from($this->getTableName(), 'ch')
			->innerJoin('ch', 'filecache', 'fc', $qb->expr()->eq('ch.source_id', 'fc.fileid'))
			->where($qb->expr()->eq('ch.source_type', $qb->createNamedParameter('file', IQueryBuilder::PARAM_STR)));

		$this->applyFileNameSearch(qb: $qb, search: $search);

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}//end countFileSourceSummaries()

	/**
	 * Find unvectorized chunks.
	 *
	 * Retrieves chunks that have been extracted but not yet vectorized, in
	 * FIFO order (`created_at ASC` — this is a work queue, not a relevance
	 * ranking). Consumed by ChunkVectorizationJob.
	 *
	 * @param int|null $limit Maximum number of chunks to return
	 * @param int|null $offset Offset for pagination
	 *
	 * @return Chunk[] Array of unvectorized chunks
	 *
	 * @psalm-return list<\OCA\OpenRegister\Files\Db\Chunk>
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.1
	 */
	public function findUnvectorized(?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'vectorized',
					$qb->createNamedParameter(
						false,
						\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL
					)
				)
			)
			->orderBy('created_at', 'ASC');

		(new QueryPage(limit: $limit, offset: $offset))->applyTo(qb: $qb);

		return $this->findEntities(query: $qb);
	}//end findUnvectorized()

	/**
	 * Ranked keyword search over chunk text (PostgreSQL only).
	 *
	 * Queries `to_tsvector('simple', text_content) @@ plainto_tsquery('simple',
	 * :query)`, ranked by `ts_rank` descending. The expression form matches the
	 * functional GIN index created by Version1Date20260706101000 (a STORED
	 * tsvector generated column is not viable on Nextcloud: Doctrine schema
	 * introspection throws on unknown column types — live-verified amendment,
	 * 2026-07-06). The 'simple' configuration avoids English-only stemming bias
	 * given OpenRegister's Dutch-government usage context (decision 7).
	 *
	 * Each row is shaped for VectorSearchHandler::reciprocalRankFusion()'s
	 * `$keywordResults` input. On non-PostgreSQL platforms, or when the query
	 * fails, returns [] with a logged warning — never throws (no ranked
	 * keyword path existed before this change on any platform, so this is
	 * purely additive).
	 *
	 * @param KeywordChunkSearch $search The query text, the result cap, optional filters (source_type), and
	 *                                   whether non-PostgreSQL platforms run an unranked `LIKE` scan over
	 *                                   `text_content` instead of returning `[]`. That flag defaults to
	 *                                   false so the hybrid-search RRF caller ({@see
	 *                                   \OCA\OpenRegister\Files\Controller\FileSearchController::hybridSearch()})
	 *                                   keeps its documented empty-on-non-Postgres behaviour.
	 *
	 * @return array<int, array{entity_type: string, entity_id: string, score: float,
	 *                          chunk_text: string|null, chunk_index: int, metadata: array}>
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#3.3
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function searchByKeyword(KeywordChunkSearch $search): array {
		if ($search->allowUnrankedFallback === true) {
			return $this->searchByKeywordWithFallback(search: $search);
		}

		return $this->searchByKeywordRankedOnly(search: $search);
	}//end searchByKeyword()

	/**
	 * Ranked keyword search on PostgreSQL, otherwise the unranked `LIKE` scan.
	 *
	 * @param KeywordChunkSearch $search The query, limit and filters.
	 *
	 * @return array The matching chunks.
	 */
	private function searchByKeywordWithFallback(KeywordChunkSearch $search): array {
		if ($this->isPostgres() === true) {
			return $this->searchByKeywordRanked(search: $search);
		}

		return $this->searchByKeywordUnranked(search: $search);
	}//end searchByKeywordWithFallback()

	/**
	 * Ranked keyword search on PostgreSQL; elsewhere nothing, with a warning.
	 *
	 * @param KeywordChunkSearch $search The query, limit and filters.
	 *
	 * @return array The matching chunks, or `[]` off PostgreSQL.
	 */
	private function searchByKeywordRankedOnly(KeywordChunkSearch $search): array {
		if ($this->isPostgres() === true) {
			return $this->searchByKeywordRanked(search: $search);
		}

		$platform = $this->db->getDatabasePlatform();
		$this->logger->warning(
			message: '[ChunkMapper] Ranked keyword search unavailable: requires PostgreSQL '
				. 'with the tsvector keyword-search index',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'platform' => get_class($platform),
			]
		);
		return [];
	}//end searchByKeywordRankedOnly()

	/**
	 * Whether the connection is PostgreSQL, where the tsvector index lives.
	 *
	 * @return bool True on PostgreSQL.
	 */
	private function isPostgres(): bool {
		return str_contains(get_class($this->db->getDatabasePlatform()), 'PostgreSQL') === true;
	}//end isPostgres()

	/**
	 * Ranked `ts_rank` keyword search (PostgreSQL); [] with a logged warning when the query fails.
	 *
	 * @param KeywordChunkSearch $search Query text, result cap and filters.
	 *
	 * @return array<int, array{entity_type: string, entity_id: string, score: float,
	 *                          chunk_text: string|null, chunk_index: int, metadata: array}>
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#3.3
	 */
	private function searchByKeywordRanked(KeywordChunkSearch $search): array {
		$tsVector = "to_tsvector('simple', text_content)";
		$where = [$tsVector . " @@ plainto_tsquery('simple', :query)"];
		$params = ['query' => $search->query];

		if ($search->sourceType() !== null) {
			$where[] = 'source_type = :sourceType';
			$params['sourceType'] = $search->sourceType();
		}

		$sql = 'SELECT id, source_type, source_id, text_content, chunk_index, '
			. 'ts_rank(' . $tsVector . ", plainto_tsquery('simple', :query)) AS score "
			. 'FROM *PREFIX*openregister_chunks '
			. 'WHERE ' . implode(' AND ', $where) . ' '
			. 'ORDER BY score DESC '
			. 'LIMIT ' . $search->rowCap();

		try {
			$result = $this->db->executeQuery($sql, $params);
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Exception $e) {
			// Query failure (a missing GIN index alone does not fail the
			// expression form, it only slows it) — degrade, don't throw.
			$this->logger->warning(
				message: '[ChunkMapper] Keyword search query failed; returning empty result',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}

		return array_map(
			static fn (array $row): array => self::keywordHit(row: $row, score: (float)$row['score']),
			$rows
		);
	}//end searchByKeywordRanked()

	/**
	 * Shape one chunk row as a keyword-search hit.
	 *
	 * @param array $row   The chunk row.
	 * @param float $score The hit's score.
	 *
	 * @return array{entity_type: string, entity_id: string, score: float,
	 *               chunk_text: string|null, chunk_index: int, metadata: array}
	 */
	private static function keywordHit(array $row, float $score): array {
		return [
			'entity_type' => (string)($row['source_type'] ?? 'file'),
			'entity_id' => (string)$row['source_id'],
			'score' => $score,
			'chunk_text' => $row['text_content'],
			'chunk_index' => (int)$row['chunk_index'],
			'metadata' => [],
		];
	}//end keywordHit()

	/**
	 * Unranked `LIKE` fallback for platforms without a `tsvector` index (e.g. MariaDB).
	 *
	 * Preserves the match set (every chunk whose `text_content` contains the query as a
	 * substring) while degrading ranking — every row scores `0.0` and ordering is by
	 * chunk id rather than relevance. Called only from {@see searchByKeyword()} when
	 * `$allowUnrankedFallback` is true; the pre-existing hybrid-search RRF caller does not
	 * opt in and keeps seeing `[]` on non-PostgreSQL platforms.
	 *
	 * @param KeywordChunkSearch $search Query text, result cap and filters.
	 *
	 * @return array<int, array{entity_type: string, entity_id: string, score: float,
	 *                          chunk_text: string|null, chunk_index: int, metadata: array}>
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	private function searchByKeywordUnranked(KeywordChunkSearch $search): array {
		$qb = $this->db->getQueryBuilder();
		$searchPattern = '%' . $this->db->escapeLikeParameter($search->query) . '%';

		$qb->select('id', 'source_type', 'source_id', 'text_content', 'chunk_index')
			->from($this->getTableName())
			->where(
				$qb->expr()->iLike(
					'text_content',
					$qb->createNamedParameter($searchPattern, IQueryBuilder::PARAM_STR)
				)
			)
			->orderBy('id', 'ASC')
			->setMaxResults($search->rowCap());

		if ($search->sourceType() !== null) {
			$qb->andWhere(
				$qb->expr()->eq(
					'source_type',
					$qb->createNamedParameter($search->sourceType(), IQueryBuilder::PARAM_STR)
				)
			);
		}

		try {
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[ChunkMapper] Unranked keyword search query failed; returning empty result',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}

		return array_map(static fn (array $row): array => self::keywordHit(row: $row, score: 0.0), $rows);
	}//end searchByKeywordUnranked()
}//end class
