<?php

/**
 * Resolves the day a deadline actually falls on.
 *
 * The term engine already computes the date a term lands on: it counts in
 * business time against a working calendar, and it moves when the term is
 * suspended, extended or recalculated. A feed that published the raw
 * `+6 weeks` would show a date the engine would not enforce, and a wrong date
 * in an agenda is worse than no date, because it is believed.
 *
 * So a deadline is resolved in two steps, in this order:
 *
 *  1. When the declaration binds the property to a flow timer, the open
 *     timer's fire moment is the answer. That is the engine's own number.
 *  2. Otherwise the raw property value is rolled forward onto the first
 *     working day of the resolved calendar, so a term that falls on a closure
 *     day publishes on the day work resumes.
 *
 * A calendar that cannot be resolved publishes nothing. Substituting a
 * weekday-only calendar would be the quiet wrong answer this whole class
 * exists to avoid.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Calendar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapperInterface;
use OCA\OpenRegister\Db\FlowTimer\TimerSubject;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The working day a deadline publishes on.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class DeadlineDateResolver {

	/**
	 * How many days forward the roll may walk before giving up. A calendar
	 * that closes for more than a year is a misconfiguration, not a holiday.
	 *
	 * @var int
	 */
	private const MAX_ROLL_DAYS = 400;

	/**
	 * The timer states that still hold a live term.
	 *
	 * @var array<int, string>
	 */
	private const OPEN_STATES = [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED];

	/**
	 * Constructor.
	 *
	 * @param FlowTimerMapperInterface $timers The flow-timer store.
	 * @param WorkingCalendarService $calendars The working-calendar resolver.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly FlowTimerMapperInterface $timers,
		private readonly WorkingCalendarService $calendars,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The date a deadline publishes on, or null when it publishes nothing.
	 *
	 * @param DeadlineLookup $lookup The object, declaration, raw date and organisation.
	 *
	 * @return DateTimeImmutable|null The resolved date, or null.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function resolve(DeadlineLookup $lookup): ?DateTimeImmutable {
		return ($this->fromTimer(objectUuid: $lookup->objectUuid, declaration: $lookup->declaration)
			?? $this->rollRawDate(lookup: $lookup));
	}//end resolve()

	/**
	 * The working day a raw date rolls to, or null when there is no raw date.
	 *
	 * @param DeadlineLookup $lookup The lookup carrying the raw date.
	 *
	 * @return DateTimeImmutable|null The working day, or null.
	 */
	private function rollRawDate(DeadlineLookup $lookup): ?DateTimeImmutable {
		if ($lookup->rawDate === null) {
			return null;
		}

		return $this->rollToWorkingDay(date: $lookup->rawDate, lookup: $lookup);
	}//end rollRawDate()

	/**
	 * The fire moment of the open timer this declaration binds to, when any.
	 *
	 * @param string $objectUuid The object.
	 * @param ObjectDateDeclaration $declaration The declaration.
	 *
	 * @return DateTimeImmutable|null The engine's date, or null when unbound or unarmed.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function fromTimer(string $objectUuid, ObjectDateDeclaration $declaration): ?DateTimeImmutable {
		if ($declaration->timerPurpose === null) {
			return null;
		}

		$timers = $this->openTimers(objectUuid: $objectUuid);
		if ($timers === null) {
			return null;
		}

		return $this->fireMoment(timers: $timers, purpose: $declaration->timerPurpose);
	}//end fromTimer()

	/**
	 * The object's armed and suspended timers, or null when they cannot be read.
	 *
	 * @param string $objectUuid The object.
	 *
	 * @return array<int, FlowTimer>|null The open timers, or null on a read failure.
	 */
	private function openTimers(string $objectUuid): ?array {
		try {
			return $this->timers->findBySubject(
				subject: new TimerSubject(type: 'object', uuid: $objectUuid),
				states: self::OPEN_STATES
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[DeadlineDateResolver] Could not read timers for object ' . $objectUuid . ': ' . $failure->getMessage()
			);
			return null;
		}
	}//end openTimers()

	/**
	 * The fire moment of the first timer of a purpose that has one.
	 *
	 * @param array<int, FlowTimer> $timers The open timers.
	 * @param string $purpose The timer purpose the declaration binds to.
	 *
	 * @return DateTimeImmutable|null The fire moment, or null when no such timer is set.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromInterface is the PHP conversion; there is no DI alternative.
	 */
	private function fireMoment(array $timers, string $purpose): ?DateTimeImmutable {
		foreach ($timers as $timer) {
			if ($timer->getPurpose() !== $purpose) {
				continue;
			}

			$fireAt = $timer->getFireAt();
			if ($fireAt === null) {
				continue;
			}

			return DateTimeImmutable::createFromInterface($fireAt);
		}

		return null;
	}//end fireMoment()

	/**
	 * Roll a date forward onto the first working day of the resolved calendar.
	 *
	 * @param DateTimeImmutable $date The raw date.
	 * @param DeadlineLookup $lookup The declaration, which may name a calendar, and the organisation.
	 *
	 * @return DateTimeImmutable|null The working day, or null when no calendar resolves.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function rollToWorkingDay(DateTimeImmutable $date, DeadlineLookup $lookup): ?DateTimeImmutable {
		$declaration = $lookup->declaration;
		try {
			$calendar = $this->calendars->resolve(
				calendarSlug: $declaration->calendarSlug,
				organisation: $lookup->organisation
			);
		} catch (Throwable $failure) {
			// Fail closed. Publishing a date the engine would not enforce is
			// worse than publishing nothing.
			$this->logger->warning(
				'[DeadlineDateResolver] No working calendar for ' . $declaration->property . ': ' . $failure->getMessage()
			);
			return null;
		}

		$workingDay = $this->firstWorkingDay(calendar: $calendar, date: $date);
		if ($workingDay !== null) {
			return $workingDay;
		}

		$this->logger->warning(
			'[DeadlineDateResolver] Calendar ' . $calendar->getSlug() . ' has no working day within '
			. self::MAX_ROLL_DAYS . ' days of ' . $date->format('Y-m-d') . '; publishing nothing.'
		);

		return null;
	}//end rollToWorkingDay()

	/**
	 * The first working day on or after a date, within MAX_ROLL_DAYS.
	 *
	 * @param WorkingCalendar $calendar The resolved calendar.
	 * @param DateTimeImmutable $date The raw date.
	 *
	 * @return DateTimeImmutable|null The working day, or null when none is in reach.
	 */
	private function firstWorkingDay(WorkingCalendar $calendar, DateTimeImmutable $date): ?DateTimeImmutable {
		$candidate = $date;
		for ($step = 0; $step < self::MAX_ROLL_DAYS; $step++) {
			if ($calendar->isWorkingDay(moment: $candidate) === true) {
				return $candidate;
			}

			$candidate = $candidate->modify('+1 day');
		}

		return null;
	}//end firstWorkingDay()
}//end class
