<?php

/**
 * OpenRegister RetentionConditionEvaluator
 *
 * Minimal `<field> <op> <literal>` evaluator for the
 * `x-openregister-archival.retention.rules[].condition` DSL.
 *
 * Per design decision D4 of `add-archival-annotation-support`, the
 * grammar is intentionally tiny:
 *
 *   <field> <op> <literal>
 *
 *   ops:      <  <=  ==  !=  >=  >
 *   literals: integer, float, single- or double-quoted string,
 *             `true`, `false`, `null`
 *
 * Anything more complex (AND/OR, sub-expressions, function calls)
 * is intentionally out of scope. A future change can layer a real
 * expression engine on top if the fleet ever needs it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4-1
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Archival;

use InvalidArgumentException;

/**
 * Evaluate a single retention-rule condition against a row.
 *
 * The class is stateless; callers can instantiate once and re-use.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
 */
final class RetentionConditionEvaluator {

	/**
	 * Pattern matching `<field> <op> <literal>` with two-char ops first
	 * so `<=` does not match as `<` and `==` does not match as a partial.
	 *
	 * @var string
	 */
	private const PATTERN = '/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*(<=|>=|==|!=|<|>)\s*(.+?)\s*$/';

	/**
	 * Evaluate a condition string against a row.
	 *
	 * @param string $condition Condition expression in the supported DSL.
	 * @param array<string, mixed> $row Row data keyed by field name.
	 *
	 * @return bool True when the condition matches; false when the field is
	 *              absent from the row or the comparison evaluates false.
	 *
	 * @throws InvalidArgumentException When the condition is malformed (wrong
	 *                                  grammar, unknown operator, unparseable
	 *                                  literal). Callers SHOULD catch + log;
	 *                                  the cron does not crash on a single
	 *                                  malformed rule.
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-4
	 */
	public function evaluate(string $condition, array $row): bool {
		if (preg_match(self::PATTERN, $condition, $matches) !== 1) {
			throw new InvalidArgumentException(
				sprintf('Malformed retention condition: "%s". Expected "<field> <op> <literal>".', $condition)
			);
		}

		[, $field, $operator, $literalSource] = $matches;

		if (array_key_exists($field, $row) === false) {
			// Field absent → no match. Spec D-Scenario "Missing field".
			return false;
		}

		$left = $row[$field];
		$right = $this->parseLiteral(source: $literalSource);

		return $this->compare(left: $left, right: $right, operator: $operator);
	}//end evaluate()

	/**
	 * Parse the literal source into a PHP value.
	 *
	 * @param string $source Raw literal text from the condition string.
	 *
	 * @return mixed Parsed value (int, float, string, bool, null).
	 *
	 * @throws InvalidArgumentException When the literal is not recognised.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	private function parseLiteral(string $source): mixed {
		$trim = trim($source);

		// Bool / null sentinels.
		if ($trim === 'true') {
			return true;
		}

		if ($trim === 'false') {
			return false;
		}

		if ($trim === 'null') {
			return null;
		}

		// Single- or double-quoted string literal.
		$length = strlen($trim);
		if ($length >= 2) {
			$first = $trim[0];
			$last = $trim[($length - 1)];
			if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
				return substr($trim, 1, ($length - 2));
			}
		}

		// Numeric literal — int or float.
		if (preg_match('/^-?\d+$/', $trim) === 1) {
			return (int)$trim;
		}

		if (preg_match('/^-?\d+\.\d+$/', $trim) === 1) {
			return (float)$trim;
		}

		throw new InvalidArgumentException(
			sprintf('Unrecognised literal in retention condition: "%s".', $source)
		);

	}//end parseLiteral()

	/**
	 * Compare two values under the given operator.
	 *
	 * PHP's loose comparison semantics are used for `==` / `!=` so
	 * `200 == 200.0 → true`. Strict-typed equality is not part of
	 * the DSL; if you need it, write a different rule.
	 *
	 * For ordering ops (`<`, `<=`, `>=`, `>`) on null or string
	 * pairs we fall back to PHP's native comparison rules; this is
	 * documented as DSL behaviour, not a bug.
	 *
	 * @param mixed $left Left-hand value (from row).
	 * @param mixed $right Right-hand literal.
	 * @param string $operator One of the supported operators.
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException When the operator is not in the supported set.
	 */
	private function compare(mixed $left, mixed $right, string $operator): bool {
		return match ($operator) {
			'==' => $left == $right,
			'!=' => $left != $right,
			'<' => $left < $right,
			'<=' => $left <= $right,
			'>' => $left > $right,
			'>=' => $left >= $right,
			default => throw new InvalidArgumentException(
				sprintf('Unsupported retention operator: "%s".', $operator)
			),
		};

	}//end compare()
}//end class
