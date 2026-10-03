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
		$findings = array_merge($findings, $this->validateRows(rows: ($matrix['rows'] ?? null)));

		return $findings;
	}//end validate()

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

		$hasPrefix = (trim((string)($source['groupPrefix'] ?? '')) !== '');
		$hasSchema = (trim((string)($source['schema'] ?? '')) !== ''
			&& trim((string)($source['property'] ?? '')) !== '');

		if ($hasPrefix === false && $hasSchema === false) {
			return [
				[
					'code' => 'matrix.bad-user-source',
					'message' => 'userSource must declare either a groupPrefix or a schema and property pair.',
				],
			];
		}

		return [];
	}//end validateUserSource()

	/**
	 * Findings for the rows.
	 *
	 * @param mixed $rows The declared rows.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function validateRows(mixed $rows): array {
		if (is_array($rows) === false || count($rows) === 0) {
			return [['code' => 'matrix.no-rows', 'message' => 'A matrix must declare at least one row.']];
		}

		$findings = [];
		foreach ($rows as $index => $row) {
			$findings = array_merge($findings, $this->rowFindings(row: $row, index: $index));
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

}//end class
