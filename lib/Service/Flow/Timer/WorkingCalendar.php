<?php

/**
 * A named working calendar: which weekdays work, which dates do not, and how
 * many hours a working day holds.
 *
 * Non-working dates are COMPUTED from rules, not read from a table, so the
 * calendar does not expire (design D-5). A rule is a fixed month/day, an
 * Easter offset (Goede Vrijdag −2, Tweede Paasdag +1, Hemelvaart +39, Tweede
 * Pinksterdag +50), or a fixed date with an observed shift (Koningsdag is
 * 27 April, observed on the 26th when the 27th is a Sunday). Enumerated
 * exception dates are allowed ALONGSIDE rules for a genuine one-off closure;
 * a calendar that is ONLY enumerated dates is refused, because that calendar
 * has an expiry date and degrades silently after it — the failure mode live
 * in the fleet today.
 *
 * `hoursPerWorkingDay` is required: without it `hours` and `businessDays`
 * are not commensurable.
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
 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Timer;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OCA\OpenRegister\Exception\FlowTimerValidationException;

/**
 * A validated, memoising working calendar.
 *
 * @SuppressWarnings(PHPMD.ShortVariable) {@see easterSunday()} uses the
 * single-letter names of the published anonymous Gregorian computus so the
 * code can be checked line by line against its reference.
 */
final class WorkingCalendar implements WorkingCalendarInterface {

	/**
	 * The seeded national default.
	 *
	 * @var string
	 */
	public const DEFAULT_SLUG = 'nl-national';

	/**
	 * Rule kinds.
	 *
	 * @var array<int, string>
	 */
	public const RULE_KINDS = ['fixed', 'easter', 'observedShift'];

	/**
	 * The calendar's non-working dates, memoised per year.
	 *
	 * @var WorkingCalendarHolidays
	 */
	private readonly WorkingCalendarHolidays $holidays;

	/**
	 * Constructor. Use {@see fromArray()}.
	 *
	 * @param WorkingCalendarDefinition $definition The validated definition.
	 */
	private function __construct(
		private readonly WorkingCalendarDefinition $definition,
	) {
		$this->holidays = new WorkingCalendarHolidays(definition: $definition);
	}//end __construct()

	/**
	 * Build and VALIDATE a calendar from its stored definition.
	 *
	 * @param array<string, mixed> $definition The `working-calendar` object data.
	 *
	 * @return self The calendar.
	 *
	 * @throws FlowTimerValidationException On any refused definition, naming the fault.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public static function fromArray(array $definition): self {
		return new self(definition: new WorkingCalendarDefinition(raw: $definition));
	}//end fromArray()

	/**
	 * This calendar with a person's working days laid over it.
	 *
	 * Its zone, rules, closure days, service hours and blackouts stay; the
	 * weekdays and hours the person gives replace the calendar's, and their
	 * absences are non-working days on top of the closure days.
	 *
	 * @param array<int, int>|null                          $weekdays The person's working weekdays, or null to keep the calendar's.
	 * @param float|null                                    $hours    The person's hours per working day, or null to keep the calendar's.
	 * @param array<int, array{date: string, name: string}> $absences The person's days off.
	 *
	 * @return self The overlaid calendar, under the same slug.
	 *
	 * @throws FlowTimerValidationException When the replaced weekdays or hours are refused.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-persons-working-pattern-is-read-from-the-app-that-owns-it-req-wca-006
	 */
	public function withPersonalDays(?array $weekdays, ?float $hours, array $absences): self {
		return new self(definition: $this->definition->withPersonalDays(weekdays: $weekdays, hours: $hours, absences: $absences));
	}//end withPersonalDays()

	/**
	 * The hours of the day this calendar's clock runs, per weekday.
	 *
	 * Empty on a calendar that declares none, and every caller has to ask
	 * {@see ServiceHours::areDeclared()} before using it: an undeclared
	 * calendar counts hours exactly as it did before service hours existed,
	 * which is what lets an instance upgrade without recomputing live terms.
	 *
	 * @return ServiceHours The declared windows.
	 *
	 * @spec openspec/changes/service-hours-and-repeating-reminders/specs/flow-business-timers/spec.md
	 */
	public function getServiceHours(): ServiceHours {
		return $this->definition->getServiceHours();
	}//end getServiceHours()

	/**
	 * The calendar's name.
	 *
	 * @return string The slug.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getSlug(): string {
		return $this->definition->getSlug();
	}//end getSlug()

	/**
	 * The organisation this calendar is configured for.
	 *
	 * @return string|null The organisation, or null for a shared calendar.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getOrganisation(): ?string {
		return $this->definition->getOrganisation();
	}//end getOrganisation()

	/**
	 * The ISO weekdays that are working days.
	 *
	 * Exposed so the admin preview can echo back the weekdays it actually
	 * validated: a panel that previews the holidays but shows the weekdays
	 * straight from its own form cannot tell the reader that a malformed
	 * weekday list was normalised away.
	 *
	 * @return array<int, int> ISO weekdays, 1 = Monday.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-working-calendars-are-administered-under-nextcloud-admin-settings
	 */
	public function getWorkingWeekdays(): array {
		return $this->definition->getWorkingWeekdays();
	}//end getWorkingWeekdays()

	/**
	 * Working hours in one working day.
	 *
	 * @return float The hours.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function getHoursPerWorkingDay(): float {
		return $this->definition->getHoursPerWorkingDay();
	}//end getHoursPerWorkingDay()

	/**
	 * The minute of the day the working day opens.
	 *
	 * WHY THE CALENDAR CARRIES A TIME OF DAY AT ALL. Until now a calendar
	 * answered which DAYS are worked and how many hours one of them holds,
	 * which is everything a deadline needs: "five business days from now"
	 * never asks what time the office opens. Elapsed business time does ask.
	 * A case entered at 16:00 on Friday and left at 09:00 on Monday spans one
	 * working hour or eight depending entirely on where the day starts, and
	 * there is no honest way to answer without knowing.
	 *
	 * The window is start plus `hoursPerWorkingDay`, so the two can never
	 * disagree: a calendar cannot say the day is eight hours long and then
	 * describe a nine-hour window.
	 *
	 * @return integer Minutes past midnight.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function getDayStartsAtMinute(): int {
		return $this->definition->getDayStartsAtMinute();
	}//end getDayStartsAtMinute()

	/**
	 * The minute of the day the working day closes.
	 *
	 * @return integer Minutes past midnight, never beyond the end of the day.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function getDayEndsAtMinute(): int {
		$end = ($this->getDayStartsAtMinute() + (int)round($this->getHoursPerWorkingDay() * 60));

		// A twelve-hour day starting at 18:00 would close at 06:00 the next
		// morning, which is a second day's worth of bookkeeping for a case
		// nobody has. Clamped instead, so the window stays inside its day and
		// the measurement below never has to cross midnight.
		return min($end, (24 * 60));
	}//end getDayEndsAtMinute()

	/**
	 * The zone the organisation counts its days in.
	 *
	 * WHY A CALENDAR HAS A ZONE, AND WHY IT IS NOT THE VIEWER'S. A calendar
	 * date is not an instant: "the term ends on 2 June" becomes a moment only
	 * once somebody says where midnight is. Without a zone the answer is the
	 * server's, which means a term computed at 23:30 UTC lands a day early for
	 * an organisation in Amsterdam and nothing on screen says why.
	 *
	 * It is the ORGANISATION's zone and not the signed-in person's. A display
	 * preference must not move a statutory deadline: two handlers on one case
	 * would then be owed different days, and the one who travelled would be
	 * right.
	 *
	 * @return string An IANA zone name.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md
	 */
	public function getTimezone(): string {
		return $this->definition->getTimezone();
	}//end getTimezone()

	/**
	 * Whether the calendar day containing this instant is a working day.
	 *
	 * @param DateTimeInterface $moment Any instant on the day.
	 *
	 * @return boolean True on a working weekday that is not a non-working date.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function isWorkingDay(DateTimeInterface $moment): bool {
		if (in_array((int)$moment->format('N'), $this->getWorkingWeekdays(), true) === false) {
			return false;
		}

		$nonWorking = $this->nonWorkingDates(year: (int)$moment->format('Y'));

		return array_key_exists($moment->format('Y-m-d'), $nonWorking) === false;
	}//end isWorkingDay()

	/**
	 * The blackout period refusing this kind of booking at this moment, or null.
	 *
	 * Read in the calendar's own zone. A blackout never makes a day
	 * non-working: {@see self::isWorkingDay()} does not consult it, so a term
	 * runs through it as it would otherwise.
	 *
	 * @param DateTimeInterface $moment The booking's moment.
	 * @param string            $kind   The kind of booking, e.g. `hearing`.
	 *
	 * @return array{name: string, from: string, to: string, refuses: array<int, string>}|null
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function blackoutRefusing(DateTimeInterface $moment, string $kind): ?array {
		return $this->definition->getBlackouts()->refusing(moment: $this->inZone(moment: $moment), kind: $kind);
	}//end blackoutRefusing()

	/**
	 * Refuse a booking of this kind at this moment when a blackout covers it.
	 *
	 * @param DateTimeInterface $moment The booking's moment.
	 * @param string            $kind   The kind of booking.
	 *
	 * @return void
	 *
	 * @throws CalendarBlackoutRefusal Naming the period, when one refuses it.
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function assertBookable(DateTimeInterface $moment, string $kind): void {
		$period = $this->blackoutRefusing(moment: $moment, kind: $kind);
		if ($period !== null) {
			throw new CalendarBlackoutRefusal(slug: $this->getSlug(), kind: trim($kind), period: $period);
		}
	}//end assertBookable()

	/**
	 * The declared blackout periods.
	 *
	 * @return array<int, array{name: string, from: string, to: string, refuses: array<int, string>}>
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function getBlackouts(): array {
		return $this->definition->getBlackouts()->all();
	}//end getBlackouts()

	/**
	 * The declared first week of the year: `iso`, `containsJanuary1` or `firstFullWeek`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function getFirstWeekOfYear(): string {
		return $this->definition->getWeekNumbering()->firstWeek;
	}//end getFirstWeekOfYear()

	/**
	 * The week-year and week number of a moment, following the declared first week, in the calendar's zone.
	 *
	 * @param DateTimeInterface $moment The moment.
	 *
	 * @return array{year: int, week: int}
	 *
	 * @spec openspec/changes/working-calendar-admin/specs/flow-business-timers/spec.md#requirement-a-calendar-declares-blackout-periods-and-its-first-week-of-the-year-req-wca-007
	 */
	public function weekOf(DateTimeInterface $moment): array {
		return $this->definition->getWeekNumbering()->weekOf(moment: $this->inZone(moment: $moment));
	}//end weekOf()

	/**
	 * The moment in the calendar's own zone, so the day is the organisation's day.
	 *
	 * @param DateTimeInterface $moment The moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function inZone(DateTimeInterface $moment): DateTimeImmutable {
		return DateTimeImmutable::createFromInterface($moment)->setTimezone(new DateTimeZone($this->getTimezone()));
	}//end inZone()

	/**
	 * The non-working dates of a year, computed from the rules and memoised.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, string> `Y-m-d` => name.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public function nonWorkingDates(int $year): array {
		return ($this->holidays->cached(year: $year) ?? $this->holidays->remember(year: $year, easter: self::easterSunday(year: $year)));
	}//end nonWorkingDates()

	/**
	 * Easter Sunday (Gregorian), by the anonymous algorithm — computed, so no year runs out.
	 *
	 * @param int $year The year.
	 *
	 * @return DateTimeImmutable Easter Sunday at midnight UTC.
	 *
	 * @spec openspec/specs/flow-business-timers/spec.md#requirement-business-time-is-measured-against-one-resolvable-working-calendar
	 */
	public static function easterSunday(int $year): DateTimeImmutable {
		$sum = self::computus(year: $year);
		$month = intdiv($sum, 31);
		$day = (($sum % 31) + 1);

		return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new DateTimeZone('UTC'));
	}//end easterSunday()

	/**
	 * The anonymous Gregorian computus, up to the sum that encodes Easter's month and day.
	 *
	 * @param int $year The year.
	 *
	 * @return integer `h + l - 7m + 114`: month is its quotient by 31, day its remainder plus one.
	 */
	private static function computus(int $year): int {
		$a = ($year % 19);
		$b = intdiv($year, 100);
		$c = ($year % 100);
		$d = intdiv($b, 4);
		$e = ($b % 4);
		$f = intdiv(($b + 8), 25);
		$g = intdiv(($b - $f + 1), 3);
		$h = ((19 * $a + $b - $d - $g + 15) % 30);
		$i = intdiv($c, 4);
		$k = ($c % 4);
		$l = ((32 + 2 * $e + 2 * $i - $h - $k) % 7);
		$m = intdiv(($a + 11 * $h + 22 * $l), 451);

		return ($h + $l - 7 * $m + 114);
	}//end computus()

}//end class
