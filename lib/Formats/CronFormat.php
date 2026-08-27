<?php

/**
 * Cron Format Validator
 *
 * Validates standard five-field cron expressions.
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
 * @link      https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * Standard five-field cron format validator.
 *
 * `minute hour day-of-month month day-of-week`, with `*`, numbers, `a-b`
 * ranges, `a,b` lists and `*​/n` steps.
 *
 * WHY THIS IS WORTH VALIDATING AT ALL
 * -----------------------------------
 * A cron expression fails in the quietest way a value can: the schedule simply
 * never fires, at 03:00, with nobody watching. There is no request to return an
 * error to and no user in the room. The only moment the mistake is cheap is the
 * moment it is saved, which is what this exists for.
 *
 * ⚠️ `@daily` AND ITS SIBLINGS ARE DELIBERATELY REFUSED. Which shortcuts a
 * scheduler resolves varies between implementations, so accepting them here
 * would let a document validate against a vocabulary the runtime may not share
 * — producing exactly the silent never-fires this check exists to prevent. Five
 * fields is the form every cron implementation agrees on.
 *
 * @category Formats
 * @package  OCA\OpenRegister\Formats
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  GIT: <git_id>
 * @link     https://www.conduction.nl
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class CronFormat implements Format {
	/**
	 * The inclusive numeric range of each field, in cron's own order.
	 *
	 * Day-of-week runs 0-7 because BOTH 0 and 7 mean Sunday in standard cron.
	 * Refusing 7 would reject expressions every crontab accepts.
	 *
	 * @var array<int, array{int, int}>
	 */
	private const RANGES = [
		[0, 59],
		[0, 23],
		[1, 31],
		[1, 12],
		[0, 7],
	];

	/**
	 * Validate a five-field cron expression.
	 *
	 * @param mixed $data The data to validate.
	 *
	 * @inheritDoc
	 *
	 * @return bool True when the value is a valid cron expression.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function validate(mixed $data): bool {
		if (is_string($data) === false) {
			return false;
		}

		$fields = preg_split('/\s+/', trim($data), flags: PREG_SPLIT_NO_EMPTY);
		if (is_array($fields) === false || count($fields) !== 5) {
			return false;
		}

		foreach ($fields as $index => $field) {
			if ($this->isValidField(field: $field, range: self::RANGES[$index]) === false) {
				return false;
			}
		}

		return true;
	}//end validate()

	/**
	 * Whether one field is a legal term for its position.
	 *
	 * Every term of a comma list is checked on its own rather than by one large
	 * pattern, so `1,,2` and `1,99` are refused for the same reason a bare `99`
	 * is — and the reason stays readable.
	 *
	 * @param string          $field The field.
	 * @param array{int, int} $range Its inclusive bounds.
	 *
	 * @return bool True when the field is legal.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function isValidField(string $field, array $range): bool {
		if ($field === '*') {
			return true;
		}

		foreach (explode(',', $field) as $term) {
			if ($this->isValidTerm(term: $term, range: $range) === false) {
				return false;
			}
		}

		return true;
	}//end isValidField()

	/**
	 * Whether a single comma-free term is legal.
	 *
	 * Split from the BODY check below on a real seam rather than to satisfy a
	 * counter: this method answers "is the step legal", the other answers "is
	 * the value or range legal", and they share nothing but the term they came
	 * from. Together they were cyclomatic 14 / NPath 1344, which is the shape
	 * of a method doing two jobs.
	 *
	 * @param string          $term  The term.
	 * @param array{int, int} $range Its inclusive bounds.
	 *
	 * @return bool True when the term is legal.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function isValidTerm(string $term, array $range): bool {
		if ($term === '') {
			return false;
		}

		$parts = explode('/', $term);
		if (count($parts) > 2) {
			return false;
		}

		// A step of 0 would never advance, so the expression names no time at
		// all rather than every time.
		if (count($parts) === 2
			&& (preg_match('/^\d+$/', $parts[1]) !== 1 || (int)$parts[1] < 1)
		) {
			return false;
		}

		return $this->isValidBody(body: $parts[0], range: $range);
	}//end isValidTerm()

	/**
	 * Whether a term's body — the part before any step — is legal.
	 *
	 * Either `*`, a single number in range, or an ascending `a-b` range.
	 *
	 * @param string          $body  The body.
	 * @param array{int, int} $range Its inclusive bounds.
	 *
	 * @return bool True when the body is legal.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function isValidBody(string $body, array $range): bool {
		if ($body === '*') {
			return true;
		}

		$bounds = explode('-', $body);
		if (count($bounds) > 2) {
			return false;
		}

		$numbers = [];
		foreach ($bounds as $bound) {
			if (preg_match('/^\d+$/', $bound) !== 1) {
				return false;
			}

			$value = (int)$bound;
			if ($value < $range[0] || $value > $range[1]) {
				return false;
			}

			$numbers[] = $value;
		}

		// A backwards range names no values at all.
		if (count($numbers) === 2 && $numbers[0] > $numbers[1]) {
			return false;
		}

		return true;
	}//end isValidBody()

}//end class
