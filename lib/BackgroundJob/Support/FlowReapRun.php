<?php

/**
 * One reap of abandoned flow work: the pass clock and the stale window.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use DateTime;

/**
 * The state the run reaper and the claim reaper of one FlowRunWorker pass
 * share: the pass clock and the window after which work counts as abandoned.
 * Both reapers derive their cutoff from it, so the two can never contradict
 * each other.
 *
 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
 */
final class FlowReapRun {

	/**
	 * Constructor.
	 *
	 * @param DateTime $now     The pass clock.
	 * @param int      $minutes Work untouched for more than this many minutes is abandoned.
	 */
	public function __construct(
		public readonly DateTime $now,
		public readonly int $minutes,
	) {
	}//end __construct()

	/**
	 * The moment before which work counts as abandoned.
	 *
	 * @return DateTime A new instance; the pass clock is not modified.
	 */
	public function cutoff(): DateTime {
		return (clone $this->now)->modify('-' . $this->minutes . ' minutes');
	}//end cutoff()
}//end class
