<?php

/**
 * One sweep pass's instant, batch limit and running counts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeInterface;

/**
 * What one `FlowTimerSweep::run()` scans against and what it has done so far.
 *
 * The counts are work PERFORMED, and `truncated` turns true as soon as any one
 * scan returns a full batch.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */
class FlowTimerSweepTally {

	/**
	 * The running result, in the shape `run()` returns.
	 *
	 * @var array{expiriesFired: int, rungsFired: int, taskTimeouts: int, truncated: bool, errors: int}
	 */
	private array $result = [
		'expiriesFired' => 0,
		'rungsFired' => 0,
		'taskTimeouts' => 0,
		'truncated' => false,
		'errors' => 0,
	];

	/**
	 * Constructor.
	 *
	 * @param DateTimeInterface $now   The sweep instant.
	 * @param int               $batch The per-scan batch limit, at least 1.
	 */
	public function __construct(
		public readonly DateTimeInterface $now,
		public readonly int $batch,
	) {

	}//end __construct()

	/**
	 * Add to one of the work counters.
	 *
	 * @param string $counter `expiriesFired`, `rungsFired`, `taskTimeouts` or `errors`.
	 * @param int    $amount  How much work was done.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function add(string $counter, int $amount): void {
		$this->result[$counter] += $amount;

	}//end add()

	/**
	 * Record how many rows one scan returned; a full batch means truncated.
	 *
	 * @param int $found The rows the scan returned.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function scanned(int $found): void {
		if ($found >= $this->batch) {
			$this->result['truncated'] = true;
		}

	}//end scanned()

	/**
	 * The work performed.
	 *
	 * @return array{expiriesFired: int, rungsFired: int, taskTimeouts: int, truncated: bool, errors: int} The result.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function result(): array {
		return $this->result;

	}//end result()
}//end class
