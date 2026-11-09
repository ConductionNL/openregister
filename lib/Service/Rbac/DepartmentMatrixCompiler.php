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

use DateTimeImmutable;
use DateTimeInterface;

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
	 * nothing and read, in the grid, as a right somebody has. The three file
	 * verbs grant what may be done with the object's files.
	 *
	 * @var string[]
	 */
	public const ACTIONS = ['read', 'create', 'update', 'delete', 'share', 'handle', 'readFiles', 'addFiles', 'deleteFiles'];

	/**
	 * The verb `handle` falls back to when no voter claims it.
	 *
	 * @var string
	 */
	public const HANDLE_FALLBACK = 'update';

	/**
	 * Reads a row's `where`.
	 *
	 * @var MatrixRowWhere
	 */
	private readonly MatrixRowWhere $where;

	/**
	 * Constructor.
	 *
	 * @param MatrixRowWhere|null $where           Reads a row's `where`; a fresh one when null.
	 * @param array<int, string>  $declaredActions The actions the schema declares itself, which a row may grant too.
	 */
	public function __construct(?MatrixRowWhere $where = null, private readonly array $declaredActions = []) {
		$this->where = ($where ?? new MatrixRowWhere());
	}//end __construct()


	/**
	 * Compile a matrix into authorization rules, by action.
	 *
	 * A row's `where` is copied into the rule's `match` beside the field
	 * condition, so it is one more AND-ed key of an ordinary conditional scope
	 * and both evaluators read it as they read any other. A row past its
	 * `until` compiles to nothing.
	 *
	 * @param array<string, mixed>   $matrix    The declared matrix.
	 * @param string[]               $ownValues The caller's own field values, for `$self`.
	 * @param DateTimeInterface|null $now       The moment `until` is read against; now when null.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Rules per action.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function compile(array $matrix, array $ownValues, ?DateTimeInterface $now = null): array {
		$field = trim((string)($matrix['field'] ?? ''));
		$rows = ($matrix['rows'] ?? null);
		if ($field === '' || is_array($rows) === false) {
			return [];
		}

		$gathered = $this->gatherByActionGroup(
			rows: $rows,
			ownValues: $ownValues,
			field: $field,
			now: ($now ?? new DateTimeImmutable())
		);

		return $this->rulesFrom(gathered: $gathered, field: $field);
	}//end compile()

	/**
	 * One rule per gathered scope, its values sorted, keyed by action.
	 *
	 * @param array<string, array<string, array>> $gathered Action => key => scope.
	 * @param string                              $field    The record field the matrix matches on.
	 *
	 * @return array<string, array<int, array>> Action => rules.
	 */
	private function rulesFrom(array $gathered, string $field): array {
		$compiled = [];
		foreach ($gathered as $action => $scopes) {
			foreach ($scopes as $scope) {
				$values = $scope['values'];
				sort($values);
				$compiled[$action][] = array_merge(
					[
						'group' => $scope['group'],
						'match' => array_merge([$field => ['$in' => $values]], $scope['where']),
					],
					$scope['marker']
				);
			}
		}

		return $compiled;
	}//end rulesFrom()

	/**
	 * Gather the declared values per action, group and `where`.
	 *
	 * Values are gathered PER (action, group, where) and only then turned into
	 * one rule, which is what D-1's "rows sharing a group merge into one scope"
	 * asks for. Rows with a different `where` stay separate scopes: merging
	 * them would widen one row's condition to the other's values.
	 *
	 * @param array<int|string, mixed> $rows      The declared rows.
	 * @param string[]                 $ownValues The caller's own field values, for `$self`.
	 * @param string                   $field     The matrix field, which a `where` may not repeat.
	 * @param DateTimeInterface        $now       The moment `until` is read against.
	 *
	 * @return array<string, array<string, array{group: string, where: array<string, mixed>, marker: array<string, mixed>, values: array<int, string>}>>
	 *         Scopes by action, keyed by group and `where`.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	private function gatherByActionGroup(array $rows, array $ownValues, string $field, DateTimeInterface $now): array {
		$gathered = [];

		foreach ($rows as $row) {
			$scope = $this->scopeOf(row: $row, ownValues: $ownValues, field: $field, now: $now);
			if ($scope === null) {
				continue;
			}

			$this->gather(gathered: $gathered, scope: $scope, actions: $this->actionsOf(row: $row));
		}

		return $gathered;
	}//end gatherByActionGroup()

	/**
	 * The scope one row compiles to, or null when the row is dropped.
	 *
	 * @param mixed              $row       One matrix row.
	 * @param array<int, string> $ownValues The caller's own values.
	 * @param string             $field     The record field the matrix matches on.
	 * @param DateTimeInterface  $now       The moment `until` is read against.
	 *
	 * @return array{group: string, where: array, marker: array, values: array}|null The scope.
	 */
	private function scopeOf(mixed $row, array $ownValues, string $field, DateTimeInterface $now): array|null {
		if (is_array($row) === false) {
			return null;
		}

		$group = trim((string)($row['group'] ?? ''));
		if ($group === '' || $this->hasLapsed(row: $row, now: $now) === true) {
			return null;
		}

		return $this->conditionedScope(row: $row, group: $group, ownValues: $ownValues, field: $field);
	}//end scopeOf()

	/**
	 * Whether a row carries an `until` that has passed.
	 *
	 * @param array             $row The row.
	 * @param DateTimeInterface $now The moment it is read against.
	 *
	 * @return bool True when the row no longer answers.
	 */
	private function hasLapsed(array $row, DateTimeInterface $now): bool {
		return array_key_exists(GrantConstraints::UNTIL_KEY, $row) === true
			&& (new GrantConstraints())->reaches(entry: [GrantConstraints::UNTIL_KEY => $row[GrantConstraints::UNTIL_KEY]], area: [], now: $now) === false;
	}//end hasLapsed()

	/**
	 * A live row's scope: its `where`, values and marker, or null when either is unusable.
	 *
	 * @param array              $row       The row.
	 * @param string             $group     Its group.
	 * @param array<int, string> $ownValues The caller's own values.
	 * @param string             $field     The record field the matrix matches on.
	 *
	 * @return array{group: string, where: array, marker: array, values: array}|null The scope.
	 */
	private function conditionedScope(array $row, string $group, array $ownValues, string $field): array|null {
		$where = $this->where->conditionsOf(row: $row, field: $field);
		if ($where === null) {
			return null;
		}

		$values = $this->valuesOf(row: $row, ownValues: $ownValues);
		if (empty($values) === true) {
			// 🔴 DROPPED WHOLE. See the class docblock: an empty `$in` is
			// dropped by the SQL builder and the rule becomes an
			// unconditional grant to the group.
			return null;
		}

		return [
			'group' => $group,
			'where' => $where,
			'marker' => (new ReadRuleTenancy())->markerOf(rule: $row),
			'values' => $values,
		];
	}//end conditionedScope()

	/**
	 * Add one scope under each of its actions, merging values with an equal group, where and marker.
	 *
	 * @param array<string, array<string, array>> $gathered Action => key => scope, modified in place.
	 * @param array                               $scope    The scope.
	 * @param array<int, string>                  $actions  The row's actions.
	 *
	 * @return void
	 */
	private function gather(array &$gathered, array $scope, array $actions): void {
		$key = $scope['group'] . "\0" . json_encode($scope['where']) . "\0" . json_encode($scope['marker']);
		foreach ($actions as $action) {
			$existing = ($gathered[$action][$key]['values'] ?? []);
			$gathered[$action][$key] = [
				'group' => $scope['group'],
				'where' => $scope['where'],
				'marker' => $scope['marker'],
				'values' => array_values(array_unique(array_merge($existing, $scope['values']))),
			];
		}
	}//end gather()



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
			$merged[$action] = array_merge($this->existingRules(block: $merged, action: $action), $rules);
		}

		return $merged;
	}//end merge()

	/**
	 * The rules a block already holds for an action, as a list; none when it holds no list.
	 *
	 * @param array      $block  The block.
	 * @param int|string $action The action.
	 *
	 * @return array<int, mixed> The rules.
	 */
	private function existingRules(array $block, int|string $action): array {
		$existing = ($block[$action] ?? []);
		if (is_array($existing) === false) {
			return [];
		}

		return array_values($existing);
	}//end existingRules()

	/**
	 * The caller's own values, from a group-prefix user source.
	 *
	 * `groups: true` adds every group id the caller holds, unprefixed, so
	 * `$self` on a group-id field means "a group I am in". Both may be
	 * declared; the union is used.
	 *
	 * @param array<string, mixed>|null $source The declared user source.
	 * @param string[] $userGroups The caller's Nextcloud groups.
	 *
	 * @return string[] The caller's own values, prefix stripped.
	 *
	 * @spec openspec/changes/rbac-department-role-matrix/specs/rbac-scopes/spec.md
	 */
	public function valuesFromGroups(?array $source, array $userGroups): array {
		$values = [];
		if (($source['groups'] ?? false) === true) {
			$values = array_map('strval', $userGroups);
		}

		$prefix = trim((string)($source['groupPrefix'] ?? ''));
		if ($prefix === '') {
			return array_values(array_unique($values));
		}

		$values = array_merge($values, $this->valuesAfterPrefix(userGroups: $userGroups, prefix: $prefix));

		return array_values(array_unique($values));
	}//end valuesFromGroups()

	/**
	 * What follows the prefix in each of the caller's groups that carries it.
	 *
	 * @param array<int, mixed> $userGroups The caller's groups.
	 * @param string            $prefix     The non-empty prefix.
	 *
	 * @return array<int, string> The values, in group order.
	 */
	private function valuesAfterPrefix(array $userGroups, string $prefix): array {
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

		return $values;
	}//end valuesAfterPrefix()

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
		$values = [];
		foreach ($this->declaredValues(row: $row) as $value) {
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
	 * The values a row declares under `value` (preferred) or `values`, as a list.
	 *
	 * @param array $row The row.
	 *
	 * @return array<int, mixed> The declared values, empty when none are readable.
	 */
	private function declaredValues(array $row): array {
		$declared = ($row['value'] ?? ($row['values'] ?? null));
		if (is_string($declared) === true) {
			return [$declared];
		}

		if (is_array($declared) === false) {
			return [];
		}

		return $declared;
	}//end declaredValues()

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
			if (in_array($action, self::ACTIONS, true) === false && in_array($action, $this->declaredActions, true) === false) {
				continue;
			}

			$actions[] = $this->resolveAction(action: $action);
		}

		return array_values(array_unique($actions));
	}//end actionsOf()

}//end class
