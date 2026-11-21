<?php

/**
 * The thresholds a circuit breaker opens, waits and reopens by.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Resilience
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Resilience;

/**
 * When a breaker opens and how long it waits (design D-1).
 *
 * @spec openspec/changes/operate-load-shedding/specs/load-shedding/spec.md
 */
final class BreakerThresholds {

	/**
	 * Constructor.
	 *
	 * @param int $minCalls       Calls in the window before a breaker may open.
	 * @param int $minFailures    Failures in the window before a breaker may open.
	 * @param int $failurePercent The share of failures, in percent, at which it opens.
	 * @param int $coolDown       The first cool-down in seconds.
	 * @param int $maxCoolDown    The longest cool-down in seconds, after doubling.
	 * @param int $window         The window the counts cover, in seconds.
	 * @param int $bucket         The size of one counting bucket, in seconds.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly int $minCalls = 10,
		public readonly int $minFailures = 5,
		public readonly int $failurePercent = 50,
		public readonly int $coolDown = 30,
		public readonly int $maxCoolDown = 300,
		public readonly int $window = 60,
		public readonly int $bucket = 10,
	) {
	}//end __construct()
}//end class
