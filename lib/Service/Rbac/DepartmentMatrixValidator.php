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
	 * Metadata fields a row's `where` may name besides the schema's properties.
	 *
	 * Each is a column of every magic table that the system sets (a writer
	 * cannot put it in the object body) and that every enforcement path reads
	 * the same way: `t._organisation` on the list, `@self.organisation` on a
	 * single object.
	 *
	 * @var string[]
	 */
	public const WHERE_METADATA_FIELDS = ['_organisation', '_owner'];

	/**
	 * Constructor.
	 *
	 * @param array<int, string> $declaredActions The actions the schema declares itself, which a row may grant too.
	 */
	public function __construct(private readonly array $declaredActions = []) {
	}//end __construct()

	/**
	 * This validator for a schema that declares these actions.
	 *
	 * @param array<int, string> $declaredActions The schema's declared action keys.
	 *
	 * @return self The validator.
	 *
	 * @spec openspec/changes/declared-self-assign-action/specs/declared-actions/spec.md
	 */
	public function withDeclaredActions(array $declaredActions): self {
		return new self(declaredActions: $declaredActions);
	}//end withDeclaredActions()

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
	 * @spec openspec/specs/rbac-scopes/spec.md
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

		$field = trim((string)($matrix['field'] ?? ''));
		$findings = $this->fieldFindings(field: $field, properties: $properties);

		$findings = array_merge($findings, $this->validateUserSource(source: ($matrix['userSource'] ?? null)));

		return array_merge(
			$findings,
			$this->rowsFindings(matrix: $matrix, properties: $properties, field: $field)
		);
	}//end validate()

	/**
	 * The findings on the field a matrix keys on.
	 *
	 * @param string $field      The trimmed field name.
	 * @param array  $properties The schema's properties.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function fieldFindings(string $field, array $properties): array {
		if ($field === '') {
			return [['code' => 'matrix.no-field', 'message' => 'A matrix must name the object field it keys on.']];
		}

		if (array_key_exists($field, $properties) === false) {
			// Named rather than described: a matrix on `afdeling` where the
			// schema declares `department` compiles to a condition on a column
			// that does not exist, which the SQL path answers by dropping the
			// predicate.
			return [
				[
					'code' => 'matrix.unknown-field',
					'message' => 'The matrix field "' . $field . '" is not a property of this schema.',
				],
			];
		}

		return [];
	}//end fieldFindings()

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
		$rowsFrom = ($matrix[self::ROWS_FROM_KEY] ?? null);
		$findings = array_merge($this->rowsFromFindings(rowsFrom: $rowsFrom), $this->fileVerbsFindings(matrix: $matrix));

		$rows = ($matrix['rows'] ?? null);
		if ($this->providerSuppliesRows(rowsFrom: $rowsFrom, rows: $rows) === true) {
			return $findings;
		}

		return array_merge(
			$findings,
			$this->validateRows(rows: $rows, properties: $properties, field: $field)
		);
	}//end rowsFindings()

	/**
	 * The finding on a `rowsFrom` that names no provider.
	 *
	 * @param mixed $rowsFrom The declared provider, or null.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function rowsFromFindings(mixed $rowsFrom): array {
		if ($rowsFrom !== null && (is_string($rowsFrom) === false || trim($rowsFrom) === '')) {
			return [['code' => 'matrix.bad-rows-from', 'message' => 'rowsFrom must name a provider.']];
		}

		return [];
	}//end rowsFromFindings()

	/**
	 * The finding on a `fileVerbs` that is not a boolean.
	 *
	 * @param array $matrix The matrix declaration.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function fileVerbsFindings(array $matrix): array {
		if (array_key_exists('fileVerbs', $matrix) === true && is_bool($matrix['fileVerbs']) === false) {
			return [['code' => 'matrix.bad-file-verbs', 'message' => 'fileVerbs must be true or false.']];
		}

		return [];
	}//end fileVerbsFindings()

	/**
	 * Whether a provider stands in for rows the declaration leaves out.
	 *
	 * @param mixed $rowsFrom The declared provider, or null.
	 * @param mixed $rows     The declared rows.
	 *
	 * @return bool True when the rows are not the declaration's to check.
	 */
	private function providerSuppliesRows(mixed $rowsFrom, mixed $rows): bool {
		return $rowsFrom !== null && ($rows === null || $rows === []);
	}//end providerSuppliesRows()

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

		if ($this->declaresAUserSource(source: $source) === false) {
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
	 * Whether a userSource declares groups: true, a groupPrefix, or a schema and property pair.
	 *
	 * @param array $source The userSource declaration.
	 *
	 * @return bool True when at least one form is declared.
	 */
	private function declaresAUserSource(array $source): bool {
		$hasGroups = (($source['groups'] ?? false) === true);
		$hasPrefix = (trim((string)($source['groupPrefix'] ?? '')) !== '');

		return $hasGroups === true || $hasPrefix === true || $this->declaresASchemaSource(source: $source);
	}//end declaresAUserSource()

	/**
	 * Whether a userSource names both a schema and a property.
	 *
	 * @param array $source The userSource declaration.
	 *
	 * @return bool True when both are non-blank.
	 */
	private function declaresASchemaSource(array $source): bool {
		return (trim((string)($source['schema'] ?? '')) !== ''
			&& trim((string)($source['property'] ?? '')) !== '');
	}//end declaresASchemaSource()

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

		return array_merge($findings, $this->actionFindings(actions: $actions, index: $index));
	}//end rowFindings()

	/**
	 * A finding for every action of a row this engine does not resolve.
	 *
	 * @param array      $actions The row's actions.
	 * @param string|int $index   The row's index.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function actionFindings(array $actions, string|int $index): array {
		$findings = [];
		foreach ($actions as $action) {
			if (in_array(trim((string)$action), [...DepartmentMatrixCompiler::ACTIONS, ...$this->declaredActions], true) === false) {
				$findings[] = [
					'code' => 'matrix.unknown-action',
					'message' => 'Row ' . (string)$index . ' names the action "'
						. trim((string)$action) . '", which is not one this engine resolves.',
				];
			}
		}

		return $findings;
	}//end actionFindings()

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
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function whereFindings(array $row, string|int $index, array $properties, string $field): array {
		$name = 'Row ' . (string)$index;
		$findings = $this->untilFindings(row: $row, name: $name);

		$where = ($row['where'] ?? []);
		if ($this->isMalformedWhere(where: $where) === true) {
			$findings[] = ['code' => 'matrix.bad-where', 'message' => $name . ': where must be an object.'];
			return $findings;
		}

		$findings = array_merge(
			$findings,
			$this->whereEntryFindings(where: $where, name: $name, properties: $properties, field: $field)
		);

		return array_merge($findings, (new MatrixRowWhere())->crossingFindings(row: $row, name: $name));
	}//end whereFindings()

	/**
	 * The finding on an `until` that is not a date.
	 *
	 * @param array  $row  The row.
	 * @param string $name The row's name in messages.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function untilFindings(array $row, string $name): array {
		$until = ($row[GrantConstraints::UNTIL_KEY] ?? null);
		if ($until !== null && (is_string($until) === false || strtotime($until) === false)) {
			return [['code' => 'matrix.bad-until', 'message' => $name . ' has an end that is not a date.']];
		}

		return [];
	}//end untilFindings()

	/**
	 * Whether a `where` is not an object: not an array, or a non-empty list.
	 *
	 * @param mixed $where The declared `where`.
	 *
	 * @return bool True when it cannot be read as conditions.
	 */
	private function isMalformedWhere(mixed $where): bool {
		return is_array($where) === false || ($where !== [] && array_is_list($where) === true);
	}//end isMalformedWhere()

	/**
	 * One finding per `where` entry whose key or value is refused (key first).
	 *
	 * @param array  $where      The conditions.
	 * @param string $name       The row's name in messages.
	 * @param array  $properties The schema's properties.
	 * @param string $field      The matrix field.
	 *
	 * @return array<int, array{code: string, message: string}> The findings.
	 */
	private function whereEntryFindings(array $where, string $name, array $properties, string $field): array {
		$findings = [];
		foreach ($where as $key => $value) {
			$finding = (
				$this->whereKeyNameFinding(name: $name, key: (string)$key, properties: $properties, field: $field)
				?? $this->whereValueFinding(name: $name, key: (string)$key, value: $value)
			);
			if ($finding !== null) {
				$findings[] = $finding;
			}
		}

		return $findings;
	}//end whereEntryFindings()

	/**
	 * The finding for the field one `where` condition names, or null when it reads.
	 *
	 * @param string               $name       The row, as messages name it.
	 * @param string               $key        The property the condition is on.
	 * @param array<string, mixed> $properties The schema's properties.
	 * @param string               $field      The matrix field.
	 *
	 * @return array{code: string, message: string}|null The finding.
	 */
	private function whereKeyNameFinding(string $name, string $key, array $properties, string $field): ?array {
		if ($key === $field) {
			return [
				'code' => 'matrix.where-repeats-field',
				'message' => $name . ': where repeats the matrix field "' . $key . '".',
			];
		}

		if (array_key_exists($key, $properties) === false && in_array($key, self::WHERE_METADATA_FIELDS, true) === false) {
			return [
				'code' => 'matrix.where-unknown-field',
				'message' => $name . ': where names "' . $key . '", which is neither a property of this schema nor '
					. implode(' or ', self::WHERE_METADATA_FIELDS) . '.',
			];
		}

		return null;
	}//end whereKeyNameFinding()

	/**
	 * The finding for the value of one `where` condition, or null when it reads.
	 *
	 * @param string $name  The row, as messages name it.
	 * @param string $key   The property the condition is on.
	 * @param mixed  $value The condition.
	 *
	 * @return array{code: string, message: string}|null The finding.
	 */
	private function whereValueFinding(string $name, string $key, mixed $value): ?array {
		if ((new MatrixRowWhere())->condition(value: $value) === null) {
			return [
				'code' => 'matrix.bad-where-value',
				'message' => $name . ': the condition on "' . $key
					. '" must be a value, a non-empty list of values or {$in: [...]}.',
			];
		}

		return null;
	}//end whereValueFinding()

}//end class
