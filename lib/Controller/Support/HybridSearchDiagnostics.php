<?php

/**
 * Hybrid-document-search readiness diagnostics.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#6.1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use OCA\OpenRegister\Kernel\Contracts\ChunkMapperInterface;
use OCA\OpenRegister\Search\Service\Vectorization\Handlers\PgVectorPlatform;
use OCP\IDBConnection;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reports whether the hybrid-search schema surface exists.
 *
 * That is the `openregister_vec_ann` pgvector ANN sidecar table with its HNSW
 * index (the sidecar replaces the originally-designed in-table column, which
 * broke Doctrine schema introspection) and the functional tsvector GIN index on
 * openregister_chunks, plus vectorization progress so an operator can watch the
 * job-only warm-up converge. All lookups are tolerant: a failed catalog query
 * reports `false` or zero rather than failing the database-info panel.
 *
 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#6.1
 */
final class HybridSearchDiagnostics {

	/**
	 * What is reported when nothing could be read.
	 *
	 * @var array<string, mixed>
	 */
	private const NOTHING_FOUND = [
		'annSidecarTable' => false,
		'embeddingVectorDimension' => null,
		'hnswIndex' => false,
		'textSearchGinIndex' => false,
		'chunks' => ['total' => 0, 'vectorized' => 0],
		'vectors' => ['total' => 0, 'pgvectorPopulated' => 0],
	];

	/**
	 * The diagnostics key of each index looked for.
	 *
	 * @var array<string, string>
	 */
	private const INDEX_KEYS = [
		'idx_or_vec_ann_hnsw' => 'hnswIndex',
		'idx_or_chunks_text_search_gin' => 'textSearchGinIndex',
	];

	/**
	 * Finds the indexes looked for.
	 *
	 * @var string
	 */
	private const INDEX_SQL = 'SELECT indexname FROM pg_indexes WHERE indexname IN '
		. "('idx_or_vec_ann_hnsw', 'idx_or_chunks_text_search_gin')";

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 * @param ContainerInterface $container The container the chunk mapper comes from.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The diagnostics for the active platform.
	 *
	 * @param bool $isPostgres Whether the active platform is PostgreSQL.
	 *
	 * @return array<string, mixed> Diagnostics payload.
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#6.1
	 */
	public function report(bool $isPostgres): array {
		$diagnostics = self::NOTHING_FOUND;
		if ($isPostgres === true) {
			$sidecar = PgVectorPlatform::SIDECAR_TABLE;
			$diagnostics = array_replace($diagnostics, $this->annSidecar(sidecar: $sidecar), $this->searchIndexes());
			$diagnostics['vectors'] = $this->vectorCounts(sidecar: $sidecar);
		}

		$diagnostics['chunks'] = $this->chunkVectorizationProgress();

		return $diagnostics;
	}//end report()

	/**
	 * Whether the pgvector ANN sidecar table exists, and its embedding dimension.
	 *
	 * @param string $sidecar The sidecar table name.
	 *
	 * @return array{annSidecarTable?: true, embeddingVectorDimension?: int} Empty when the sidecar is unavailable.
	 */
	private function annSidecar(string $sidecar): array {
		try {
			$typmod = $this->embeddingTypmod(sidecar: $sidecar);
			if ($typmod !== false && (int)$typmod > 0) {
				return ['annSidecarTable' => true, 'embeddingVectorDimension' => (int)$typmod];
			}
		} catch (\Throwable $e) {
			// Sidecar unavailable: reported as false.
		}

		return [];
	}//end annSidecar()

	/**
	 * The type modifier of the sidecar's embedding column, which holds its dimension.
	 *
	 * @param string $sidecar The sidecar table name.
	 *
	 * @return mixed The modifier, or false without such a column.
	 */
	private function embeddingTypmod(string $sidecar): mixed {
		$result = $this->db->executeQuery(
			'SELECT a.atttypmod FROM pg_attribute a '
			. "WHERE a.attrelid = '" . $sidecar . "'::regclass "
			. "AND a.attname = 'embedding' AND NOT a.attisdropped"
		);
		$typmod = $result->fetchOne();
		$result->closeCursor();

		return $typmod;
	}//end embeddingTypmod()

	/**
	 * Whether the HNSW and the text-search GIN indexes exist.
	 *
	 * @return array{hnswIndex: bool, textSearchGinIndex: bool}
	 */
	private function searchIndexes(): array {
		$found = ['hnswIndex' => false, 'textSearchGinIndex' => false];
		try {
			$result = $this->db->executeQuery(self::INDEX_SQL);
			while (is_array($row = $result->fetch()) === true) {
				$found = $this->withIndex(found: $found, row: $row);
			}

			$result->closeCursor();
		} catch (\Throwable $e) {
			// Reported as false.
		}

		return $found;
	}//end searchIndexes()

	/**
	 * Mark the search index a catalogue row names as found, if it is one we look for.
	 *
	 * @param array{hnswIndex: bool, textSearchGinIndex: bool} $found The indexes found so far.
	 * @param array<string, mixed> $row A pg_indexes row.
	 *
	 * @return array{hnswIndex: bool, textSearchGinIndex: bool} The indexes found.
	 */
	private function withIndex(array $found, array $row): array {
		$key = (self::INDEX_KEYS[$row['indexname']] ?? null);
		if ($key !== null) {
			$found[$key] = true;
		}

		return $found;
	}//end withIndex()

	/**
	 * The vector row count and how many of them have a pgvector sidecar row.
	 *
	 * @param string $sidecar The sidecar table name.
	 *
	 * @return array{total: int, pgvectorPopulated: int}
	 */
	private function vectorCounts(string $sidecar): array {
		try {
			return $this->sidecarVectorCounts(sidecar: $sidecar);
		} catch (\Throwable $e) {
			// The ANN sidecar may not exist yet: plain row count only.
			return ['total' => $this->plainVectorCount(), 'pgvectorPopulated' => 0];
		}
	}//end vectorCounts()

	/**
	 * The vector counts through the sidecar join.
	 *
	 * A fetch can answer NULL as well as false, so the row is checked with is_array.
	 *
	 * @param string $sidecar The sidecar table name.
	 *
	 * @return array{total: int, pgvectorPopulated: int}
	 */
	private function sidecarVectorCounts(string $sidecar): array {
		$result = $this->db->executeQuery(
			'SELECT COUNT(*) AS total, COUNT(a.vector_id) AS populated '
			. 'FROM *PREFIX*openregister_vectors v '
			. "LEFT JOIN $sidecar a ON a.vector_id = v.id"
		);
		$row = $result->fetch();
		$result->closeCursor();
		if (is_array($row) === false) {
			return ['total' => 0, 'pgvectorPopulated' => 0];
		}

		return ['total' => (int)$row['total'], 'pgvectorPopulated' => (int)$row['populated']];
	}//end sidecarVectorCounts()

	/**
	 * The vector row count without the sidecar join.
	 *
	 * @return int The count, or zero when it cannot be read.
	 */
	private function plainVectorCount(): int {
		try {
			$result = $this->db->executeQuery('SELECT COUNT(*) AS total FROM *PREFIX*openregister_vectors');
			$total = $result->fetchOne();
			$result->closeCursor();

			return (int)$total;
		} catch (\Throwable $inner) {
			// Reported as zero.
		}

		return 0;
	}//end plainVectorCount()

	/**
	 * How many chunks exist and how many are vectorized.
	 *
	 * @return array{total: int, vectorized: int} Zeros for what cannot be read.
	 */
	private function chunkVectorizationProgress(): array {
		$progress = ['total' => 0, 'vectorized' => 0];
		try {
			$chunkMapper = $this->container->get(ChunkMapperInterface::class);
			if ($chunkMapper instanceof ChunkMapperInterface) {
				$progress['total'] = $chunkMapper->countAll();
				$progress['vectorized'] = $chunkMapper->countVectorized();
			}
		} catch (\Throwable $e) {
			$context = ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()];
			$this->logger->debug(message: '[SettingsController] Failed to fetch chunk vectorization progress', context: $context);
		}

		return $progress;
	}//end chunkVectorizationProgress()
}//end class
