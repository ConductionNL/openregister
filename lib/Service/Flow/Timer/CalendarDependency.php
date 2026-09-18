<?php

/**
 * Which timers depend on a calendar, asked of the thing that armed them.
 *
 * 🔑 THE DEPENDENCY IS NOT RE-DERIVED, IT IS ASKED (design D-3, read the safe
 * way). The design names three sets — timers naming the slug, timers naming
 * nothing whose organisation resolves to it, and timers naming nothing whose
 * organisation has no calendar at all when the changed one is the default. All
 * three are the SAME question `WorkingCalendarService::resolve()` already
 * answers when a timer is armed, so this asks that method rather than writing
 * the resolution order out a second time. Two implementations of "which
 * calendar does this timer use" is how a recompute silently skips the timers it
 * exists for.
 *
 * 🔴 AND AN UNRESOLVABLE CALENDAR IS COUNTED, NOT SKIPPED. A timer whose
 * calendar no longer exists cannot be judged, and treating "cannot resolve" as
 * "does not depend" would quietly leave exactly those timers on a stale
 * deadline — the ones most likely to be wrong. The caller gets them back as a
 * separate count so the job's log can say so.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Timer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use Throwable;

/**
 * Decides whether one timer's resolved calendar is the changed one.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
class CalendarDependency {

	/**
	 * The timer depends on the changed calendar.
	 *
	 * @var string
	 */
	public const DEPENDS = 'depends';

	/**
	 * The timer resolves to a different calendar.
	 *
	 * @var string
	 */
	public const INDEPENDENT = 'independent';

	/**
	 * The timer's calendar cannot be resolved at all.
	 *
	 * @var string
	 */
	public const UNRESOLVABLE = 'unresolvable';

	/**
	 * Constructor.
	 *
	 * @param WorkingCalendarService $calendars The one resolver, which armed the timers.
	 */
	public function __construct(
		private readonly WorkingCalendarService $calendars,
	) {
	}//end __construct()

	/**
	 * Whether one timer depends on the changed calendar.
	 *
	 * @param string|null $timerCalendarSlug The calendar the timer names, if any.
	 * @param string|null $organisation      The timer's organisation.
	 * @param string      $changedSlug       The calendar that changed.
	 *
	 * @return string One of {@see self::DEPENDS}, {@see self::INDEPENDENT}, {@see self::UNRESOLVABLE}.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function verdictFor(?string $timerCalendarSlug, ?string $organisation, string $changedSlug): string {
		// A timer that NAMES the changed calendar depends on it whatever the
		// resolver would say, and answering that without a lookup is what keeps
		// the common case an index read rather than a resolution per row.
		if (trim((string)$timerCalendarSlug) === $changedSlug && $changedSlug !== '') {
			return self::DEPENDS;
		}

		try {
			$resolved = $this->calendars->resolve(
				calendarSlug: $timerCalendarSlug,
				organisation: $organisation
			);
		} catch (Throwable $e) {
			return self::UNRESOLVABLE;
		}

		if ($resolved->getSlug() === $changedSlug) {
			return self::DEPENDS;
		}

		return self::INDEPENDENT;
	}//end verdictFor()

	/**
	 * Whether a timer is even a candidate, before the resolver is asked.
	 *
	 * The narrowing a query can do with an index: a timer naming ANOTHER
	 * calendar cannot possibly resolve to the changed one, because a named slug
	 * short-circuits the resolution order. Everything else — the changed slug
	 * itself, and every timer naming nothing — has to be asked.
	 *
	 * @param string|null $timerCalendarSlug The calendar the timer names.
	 * @param string      $changedSlug       The calendar that changed.
	 *
	 * @return bool True when the resolver has to be asked.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function isCandidate(?string $timerCalendarSlug, string $changedSlug): bool {
		$named = trim((string)$timerCalendarSlug);

		return ($named === '' || $named === $changedSlug);
	}//end isCandidate()
}//end class
