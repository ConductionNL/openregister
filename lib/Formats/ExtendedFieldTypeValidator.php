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
use Sabre\VObject\InvalidDataException;
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
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Per-format colour dispatch is inherently branchy
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */
class ExtendedFieldTypeValidator {

	/**
	 * Default number of occurrences materialised for a recurrence value on read.
	 *
	 * @var int
	 */
	public const DEFAULT_OCCURRENCES = 5;

	/**
	 * Maximum number of occurrences a single request may materialise.
	 *
	 * @var int
	 */
	public const MAX_OCCURRENCES = 100;

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
	 * Validate a `color` value against the property's declared format.
	 *
	 * The default format is `hex`. The `rgba` format requires exactly four
	 * components inside the `rgba(...)` envelope. The `oklch` format validates
	 * the `oklch(L C H)` syntax. Returns `null` on success or the exact 422
	 * message mandated by REQ-EFT-005 on failure.
	 *
	 * @param mixed $value The colour value submitted by the client.
	 * @param string|null $format The declared `format` annotation (null => hex).
	 * @param string $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	public function validateColor(mixed $value, ?string $format, string $propertyName): ?string {
		if (is_string($value) === false) {
			return "Property '{$propertyName}': color value must be a string";
		}

		$format = strtolower((string)$format);
		if ($format === '') {
			$format = 'hex';
		}

		if ($format === 'rgba') {
			return $this->validateRgba(value: $value, propertyName: $propertyName);
		}

		if ($format === 'oklch') {
			if (preg_match(self::REGEX_OKLCH, $value) === 1) {
				return null;
			}

			return "Property '{$propertyName}' declared format 'oklch'; '{$value}' does not match oklch regex";
		}

		// Default + explicit `hex`.
		if (preg_match(self::REGEX_HEX, $value) === 1) {
			return null;
		}

		return "Property '{$propertyName}' declared format 'hex'; '{$value}' does not match hex regex";
	}//end validateColor()

	/**
	 * Validate an `rgba(...)` colour value: it MUST carry exactly 4 components.
	 *
	 * @param string $value The submitted colour string.
	 * @param string $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	private function validateRgba(string $value, string $propertyName): ?string {
		$matched = preg_match('/^rgba\(\s*([^)]*)\)$/i', $value, $matches);
		if ($matched !== 1) {
			return "Property '{$propertyName}' declared format 'rgba'; '{$value}' does not match rgba regex";
		}

		$components = array_filter(
			array_map('trim', explode(',', $matches[1])),
			static function ($component) {
				return $component !== '';
			}
		);
		$count = count($components);

		if ($count !== 4) {
			return "Property '{$propertyName}' format 'rgba' requires 4 components; got {$count}";
		}

		return null;
	}//end validateRgba()

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
		} catch (InvalidDataException $e) {
			return "Property '{$propertyName}': invalid RRULE '{$value}' (sabre/vobject parse error)";
		} catch (Throwable $e) {
			return "Property '{$propertyName}': invalid RRULE '{$value}' (sabre/vobject parse error)";
		}//end try
	}//end validateRecurrence()

	/**
	 * Materialise the next N upcoming occurrences for a recurrence RRULE.
	 *
	 * Iterates the RRULE from `now` and collects up to `$count` ISO-8601
	 * occurrence timestamps. `$count` is clamped to [1, MAX_OCCURRENCES]. The
	 * RRULE string itself is never modified — callers preserve it for round-trip
	 * integrity and attach the result under the `_occurrences` virtual field.
	 *
	 * @param string $rrule The RFC 5545 RRULE string.
	 * @param int $count The number of occurrences to emit.
	 * @param DateTimeInterface|null $start The iteration start (defaults to now).
	 *
	 * @return array<int, string> ISO-8601 occurrence timestamps, or empty on parse failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
	 */
	public function materialiseOccurrences(string $rrule, int $count, ?DateTimeInterface $start = null): array {
		if ($count < 1) {
			$count = self::DEFAULT_OCCURRENCES;
		}

		if ($count > self::MAX_OCCURRENCES) {
			$count = self::MAX_OCCURRENCES;
		}

		if ($start === null) {
			$start = new DateTimeImmutable('now');
		}

		try {
			$iterator = new RRuleIterator($rrule, $start);
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
		} catch (Throwable $e) {
			// A value that fails to parse here would already have been rejected at
			// write time; on read we degrade gracefully to no occurrences.
			return [];
		}//end try
	}//end materialiseOccurrences()
}//end class
