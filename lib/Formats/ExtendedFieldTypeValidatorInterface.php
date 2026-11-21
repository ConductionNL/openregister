<?php

/**
 * ExtendedFieldTypeValidatorInterface: what callers outside Formats ask of ExtendedFieldTypeValidator.
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
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;


/**
 * Validates `color`, `recurrence`, `calendar-range` and `image-url` values and materialises recurrence occurrences.
 * Only the constants and methods ExtendedFieldCheck and RecurrenceOccurrences use.
 *
 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
 */
interface ExtendedFieldTypeValidatorInterface {

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
	 * Validate a `color` value against the declared format of its property.
	 *
	 * @param mixed             $value    The colour value submitted by the client.
	 * @param FormattedProperty $property The property name and its declared `format` (null => hex).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	public function validateColorProperty(mixed $value, FormattedProperty $property): ?string;

	/**
	 * Validate a `recurrence` value as an RFC 5545 RRULE.
	 *
	 * @param mixed  $value        The RRULE string submitted by the client.
	 * @param string $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
	 */
	public function validateRecurrence(mixed $value, string $propertyName): ?string;

	/**
	 * Validate a `calendar-range` value: `{ start, end }`, both present, in the declared format, end not before start.
	 *
	 * @param mixed             $value    The range submitted by the client.
	 * @param FormattedProperty $property The property name and its declared `format` (`date`, else date-time).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function validateCalendarRange(mixed $value, FormattedProperty $property): ?string;

	/**
	 * Validate an `image-url` value: a URL, or `{ url, width?, height?, altText?, format? }`.
	 *
	 * @param mixed  $value        The image submitted by the client.
	 * @param string $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/changes/extended-field-types-range-and-image/specs/extended-field-types/spec.md
	 */
	public function validateImageUrl(mixed $value, string $propertyName): ?string;

	/**
	 * Materialise the occurrences of a recurrence window.
	 *
	 * @param RecurrenceWindow $window The RRULE, the number of occurrences and the start.
	 *
	 * @return array<int, string> ISO-8601 occurrence timestamps, or empty on parse failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
	 */
	public function occurrencesIn(RecurrenceWindow $window): array;
}//end interface
