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
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
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
	 * @param string $calendarName The display name the client shows.
	 * @param DateTimeZone|null $timeZone The tenant time zone, when the body holds timed events.
	 * @param array<int, string> $componentLines The unfolded lines of every component.
	 * @param int $fromYear First year the VTIMEZONE must cover.
	 * @param int $toYear Last year the VTIMEZONE must cover.
	 *
	 * @return string The complete, folded, CRLF-terminated calendar.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function calendar(
		string $calendarName,
		?DateTimeZone $timeZone,
		array $componentLines,
		int $fromYear,
		int $toYear,
	): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:' . self::PRODID,
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'X-WR-CALNAME:' . $this->escapeText(value: $calendarName),
		];

		if ($timeZone !== null) {
			$lines[] = 'X-WR-TIMEZONE:' . $timeZone->getName();
			$lines = array_merge(
				$lines,
				$this->timeZoneComponent(timeZone: $timeZone, fromYear: $fromYear, toYear: $toYear)
			);
		}

		$lines = array_merge($lines, $componentLines);
		$lines[] = 'END:VCALENDAR';

		$folded = array_map([$this, 'fold'], $lines);

		return implode("\r\n", $folded) . "\r\n";
	}//end calendar()

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
	 * @param int $fromYear First year to cover.
	 * @param int $toYear Last year to cover.
	 *
	 * @return array<int, string> The unfolded lines of the component.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function timeZoneComponent(DateTimeZone $timeZone, int $fromYear, int $toYear): array {
		$start = (new DateTimeImmutable(sprintf('%04d-01-01T00:00:00+00:00', ($fromYear - 1))))->getTimestamp();
		$end = (new DateTimeImmutable(sprintf('%04d-12-31T23:59:59+00:00', ($toYear + 1))))->getTimestamp();

		$transitions = $timeZone->getTransitions($start, $end);
		if ($transitions === false) {
			$transitions = [];
		}

		$lines = ['BEGIN:VTIMEZONE', 'TZID:' . $timeZone->getName()];

		$previousOffset = ($transitions[0]['offset'] ?? 0);
		$emitted = 0;

		foreach (array_slice($transitions, 1) as $transition) {
			$lines = array_merge(
				$lines,
				$this->transitionComponent(transition: $transition, previousOffset: $previousOffset)
			);
			$previousOffset = (int)$transition['offset'];
			$emitted++;
		}

		if ($emitted === 0) {
			$offset = $this->formatOffset(seconds: (int)$previousOffset);
			$lines[] = 'BEGIN:STANDARD';
			$lines[] = 'DTSTART:19700101T000000';
			$lines[] = 'TZOFFSETFROM:' . $offset;
			$lines[] = 'TZOFFSETTO:' . $offset;
			$lines[] = 'TZNAME:' . $timeZone->getName();
			$lines[] = 'END:STANDARD';
		}

		$lines[] = 'END:VTIMEZONE';

		return $lines;
	}//end timeZoneComponent()

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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
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
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function fold(string $line): string {
		if (strlen($line) <= self::FOLD_AT) {
			return $line;
		}

		$segments = [];
		$remaining = $line;
		$budget = self::FOLD_AT;

		while (strlen($remaining) > $budget) {
			$cut = $budget;

			// Walk back off a UTF-8 continuation byte (10xxxxxx).
			while ($cut > 1 && (ord($remaining[$cut]) & 0xC0) === 0x80) {
				$cut--;
			}

			$segments[] = substr($remaining, 0, $cut);
			$remaining = substr($remaining, $cut);

			// Continuation lines spend one octet on the leading space.
			$budget = (self::FOLD_AT - 1);
		}

		$segments[] = $remaining;

		return implode("\r\n ", $segments);
	}//end fold()
}//end class
