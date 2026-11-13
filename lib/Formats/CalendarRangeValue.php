<?php

/**
 * Calendar range value check
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use DateTimeImmutable;
use Throwable;

/**
 * Checks a `calendar-range` value: an object `{ start, end }`, both present,
 * both in the property's declared format, and `end` not before `start`.
 *
 * The format is `date` (`YYYY-MM-DD`) or `date-time`, the default, which
 * accepts what the `date-time` string format accepts.
 *
 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
 */
final class CalendarRangeValue {

	/**
	 * A calendar date, `YYYY-MM-DD`.
	 *
	 * @var string
	 */
	private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/';

	/**
	 * The two members of a range, in the order they are checked.
	 *
	 * @var array<int, string>
	 */
	private const MEMBERS = ['start', 'end'];

	/**
	 * What is wrong with a range value, or null when it is valid.
	 *
	 * @param mixed             $value    The submitted value.
	 * @param FormattedProperty $property The property name and its declared `format` (null => date-time).
	 *
	 * @return string|null Null on success, or the 422 message naming the property.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function validate(mixed $value, FormattedProperty $property): ?string {
		$name = $property->name;
		if (is_array($value) === false || ($value !== [] && array_is_list($value) === true)) {
			return "Property '{$name}': calendar-range must be an object with 'start' and 'end'";
		}

		$format = 'date-time';
		if ($property->format === 'date') {
			$format = 'date';
		}

		foreach (self::MEMBERS as $member) {
			$problem = $this->memberProblem(value: $value, member: $member, format: $format);
			if ($problem !== null) {
				return str_replace('{name}', $name, $problem);
			}
		}

		return $this->orderProblem(start: $value['start'], end: $value['end'], name: $name);
	}//end validate()

	/**
	 * What is wrong with one member of a range, with `{name}` standing for the property.
	 *
	 * @param array  $value  The range object.
	 * @param string $member `start` or `end`.
	 * @param string $format `date` or `date-time`.
	 *
	 * @return string|null Null when the member is present and in the format.
	 */
	private function memberProblem(array $value, string $member, string $format): ?string {
		if (array_key_exists($member, $value) === false || $value[$member] === null || $value[$member] === '') {
			return "Property '{name}': calendar-range is missing '{$member}'";
		}

		if ($format === 'date' && $this->isDate(value: $value[$member]) === false) {
			return "Property '{name}' declared format 'date'; '{$member}' must match YYYY-MM-DD";
		}

		if ($format === 'date-time' && (new Iso8601DateTimeFormat())->validate(data: $value[$member]) === false) {
			return "Property '{name}' declared format 'date-time'; '{$member}' must be an ISO 8601 date-time";
		}

		return null;
	}//end memberProblem()

	/**
	 * Whether a value is a real calendar date written as `YYYY-MM-DD`.
	 *
	 * @param mixed $value The member value.
	 *
	 * @return bool
	 */
	private function isDate(mixed $value): bool {
		if (is_string($value) === false || preg_match(self::DATE_PATTERN, $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
	}//end isDate()

	/**
	 * The refusal when the range ends before it starts, else null.
	 *
	 * Both members have passed their format check, so they parse; the
	 * comparison is on the instant, so offsets in different zones compare
	 * correctly.
	 *
	 * @param string $start The start member.
	 * @param string $end   The end member.
	 * @param string $name  The property name.
	 *
	 * @return string|null
	 */
	private function orderProblem(string $start, string $end, string $name): ?string {
		try {
			$ends = new DateTimeImmutable($end);
			$starts = new DateTimeImmutable($start);
		} catch (Throwable $e) {
			return "Property '{$name}': calendar-range 'start' and 'end' must be dates";
		}

		if ($ends < $starts) {
			return "Property '{$name}': calendar-range 'end' ({$end}) must be >= 'start' ({$start})";
		}

		return null;
	}//end orderProblem()
}//end class
