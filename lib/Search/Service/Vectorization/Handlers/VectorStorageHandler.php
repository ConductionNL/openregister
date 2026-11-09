<?php

/**
 * Vector Storage Handler
 *
 * Handles storing vector embeddings in the database (serialized-BLOB storage of
 * record plus an opportunistic PostgreSQL pgvector fast-path column).
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

use Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * VectorStorageHandler
 *
 * Responsible for storing vector embeddings in the PostgreSQL database.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Handlers
 */
class VectorStorageHandler implements VectorStorageHandlerInterface {
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
	 * Store vector embedding in the database
	 *
	 * PostgreSQL is the sole vector storage backend.
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
		return $this->storeVectorInDatabase(vector: $vector);
	}//end storeVector()

	/**
	 * Store vector embedding in database
	 *
	 * @param StoredVector $vector The entity, embedding, model and chunk to store.
	 *
	 * @return int The ID of the inserted vector
	 *
	 * @throws \Exception If storage fails
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function storeVectorInDatabase(StoredVector $vector): int {
		$this->logStoring(vector: $vector);

		try {
			$vectorId = $this->insertVector(vector: $vector);

			// Additive pgvector dual-write (hybrid-document-search, decision 1): the
			// fast-path column is filled when it fits; the BLOB stays the storage of record.
			$this->populateVectorColumn(vectorId: $vectorId, embedding: $vector->embedding);
			$this->logStored(vector: $vector, vectorId: $vectorId);

			return $vectorId;
		} catch (Exception $e) {
			$this->logStoreFailure(vector: $vector, error: $e);
			throw new Exception('Vector storage failed: ' . $e->getMessage());
		}//end try
	}//end storeVectorInDatabase()

	/**
	 * Insert the vector row.
	 *
	 * @param StoredVector $vector The vector to insert.
	 *
	 * @return int The ID of the inserted row.
	 */
	private function insertVector(StoredVector $vector): int {
		$qb = $this->db->getQueryBuilder();
		$qb->insert('openregister_vectors')
			->values(values: $this->rowValues(qb: $qb, vector: $vector))
			->executeStatement();

		return $qb->getLastInsertId();
	}//end insertVector()

	/**
	 * The bound columns of one vector row.
	 *
	 * @param IQueryBuilder $qb     The insert being built.
	 * @param StoredVector  $vector The vector to insert.
	 *
	 * @return array<string, mixed> The column values.
	 */
	private function rowValues(IQueryBuilder $qb, StoredVector $vector): array {
		$chunkText = $this->sanitizedChunkText(vector: $vector);
		return [
			'entity_type' => $qb->createNamedParameter($vector->entityType),
			'entity_id' => $qb->createNamedParameter($vector->entityId),
			'chunk_index' => $qb->createNamedParameter($vector->chunkIndex, \PDO::PARAM_INT),
			'total_chunks' => $qb->createNamedParameter($vector->totalChunks, \PDO::PARAM_INT),
			'chunk_text' => $qb->createNamedParameter($chunkText),
			'embedding' => $qb->createNamedParameter(serialize($vector->embedding), \PDO::PARAM_LOB),
			'embedding_model' => $qb->createNamedParameter($vector->model),
			'embedding_dimensions' => $qb->createNamedParameter($vector->dimensions, \PDO::PARAM_INT),
			'metadata' => $qb->createNamedParameter($this->metadataJson(vector: $vector)),
			'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
		];
	}//end rowValues()

	/**
	 * The metadata as JSON, or null when there is none.
	 *
	 * @param StoredVector $vector The vector.
	 *
	 * @return string|false|null The JSON, false when it cannot be encoded, or null.
	 */
	private function metadataJson(StoredVector $vector): string|false|null {
		if (empty($vector->metadata) === true) {
			return null;
		}

		return json_encode($vector->metadata);
	}//end metadataJson()

	/**
	 * The embedded text made safe for UTF-8 storage, or null when there is none.
	 *
	 * @param StoredVector $vector The vector.
	 *
	 * @return string|null The text.
	 */
	private function sanitizedChunkText(StoredVector $vector): ?string {
		if ($vector->chunkText === null) {
			return null;
		}

		return $this->sanitizeText(text: $vector->chunkText);
	}//end sanitizedChunkText()

	/**
	 * Log that a vector is about to be stored.
	 *
	 * @param StoredVector $vector The vector.
	 *
	 * @return void
	 */
	private function logStoring(StoredVector $vector): void {
		$this->logger->debug(
			message: '[VectorStorageHandler] Storing vector in database',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				...$this->entityContext(vector: $vector),
				'chunk_index' => $vector->chunkIndex,
				'dimensions' => $vector->dimensions,
			]
		);
	}//end logStoring()

	/**
	 * Log that a vector was stored.
	 *
	 * @param StoredVector $vector   The vector.
	 * @param int          $vectorId The ID of the inserted row.
	 *
	 * @return void
	 */
	private function logStored(StoredVector $vector, int $vectorId): void {
		$this->logger->info(
			message: '[VectorStorageHandler] Vector stored successfully in database',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'vector_id' => $vectorId,
				...$this->entityContext(vector: $vector),
			]
		);
	}//end logStored()

	/**
	 * Log that storing a vector failed.
	 *
	 * @param StoredVector $vector The vector.
	 * @param Exception    $error  The failure.
	 *
	 * @return void
	 */
	private function logStoreFailure(StoredVector $vector, Exception $error): void {
		$this->logger->error(
			message: '[VectorStorageHandler] Failed to store vector in database',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'error' => $error->getMessage(),
				...$this->entityContext(vector: $vector),
			]
		);
	}//end logStoreFailure()

	/**
	 * The entity a vector belongs to, as log context.
	 *
	 * @param StoredVector $vector The vector.
	 *
	 * @return array{entity_type: string, entity_id: string} The context.
	 */
	private function entityContext(StoredVector $vector): array {
		return [
			'entity_type' => $vector->entityType,
			'entity_id' => $vector->entityId,
		];
	}//end entityContext()

	/**
	 * Upsert the pgvector ANN sidecar row for a stored vector.
	 *
	 * PostgreSQL + matching configured dimension only (hybrid-document-search,
	 * decision 2): vectors whose embedding dimension does not match the
	 * sidecar's declared dimension get no sidecar row and keep being served by
	 * the PHP-cosine fallback. Failures are logged, never fatal — the BLOB
	 * write is the durable storage of record.
	 *
	 * @param int $vectorId Vector row id
	 * @param array $embedding Embedding (array of floats)
	 *
	 * @return bool True when the sidecar row was written
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#2.1
	 */
	private function populateVectorColumn(int $vectorId, array $embedding): bool {
		$columnDimension = $this->pgVector->getVectorColumnDimension();

		if ($columnDimension === null || count($embedding) !== $columnDimension) {
			return false;
		}

		try {
			$this->db->executeStatement(
				'INSERT INTO ' . PgVectorPlatform::SIDECAR_TABLE . ' (vector_id, embedding) '
				. 'VALUES (?, ?::vector) '
				. 'ON CONFLICT (vector_id) DO UPDATE SET embedding = EXCLUDED.embedding',
				[(string)$vectorId, $this->pgVector->formatVector($embedding)]
			);

			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				message: '[VectorStorageHandler] Failed to write pgvector ANN sidecar row',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'vector_id' => $vectorId,
					'error' => $e->getMessage(),
				]
			);

			return false;
		}//end try
	}//end populateVectorColumn()

	/**
	 * Warm-up backfill: convert existing BLOB rows to pgvector ANN sidecar rows.
	 *
	 * Job-only warm-up (DECIDED 2026-07-06): the migration creates the sidecar
	 * table and index only; this method — driven by ChunkVectorizationJob —
	 * converts existing rows in bounded batches, selecting vectors WITHOUT a
	 * sidecar row (the sidecar equivalent of `embedding_vector IS NULL`) whose
	 * stored dimension matches the sidecar's declared dimension (idempotent:
	 * converted rows drop out of the selection). Rows with an unparseable BLOB
	 * are logged and skipped; the `$afterId` cursor lets the caller make
	 * progress past persistently-failing rows.
	 *
	 * @param int $batchSize Maximum rows to process in this call
	 * @param int $afterId Only process rows with id > this cursor
	 *
	 * @return array{converted: int, failed: int, last_id: int, remaining: int}
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#2.2
	 */
	public function backfillEmbeddingVectors(int $batchSize = 100, int $afterId = 0): array {
		$tally = [
			'converted' => 0,
			'failed' => 0,
			'last_id' => $afterId,
			'remaining' => 0,
		];

		$columnDimension = $this->pgVector->getVectorColumnDimension();

		if ($columnDimension === null) {
			return $tally;
		}

		try {
			foreach ($this->selectBackfillRows(columnDimension: $columnDimension, batchSize: $batchSize, afterId: $afterId) as $row) {
				$tally['last_id'] = (int)$row['id'];
				$tally[$this->backfillRow(row: $row, columnDimension: $columnDimension)]++;
			}

			$tally['remaining'] = $this->countRemainingBackfill(columnDimension: $columnDimension);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[VectorStorageHandler] pgvector warm-up backfill batch failed',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);

			$tally['remaining'] = 0;
		}//end try

		return $tally;
	}//end backfillEmbeddingVectors()

	/**
	 * Select the next batch of sidecar-less, dimension-matched vector rows.
	 *
	 * @param int $columnDimension Sidecar column dimension
	 * @param int $batchSize Maximum rows to select
	 * @param int $afterId Only rows with id > this cursor
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function selectBackfillRows(int $columnDimension, int $batchSize, int $afterId): array {
		$sidecar = PgVectorPlatform::SIDECAR_TABLE;
		$result = $this->db->executeQuery(
			'SELECT v.id, v.embedding FROM *PREFIX*openregister_vectors v '
			. "LEFT JOIN $sidecar a ON a.vector_id = v.id "
			. 'WHERE a.vector_id IS NULL AND v.embedding_dimensions = ? AND v.id > ? '
			. 'ORDER BY v.id ASC LIMIT ' . ((int)$batchSize),
			[(string)$columnDimension, (string)$afterId]
		);
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}//end selectBackfillRows()

	/**
	 * Count the vector rows still lacking a sidecar row.
	 *
	 * @param int $columnDimension Sidecar column dimension
	 *
	 * @return int
	 */
	private function countRemainingBackfill(int $columnDimension): int {
		$sidecar = PgVectorPlatform::SIDECAR_TABLE;
		$remainingResult = $this->db->executeQuery(
			'SELECT COUNT(*) FROM *PREFIX*openregister_vectors v '
			. "LEFT JOIN $sidecar a ON a.vector_id = v.id "
			. 'WHERE a.vector_id IS NULL AND v.embedding_dimensions = ?',
			[(string)$columnDimension]
		);
		$remaining = (int)$remainingResult->fetchOne();
		$remainingResult->closeCursor();

		return $remaining;
	}//end countRemainingBackfill()

	/**
	 * Convert one BLOB row into a sidecar row.
	 *
	 * @param array<string, mixed> $row Vector row (id, embedding)
	 * @param int $columnDimension Sidecar column dimension
	 *
	 * @return string The tally key: 'converted' or 'failed'
	 */
	private function backfillRow(array $row, int $columnDimension): string {
		$embedding = $this->decodeEmbeddingBlob(blob: $row['embedding']);

		if (is_array($embedding) === false || count($embedding) !== $columnDimension) {
			$this->logger->warning(
				message: '[VectorStorageHandler] Skipping backfill for unparseable embedding BLOB',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'vector_id' => $row['id'],
				]
			);

			return 'failed';
		}

		// A false populate result was logged by populateVectorColumn already.
		if ($this->populateVectorColumn(vectorId: (int)$row['id'], embedding: $embedding) === true) {
			return 'converted';
		}

		return 'failed';
	}//end backfillRow()

	/**
	 * Decode a stored embedding BLOB.
	 *
	 * PostgreSQL returns BLOB columns as stream resources (live-verified on
	 * PG16); they are normalised to a string first. SEC-SVC-9: embeddings are
	 * plain float arrays; never allow object instantiation during unserialize.
	 * The error-control operator suppresses the E_WARNING malformed input
	 * emits — the false return value is handled by the caller.
	 *
	 * @param mixed $blob Raw column value
	 *
	 * @return mixed The decoded value, false when undecodable
	 *
	 * @SuppressWarnings(PHPMD.ErrorControlOperator)  @unserialize: malformed BLOBs emit
	 *   E_WARNING; the false return is handled explicitly (row skipped + logged).
	 */
	private function decodeEmbeddingBlob(mixed $blob): mixed {
		if (is_resource($blob) === true) {
			$blob = stream_get_contents($blob);
		}

		if (is_string($blob) === false) {
			return false;
		}

		return @unserialize($blob, ['allowed_classes' => false]);
	}//end decodeEmbeddingBlob()

	/**
	 * Sanitize text to prevent UTF-8 encoding errors
	 *
	 * Removes invalid UTF-8 sequences and problematic control characters.
	 *
	 * @param string $text Text to sanitize
	 *
	 * @return string Sanitized text safe for UTF-8 storage
	 *
	 * @spec openspec/specs/vector-embeddings/spec.md
	 */
	private function sanitizeText(string $text): string {
		// Step 1: Remove invalid UTF-8 sequences.
		$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

		// Step 2: Remove NULL bytes and other problematic control characters.
		$text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

		// Step 3: Replace any remaining invalid UTF-8 with replacement character.
		$text = iconv('UTF-8', 'UTF-8//IGNORE', $text);

		// Step 4: Normalize whitespace.
		$text = preg_replace('/\s+/u', ' ', $text);

		return trim($text);
	}//end sanitizeText()
}//end class
