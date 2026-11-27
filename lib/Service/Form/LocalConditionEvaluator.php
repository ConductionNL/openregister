<?php

/**
 * LocalConditionEvaluator: evaluate a manifest `visibleWhen` condition against local values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

/**
 * Evaluate the LOCAL mode of the manifest-v2 `$defs.visibleWhen` predicate on the server.
 *
 * Only the local mode is evaluated: `field` dot-paths into the given values.
 * The endpoint, source and workspace modes need a request the server does
 * not make on a run's behalf, so a condition using them does not hold. The
 * evaluation is fail-safe like the client's: anything it cannot read does
 * not hold.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
 */
class LocalConditionEvaluator {

	/**
	 * The operators of `$defs.visibleWhen.op`, in the manifest schema's order.
	 *
	 * @var list<string>
	 */
	public const OPERATORS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'empty', 'notEmpty', 'in', 'anyOf', 'contains'];

	/**
	 * The modes this evaluator cannot answer locally.
	 *
	 * @var list<string>
	 */
	private const REMOTE_MODES = ['endpoint', 'source', 'workspace', 'appInstalled'];

	/**
	 * Whether the condition holds for the values.
	 *
	 * @param array<string, mixed> $condition The `visibleWhen` condition.
	 * @param array<string, mixed> $values    The values `field` dot-paths into.
	 *
	 * @return bool True when it holds; false when it does not or cannot be read.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function holds(array $condition, array $values): bool {
		foreach (self::REMOTE_MODES as $mode) {
			if (array_key_exists($mode, $condition) === true) {
				return false;
			}
		}

		if (is_array($condition['all'] ?? null) === true) {
			return $this->allHold(conditions: $condition['all'], values: $values);
		}

		if (is_array($condition['any'] ?? null) === true) {
			return $this->anyHolds(conditions: $condition['any'], values: $values);
		}

		$field = $condition['field'] ?? null;
		if (is_string($field) === false || $field === '') {
			return false;
		}

		$operator = (string)($condition['op'] ?? 'eq');
		if (in_array($operator, self::OPERATORS, true) === false) {
			return false;
		}

		return $this->compare(
			operator: $operator,
			actual: $this->read(values: $values, path: $field),
			expected: ($condition['value'] ?? null)
		);
	}//end holds()

	/**
	 * The operators a condition uses that `$defs.visibleWhen.op` does not accept, depth first.
	 *
	 * @param array<string, mixed> $condition The `visibleWhen` condition.
	 *
	 * @return list<string> The unknown operators; empty when every one is accepted.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-journey-must-declare-steps-branching-writes-and-access
	 */
	public function unknownOperators(array $condition): array {
		$unknown = [];
		if (array_key_exists('op', $condition) === true
			&& in_array($condition['op'], self::OPERATORS, true) === false
		) {
			$unknown[] = gettype($condition['op']);
			if (is_scalar($condition['op']) === true) {
				$unknown[array_key_last($unknown)] = (string)$condition['op'];
			}
		}

		foreach (['all', 'any'] as $group) {
			foreach ((array)($condition[$group] ?? []) as $nested) {
				if (is_array($nested) === true) {
					$unknown = array_merge($unknown, $this->unknownOperators(condition: $nested));
				}
			}
		}

		return $unknown;
	}//end unknownOperators()

	/**
	 * Whether every condition holds; an empty list does not.
	 *
	 * @param array<int|string, mixed> $conditions The conditions.
	 * @param array<string, mixed>     $values     The values.
	 *
	 * @return bool True when all hold.
	 */
	private function allHold(array $conditions, array $values): bool {
		if ($conditions === []) {
			return false;
		}

		foreach ($conditions as $nested) {
			if (is_array($nested) === false || $this->holds(condition: $nested, values: $values) === false) {
				return false;
			}
		}

		return true;
	}//end allHold()

	/**
	 * Whether at least one condition holds.
	 *
	 * @param array<int|string, mixed> $conditions The conditions.
	 * @param array<string, mixed>     $values     The values.
	 *
	 * @return bool True when one holds.
	 */
	private function anyHolds(array $conditions, array $values): bool {
		foreach ($conditions as $nested) {
			if (is_array($nested) === true && $this->holds(condition: $nested, values: $values) === true) {
				return true;
			}
		}

		return false;
	}//end anyHolds()

	/**
	 * Read a dot-path from the values.
	 *
	 * @param array<string, mixed> $values The values.
	 * @param string               $path   The dot-path.
	 *
	 * @return mixed The value, or null when a segment is missing.
	 */
	private function read(array $values, string $path): mixed {
		$current = $values;
		foreach (explode('.', $path) as $segment) {
			if (is_array($current) === false || array_key_exists($segment, $current) === false) {
				return null;
			}

			$current = $current[$segment];
		}

		return $current;
	}//end read()

	/**
	 * Apply one operator.
	 *
	 * @param string $operator An accepted operator.
	 * @param mixed  $actual   The value read.
	 * @param mixed  $expected The condition's `value`.
	 *
	 * @return bool Whether it holds.
	 */
	private function compare(string $operator, mixed $actual, mixed $expected): bool {
		return match ($operator) {
			'eq' => $this->same(left: $actual, right: $expected),
			'neq' => $this->same(left: $actual, right: $expected) === false,
			'gt', 'gte', 'lt', 'lte' => $this->ordered(operator: $operator, actual: $actual, expected: $expected),
			'empty' => $this->isEmpty(value: $actual),
			'notEmpty' => $this->isEmpty(value: $actual) === false,
			'in' => $this->inList(actual: $actual, expected: $expected),
			'anyOf' => $this->anyOf(actual: $actual, expected: $expected),
			default => $this->contains(actual: $actual, expected: $expected),
		};
	}//end compare()

	/**
	 * Loose primitive equality: both sides normalised to a string, null to ''.
	 *
	 * @param mixed $left  One side.
	 * @param mixed $right The other side.
	 *
	 * @return bool Whether they are equal.
	 */
	private function same(mixed $left, mixed $right): bool {
		if (is_array($left) === true || is_array($right) === true) {
			return $left === $right;
		}

		return $this->normalise(value: $left) === $this->normalise(value: $right);
	}//end same()

	/**
	 * A primitive as the comparison string.
	 *
	 * @param mixed $value The primitive.
	 *
	 * @return string Its comparison form.
	 */
	private function normalise(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return '';
	}//end normalise()

	/**
	 * An ordering comparison; both sides must be numeric.
	 *
	 * @param string $operator gt, gte, lt or lte.
	 * @param mixed  $actual   The value read.
	 * @param mixed  $expected The bound.
	 *
	 * @return bool Whether it holds; false when either side is not a number.
	 */
	private function ordered(string $operator, mixed $actual, mixed $expected): bool {
		if (is_numeric($actual) === false || is_numeric($expected) === false) {
			return false;
		}

		$left  = (float)$actual;
		$right = (float)$expected;

		return match ($operator) {
			'gt' => $left > $right,
			'gte' => $left >= $right,
			'lt' => $left < $right,
			default => $left <= $right,
		};
	}//end ordered()

	/**
	 * Whether a value is empty: null, '' or an empty list, as the client treats them alike.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool Whether it is empty.
	 */
	private function isEmpty(mixed $value): bool {
		if ($value === null || $value === []) {
			return true;
		}

		return $value === '';
	}//end isEmpty()

	/**
	 * Whether the value is one of the listed values.
	 *
	 * @param mixed $actual   The value read.
	 * @param mixed $expected The list.
	 *
	 * @return bool Whether it is listed.
	 */
	private function inList(mixed $actual, mixed $expected): bool {
		if (is_array($expected) === false) {
			return false;
		}

		foreach ($expected as $candidate) {
			if ($this->same(left: $actual, right: $candidate) === true) {
				return true;
			}
		}

		return false;
	}//end inList()

	/**
	 * Whether the value, or any entry of a list value, is one of the listed values.
	 *
	 * @param mixed $actual   The value read.
	 * @param mixed $expected The list.
	 *
	 * @return bool Whether one is listed.
	 */
	private function anyOf(mixed $actual, mixed $expected): bool {
		$candidates = [$actual];
		if (is_array($actual) === true) {
			$candidates = $actual;
		}
		foreach ($candidates as $candidate) {
			if ($this->inList(actual: $candidate, expected: $expected) === true) {
				return true;
			}
		}

		return false;
	}//end anyOf()

	/**
	 * Whether a list value holds the sought value; a non-list never does.
	 *
	 * @param mixed $actual   The value read.
	 * @param mixed $expected The sought value.
	 *
	 * @return bool Whether it is contained.
	 */
	private function contains(mixed $actual, mixed $expected): bool {
		if (is_array($actual) === false) {
			return false;
		}

		return $this->inList(actual: $expected, expected: $actual);
	}//end contains()
}//end class
