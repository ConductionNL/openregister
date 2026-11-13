<?php

/**
 * VeventSearchShape — one generated VEVENT as an ICalendar::search() result.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Calendar
 * @package  OCA\OpenRegister\Calendar
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Calendar;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Reads the unfolded iCalendar lines the feed's event generator produced for
 * one VEVENT and answers the array shape ICalendar::search() returns.
 *
 * The platform calendar renders nothing itself: it reads the very lines the
 * subscribable feed writes, so a date, an alarm or an attendee cannot differ
 * between the two surfaces. Properties are `[value, parameters]`; ATTENDEE is
 * a list of those, and a nested component (the VALARM) is a list of property
 * maps, as the platform's own CalDAV search answers them.
 *
 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
 */
class VeventSearchShape {

	/**
	 * Properties that may occur more than once in one component.
	 *
	 * @var array<int, string>
	 */
	private const MULTI_VALUED = ['ATTENDEE'];

	/**
	 * Properties whose value is escaped TEXT.
	 *
	 * @var array<int, string>
	 */
	private const TEXT_VALUED = ['SUMMARY', 'DESCRIPTION', 'LOCATION', 'COMMENT'];

	/**
	 * The search result of one VEVENT, or null when the lines hold no VEVENT with a UID.
	 *
	 * @param array<int, string> $lines       The unfolded lines, BEGIN:VEVENT to END:VEVENT.
	 * @param string             $calendarKey The key and URI of the calendar the event is on.
	 *
	 * @return array{id: string, type: string, calendar-key: string, calendar-uri: string, objects: array<int, array<string, mixed>>}|null
	 *         The result, or null.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function toSearchResult(array $lines, string $calendarKey): ?array {
		$vevent = $this->component(lines: $lines, name: 'VEVENT');
		if ($vevent === null || isset($vevent['UID'][0]) === false || is_string($vevent['UID'][0]) === false) {
			return null;
		}

		return [
			'id' => $vevent['UID'][0],
			'type' => 'VEVENT',
			'calendar-key' => $calendarKey,
			'calendar-uri' => $calendarKey,
			'objects' => [$vevent],
		];
	}//end toSearchResult()

	/**
	 * The instants a search result starts and ends, read from its DTSTART and DTEND.
	 *
	 * A VALUE=DATE value is a whole day in the tenant zone; a TZID value is a
	 * local time in that zone; a value ending in Z is UTC. An event without a
	 * readable DTEND ends where it starts.
	 *
	 * @param array<string, mixed> $vevent   The VEVENT properties of a search result.
	 * @param DateTimeZone         $timeZone The zone a date or a floating time is read in.
	 *
	 * @return array{start: DateTimeImmutable, end: DateTimeImmutable}|null The span, or null without a readable DTSTART.
	 *
	 * @spec openspec/changes/platform-caldav-backend/specs/calendar-provider/spec.md#requirement-declared-object-dates-appear-as-platform-calendars-req-pcb-001
	 */
	public function span(array $vevent, DateTimeZone $timeZone): ?array {
		$start = $this->instant(property: ($vevent['DTSTART'] ?? null), timeZone: $timeZone);
		if ($start === null) {
			return null;
		}

		$end = $this->instant(property: ($vevent['DTEND'] ?? null), timeZone: $timeZone);
		if ($end === null || $end < $start) {
			$end = $start;
		}

		return ['start' => $start, 'end' => $end];
	}//end span()

	/**
	 * The instant one date property names, or null when it is unreadable.
	 *
	 * @param mixed        $property The `[value, parameters]` pair.
	 * @param DateTimeZone $timeZone The fallback zone.
	 *
	 * @return DateTimeImmutable|null The instant.
	 */
	private function instant(mixed $property, DateTimeZone $timeZone): ?DateTimeImmutable {
		if (is_array($property) === false || is_string($property[0] ?? null) === false) {
			return null;
		}

		$value  = $property[0];
		$params = ($property[1] ?? []);
		$zone   = $this->zoneOf(params: $params, value: $value, fallback: $timeZone);
		$format = '!Ymd\THis';
		if (($params['VALUE'] ?? null) === 'DATE' || preg_match('/^\d{8}$/', $value) === 1) {
			$format = '!Ymd';
		}

		$parsed = DateTimeImmutable::createFromFormat($format, rtrim($value, 'Z'), $zone);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end instant()

	/**
	 * The zone a date value is read in: UTC for a Z value, its TZID when it names a known one, else the fallback.
	 *
	 * @param mixed        $params   The property parameters.
	 * @param string       $value    The value.
	 * @param DateTimeZone $fallback The tenant zone.
	 *
	 * @return DateTimeZone The zone.
	 */
	private function zoneOf(mixed $params, string $value, DateTimeZone $fallback): DateTimeZone {
		if (str_ends_with($value, 'Z') === true) {
			return new DateTimeZone('UTC');
		}

		$tzid = (string)(((array)$params)['TZID'] ?? '');
		if ($tzid === '') {
			return $fallback;
		}

		try {
			return new DateTimeZone($tzid);
		} catch (Throwable $unknown) {
			unset($unknown);
			return $fallback;
		}
	}//end zoneOf()

	/**
	 * The properties of the first component of a name, with its nested components.
	 *
	 * @param array<int, string> $lines The unfolded lines.
	 * @param string             $name  The component name.
	 *
	 * @return array<string, mixed>|null The properties, or null when the component is absent or unterminated.
	 */
	private function component(array $lines, string $name): ?array {
		$stack = [];
		foreach ($lines as $line) {
			$result = $this->readLine(line: $line, stack: $stack, name: $name);
			if ($result['done'] !== null) {
				return $result['done'];
			}

			$stack = $result['stack'];
		}

		return null;
	}//end component()

	/**
	 * Apply one line to the component stack.
	 *
	 * @param string                                                     $line  The line.
	 * @param array<int, array{name: string, props: array<string, mixed>}> $stack The open components.
	 * @param string                                                     $name  The component sought.
	 *
	 * @return array{stack: array<int, array{name: string, props: array<string, mixed>}>, done: array<string, mixed>|null}
	 *         The new stack, and the sought component once it closes.
	 */
	private function readLine(string $line, array $stack, string $name): array {
		if (str_starts_with($line, 'BEGIN:') === true) {
			$stack[] = ['name' => strtoupper(substr($line, 6)), 'props' => []];
			return ['stack' => $stack, 'done' => null];
		}

		if (str_starts_with($line, 'END:') === true) {
			return $this->closeComponent(stack: $stack, name: $name);
		}

		if ($stack === []) {
			return ['stack' => $stack, 'done' => null];
		}

		$property = $this->property(line: $line);
		if ($property !== null) {
			$top = (count($stack) - 1);
			$stack[$top]['props'] = $this->withProperty(props: $stack[$top]['props'], property: $property);
		}

		return ['stack' => $stack, 'done' => null];
	}//end readLine()

	/**
	 * Close the innermost component: it is the result when it is the one sought, else it nests in its parent.
	 *
	 * @param array<int, array{name: string, props: array<string, mixed>}> $stack The open components.
	 * @param string                                                     $name  The component sought.
	 *
	 * @return array{stack: array<int, array{name: string, props: array<string, mixed>}>, done: array<string, mixed>|null}
	 *         The new stack, and the sought component when it just closed.
	 */
	private function closeComponent(array $stack, string $name): array {
		$closed = array_pop($stack);
		if ($closed === null) {
			return ['stack' => $stack, 'done' => null];
		}

		if ($closed['name'] === $name) {
			return ['stack' => $stack, 'done' => $closed['props']];
		}

		if ($stack !== []) {
			$top = (count($stack) - 1);
			$stack[$top]['props'][$closed['name']][] = $closed['props'];
		}

		return ['stack' => $stack, 'done' => null];
	}//end closeComponent()

	/**
	 * Properties with one more added: appended for a multi-valued property, set otherwise.
	 *
	 * @param array<string, mixed>                                $props    The properties so far.
	 * @param array{name: string, value: mixed, params: array<string, string>} $property The property.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function withProperty(array $props, array $property): array {
		$pair = [$property['value'], $property['params']];
		if (in_array($property['name'], self::MULTI_VALUED, true) === true) {
			$props[$property['name']][] = $pair;
			return $props;
		}

		$props[$property['name']] = $pair;

		return $props;
	}//end withProperty()

	/**
	 * One content line as a name, a value and its parameters; null when it has no colon.
	 *
	 * @param string $line The unfolded line.
	 *
	 * @return array{name: string, value: mixed, params: array<string, string>}|null The property.
	 */
	private function property(string $line): ?array {
		$colon = $this->firstUnquoted(text: $line, char: ':');
		if ($colon === null) {
			return null;
		}

		$head  = $this->splitUnquoted(text: substr($line, 0, $colon), char: ';');
		$name  = strtoupper((string)array_shift($head));
		$value = substr($line, ($colon + 1));

		return [
			'name' => $name,
			'value' => $this->typedValue(name: $name, value: $value),
			'params' => $this->parameters(parts: $head),
		];
	}//end property()

	/**
	 * A raw value as the search result carries it: TEXT unescaped, CATEGORIES as a list.
	 *
	 * @param string $name  The property name.
	 * @param string $value The raw value.
	 *
	 * @return mixed The value.
	 */
	private function typedValue(string $name, string $value): mixed {
		if ($name === 'CATEGORIES') {
			return array_map(
				fn (string $category): string => $this->unescape(text: $category),
				$this->splitUnescaped(text: $value)
			);
		}

		if (in_array($name, self::TEXT_VALUED, true) === true) {
			return $this->unescape(text: $value);
		}

		return $value;
	}//end typedValue()

	/**
	 * The `NAME=value` parameters of a property, unquoted.
	 *
	 * @param array<int, string> $parts The parts after the property name.
	 *
	 * @return array<string, string> The parameters.
	 */
	private function parameters(array $parts): array {
		$params = [];
		foreach ($parts as $part) {
			$equals = strpos($part, '=');
			if ($equals === false) {
				continue;
			}

			$params[strtoupper(substr($part, 0, $equals))] = trim(substr($part, ($equals + 1)), '"');
		}

		return $params;
	}//end parameters()

	/**
	 * The offset of the first occurrence of a character outside double quotes.
	 *
	 * @param string $text The text.
	 * @param string $char The character.
	 *
	 * @return int|null The offset, or null.
	 */
	private function firstUnquoted(string $text, string $char): ?int {
		$quoted = false;
		$length = strlen($text);
		for ($i = 0; $i < $length; $i++) {
			if ($text[$i] === '"') {
				$quoted = !$quoted;
				continue;
			}

			if ($text[$i] === $char && $quoted === false) {
				return $i;
			}
		}

		return null;
	}//end firstUnquoted()

	/**
	 * Split text on a character outside double quotes.
	 *
	 * @param string $text The text.
	 * @param string $char The separator.
	 *
	 * @return array<int, string> The parts.
	 */
	private function splitUnquoted(string $text, string $char): array {
		$parts  = [];
		$offset = $this->firstUnquoted(text: $text, char: $char);
		while ($offset !== null) {
			$parts[] = substr($text, 0, $offset);
			$text    = substr($text, ($offset + 1));
			$offset  = $this->firstUnquoted(text: $text, char: $char);
		}

		$parts[] = $text;

		return $parts;
	}//end splitUnquoted()

	/**
	 * Split a TEXT list on commas that are not escaped.
	 *
	 * @param string $text The list.
	 *
	 * @return array<int, string> The still-escaped items.
	 */
	private function splitUnescaped(string $text): array {
		return (array)preg_split('/(?<!\\\\),/', $text);
	}//end splitUnescaped()

	/**
	 * Undo RFC 5545 TEXT escaping.
	 *
	 * @param string $text The escaped text.
	 *
	 * @return string The text.
	 */
	private function unescape(string $text): string {
		// strtr() replaces the longest match first and never rescans, so `\\n` stays a backslash and an n.
		return strtr($text, ['\\\\' => '\\', '\\;' => ';', '\\,' => ',', '\\n' => "\n", '\\N' => "\n"]);
	}//end unescape()
}//end class
