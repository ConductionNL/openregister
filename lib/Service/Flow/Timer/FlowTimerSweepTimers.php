<?php

/**
 * The timer side of the sweep: the range scans, what fires them, and the calendars reset per pass.
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

use OCA\OpenRegister\Db\FlowTimerMapperInterface;

/**
 * The collaborators FlowTimerSweep sweeps flow timers with.
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */
final class FlowTimerSweepTimers {
	/**
	 * Bundle the collaborators.
	 *
	 * @param FlowTimerMapperInterface        $timers    The two timer range scans.
	 * @param FlowTimerService       $service   Fires expiries and rungs.
	 * @param WorkingCalendarService $calendars Reset once per pass so memoisation is per pass.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function __construct(
		public readonly FlowTimerMapperInterface $timers,
		public readonly FlowTimerService $service,
		public readonly WorkingCalendarService $calendars,
	) {
	}//end __construct()
}//end class
