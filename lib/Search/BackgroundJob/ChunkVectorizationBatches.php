<?php

/**
 * ChunkVectorizationBatches: builds a fresh ChunkVectorizationBatch for every run.
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

use Psr\Container\ContainerInterface;

/**
 * Runs each batch on its own ChunkVectorizationBatch, so a batch's per-source chunk counts never outlive it.
 *
 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
 */
final class ChunkVectorizationBatches implements ChunkVectorizationBatchesInterface {
	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container App container the batch resolves its collaborators from.
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Embed and store the vectors of the next batch of unvectorized chunks.
	 *
	 * @return array{0: int, 1: int} [vectorized count, failed count]
	 *
	 * @throws \Exception When the batch-embedding call itself fails (e.g. no provider configured)
	 *
	 * @spec openspec/changes/archive/2026-10-05-hybrid-document-search/tasks.md#5.2
	 */
	public function vectorizeNextBatch(): array {
		return (new ChunkVectorizationBatch(container: $this->container))->vectorize();
	}//end vectorizeNextBatch()
}//end class
