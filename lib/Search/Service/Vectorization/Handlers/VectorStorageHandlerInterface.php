<?php

/**
 * VectorStorageHandlerInterface: the public surface of VectorStorageHandler, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search\Service\Vectorization\Handlers
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

namespace OCA\OpenRegister\Search\Service\Vectorization\Handlers;

/**
 * Every public method of VectorStorageHandler. Callers depend on this interface, not on the class.
 */
interface VectorStorageHandlerInterface {

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
	public function storeVector(StoredVector $vector): int;

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
	 * @SuppressWarnings(PHPMD.ErrorControlOperator)  @unserialize: malformed BLOBs emit
	 *   E_WARNING; the false return is handled explicitly (row skipped + logged).
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  One bounded batch loop with explicit
	 *   per-row failure handling (resource normalisation, parse check, upsert outcome).
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Selection + per-row tolerance +
	 *   remaining-count reporting belong to one atomic batch step.
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#2.2
	 */
	public function backfillEmbeddingVectors(int $batchSize = 100, int $afterId = 0): array;
}//end interface
