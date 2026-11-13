<?php

/**
 * Extended Field-Type Validator
 *
 * Stateless validation + occurrence-materialisation helpers for the extended
 * OpenRegister property types declared in the `extended-field-types` spec.
 *
 * This class concentrates the per-type contract (regex, RRULE parsing) in one
 * place so both the REST pre-validation hook (ValidateObject) and the read-side
 * converter (SchemaTypeConverter) share a single implementation. Methods return
 * a `null` error string on success or the exact, spec-mandated 422 message on
 * failure — keeping the "single point of truth" property the spec requires for
 * REST + GraphQL parity.
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
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use DateTimeImmutable;
use DateTimeInterface;
use Sabre\VObject\Recur\RRuleIterator;
use Throwable;

/**
 * Validator for the extended field types `color` and `recurrence`.
 *
 * The `color` type accepts one of three notations controlled by the property's
 * declared `format` annotation (`hex` default, `rgba`, `oklch`). The `recurrence`
 * type stores an RFC 5545 RRULE string validated via sabre/vobject and may emit
 * the next N upcoming occurrences on read.
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */
class ExtendedFieldTypeValidator implements ExtendedFieldTypeValidatorInterface {

	/**
	 * Hex colour regex: #RGB, #RRGGBB or #RRGGBBAA (case-insensitive).
	 *
	 * @var string
	 */
	private const REGEX_HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/';

	/**
	 * OKLCH colour regex: oklch(L% C H) with optional alpha.
	 *
	 * Accepts lightness as percentage or number, chroma and hue as numbers, and
	 * an optional `/ A` alpha component.
	 *
	 * @var string
	 */
	private const REGEX_OKLCH = '/^oklch\(\s*[0-9]*\.?[0-9]+%?\s+[0-9]*\.?[0-9]+\s+[0-9]*\.?[0-9]+(?:deg)?\s*(?:\/\s*[0-9]*\.?[0-9]+%?\s*)?\)$/i';

	/**
	 * The pattern each regex-checked colour format must match.
	 *
	 * @var array<string, string>
	 */
	private const COLOR_PATTERNS = [
		'hex' => self::REGEX_HEX,
		'oklch' => self::REGEX_OKLCH,
	];

	/**
	 * Validate a `color` value against the declared format of its property.
	 *
	 * The default format is `hex`. The `rgba` format requires exactly four
	 * components inside the `rgba(...)` envelope. The `oklch` format validates
	 * the `oklch(L C H)` syntax. Returns `null` on success or the exact 422
	 * message mandated by REQ-EFT-005 on failure.
	 *
	 * @param mixed             $value    The colour value submitted by the client.
	 * @param FormattedProperty $property The property name and its declared `format` (null => hex).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	public function validateColorProperty(mixed $value, FormattedProperty $property): ?string {
		$propertyName = $property->name;
		if (is_string($value) === false) {
			return "Property '{$propertyName}': color value must be a string";
		}

		$problem = $this->colorProblem(value: $value, format: strtolower((string)$property->format));
		if ($problem === null) {
			return null;
		}

		return "Property '{$propertyName}' {$problem}";
	}//end validateColorProperty()

	/**
	 * What is wrong with a colour string under a lower-cased declared format.
	 *
	 * `rgba` counts components; `oklch` is matched against its regex; every
	 * other format, including none, is treated as `hex`.
	 *
	 * @param string $value  The submitted colour string.
	 * @param string $format The lower-cased declared format.
	 *
	 * @return string|null Null when valid, or the message without its property prefix.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	private function colorProblem(string $value, string $format): ?string {
		if ($format === 'rgba') {
			return $this->rgbaProblem(value: $value);
		}

		if ($format !== 'oklch') {
			$format = 'hex';
		}

		if (preg_match(self::COLOR_PATTERNS[$format], $value) === 1) {
			return null;
		}

		return "declared format '{$format}'; '{$value}' does not match {$format} regex";
	}//end colorProblem()

	/**
	 * What is wrong with an `rgba(...)` colour value: it MUST carry exactly 4 components.
	 *
	 * @param string $value The submitted colour string.
	 *
	 * @return string|null Null when valid, or the message without its property prefix.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	private function rgbaProblem(string $value): ?string {
		if (preg_match('/^rgba\(\s*([^)]*)\)$/i', $value, $matches) !== 1) {
			return "declared format 'rgba'; '{$value}' does not match rgba regex";
		}

		$count = $this->countComponents(list: $matches[1]);
		if ($count !== 4) {
			return "format 'rgba' requires 4 components; got {$count}";
		}

		return null;
	}//end rgbaProblem()

	/**
	 * Count the non-empty, comma-separated components inside an `rgba(...)` envelope.
	 *
	 * @param string $list The text between the parentheses.
	 *
	 * @return int The number of non-empty components.
	 */
	private function countComponents(string $list): int {
		$components = array_filter(
			array_map('trim', explode(',', $list)),
			static function ($component) {
				return $component !== '';
			}
		);

		return count($components);
	}//end countComponents()

	/**
	 * Validate a `recurrence` value as a parseable RFC 5545 RRULE string.
	 *
	 * Validation is delegated to sabre/vobject's RRULE parser, which surfaces a
	 * parse failure as an `InvalidDataException`. Returns `null` when the RRULE
	 * parses cleanly, or the exact 422 message mandated by REQ-EFT-003 on failure.
	 *
	 * @param mixed $value The RRULE string submitted by the client.
	 * @param string $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
	 */
	public function validateRecurrence(mixed $value, string $propertyName): ?string {
		if (is_string($value) === false) {
			return "Property '{$propertyName}': recurrence value must be an RRULE string";
		}

		try {
			// Constructing the iterator parses the RRULE and throws on bad input.
			new RRuleIterator($value, new DateTimeImmutable('now'));
			return null;
		} catch (Throwable $e) {
			return "Property '{$propertyName}': invalid RRULE '{$value}' (sabre/vobject parse error)";
		}//end try
	}//end validateRecurrence()

	/**
	 * Materialise the next N upcoming occurrences of a recurrence window.
	 *
	 * Iterates the RRULE from the window's start (now when absent) and collects
	 * up to its count of ISO-8601 occurrence timestamps. The count is clamped to
	 * [1, MAX_OCCURRENCES]. The RRULE string itself is never modified — callers
	 * preserve it for round-trip integrity and attach the result under the
	 * `_occurrences` virtual field.
	 *
	 * @param RecurrenceWindow $window The RRULE, the number of occurrences and the start.
	 *
	 * @return array<int, string> ISO-8601 occurrence timestamps, or empty on parse failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
	 */
	public function occurrencesIn(RecurrenceWindow $window): array {
		try {
			$iterator = new RRuleIterator($window->rrule, $window->start ?? new DateTimeImmutable('now'));
			return $this->collectOccurrences(iterator: $iterator, count: $this->clampCount(count: $window->count));
		} catch (Throwable $e) {
			// A value that fails to parse here would already have been rejected at
			// write time; on read we degrade gracefully to no occurrences.
			return [];
		}
	}//end occurrencesIn()

	/**
	 * Clamp a requested occurrence count: below 1 means the default, above the maximum means the maximum.
	 *
	 * @param int $count The requested count.
	 *
	 * @return int The count to materialise.
	 */
	private function clampCount(int $count): int {
		if ($count < 1) {
			return self::DEFAULT_OCCURRENCES;
		}

		return min($count, self::MAX_OCCURRENCES);
	}//end clampCount()

	/**
	 * Collect up to `$count` ISO-8601 timestamps from an RRULE iterator.
	 *
	 * @param RRuleIterator $iterator The iterator, positioned at its start.
	 * @param int           $count    The maximum number of timestamps.
	 *
	 * @return array<int, string> ISO-8601 occurrence timestamps.
	 */
	private function collectOccurrences(RRuleIterator $iterator, int $count): array {
		$occurrences = [];
		$collected = 0;
		while ($iterator->valid() === true && $collected < $count) {
			$current = $iterator->current();
			if ($current instanceof DateTimeInterface) {
				$occurrences[] = $current->format(DateTimeInterface::ATOM);
				$collected++;
			}

			$iterator->next();
		}

		return $occurrences;
	}//end collectOccurrences()

	/**
	 * Validate a `calendar-range` value.
	 *
	 * @param mixed             $value    The range submitted by the client.
	 * @param FormattedProperty $property The property name and its declared `format`.
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function validateCalendarRange(mixed $value, FormattedProperty $property): ?string {
		return (new CalendarRangeValue())->validate(value: $value, property: $property);
	}//end validateCalendarRange()

	/**
	 * Validate an `image-url` value.
	 *
	 * @param mixed  $value        The image submitted by the client.
	 * @param string $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function validateImageUrl(mixed $value, string $propertyName): ?string {
		return (new ImageUrlValue())->validate(value: $value, propertyName: $propertyName);
	}//end validateImageUrl()
}//end class
