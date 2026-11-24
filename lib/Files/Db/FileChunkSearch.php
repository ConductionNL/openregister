<?php

/**
 * OpenRegister File Chunk Search
 *
 * Keyword queries over the extracted text chunks of a given set of files.
 * Kept apart from ChunkMapper, which searches every chunk on the instance:
 * this one never looks outside the files it is given.
 *
 * @category  Db
 * @package   OCA\OpenRegister\Files\Db
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Searches the chunks of the given files only.
 *
 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
 */
class FileChunkSearch {

	/**
	 * The chunk table, without prefix.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_chunks';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     The database.
	 * @param LoggerInterface $logger Logs a failed query.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * File chunks that contain the words, limited to the given files.
	 *
	 * Ranked by `ts_rank` on PostgreSQL; elsewhere an unranked substring match
	 * that scores every row 0.0. A query failure degrades to `[]`.
	 *
	 * @param string       $words   The words to look for.
	 * @param array<mixed> $fileIds The files to look in; none means no hits.
	 * @param int          $limit   The most chunks to return.
	 *
	 * @return list<array{fileId: int, rank: float, text: string}> The matching chunks, best first.
	 *
	 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
	 */
	public function searchFileChunks(string $words, array $fileIds, int $limit): array {
		$ids   = self::fileIdList(fileIds: $fileIds);
		$words = trim($words);
		if ($ids === [] || $words === '') {
			return [];
		}

		try {
			$rows = $this->unrankedRows(words: $words, ids: $ids, limit: max(1, $limit));
			if ($this->isPostgres() === true) {
				$rows = $this->rankedRows(words: $words, ids: $ids, limit: max(1, $limit));
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[FileChunkSearch] Scoped file content search failed; returning empty result',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}

		return array_map(
			static fn (array $row): array => [
				'fileId' => (int)$row['source_id'],
				'rank' => (float)($row['score'] ?? 0.0),
				'text' => (string)($row['text_content'] ?? ''),
			],
			$rows
		);
	}//end searchFileChunks()

	/**
	 * Which of the given files have extracted text (at least one chunk).
	 *
	 * @param array<mixed> $fileIds The files to ask about.
	 *
	 * @return list<int> The ids that have chunks.
	 *
	 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
	 */
	public function fileIdsWithChunks(array $fileIds): array {
		$ids = self::fileIdList(fileIds: $fileIds);
		if ($ids === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('source_id')
			->from(self::TABLE)
			->where($qb->expr()->eq('source_type', $qb->createNamedParameter('file', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('source_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$result = $qb->executeQuery();
		$found  = array_map(static fn (mixed $value): int => (int)$value, $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return array_values(array_unique($found));
	}//end fileIdsWithChunks()

	/**
	 * The given file ids as unique positive integers, in the given order.
	 *
	 * @param array<mixed> $fileIds The ids as given: integers or digit strings; anything else is dropped.
	 *
	 * @return list<int> The ids.
	 *
	 * @spec openspec/changes/scoped-file-content-search/specs/scoped-file-content-search/spec.md#requirement-an-app-can-search-the-content-of-the-files-one-user-may-read-req-sfcs-001
	 */
	public static function fileIdList(array $fileIds): array {
		$ids = [];
		foreach ($fileIds as $fileId) {
			$isId = is_int($fileId) === true || (is_string($fileId) === true && ctype_digit($fileId) === true);
			if ($isId === true && (int)$fileId > 0) {
				$ids[(int)$fileId] = (int)$fileId;
			}
		}

		return array_values($ids);
	}//end fileIdList()

	/**
	 * Ranked chunk rows on PostgreSQL.
	 *
	 * @param string    $words The words, trimmed.
	 * @param list<int> $ids   The file ids, positive integers only.
	 * @param int       $limit The most rows.
	 *
	 * @return array<int, array<string, mixed>> The rows, with `score`.
	 */
	private function rankedRows(string $words, array $ids, int $limit): array {
		// The ids are integers by construction (fileIdList), so they are safe to place in the statement.
		$tsVector = "to_tsvector('simple', text_content)";
		$sql      = 'SELECT source_id, text_content, '
			. 'ts_rank(' . $tsVector . ", plainto_tsquery('simple', :query)) AS score "
			. 'FROM *PREFIX*' . self::TABLE . ' '
			. "WHERE source_type = 'file' AND source_id IN (" . implode(',', $ids) . ') '
			. 'AND ' . $tsVector . " @@ plainto_tsquery('simple', :query) "
			. 'ORDER BY score DESC '
			. 'LIMIT ' . $limit;

		$result = $this->db->executeQuery($sql, ['query' => $words]);
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end rankedRows()

	/**
	 * Unranked chunk rows: a case-insensitive substring match.
	 *
	 * @param string    $words The words, trimmed.
	 * @param list<int> $ids   The file ids.
	 * @param int       $limit The most rows.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function unrankedRows(string $words, array $ids, int $limit): array {
		if ($this->isPostgres() === true) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('source_id', 'text_content')
			->from(self::TABLE)
			->where($qb->expr()->eq('source_type', $qb->createNamedParameter('file', IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('source_id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere(
				$qb->expr()->iLike(
					'text_content',
					$qb->createNamedParameter('%' . $this->db->escapeLikeParameter($words) . '%', IQueryBuilder::PARAM_STR)
				)
			)
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		$result = $qb->executeQuery();
		$rows   = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end unrankedRows()

	/**
	 * Whether the connection is PostgreSQL, where the full-text rank lives.
	 *
	 * @return bool True on PostgreSQL.
	 */
	private function isPostgres(): bool {
		return str_contains(get_class($this->db->getDatabasePlatform()), 'PostgreSQL') === true;
	}//end isPostgres()
}//end class
