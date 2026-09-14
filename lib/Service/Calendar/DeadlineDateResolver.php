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
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Calendar;

use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerMapper;
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
	 * @param FlowTimerMapper $timers The flow-timer store.
	 * @param WorkingCalendarService $calendars The working-calendar resolver.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly FlowTimerMapper $timers,
		private readonly WorkingCalendarService $calendars,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The date a deadline publishes on, or null when it publishes nothing.
	 *
	 * @param string $objectUuid The object carrying the deadline.
	 * @param ObjectDateDeclaration $declaration The declaration for the property.
	 * @param DateTimeImmutable|null $rawDate The property's own value, when it has one.
	 * @param string|null $organisation The object's organisation, for calendar resolution.
	 *
	 * @return DateTimeImmutable|null The resolved date, or null.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function resolve(
		string $objectUuid,
		ObjectDateDeclaration $declaration,
		?DateTimeImmutable $rawDate,
		?string $organisation,
	): ?DateTimeImmutable {
		$fromEngine = $this->fromTimer(objectUuid: $objectUuid, declaration: $declaration);
		if ($fromEngine !== null) {
			return $fromEngine;
		}

		if ($rawDate === null) {
			return null;
		}

		return $this->rollToWorkingDay(
			date: $rawDate,
			declaration: $declaration,
			organisation: $organisation
		);
	}//end resolve()

	/**
	 * The fire moment of the open timer this declaration binds to, when any.
	 *
	 * @param string $objectUuid The object.
	 * @param ObjectDateDeclaration $declaration The declaration.
	 *
	 * @return DateTimeImmutable|null The engine's date, or null when unbound or unarmed.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	private function fromTimer(string $objectUuid, ObjectDateDeclaration $declaration): ?DateTimeImmutable {
		if ($declaration->timerPurpose === null) {
			return null;
		}

		try {
			$timers = $this->timers->findBySubject(
				subjectType: 'object',
				subjectUuid: $objectUuid,
				states: self::OPEN_STATES
			);
		} catch (Throwable $failure) {
			$this->logger->warning(
				'[DeadlineDateResolver] Could not read timers for object ' . $objectUuid . ': ' . $failure->getMessage()
			);
			return null;
		}

		foreach ($timers as $timer) {
			if ($timer->getPurpose() !== $declaration->timerPurpose) {
				continue;
			}

			$fireAt = $timer->getFireAt();
			if ($fireAt === null) {
				continue;
			}

			return DateTimeImmutable::createFromInterface($fireAt);
		}

		return null;
	}//end fromTimer()

	/**
	 * Roll a date forward onto the first working day of the resolved calendar.
	 *
	 * @param DateTimeImmutable $date The raw date.
	 * @param ObjectDateDeclaration $declaration The declaration, which may name a calendar.
	 * @param string|null $organisation The object's organisation.
	 *
	 * @return DateTimeImmutable|null The working day, or null when no calendar resolves.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	private function rollToWorkingDay(
		DateTimeImmutable $date,
		ObjectDateDeclaration $declaration,
		?string $organisation,
	): ?DateTimeImmutable {
		try {
			$calendar = $this->calendars->resolve(
				calendarSlug: $declaration->calendarSlug,
				organisation: $organisation
			);
		} catch (Throwable $failure) {
			// Fail closed. Publishing a date the engine would not enforce is
			// worse than publishing nothing.
			$this->logger->warning(
				'[DeadlineDateResolver] No working calendar for ' . $declaration->property . ': ' . $failure->getMessage()
			);
			return null;
		}

		$candidate = $date;
		for ($step = 0; $step < self::MAX_ROLL_DAYS; $step++) {
			if ($calendar->isWorkingDay(moment: $candidate) === true) {
				return $candidate;
			}

			$candidate = $candidate->modify('+1 day');
		}

		$this->logger->warning(
			'[DeadlineDateResolver] Calendar ' . $calendar->getSlug() . ' has no working day within '
			. self::MAX_ROLL_DAYS . ' days of ' . $date->format('Y-m-d') . '; publishing nothing.'
		);

		return null;
	}//end rollToWorkingDay()
}//end class
