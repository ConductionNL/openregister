<?php

/**
 * One batch of a calendar recompute: which calendar changed, to which version,
 * the candidate timers, and what to do with a timer whose moment moved.
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
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use Closure;

/**
 * A batch of timers to re-project against one calendar version.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
final class CalendarBatch {
	/**
	 * Describe the batch.
	 *
	 * @param string   $slug      The calendar that changed.
	 * @param string   $version   Its object version.
	 * @param iterable $timers    The candidate timers.
	 * @param Closure  $supersede What to do with a timer whose moment moved: (FlowTimer, string $reason):void.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $version,
		public readonly iterable $timers,
		public readonly Closure $supersede,
	) {
	}//end __construct()

	/**
	 * The supersession reason for a timer this batch moves: `calendar-changed`
	 * followed by the calendar and the object version it changed to.
	 *
	 * The version rides in the reason, not in a new ledger column, so the
	 * ledger row answers "which edit of which calendar moved this deadline"
	 * without widening the record path every other supersession reason uses.
	 * The reason still STARTS with `calendar-changed`, so a reader matching
	 * the reason keeps matching.
	 *
	 * @return string The reason, e.g. `calendar-changed: nl-national version 7`.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md#requirement-a-calendar-change-re-projects-the-timers-that-depend-on-it
	 */
	public function reason(): string {
		return sprintf('%s: %s version %s', CalendarRecomputeInterface::REASON, $this->slug, $this->version);
	}//end reason()
}//end class
