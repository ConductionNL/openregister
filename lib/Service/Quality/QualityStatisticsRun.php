<?php

/**
 * OpenRegister QualityStatisticsRun
 *
 * The running totals of one quality statistics pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Quality
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Quality;

/**
 * The running totals of one QualityStatisticsService::statisticsFor() pass.
 *
 * Built by statisticsFor() and never handed out of the service: the reading is
 * fixed for the pass, the counters move as objects are tallied.
 *
 * @spec openspec/changes/mdm-surface-api/tasks.md#task-1
 */
final class QualityStatisticsRun {

	/**
	 * How many scored objects fell in each status bucket.
	 *
	 * @var array<string, int>
	 */
	public array $buckets = [
		'good' => 0,
		'fair' => 0,
		'poor' => 0,
	];

	/**
	 * The histogram, keyed by bucket index.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $histogram = [];

	/**
	 * The sum of the scores tallied so far.
	 *
	 * @var float
	 */
	public float $sum = 0.0;

	/**
	 * How many scored objects were tallied so far.
	 *
	 * @var integer
	 */
	public int $total = 0;

	/**
	 * Constructor.
	 *
	 * @param QualityScoreReading $reading Where the score lives and its status cut-offs.
	 */
	public function __construct(
		public readonly QualityScoreReading $reading,
	) {
	}//end __construct()
}//end class
