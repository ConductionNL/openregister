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
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Exception\TaskValidationException;

/**
 * Normalises every known priority scale onto low|normal|high|urgent.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
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
	 * Normalise a priority from any known scale.
	 *
	 * @param mixed $value A string from a known scale, or an iCal 0-9 integer
	 *                     (numeric strings are read as the integer they spell).
	 *
	 * @return string One of low|normal|high|urgent.
	 *
	 * @throws TaskValidationException When the value is on no known scale.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
	 */
	public static function normalise(mixed $value): string {
		// The iCal integer range, arriving as int or as a numeric string.
		if (is_int($value) === true || (is_string($value) === true && preg_match('/^\d+$/', trim($value)) === 1)) {
			return self::fromIcal(value: (int)trim((string)$value));
		}

		if (is_string($value) === true) {
			$lowered = strtolower(trim($value));
			if (array_key_exists($lowered, self::STRINGS) === true) {
				return self::STRINGS[$lowered];
			}
		}

		$printable = gettype($value);
		if (is_scalar($value) === true) {
			$printable = (string)$value;
		}

		throw new TaskValidationException(
			message: sprintf(
				"Priority '%s' is on no known scale (low|normal|high|urgent, low|medium|high|critical, or iCal 0-9) and is refused, not coerced.",
				$printable
			)
		);
	}//end normalise()

	/**
	 * The iCal 0-9 mapping: 1 is the wire format's highest.
	 *
	 * RFC 5545: 0 undefined, 1-4 high, 5 medium, 6-9 low. `1` — the value a
	 * VTODO marks its most urgent work with — lands on `urgent`; the rest of
	 * the high band on `high`.
	 *
	 * @param int $value The iCal integer.
	 *
	 * @return string One of low|normal|high|urgent.
	 *
	 * @throws TaskValidationException When outside 0-9.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-priority-is-normalised-to-one-scale-on-the-way-in
	 */
	private static function fromIcal(int $value): string {
		if ($value < 0 || $value > 9) {
			throw new TaskValidationException(
				message: sprintf("Priority '%d' is outside the iCal 0-9 range and is refused, not coerced.", $value)
			);
		}

		if ($value === 0 || $value === 5) {
			return 'normal';
		}

		if ($value === 1) {
			return 'urgent';
		}

		if ($value <= 4) {
			return 'high';
		}

		return 'low';
	}//end fromIcal()
}//end class
