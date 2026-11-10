<?php

/**
 * ChunkMapperInterface: the public surface of ChunkMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Files
 * @package  OCA\OpenRegister\Files\Db
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

namespace OCA\OpenRegister\Kernel\Contracts;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Every public method of ChunkMapper. Callers depend on this interface, not on the class.
 */
interface ChunkMapperInterface {

	/**
	 * Public wrapper for findEntities (parent protected method).
	 *
	 * @param IQueryBuilder $query The query builder.
	 *
	 * @return list<\OCA\OpenRegister\Files\Db\Chunk> Array of chunks.
	 */
	public function findEntitiesPublic(IQueryBuilder $query): array;

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
	 * @return list<\OCA\OpenRegister\Files\Db\Chunk>
	 *
	 * @psalm-return list<\OCA\OpenRegister\Files\Db\Chunk>
	 */
	public function findBySource(string $sourceType, int $sourceId): array;

	/**
	 * Count all chunks in the database.
	 *
	 * @return int Total chunk count
	 */
	public function countAll(): int;

	/**
	 * Count vectorized chunks.
	 *
	 * Chunks that have been converted to vector embeddings.
	 *
	 * @return int Vectorized chunk count
	 */
	public function countVectorized(): int;

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
	 * @param string $query Search query text
	 * @param int $limit Maximum number of results
	 * @param array $filters Optional filters (source_type)
	 * @param bool $allowUnrankedFallback When true, non-PostgreSQL platforms run an
	 *                                    unranked `LIKE` scan over `text_content`
	 *                                    instead of returning `[]` (see {@see
	 *                                    searchByKeywordUnranked()}). Defaults to
	 *                                    false so the pre-existing hybrid-search
	 *                                    RRF caller ({@see
	 *                                    \OCA\OpenRegister\Files\Controller\FileSearchController::hybridSearch()})
	 *                                    keeps its documented empty-on-non-Postgres
	 *                                    behaviour unchanged.
	 *
	 * @return array<int, array{entity_type: string, entity_id: string, score: float,
	 *                          chunk_text: string|null, chunk_index: int, metadata: array}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Established pattern across this codebase
	 *   for opt-in behaviour flags (see SearchBackendInterface).
	 * @SuppressWarnings(PHPMD.LongVariable)        Descriptive variable name improves readability.
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#3.3
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function searchByKeyword(string $query, int $limit, array $filters = [], bool $allowUnrankedFallback = false): array;
}//end interface
