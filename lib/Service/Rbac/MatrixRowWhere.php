<?php

/**
 * A department matrix row's `where`: extra conditions in the scope vocabulary.
 *
 * Read in one place so the compiler and the validator cannot disagree about
 * which `where` is readable: the compiler drops a row it cannot read, and the
 * validator refuses the same row at save.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Reads a matrix row's `where`.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class MatrixRowWhere {

	/**
	 * A row's extra conditions, key-sorted, or null when the row must be dropped.
	 *
	 * 🔴 A `where` that cannot be read drops the row, never the condition: a
	 * row without its condition is wider than the one written. That covers a
	 * non-object `where`, a key that repeats the matrix field, a value that is
	 * not a scalar, a list of scalars or `{$in: [...]}`, and an empty list,
	 * which the SQL builder would drop the same way it drops an empty `$in`.
	 *
	 * @param array<string, mixed> $row   The row.
	 * @param string               $field The matrix field.
	 *
	 * @return array<string, mixed>|null The conditions, or null to drop the row.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function conditionsOf(array $row, string $field): ?array {
		$where = ($row['where'] ?? []);
		if (is_array($where) === false) {
			return null;
		}

		$conditions = [];
		foreach ($where as $key => $value) {
			$key = trim((string)$key);
			if ($key === '' || $key === $field) {
				return null;
			}

			$condition = $this->condition(value: $value);
			if ($condition === null) {
				return null;
			}

			$conditions[$key] = $condition;
		}

		ksort($conditions);
		return $conditions;
	}//end conditionsOf()

	/**
	 * One `where` value in the scope vocabulary, or null when it cannot be read.
	 *
	 * A scalar stays a scalar; a list becomes `{$in: list}`; `{$in: list}` stays
	 * as written. Lists must be non-empty and hold scalars only.
	 *
	 * @param mixed $value The value as written.
	 *
	 * @return string|int|float|bool|array{'$in': array<int, string|int|float|bool>}|null The condition.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function condition(mixed $value): string|int|float|bool|array|null {
		if (is_scalar($value) === true) {
			return $value;
		}

		if (is_array($value) === false) {
			return null;
		}

		if (array_is_list($value) === false) {
			$value = $this->listInsideIn(value: $value);
		}

		if ($value === null || $value === [] || array_is_list($value) === false) {
			return null;
		}

		if (count(array_filter($value, 'is_scalar')) !== count($value)) {
			return null;
		}

		return ['$in' => $value];
	}//end condition()

	/**
	 * The list inside `{$in: list}`, or null for any other object.
	 *
	 * @param array<string|int, mixed> $value The object as written.
	 *
	 * @return array<int|string, mixed>|null The list.
	 */
	private function listInsideIn(array $value): ?array {
		if (array_keys($value) !== ['$in'] || is_array($value['$in']) === false) {
			return null;
		}

		return $value['$in'];
	}//end listInsideIn()
}//end class
