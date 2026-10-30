<?php

/**
 * What is wrong with a department/role matrix, before it can grant anything.
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
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Refuses a matrix that cannot be compiled, naming the row that is wrong.
 *
 * WHY THIS IS NOT IN THE COMPILER. The two run at different moments and one
 * of them must not be skippable: the checks here happen when a SCHEMA IS
 * SAVED, and the compile happens on every read afterwards. A matrix on
 * `afdeling` where the schema declares `department` compiles to a condition
 * on a column that does not exist, and the SQL path answers that by dropping
 * the predicate — the widening direction, arriving in silence. The save is
 * the last point at which it can be named.
 *
 * Every message names the row by index, because a matrix is a table an
 * administrator typed and "a row is wrong" sends them back to read all of
 * them.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
class DepartmentMatrixValidator {

	/**
	 * The matrix key naming the provider of rows an app adds at check time.
	 *
	 * @var string
	 */
	public const ROWS_FROM_KEY = 'rowsFrom';

	/**
	 * Findings for one row, as the compiler will read it.
	 *
	 * Used for rows an app adds at check time, which never pass a schema save.
	 *
	 * @param mixed                $row        The row.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $field      The matrix field.
	 *
	 * @return array<int, array{code: string, message: string}> The findings; empty when the row reads.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	public function findingsForRow(mixed $row, array $properties, string $field): array {
		$findings = $this->rowFindings(row: $row, index: 0);
		if (is_array($row) === true) {
			$findings = array_merge(
				$findings,
				$this->whereFindings(row: $row, index: 0, properties: $properties, field: $field)
			);
		}

		return $findings;
	}//end findingsForRow()

	/**
	 * Findings for a matrix declared on a schema.
	 *
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param array<string, mixed>|null $authorization The authorization block.
	 *
	 * @return array<int, array{code: string, message: string}> The findings; empty when valid.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function validate(array $properties, ?array $authorization): array {
		$matrix = ($authorization[DepartmentMatrixCompiler::KEY] ?? null);
		if ($matrix === null) {
			return [];
		}

		if (is_array($matrix) === false) {
			return [['code' => 'matrix.not-object', 'message' => 'authorization.matrix must be an object.']];
		}

		$findings = [];

		$field = trim((string)($matrix['field'] ?? ''));
		if ($field === '') {
			$findings[] = ['code' => 'matrix.no-field', 'message' => 'A matrix must name the object field it keys on.'];
		} elseif (array_key_exists($field, $properties) === false) {
			// Named rather than described: a matrix on `afdeling` where the
			// schema declares `department` compiles to a condition on a column
			// that does not exist, which the SQL path answers by dropping the
			// predicate.
			$findings[] = [
				'code' => 'matrix.unknown-field',
				'message' => 'The matrix field "' . $field . '" is not a property of this schema.',
			];
		}

		$findings = array_merge($findings, $this->validateUserSource(source: ($matrix['userSource'] ?? null)));

		return array_merge(
			$findings,
			$this->rowsFindings(matrix: $matrix, properties: $properties, field: $field)
		);
	}//end validate()

	/**
	 * Findings for `rowsFrom` and the declared rows.
	 *
	 * A matrix that names a provider may declare no rows of its own: its rows
	 * arrive at check time.
	 *
	 * @param array<string, mixed> $matrix     The matrix.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $field      The matrix field.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function rowsFindings(array $matrix, array $properties, string $field): array {
		$findings = [];
		$rowsFrom = ($matrix[self::ROWS_FROM_KEY] ?? null);
		if ($rowsFrom !== null && (is_string($rowsFrom) === false || trim($rowsFrom) === '')) {
			$findings[] = ['code' => 'matrix.bad-rows-from', 'message' => 'rowsFrom must name a provider.'];
		}

		if (array_key_exists('fileVerbs', $matrix) === true && is_bool($matrix['fileVerbs']) === false) {
			$findings[] = ['code' => 'matrix.bad-file-verbs', 'message' => 'fileVerbs must be true or false.'];
		}

		$rows = ($matrix['rows'] ?? null);
		if ($rowsFrom !== null && ($rows === null || $rows === [])) {
			return $findings;
		}

		return array_merge(
			$findings,
			$this->validateRows(rows: $rows, properties: $properties, field: $field)
		);
	}//end rowsFindings()

	/**
	 * Findings for the user source.
	 *
	 * @param mixed $source The declared source.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateUserSource(mixed $source): array {
		if (is_array($source) === false) {
			return [
				[
					'code' => 'matrix.no-user-source',
					'message' => 'A matrix must declare where a user\'s own values come from.',
				],
			];
		}

		$hasGroups = (($source['groups'] ?? false) === true);
		$hasPrefix = (trim((string)($source['groupPrefix'] ?? '')) !== '');
		$hasSchema = (trim((string)($source['schema'] ?? '')) !== ''
			&& trim((string)($source['property'] ?? '')) !== '');

		if ($hasGroups === false && $hasPrefix === false && $hasSchema === false) {
			return [
				[
					'code' => 'matrix.bad-user-source',
					'message' => 'userSource must declare groups: true, a groupPrefix, or a schema and property pair.',
				],
			];
		}

		return [];
	}//end validateUserSource()

	/**
	 * Findings for the rows.
	 *
	 * @param mixed                $rows       The declared rows.
	 * @param array<string, mixed> $properties The schema's properties, which a `where` key must name.
	 * @param string               $field      The matrix field, which a `where` may not repeat.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateRows(mixed $rows, array $properties, string $field): array {
		if (is_array($rows) === false || count($rows) === 0) {
			return [['code' => 'matrix.no-rows', 'message' => 'A matrix must declare at least one row.']];
		}

		$findings = [];
		foreach ($rows as $index => $row) {
			$findings = array_merge($findings, $this->rowFindings(row: $row, index: $index));
			if (is_array($row) === true) {
				$findings = array_merge(
					$findings,
					$this->whereFindings(row: $row, index: $index, properties: $properties, field: $field)
				);
			}
		}//end foreach

		return $findings;
	}//end validateRows()

	/**
	 * Findings for ONE row.
	 *
	 * Every message names the row by index, because a matrix is a table an
	 * administrator typed and "a row is wrong" sends them back to read all of
	 * them.
	 *
	 * @param mixed          $row   The declared row.
	 * @param string|integer $index Which row it is.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	private function rowFindings(mixed $row, string|int $index): array {
		if (is_array($row) === false) {
			return [
				[
					'code' => 'matrix.bad-row',
					'message' => 'Row ' . (string)$index . ' is not an object.',
				],
			];
		}

		$findings = [];
		if (trim((string)($row['group'] ?? '')) === '') {
			$findings[] = [
				'code' => 'matrix.no-group',
				'message' => 'Row ' . (string)$index . ' names no role group.',
			];
		}

		$actions = ($row['actions'] ?? null);
		if (is_array($actions) === false || count($actions) === 0) {
			$findings[] = [
				'code' => 'matrix.no-actions',
				'message' => 'Row ' . (string)$index . ' grants no action.',
			];
			return $findings;
		}

		foreach ($actions as $action) {
			if (in_array(trim((string)$action), DepartmentMatrixCompiler::ACTIONS, true) === false) {
				$findings[] = [
					'code' => 'matrix.unknown-action',
					'message' => 'Row ' . (string)$index . ' names the action "'
						. trim((string)$action) . '", which is not one this engine resolves.',
				];
			}
		}

		return $findings;
	}//end rowFindings()

	/**
	 * Findings for one row's `where` and `until`.
	 *
	 * The compiler drops a row it cannot read; refusing it here is what tells
	 * the administrator, before a row they wrote quietly grants nothing.
	 *
	 * @param array<string, mixed> $row        The row.
	 * @param string|integer       $index      Which row it is.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $field      The matrix field.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 *
	 * @spec openspec/changes/rbac-matrix-app-rows-and-file-verbs/specs/rbac-scopes/spec.md
	 */
	private function whereFindings(array $row, string|int $index, array $properties, string $field): array {
		$name = 'Row ' . (string)$index;
		$findings = [];

		$until = ($row[GrantConstraints::UNTIL_KEY] ?? null);
		if ($until !== null && (is_string($until) === false || strtotime($until) === false)) {
			$findings[] = ['code' => 'matrix.bad-until', 'message' => $name . ' has an end that is not a date.'];
		}

		$where = ($row['where'] ?? []);
		if (is_array($where) === false || ($where !== [] && array_is_list($where) === true)) {
			$findings[] = ['code' => 'matrix.bad-where', 'message' => $name . ': where must be an object.'];
			return $findings;
		}

		foreach ($where as $key => $value) {
			$finding = $this->whereKeyFinding(name: $name, key: (string)$key, value: $value, properties: $properties, field: $field);
			if ($finding !== null) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}//end whereFindings()

	/**
	 * The finding for one `where` condition, or null when it reads.
	 *
	 * @param string               $name       The row, as messages name it.
	 * @param string               $key        The property the condition is on.
	 * @param mixed                $value      The condition.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $field      The matrix field.
	 *
	 * @return array{code: string, message: string}|null The finding.
	 */
	private function whereKeyFinding(string $name, string $key, mixed $value, array $properties, string $field): ?array {
		if ($key === $field) {
			return [
				'code' => 'matrix.where-repeats-field',
				'message' => $name . ': where repeats the matrix field "' . $key . '".',
			];
		}

		if (array_key_exists($key, $properties) === false) {
			return [
				'code' => 'matrix.where-unknown-field',
				'message' => $name . ': where names "' . $key . '", which is not a property of this schema.',
			];
		}

		if ((new MatrixRowWhere())->condition(value: $value) === null) {
			return [
				'code' => 'matrix.bad-where-value',
				'message' => $name . ': the condition on "' . $key
					. '" must be a value, a non-empty list of values or {$in: [...]}.',
			];
		}

		return null;
	}//end whereKeyFinding()

}//end class
