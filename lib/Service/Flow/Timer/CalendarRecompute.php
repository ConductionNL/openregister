<?php

/**
 * A calendar changed, so the deadlines measured against it are re-projected
 * (row Q8.17).
 *
 * The register's note: "Nothing recomputes. Five private holiday
 * implementations in `lib/Service/`, so there is no single place a calendar
 * change could be observed." `flow-business-timers` re-arms a timer when its
 * ANCHOR moves; nothing reacted to a calendar edit, so an administrator adding
 * a closure day moved nobody's deadline and every term that crossed it was
 * quietly a day out.
 *
 * 🔑 SUPERSEDE, DO NOT MUTATE (D-4). The existing supersession path already
 * writes the history and already re-inherits the rungs that have fired, so a
 * calendar change lands in the ledger looking exactly like an anchor move with
 * a different reason. Nothing new has to explain itself, and the "a 7-day
 * warning already sent is not repeated" requirement is satisfied by the code
 * that already satisfies it for anchor moves.
 *
 * 🔴 AND THE PROJECTION IS THE ENGINE'S OWN FORMULA, not a second one. The fire
 * moment is `runningSince + max(0, budget - consumed)` in the timer's own unit,
 * which is what `FlowTimerService::recompute()` computes. A recompute that
 * decided "moved" by a different rule than the one that will store the new
 * moment would supersede timers that do not move and skip timers that do, and
 * both failures are invisible in a count.
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

use OCA\OpenRegister\Db\FlowTimer;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-projects the timers a changed calendar governs, once per calendar version.
 *
 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
 */
class CalendarRecompute {

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
	 * Where the "this version has been done" marks are kept.
	 *
	 * @var string
	 */
	public const DONE_KEY_PREFIX = 'calendar_recompute_done_';

	/**
	 * Constructor.
	 *
	 * @param CalendarDependency     $dependency The one dependency question.
	 * @param WorkingCalendarService $calendars  The resolver.
	 * @param SlaCalculator          $calculator The engine.
	 * @param IAppConfig             $appConfig  Where the idempotency mark lives.
	 * @param LoggerInterface        $logger     The logger.
	 */
	public function __construct(
		private readonly CalendarDependency $dependency,
		private readonly WorkingCalendarService $calendars,
		private readonly SlaCalculator $calculator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this calendar version has already been recomputed.
	 *
	 * 🔑 THE KEY IS (SLUG, VERSION), NOT THE SLUG. A second event for the SAME
	 * version is a duplicate and must do nothing; a second event for a LATER
	 * version is a second edit and must run. Keying on the slug alone would
	 * make the second edit of the day a no-op, which is the failure that would
	 * be found months later by a deadline that never moved.
	 *
	 * @param string $slug    The calendar.
	 * @param string $version The calendar object's version.
	 *
	 * @return bool True when it has run.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function alreadyRan(string $slug, string $version): bool {
		return ($this->appConfig->getValueString('openregister', $this->doneKey(slug: $slug), '') === $version);
	}//end alreadyRan()

	/**
	 * Record that this calendar version has been recomputed.
	 *
	 * @param string $slug    The calendar.
	 * @param string $version The version.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function markRan(string $slug, string $version): void {
		$this->appConfig->setValueString('openregister', $this->doneKey(slug: $slug), $version);
	}//end markRan()

	/**
	 * Re-project every timer in this batch that the changed calendar governs.
	 *
	 * @param string                 $slug       The calendar that changed.
	 * @param string                 $version    Its object version.
	 * @param iterable<FlowTimer>    $timers     The candidate timers.
	 * @param callable(FlowTimer):void $supersede What to do with a timer whose moment moved.
	 *
	 * @return array{examined: int, moved: int, unchanged: int, unresolvable: int, deferred: int, skipped: bool} The counts.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function recomputeBatch(string $slug, string $version, iterable $timers, callable $supersede): array {
		if ($this->alreadyRan(slug: $slug, version: $version) === true) {
			$this->logger->info(
				sprintf('[CalendarRecompute] %s version %s has already been recomputed; skipping', $slug, $version)
			);

			return [
				'examined' => 0,
				'moved' => 0,
				'unchanged' => 0,
				'unresolvable' => 0,
				'deferred' => 0,
				'skipped' => true,
			];
		}

		$counts = ['examined' => 0, 'moved' => 0, 'unchanged' => 0, 'unresolvable' => 0, 'deferred' => 0, 'skipped' => false];

		foreach ($timers as $timer) {
			$counts['examined']++;
			$counts[$this->outcomeFor(timer: $timer, slug: $slug, supersede: $supersede)]++;
		}//end foreach

		$this->logger->info(
			sprintf(
				'[CalendarRecompute] %s version %s: examined %d, moved %d, unchanged %d, deferred %d, unresolvable %d',
				$slug,
				$version,
				$counts['examined'],
				$counts['moved'],
				$counts['unchanged'],
				$counts['deferred'],
				$counts['unresolvable']
			)
		);

		return $counts;
	}//end recomputeBatch()

	/**
	 * What became of ONE timer, as the counter key to raise.
	 *
	 * @param FlowTimer                $timer     The timer.
	 * @param string                   $slug      The changed calendar.
	 * @param callable(FlowTimer):void $supersede What to do with a timer whose moment moved.
	 *
	 * @return string One of moved, unchanged, deferred or unresolvable.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	private function outcomeFor(FlowTimer $timer, string $slug, callable $supersede): string {
		$verdict = $this->dependency->verdictFor(
			timerCalendarSlug: $timer->getCalendarSlug(),
			organisation: $timer->getOrganisation(),
			changedSlug: $slug
		);

		if ($verdict === CalendarDependency::UNRESOLVABLE) {
			return 'unresolvable';
		}

		if ($verdict === CalendarDependency::INDEPENDENT) {
			return 'unchanged';
		}

		// 🔑 A SUSPENDED TIMER IS NOT SUPERSEDED, and D-5 says why without
		// quite saying this: its remaining budget is re-projected against the
		// calendar at RESUME, so its moment is already going to be right. It
		// has no stored `fireAt` either — `recompute()` nulls it for anything
		// not armed — so "did the moment move" has nothing to compare, and
		// superseding it would write a successor with no fire moment. Counted
		// separately rather than folded into `unchanged`, because "will be
		// correct later" and "is correct now" are different facts.
		if ($timer->getState() !== FlowTimer::STATE_ARMED) {
			return 'deferred';
		}

		$projected = $this->projectedFireAt(timer: $timer, slug: $slug);
		if ($projected === null) {
			return 'unresolvable';
		}

		$stored = $timer->getFireAt();
		if ($stored !== null && $stored->getTimestamp() === $projected) {
			return 'unchanged';
		}

		try {
			$supersede($timer);
			return 'moved';
		} catch (Throwable $e) {
			// One timer that cannot be superseded must not abandon the rest:
			// the batch is thousands of other people's deadlines.
			$this->logger->error(
				sprintf(
					'[CalendarRecompute] timer %s could not be superseded for %s: %s',
					(string)$timer->getUuid(),
					$slug,
					$e->getMessage()
				)
			);

			return 'unresolvable';
		}//end try
	}//end outcomeFor()

	/**
	 * The fire moment this timer would have under the changed calendar.
	 *
	 * The SAME formula `FlowTimerService::recompute()` uses, deliberately: a
	 * projection that disagrees with the one that stores the result supersedes
	 * timers that do not move and skips timers that do.
	 *
	 * @param FlowTimer $timer The timer.
	 * @param string    $slug  The changed calendar.
	 *
	 * @return int|null The projected timestamp, or null when it cannot be computed.
	 *
	 * @spec openspec/changes/calendar-change-recomputes-timers/specs/flow-business-timers/spec.md
	 */
	public function projectedFireAt(FlowTimer $timer, string $slug): ?int {
		$runningSince = $timer->getRunningSince();
		if ($runningSince === null) {
			return null;
		}

		try {
			$calendar = $this->calendars->resolve(
				calendarSlug: $timer->getCalendarSlug(),
				organisation: $timer->getOrganisation()
			);

			$remaining = ((float)$timer->getBudgetValue() - (float)$timer->getConsumedValue());

			return $this->calculator->add(
				from: $runningSince,
				value: max(0.0, $remaining),
				unit: (string)$timer->getBudgetUnit(),
				calendar: $calendar
			)->getTimestamp();
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[CalendarRecompute] timer %s could not be projected against %s: %s',
					(string)$timer->getUuid(),
					$slug,
					$e->getMessage()
				)
			);

			return null;
		}//end try
	}//end projectedFireAt()

	/**
	 * The app-config key holding the last recomputed version of one calendar.
	 *
	 * @param string $slug The calendar.
	 *
	 * @return string The key.
	 */
	private function doneKey(string $slug): string {
		return (self::DONE_KEY_PREFIX . $slug);
	}//end doneKey()
}//end class
