<?php

/**
 * The one priority scale, and the one normaliser every caller uses.
 *
 * The fleet runs priority on four scales: `low|normal|high|urgent`,
 * `low|normal|high`, the iCal integer range 0-9 (the CalDAV VTODO wire
 * format), and the notification scale `low|medium|high|critical`. All four
 * land on `low|normal|high|urgent` here.
 *
 * An off-scale value is REFUSED naming itself: pipelinq declares the enum
 * `["low","normal","high"]` with `"default": "normaal"` — a default not in
 * its own enum — and a coercing normaliser would have hidden that for as
 * long as it existed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Exception\TaskValidationException;

/**
 * Normalises every known priority scale onto low|normal|high|urgent.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
 */
final class TaskPriority {

	/**
	 * String scales: the canonical four and the notification scale.
	 *
	 * @var array<string, string>
	 */
	private const STRINGS = [
		'low' => 'low',
		'normal' => 'normal',
		'high' => 'high',
		'urgent' => 'urgent',
		// The notification scale.
		'medium' => 'normal',
		'critical' => 'urgent',
	];

	/**
	 * The iCal 0-9 mapping: 1 is the wire format's highest.
	 *
	 * RFC 5545: 0 undefined, 1-4 high, 5 medium, 6-9 low. `1` — the value a
	 * VTODO marks its most urgent work with — lands on `urgent`; the rest of
	 * the high band on `high`.
	 *
	 * @var array<int, string>
	 */
	private const ICAL = [
		0 => 'normal',
		1 => 'urgent',
		2 => 'high',
		3 => 'high',
		4 => 'high',
		5 => 'normal',
		6 => 'low',
		7 => 'low',
		8 => 'low',
		9 => 'low',
	];

	/**
	 * Normalise a priority from any known scale.
	 *
	 * @param mixed $value A string from a known scale, or an iCal 0-9 integer
	 *                     (numeric strings are read as the integer they spell).
	 *
	 * @return string One of low|normal|high|urgent.
	 *
	 * @throws TaskValidationException When the value is on no known scale.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
	 */
	public static function normalise(mixed $value): string {
		// The iCal integer range, arriving as int or as a numeric string.
		if (self::isIcalInteger(value: $value) === true) {
			return self::fromIcal(value: (int)trim((string)$value));
		}

		$lowered = '';
		if (is_string($value) === true) {
			$lowered = strtolower(trim($value));
		}

		if (array_key_exists($lowered, self::STRINGS) === true) {
			return self::STRINGS[$lowered];
		}

		throw self::offScale(value: $value);
	}//end normalise()

	/**
	 * Whether a value spells an integer: an int, or a string of digits.
	 *
	 * @param mixed $value The raw priority.
	 *
	 * @return bool True when the value is read on the iCal scale.
	 */
	private static function isIcalInteger(mixed $value): bool {
		if (is_int($value) === true) {
			return true;
		}

		return is_string($value) === true && preg_match('/^\d+$/', trim($value)) === 1;
	}//end isIcalInteger()

	/**
	 * The refusal for a value on no known scale, naming the value.
	 *
	 * @param mixed $value The raw priority.
	 *
	 * @return TaskValidationException The refusal to throw.
	 */
	private static function offScale(mixed $value): TaskValidationException {
		$printable = gettype($value);
		if (is_scalar($value) === true) {
			$printable = (string)$value;
		}

		return new TaskValidationException(
			message: sprintf(
				"Priority '%s' is on no known scale (low|normal|high|urgent, low|medium|high|critical, or iCal 0-9) and is refused, not coerced.",
				$printable
			)
		);
	}//end offScale()

	/**
	 * Map an iCal integer onto the canonical scale.
	 *
	 * @param int $value The iCal integer.
	 *
	 * @return string One of low|normal|high|urgent.
	 *
	 * @throws TaskValidationException When outside 0-9.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
	 */
	private static function fromIcal(int $value): string {
		if (array_key_exists($value, self::ICAL) === false) {
			throw new TaskValidationException(
				message: sprintf("Priority '%d' is outside the iCal 0-9 range and is refused, not coerced.", $value)
			);
		}

		return self::ICAL[$value];
	}//end fromIcal()
}//end class
