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

use DateTimeInterface;

/**
 * Validates `color` and `recurrence` values and materialises recurrence occurrences.
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
	 * Validate a `color` value against the property's declared format.
	 *
	 * @param mixed       $value        The colour value submitted by the client.
	 * @param string|null $format       The declared `format` annotation (null => hex).
	 * @param string      $propertyName The property name (for the error message).
	 *
	 * @return string|null Null on success, or the exact 422 error message on failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-005
	 */
	public function validateColor(mixed $value, ?string $format, string $propertyName): ?string;

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
	 * Materialise the next N upcoming occurrences for a recurrence RRULE.
	 *
	 * @param string                 $rrule The RFC 5545 RRULE string.
	 * @param int                    $count The number of occurrences to emit.
	 * @param DateTimeInterface|null $start The iteration start (defaults to now).
	 *
	 * @return array<int, string> ISO-8601 occurrence timestamps, or empty on parse failure.
	 *
	 * @spec openspec/specs/extended-field-types/spec.md#REQ-EFT-003
	 */
	public function materialiseOccurrences(string $rrule, int $count, ?DateTimeInterface $start = null): array;
}//end interface
