<?php

/**
 * Comparable Instants
 *
 * Turns a pair of date or date-time strings into UTC instants so the RBAC
 * operator evaluator compares them as moments in time rather than as strings.
 *
 * Extracted from OperatorEvaluator to keep that class's complexity in bounds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Makes two values comparable as UTC instants when both are dates.
 *
 * @spec openspec/specs/row-field-level-security/spec.md#the-condition-syntax-must-support-mongodb-style-operators-for-match-expressions
 */
class ComparableInstants {
	/**
	 * A date, or a date-time with optional seconds, fraction and offset.
	 *
	 * @var string
	 */
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/';

	/**
	 * Turn two date strings into comparable UTC instants.
	 *
	 * The SQL path compares timestamp columns as timestamps, but `$now` reaches
	 * the evaluator as 'Y-m-d H:i:s' while stored dates are ISO 8601
	 * ('2026-09-30T20:00:00+00:00'). As strings, 'T' sorts after ' ', so every
	 * timestamp from today read as later than now: a publication dated today
	 * was listed but refused on find() until the next day (list-vs-find drift).
	 * When both sides parse as dates they compare as instants, in UTC; any
	 * other pair is returned unchanged and compares exactly as before.
	 *
	 * @param mixed $value   Object value
	 * @param mixed $operand Threshold value
	 *
	 * @return array{0: mixed, 1: mixed} The pair to compare
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#the-condition-syntax-must-support-mongodb-style-operators-for-match-expressions
	 */
	public function pair(mixed $value, mixed $operand): array {
		$left  = $this->toInstant(value: $value);
		$right = $this->toInstant(value: $operand);
		if ($left === null || $right === null) {
			return [$value, $operand];
		}

		return [$left, $right];
	}//end pair()

	/**
	 * The UTC instant of a date or date-time string, or null when it is not one.
	 *
	 * A value without an offset is read as UTC, which is how `$now` is written.
	 *
	 * @param mixed $value Candidate value
	 *
	 * @return float|null Seconds since the epoch, or null
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#the-condition-syntax-must-support-mongodb-style-operators-for-match-expressions
	 */
	public function toInstant(mixed $value): ?float {
		if (is_string($value) === false || preg_match(self::DATE_PATTERN, $value) !== 1) {
			return null;
		}

		try {
			$instant = new DateTimeImmutable($value, new DateTimeZone('UTC'));
		} catch (Exception $e) {
			return null;
		}

		return (float) $instant->format('U.u');
	}//end toInstant()
}//end class
