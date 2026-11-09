<?php

/**
 * The calendar-change recompute, as the recompute job reaches it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use OCA\OpenRegister\Db\FlowTimer;

/**
 * Re-projects the timers a changed calendar governs, once per calendar version.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
interface CalendarRecomputeInterface {

	/**
	 * The supersession reason a calendar change carries.
	 *
	 * @var string
	 */
	public const REASON = 'calendar-changed';

	/**
	 * Who the ledger records as the actor.
	 *
	 * A named machine actor rather than the administrator who edited the
	 * calendar: the edit and the supersession are different acts, minutes and
	 * a job apart, and attributing thousands of supersessions to a person who
	 * pressed Save once reads as though they moved each deadline by hand.
	 *
	 * @var string
	 */
	public const ACTOR = 'calendar-recompute';

	/**
	 * How many timers one pass examines before it stores its cursor.
	 *
	 * @var int
	 */
	public const BATCH = 500;

	/**
	 * Record that this calendar version has been recomputed.
	 *
	 * @param string $slug    The calendar.
	 * @param string $version The version.
	 *
	 * @return void
	 */
	public function markRan(string $slug, string $version): void;

	/**
	 * Re-project every timer in this batch that the changed calendar governs.
	 *
	 * @param string                   $slug      The calendar that changed.
	 * @param string                   $version   Its object version.
	 * @param iterable<FlowTimer>      $timers    The candidate timers.
	 * @param callable(FlowTimer):void $supersede What to do with a timer whose moment moved.
	 *
	 * @return array{examined: int, moved: int, unchanged: int, unresolvable: int, deferred: int, skipped: bool} The counts.
	 */
	public function recomputeBatch(string $slug, string $version, iterable $timers, callable $supersede): array;
}//end interface
