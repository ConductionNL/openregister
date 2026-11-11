<?php

/**
 * Writes RFC 5545 iCalendar text.
 *
 * A calendar client is not a browser: it will accept a malformed body and
 * then show the wrong day, or nothing, without complaining. So the two rules
 * that get broken most often are enforced here rather than at each call site.
 *
 *  - Every line is folded at 75 octets and terminated with CRLF. A long Dutch
 *    case title is longer than that on its own, and an unfolded line is where
 *    a feed stops parsing halfway.
 *  - A timed event never carries a naive local time. It carries a TZID, and
 *    the calendar carries the matching VTIMEZONE, so a client in another zone
 *    resolves the same instant. Dropping the offset is the exact defect the
 *    corpus found in Deck.
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
use DateTimeZone;

/**
 * Folds, escapes and assembles iCalendar bodies.
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class IcalendarWriter {

	/**
	 * The maximum octets on one content line before folding, per RFC 5545.
	 *
	 * @var int
	 */
	private const FOLD_AT = 75;

	/**
	 * The product identifier this feed announces itself as.
	 *
	 * @var string
	 */
	public const PRODID = '-//Conduction//OpenRegister//EN';

	/**
	 * Escape a text value for a TEXT-typed property.
	 *
	 * Backslash first: escaping it after the others would double-escape the
	 * backslashes those others just introduced.
	 *
	 * @param string $value The raw text.
	 *
	 * @return string The escaped text.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function escapeText(string $value): string {
		$escaped = str_replace('\\', '\\\\', $value);
		$escaped = str_replace(["\r\n", "\n", "\r"], '\\n', $escaped);
		$escaped = str_replace(';', '\;', $escaped);
		$escaped = str_replace(',', '\\,', $escaped);

		return $escaped;
	}//end escapeText()

	/**
	 * Assemble a VCALENDAR from component lines.
	 *
	 * @param CalendarContents $contents The name, zone, component lines and years.
	 *
	 * @return string The complete, folded, CRLF-terminated calendar.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function calendar(CalendarContents $contents): string {
		$lines = array_merge(
			$this->calendarHeader(contents: $contents),
			$this->timeZoneLines(contents: $contents),
			$contents->componentLines,
			['END:VCALENDAR']
		);

		$folded = array_map([$this, 'fold'], $lines);

		return implode("\r\n", $folded) . "\r\n";
	}//end calendar()

	/**
	 * The opening lines of a VCALENDAR: version, product, scale, method and name.
	 *
	 * @param CalendarContents $contents The name, zone, component lines and years.
	 *
	 * @return array<int, string> The unfolded lines.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function calendarHeader(CalendarContents $contents): array {
		return [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::PRODID,
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->escapeText(value: $contents->calendarName),
		];
	}//end calendarHeader()

	/**
	 * The calendar's time zone name and VTIMEZONE component, when it has a zone.
	 *
	 * @param CalendarContents $contents The name, zone, component lines and years.
	 *
	 * @return array<int, string> The unfolded lines; empty without a zone.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function timeZoneLines(CalendarContents $contents): array {
		$timeZone = $contents->timeZone;
		if ($timeZone === null) {
			return [];
		}

		return array_merge(
			['X-WR-TIMEZONE:' . $timeZone->getName()],
			$this->timeZoneComponent(timeZone: $timeZone, years: $contents->years)
		);
	}//end timeZoneLines()

	/**
	 * Build the VTIMEZONE component for a zone over a year range.
	 *
	 * The transitions come from PHP's own zone database rather than an RRULE,
	 * so the component states what actually happened in those years instead of
	 * a rule that a client has to re-derive. A zone with no transitions in the
	 * range gets one STANDARD component carrying its fixed offset, which is
	 * what UTC needs.
	 *
	 * @param DateTimeZone $timeZone The zone.
	 * @param CalendarYearSpan $years The years to cover.
	 *
	 * @return array<int, string> The unfolded lines of the component.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function timeZoneComponent(DateTimeZone $timeZone, CalendarYearSpan $years): array {
		$transitions = $this->transitionsOver(timeZone: $timeZone, years: $years);

		$lines = ['BEGIN:VTIMEZONE', 'TZID:' . $timeZone->getName()];
		$lines = array_merge($lines, $this->transitionComponents(transitions: $transitions));

		if (count($transitions) <= 1) {
			$offset = $this->formatOffset(seconds: (int)($transitions[0]['offset'] ?? 0));
			$lines  = array_merge($lines, $this->fixedOffsetComponent(timeZone: $timeZone, offset: $offset));
		}

		$lines[] = 'END:VTIMEZONE';

		return $lines;
	}//end timeZoneComponent()

	/**
	 * A zone's transitions from the year before a span to the year after it, from PHP's zone database.
	 *
	 * @param DateTimeZone     $timeZone The zone.
	 * @param CalendarYearSpan $years    The years to cover.
	 *
	 * @return array<int, array<string, mixed>> The transitions; empty when the zone reports none.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function transitionsOver(DateTimeZone $timeZone, CalendarYearSpan $years): array {
		$start = (new DateTimeImmutable(sprintf('%04d-01-01T00:00:00+00:00', ($years->fromYear - 1))))->getTimestamp();
		$end   = (new DateTimeImmutable(sprintf('%04d-12-31T23:59:59+00:00', ($years->toYear + 1))))->getTimestamp();

		$transitions = $timeZone->getTransitions($start, $end);
		if ($transitions === false) {
			return [];
		}

		return $transitions;
	}//end transitionsOver()

	/**
	 * One STANDARD or DAYLIGHT sub-component per transition after the first, each from the offset before it.
	 *
	 * @param array<int, array<string, mixed>> $transitions The zone's transitions; the first only gives the starting offset.
	 *
	 * @return array<int, string> The unfolded lines.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function transitionComponents(array $transitions): array {
		$lines          = [];
		$previousOffset = ($transitions[0]['offset'] ?? 0);

		foreach (array_slice($transitions, 1) as $transition) {
			$lines = array_merge(
				$lines,
				$this->transitionComponent(transition: $transition, previousOffset: $previousOffset)
			);
			$previousOffset = (int)$transition['offset'];
		}

		return $lines;
	}//end transitionComponents()

	/**
	 * The single STANDARD sub-component of a zone with no transitions in range, carrying its fixed offset.
	 *
	 * @param DateTimeZone $timeZone The zone.
	 * @param string       $offset   The formatted offset.
	 *
	 * @return array<int, string> The unfolded lines.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	private function fixedOffsetComponent(DateTimeZone $timeZone, string $offset): array {
		return [
			'BEGIN:STANDARD',
			'DTSTART:19700101T000000',
			'TZOFFSETFROM:' . $offset,
			'TZOFFSETTO:' . $offset,
			'TZNAME:' . $timeZone->getName(),
			'END:STANDARD',
		];
	}//end fixedOffsetComponent()

	/**
	 * One STANDARD or DAYLIGHT sub-component for a zone transition.
	 *
	 * @param array<string, mixed> $transition One entry of DateTimeZone::getTransitions().
	 * @param int $previousOffset The offset in force before the transition, in seconds.
	 *
	 * @return array<int, string> The unfolded lines.
	 */
	private function transitionComponent(array $transition, int $previousOffset): array {
		$isDaylight = ((bool)($transition['isdst'] ?? false));
		$name = 'STANDARD';
		if ($isDaylight === true) {
			$name = 'DAYLIGHT';
		}

		$offsetTo = (int)($transition['offset'] ?? 0);

		// The transition timestamp is UTC; DTSTART inside VTIMEZONE is the
		// LOCAL time at which the change takes effect, which is the instant
		// shifted by the offset coming INTO force.
		$moment = (new DateTimeImmutable('@' . (int)($transition['ts'] ?? 0)))
			->setTimezone(new DateTimeZone('UTC'))
			->modify(sprintf('%+d seconds', $offsetTo));

		return [
			'BEGIN:' . $name,
			'DTSTART:' . $moment->format('Ymd\THis'),
			'TZOFFSETFROM:' . $this->formatOffset(seconds: $previousOffset),
			'TZOFFSETTO:' . $this->formatOffset(seconds: $offsetTo),
			'TZNAME:' . (string)($transition['abbr'] ?? $name),
			'END:' . $name,
		];
	}//end transitionComponent()

	/**
	 * Format a UTC offset in seconds as the iCalendar `+HHMM` form.
	 *
	 * @param int $seconds The offset in seconds.
	 *
	 * @return string The formatted offset.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function formatOffset(int $seconds): string {
		$sign = '+';
		if ($seconds < 0) {
			$sign = '-';
		}

		$absolute = abs($seconds);

		return sprintf('%s%02d%02d', $sign, intdiv($absolute, 3600), intdiv(($absolute % 3600), 60));
	}//end formatOffset()

	/**
	 * Fold one content line at 75 octets, continuation lines starting with a space.
	 *
	 * Folding counts OCTETS, not characters, and a multi-byte character must
	 * not be split across the fold: a client reading the two halves separately
	 * sees two invalid sequences. So the cut walks back to a character
	 * boundary.
	 *
	 * @param string $line The unfolded line.
	 *
	 * @return string The folded line, with CRLF + space between segments.
	 *
	 * @spec openspec/specs/calendar-provider/spec.md
	 */
	public function fold(string $line): string {
		if (strlen($line) <= self::FOLD_AT) {
			return $line;
		}

		return implode("\r\n ", $this->foldSegments(line: $line));
	}//end fold()

	/**
	 * Cut an over-long line into octet-bounded segments, never inside a UTF-8 character.
	 *
	 * @param string $line The unfolded content line.
	 *
	 * @return array<int, string> The segments, the first FOLD_AT octets, the rest one fewer.
	 */
	private function foldSegments(string $line): array {
		$segments = [];
		$remaining = $line;
		$budget = self::FOLD_AT;

		while (strlen($remaining) > $budget) {
			$cut = $this->cutPoint(remaining: $remaining, budget: $budget);
			$segments[] = substr($remaining, 0, $cut);
			$remaining = substr($remaining, $cut);

			// Continuation lines spend one octet on the leading space.
			$budget = (self::FOLD_AT - 1);
		}

		$segments[] = $remaining;
		return $segments;
	}//end foldSegments()

	/**
	 * Where to cut: at the budget, walked back off a UTF-8 continuation byte (10xxxxxx).
	 *
	 * @param string $remaining The rest of the line.
	 * @param int    $budget    The octets this segment may hold.
	 *
	 * @return int The cut offset.
	 */
	private function cutPoint(string $remaining, int $budget): int {
		$cut = $budget;
		while ($cut > 1 && (ord($remaining[$cut]) & 0xC0) === 0x80) {
			$cut--;
		}

		return $cut;
	}//end cutPoint()
}//end class
