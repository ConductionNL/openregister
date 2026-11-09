<?php

/**
 * ChunkVectorizationBatchesInterface: vectorizes the next batch of unvectorized chunks.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\Search\BackgroundJob
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

namespace OCA\OpenRegister\Search\BackgroundJob;

/**
 * What ChunkVectorizationJob needs from the Search component: one batch of chunks embedded per call.
 *
 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
 */
interface ChunkVectorizationBatchesInterface {
	/**
	 * Embed and store the vectors of the next batch of unvectorized chunks.
	 *
	 * @return array{0: int, 1: int} [vectorized count, failed count]
	 *
	 * @throws \Exception When the batch-embedding call itself fails (e.g. no provider configured)
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	public function vectorizeNextBatch(): array;
}//end interface
