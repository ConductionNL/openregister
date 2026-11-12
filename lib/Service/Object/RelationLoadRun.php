<?php

/**
 * OpenRegister RelationLoadRun
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

/**
 * One batched relationship load: its size, its batches and when it started, for its log lines.
 *
 * @spec openspec/specs/linked-entity-types/spec.md
 */
final class RelationLoadRun {
	/**
	 * @param int   $requested The number of ids requested (after the cap).
	 * @param int   $batches   The number of batches.
	 * @param float $start     microtime(true) at the start.
	 */
	public function __construct(
		public readonly int $requested,
		public readonly int $batches,
		public readonly float $start,
	) {
	}//end __construct()

	/**
	 * The log context of the start of the load.
	 *
	 * @return array<string, mixed>
	 */
	public function startContext(): array {
		return ['totalRelationships' => $this->requested, 'batchSize' => 50, 'totalBatches' => $this->batches, 'strategy' => 'batched_sequential'];
	}//end startContext()

	/**
	 * The log context of the end of the load.
	 *
	 * @param int $loaded The number of index entries loaded.
	 *
	 * @return array<string, mixed>
	 */
	public function doneContext(int $loaded): array {
		$totalTime = (microtime(true) - $this->start) * 1000;
		return [
			'totalRequested' => $this->requested,
			'totalLoaded' => $loaded,
			'totalTime' => round($totalTime, 2) . 'ms',
			'avgPerBatch' => round($totalTime / $this->batches, 2) . 'ms',
		];
	}//end doneContext()
}//end class
