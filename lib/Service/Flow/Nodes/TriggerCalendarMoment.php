<?php

/**
 * The moment a calendar trigger fires for one date value.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use OCA\OpenRegister\Service\Flow\Timer\SlaAddition;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendar;

/**
 * A record's date plus the trigger's offset.
 *
 * Without a working calendar the offset is wall-clock time. With one, the
 * years and months of the offset stay calendar arithmetic, its days are
 * counted in working days and its hours, minutes and seconds in hours,
 * all through the flow-business-timers calculator; a moment that then lands
 * outside the calendar's working hours moves to the next opening. A date
 * without a time is midnight in the calendar's zone, or in UTC without one.
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md
 */
final class TriggerCalendarMoment {

	/**
	 * How many days the search for an opening looks ahead before giving up.
	 *
	 * @var integer
	 */
	private const OPENING_HORIZON_DAYS = 400;

	/**
	 * The business-time calculator.
	 *
	 * @var SlaCalculatorInterface
	 */
	private readonly SlaCalculatorInterface $calculator;

	/**
	 * Constructor.
	 *
	 * @param SlaCalculatorInterface|null $calculator The business-time calculator.
	 */
	public function __construct(?SlaCalculatorInterface $calculator = null) {
		$this->calculator = ($calculator ?? new SlaCalculator());
	}//end __construct()

	/**
	 * The moment the trigger fires for a date value, or null when the value holds no date.
	 *
	 * @param mixed                $value    The record's date property value.
	 * @param string               $offset   An ISO 8601 duration, negative for before the date.
	 * @param WorkingCalendar|null $calendar The working calendar the offset is counted in, if any.
	 *
	 * @return DateTimeImmutable|null The moment.
	 *
	 * @throws \InvalidArgumentException When the offset is not an ISO 8601 duration.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md
	 */
	public function moment(mixed $value, string $offset, ?WorkingCalendar $calendar): ?DateTimeImmutable {
		$zone = new DateTimeZone('UTC');
		if ($calendar !== null) {
			$zone = new DateTimeZone($calendar->getTimezone());
		}

		$date = self::date(value: $value, zone: $zone);
		if ($date === null) {
			return null;
		}

		[$sign, $interval] = self::duration(offset: $offset);
		if ($calendar === null) {
			if ($sign < 0) {
				return $date->sub($interval);
			}

			return $date->add($interval);
		}

		return $this->counted(date: $date, sign: $sign, interval: $interval, calendar: $calendar);
	}//end moment()

	/**
	 * The offset counted in the calendar's working time, then moved to an opening.
	 *
	 * @param DateTimeImmutable $date     The date, in the calendar's zone.
	 * @param integer           $sign     1 or -1.
	 * @param DateInterval      $interval The unsigned offset.
	 * @param WorkingCalendar   $calendar The calendar.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	private function counted(DateTimeImmutable $date, int $sign, DateInterval $interval, WorkingCalendar $calendar): DateTimeImmutable {
		$months = new DateInterval(sprintf('P%dY%dM', $interval->y, $interval->m));
		$moment = $date->add($months);
		if ($sign < 0) {
			$moment = $date->sub($months);
		}

		if ($interval->d > 0) {
			$moment = $this->calculator->add(
				addition: new SlaAddition(
					start: $moment,
					value: (float)($sign * $interval->d),
					unit: SlaCalculatorInterface::UNIT_BUSINESS_DAYS,
					calendar: $calendar
				)
			);
		}

		$hours = (($interval->h * 3600) + ($interval->i * 60) + $interval->s) / 3600;
		if ($hours > 0) {
			$moment = $this->calculator->add(
				addition: new SlaAddition(
					start: $moment,
					value: ($sign * $hours),
					unit: SlaCalculatorInterface::UNIT_HOURS,
					calendar: $calendar
				)
			);
		}

		return self::opening(moment: $moment->setTimezone(new DateTimeZone($calendar->getTimezone())), calendar: $calendar);
	}//end counted()

	/**
	 * The moment itself when it falls in working hours, else the next opening.
	 *
	 * @param DateTimeImmutable $moment   The moment, in the calendar's zone.
	 * @param WorkingCalendar   $calendar The calendar.
	 *
	 * @return DateTimeImmutable The moment or the next opening.
	 *
	 * @throws \RuntimeException When the calendar has no working day within the horizon.
	 */
	private static function opening(DateTimeImmutable $moment, WorkingCalendar $calendar): DateTimeImmutable {
		$opens  = $calendar->getDayStartsAtMinute();
		$closes = $calendar->getDayEndsAtMinute();
		$cursor = $moment;
		for ($day = 0; $day < self::OPENING_HORIZON_DAYS; $day++) {
			if ($calendar->isWorkingDay($cursor) === true) {
				$opening = $cursor->setTime(intdiv($opens, 60), ($opens % 60));
				$closing = $cursor->setTime(intdiv($closes, 60), ($closes % 60));
				if ($cursor < $opening) {
					return $opening;
				}

				if ($cursor < $closing) {
					return $cursor;
				}
			}

			$cursor = $cursor->modify('+1 day')->setTime(0, 0);
		}

		throw new \RuntimeException(
			sprintf('The working calendar "%s" has no working day in the next %d days.', $calendar->getSlug(), self::OPENING_HORIZON_DAYS)
		);
	}//end opening()

	/**
	 * The date a property value holds, or null.
	 *
	 * @param mixed        $value The value.
	 * @param DateTimeZone $zone  The zone a date without a time is read in.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private static function date(mixed $value, DateTimeZone $zone): ?DateTimeImmutable {
		if ($value instanceof DateTimeInterface) {
			return DateTimeImmutable::createFromInterface($value);
		}

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$value = trim($value);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
			$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
			if ($date === false || $date->format('Y-m-d') !== $value) {
				return null;
			}

			return $date;
		}

		try {
			return new DateTimeImmutable($value, $zone);
		} catch (Exception) {
			return null;
		}
	}//end date()

	/**
	 * The sign and the unsigned interval of an offset.
	 *
	 * @param string $offset The offset, e.g. `-P30D`.
	 *
	 * @return array{0: int, 1: DateInterval} The sign and the interval.
	 *
	 * @throws \InvalidArgumentException When the offset is not an ISO 8601 duration.
	 */
	private static function duration(string $offset): array {
		$offset = trim($offset);
		$sign   = 1;
		if (str_starts_with($offset, '-') === true) {
			$sign   = -1;
			$offset = substr($offset, 1);
		}

		try {
			return [$sign, new DateInterval($offset)];
		} catch (Exception) {
			throw new \InvalidArgumentException(sprintf('"%s" is not an ISO 8601 duration.', $offset));
		}
	}//end duration()
}//end class
