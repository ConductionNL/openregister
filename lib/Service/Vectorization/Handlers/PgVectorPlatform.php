<?php

/**
 * PgVector Platform Capability Helper
 *
 * Shared detection of the PostgreSQL pgvector fast path for vector storage and
 * search: platform check, ANN sidecar presence + dimension lookup, and pgvector
 * literal formatting.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Vectorization\Handlers;

use Exception;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * PgVectorPlatform
 *
 * Single source of truth for "is the pgvector fast path available, and at what
 * dimension?" — consumed by VectorStorageHandler (dual-write + warm-up backfill)
 * and VectorSearchHandler (SQL KNN path). When this helper reports the fast
 * path as unavailable, both handlers degrade to the serialized-BLOB path
 * exactly as before the hybrid-document-search change.
 *
 * The fast path lives in the UNPREFIXED sidecar table `openregister_vec_ann`
 * (one row per vector: vector_id -> embedding vector(N), HNSW-indexed, cascade
 * delete from the main vectors table). It is deliberately outside Nextcloud's
 * table prefix so Doctrine's introspectSchema() — which throws "Unknown
 * database type vector" for pgvector-typed columns on prefix-matched tables —
 * never sees it (live-verified implementation amendment, 2026-07-06).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class PgVectorPlatform {

	/**
	 * Unprefixed pgvector ANN sidecar table name.
	 *
	 * Must never carry the Nextcloud table prefix: staying outside the
	 * `/^<prefix>/` migration filter is what keeps the vector-typed column
	 * invisible to Doctrine schema introspection.
	 */
	public const SIDECAR_TABLE = 'openregister_vec_ann';

	/**
	 * Cached sidecar embedding dimension.
	 *
	 * False = not yet resolved; null = unavailable (non-Postgres platform,
	 * sidecar missing, or catalog lookup failed); int = usable dimension.
	 *
	 * @var integer|null|false
	 */
	private int|null|false $columnDimension = false;

	/**
	 * Constructor
	 *
	 * @param IDBConnection $db Database connection
	 * @param LoggerInterface $logger PSR-3 logger
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the active database platform is PostgreSQL.
	 *
	 * @return bool True on PostgreSQL
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#3.1
	 */
	public function isPostgres(): bool {
		$platform = $this->db->getDatabasePlatform();

		return str_contains(get_class($platform), 'PostgreSQL');
	}//end isPostgres()

	/**
	 * Get the declared dimension of the ANN sidecar's embedding column.
	 *
	 * Returns null when the fast path is unavailable: non-PostgreSQL platform,
	 * the sidecar table does not exist (pgvector extension missing or the
	 * migration skipped), or the catalog lookup failed. The result is cached
	 * per request.
	 *
	 * For the pgvector type the pg_attribute.atttypmod value is the declared
	 * dimension itself (no header offset, unlike varchar).
	 *
	 * @return int|null Sidecar embedding dimension, or null when unavailable
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#3.1
	 */
	public function getVectorColumnDimension(): ?int {
		if ($this->columnDimension !== false) {
			return $this->columnDimension;
		}

		if ($this->isPostgres() === false) {
			$this->columnDimension = null;
			return null;
		}

		try {
			$result = $this->db->executeQuery(
				'SELECT a.atttypmod FROM pg_attribute a '
				. "WHERE a.attrelid = '" . self::SIDECAR_TABLE . "'::regclass "
				. "AND a.attname = 'embedding' AND NOT a.attisdropped"
			);
			$typmod = $result->fetchOne();
			$result->closeCursor();

			if ($typmod === false || (int)$typmod <= 0) {
				$this->columnDimension = null;
				return null;
			}

			$this->columnDimension = (int)$typmod;
			return $this->columnDimension;
		} catch (Exception $e) {
			$this->logger->debug(
				message: '[PgVectorPlatform] pgvector ANN sidecar unavailable',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			$this->columnDimension = null;
			return null;
		}//end try
	}//end getVectorColumnDimension()

	/**
	 * Format a float array as a pgvector literal ('[0.1,0.2,...]').
	 *
	 * @param array<int|float> $embedding Embedding vector
	 *
	 * @return string pgvector literal
	 *
	 * @spec openspec/changes/hybrid-document-search/tasks.md#2.1
	 */
	public function formatVector(array $embedding): string {
		return '[' . implode(',', array_map(static fn ($v): float => (float)$v, $embedding)) . ']';
	}//end formatVector()
}//end class
