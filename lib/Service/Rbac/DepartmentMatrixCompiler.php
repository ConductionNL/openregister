<?php

/**
 * A department by role matrix, compiled into the scopes the engine already runs.
 *
 * Round 2 row B13. Every competitor grants rights per case type as a matrix:
 * Zaaksysteem's case type editor has a Rechten tab of Afdeling by Rol with four
 * checkboxes, OpenCase scopes by organisation and KLE, GZAC stores a permission
 * JSON per role. Here a group could read every object of a schema or none; it
 * could not read the objects of its own department only.
 *
 * WHY THIS COMPILES RATHER THAN ENFORCES. `rbac-scopes` already has conditional
 * scopes with dynamic variables, and both the PHP path and the SQL path already
 * evaluate them — identically, which is a requirement that suite pins. A second
 * enforcement path for the matrix would be a second answer to the same
 * question, and the first time the two disagreed the difference would be a
 * disclosure. So a matrix row becomes an ORDINARY conditional scope and nothing
 * at enforcement time changes at all (D-1).
 *
 * 🔴 AN EMPTY `$in` IS A LEAK, NOT A REFUSAL. `buildArrayOperatorCondition()`
 * returns null for an empty operand, `buildMatchConditions()` then DROPS the
 * predicate, and a rule that was meant to say "only your own departments"
 * becomes an unconditional grant to the whole group. So a row whose values
 * resolve to nothing is dropped WHOLE rather than emitted with an empty list.
 * That is the difference between a user with no department seeing nothing and
 * seeing everything, and it is invisible in the compiled JSON unless you know
 * to look for it.
 *
 * WHY `$self` IS RESOLVED HERE AND NOT BY A NEW DYNAMIC TOKEN. The values are
 * the caller's own, the compiler runs per request with the session in hand, and
 * adding a `$userDepartments` token would mean teaching both evaluators a new
 * word and keeping their two readings identical forever. Resolving it into a
 * literal `$in` list leaves one vocabulary.
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
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Compiles an `authorization.matrix` block into conditional scopes.
 *
 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
 */
class DepartmentMatrixCompiler {

	/**
	 * The key the matrix is declared under, inside `authorization`.
	 *
	 * @var string
	 */
	public const KEY = 'matrix';

	/**
	 * The wildcard that means "whatever this caller's own values are".
	 *
	 * @var string
	 */
	public const SELF = '$self';

	/**
	 * The verbs a matrix row may grant.
	 *
	 * The canonical five plus `handle`, which is not canonical and is resolved
	 * by the existing custom-verb voting. A verb outside this set is refused at
	 * save rather than dropped: a row granting `handel` would compile to
	 * nothing and read, in the grid, as a right somebody has.
	 *
	 * @var string[]
	 */
	public const ACTIONS = ['read', 'create', 'update', 'delete', 'share', 'handle'];

	/**
	 * The verb `handle` falls back to when no voter claims it.
	 *
	 * @var string
	 */
	public const HANDLE_FALLBACK = 'update';

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
		$matrix = ($authorization[self::KEY] ?? null);
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
	 * Compile a matrix into authorization rules, by action.
	 *
	 * @param array<string, mixed> $matrix The declared matrix.
	 * @param string[] $ownValues The caller's own field values, for `$self`.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rules per action.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function compile(array $matrix, array $ownValues): array {
		$field = trim((string)($matrix['field'] ?? ''));
		$rows = ($matrix['rows'] ?? null);
		if ($field === '' || is_array($rows) === false) {
			return [];
		}

		$byActionGroup = $this->gatherByActionGroup(rows: $rows, ownValues: $ownValues);

		$compiled = [];
		foreach ($byActionGroup as $action => $groups) {
			foreach ($groups as $group => $values) {
				sort($values);
				$compiled[$action][] = [
					'group' => $group,
					'match' => [$field => ['$in' => $values]],
				];
			}
		}

		return $compiled;
	}//end compile()

	/**
	 * Gather the declared values per action and per group.
	 *
	 * Values are gathered PER (action, group) and only then turned into one
	 * rule, which is what D-1's "rows sharing a group merge into one scope"
	 * asks for. Emitting a rule per row would work and would put four
	 * predicates in an OR where one `$in` belongs.
	 *
	 * @param array<int|string, mixed> $rows      The declared rows.
	 * @param string[]                 $ownValues The caller's own field values, for `$self`.
	 *
	 * @return array<string, array<string, array<int, string>>> Values by action, then group.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	private function gatherByActionGroup(array $rows, array $ownValues): array {
		$byActionGroup = [];

		foreach ($rows as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$group = trim((string)($row['group'] ?? ''));
			if ($group === '') {
				continue;
			}

			$values = $this->valuesOf(row: $row, ownValues: $ownValues);
			if (empty($values) === true) {
				// 🔴 DROPPED WHOLE. See the class docblock: an empty `$in` is
				// dropped by the SQL builder and the rule becomes an
				// unconditional grant to the group.
				continue;
			}

			foreach ($this->actionsOf(row: $row) as $action) {
				$existing = ($byActionGroup[$action][$group] ?? []);
				$byActionGroup[$action][$group] = array_values(
					array_unique(array_merge($existing, $values))
				);
			}
		}//end foreach

		return $byActionGroup;
	}//end gatherByActionGroup()

	/**
	 * Merge compiled rules into an authorization block.
	 *
	 * The compiled rules are ADDED beside whatever the block already says, and
	 * never replace it. A matrix is one more way to be admitted, so it widens
	 * within the schema's own ceiling exactly as a second conditional scope
	 * would; a compiler that overwrote the block would silently retire every
	 * rule an administrator wrote by hand.
	 *
	 * @param array<string, mixed>|null $authorization The block.
	 * @param array<string, array<int, array<string, mixed>>> $compiled The compiled rules.
	 *
	 * @return array<string, mixed>|null The block with the matrix's rules in it.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function merge(?array $authorization, array $compiled): ?array {
		if (empty($compiled) === true) {
			return $authorization;
		}

		$merged = ($authorization ?? []);

		// The declaration itself is removed from the effective block. It is an
		// INPUT to the compiler, and leaving it beside the rules would hand
		// every reader of the block a key it has to know to ignore; the deny
		// resolver walks this structure and a stray key is exactly the kind of
		// thing that fails closed for the wrong reason.
		unset($merged[self::KEY]);

		foreach ($compiled as $action => $rules) {
			$existing = ($merged[$action] ?? []);
			if (is_array($existing) === false) {
				$existing = [];
			}

			$merged[$action] = array_merge(array_values($existing), $rules);
		}

		return $merged;
	}//end merge()

	/**
	 * The caller's own values, from a group-prefix user source.
	 *
	 * @param array<string, mixed>|null $source The declared user source.
	 * @param string[] $userGroups The caller's Nextcloud groups.
	 *
	 * @return string[] The caller's own values, prefix stripped.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function valuesFromGroups(?array $source, array $userGroups): array {
		$prefix = trim((string)($source['groupPrefix'] ?? ''));
		if ($prefix === '') {
			return [];
		}

		$values = [];
		foreach ($userGroups as $group) {
			$group = (string)$group;
			if (str_starts_with($group, $prefix) === true) {
				$value = substr($group, strlen($prefix));
				if ($value !== '') {
					$values[] = $value;
				}
			}
		}

		return array_values(array_unique($values));
	}//end valuesFromGroups()

	/**
	 * The verb a matrix action enforces as.
	 *
	 * `handle` is not canonical (D-3). Without a voter it behaves as `update`,
	 * which is the conservative reading: handling an object is at least
	 * changing it, and resolving it to `read` would grant a right the row's
	 * author plainly did not mean.
	 *
	 * @param string $action The declared action.
	 * @param string[] $claimedVerbs The custom verbs a voter has claimed.
	 *
	 * @return string The verb the engine enforces.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function resolveAction(string $action, array $claimedVerbs = []): string {
		if ($action !== 'handle') {
			return $action;
		}

		if (in_array('handle', $claimedVerbs, true) === true) {
			return 'handle';
		}

		return self::HANDLE_FALLBACK;
	}//end resolveAction()

	/**
	 * The values one row applies to.
	 *
	 * @param array<string, mixed> $row The row.
	 * @param string[] $ownValues The caller's own values.
	 *
	 * @return string[] The values.
	 */
	private function valuesOf(array $row, array $ownValues): array {
		$declared = ($row['value'] ?? ($row['values'] ?? null));
		if (is_string($declared) === true) {
			$declared = [$declared];
		}

		if (is_array($declared) === false) {
			return [];
		}

		$values = [];
		foreach ($declared as $value) {
			$value = trim((string)$value);
			if ($value === '') {
				continue;
			}

			if ($value === self::SELF) {
				$values = array_merge($values, $ownValues);
				continue;
			}

			$values[] = $value;
		}

		return array_values(array_unique($values));
	}//end valuesOf()

	/**
	 * The actions one row grants, resolved.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string[] The actions.
	 */
	private function actionsOf(array $row): array {
		$declared = ($row['actions'] ?? null);
		if (is_array($declared) === false) {
			return [];
		}

		$actions = [];
		foreach ($declared as $action) {
			$action = trim((string)$action);
			if (in_array($action, self::ACTIONS, true) === false) {
				continue;
			}

			$actions[] = $this->resolveAction(action: $action);
		}

		return array_values(array_unique($actions));
	}//end actionsOf()

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
			if (in_array(trim((string)$action), self::ACTIONS, true) === false) {
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
