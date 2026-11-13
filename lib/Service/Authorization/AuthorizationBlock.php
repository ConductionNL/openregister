<?php

/**
 * OpenRegister AuthorizationBlock
 *
 * Reads the owner sentinel and condition scopes an authorization block may
 * carry into OpenRegister's native rule shape (access-owner-and-condition-scopes).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Authorization
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Authorization;

/**
 * Normalises an authorization block on read; the stored block is never rewritten.
 *
 * Two shapes a schema designer writes are not native rules:
 *
 *  - `@creator` in an `authorization.<action>` list means "the object's owner".
 *    It admits no group, so it is dropped from the list. The owner is admitted
 *    by the owner rule that applies to every object, and an action whose list
 *    is left empty admits nobody else: an empty list on a non-empty block fails
 *    closed in both the list emitter and the single-object check (design D-2).
 *  - `conditions.<action>` with `{field, operator: "equals", value}` admits any
 *    signed-in user for rows whose `field` equals `value`. It becomes the
 *    native conditional rule `{group: "authenticated", match: {field: value}}`
 *    on that action, with `@user.uid` read as the caller (`$userId`).
 *
 * A condition this class cannot read (another operator, no field, a value that
 * is not a scalar or starts with `$`) is left out, so it admits nobody rather than everybody. A
 * condition on a field the schema does not declare is kept: the match emitter
 * already refuses an undeclared field.
 *
 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md
 */
final class AuthorizationBlock {

	/**
	 * The owner sentinel in an action's list.
	 *
	 * @var string
	 */
	public const CREATOR = '@creator';

	/**
	 * The key holding the condition scopes, per action.
	 *
	 * @var string
	 */
	public const CONDITIONS_KEY = 'conditions';

	/**
	 * The condition value that means the caller's user id.
	 *
	 * @var string
	 */
	public const CALLER_UID = '@user.uid';

	/**
	 * The one operator a condition may use.
	 *
	 * @var string
	 */
	public const EQUALS = 'equals';

	/**
	 * The native variable a match resolves to the caller's user id.
	 *
	 * @var string
	 */
	private const USER_ID_VARIABLE = '$userId';

	/**
	 * The block in the native shape: no `@creator` entries, no `conditions` key,
	 * and each readable condition appended to its action's list.
	 *
	 * @param array<string, mixed>|null $authorization The block as stored.
	 *
	 * @return array<string, mixed>|null The normalised block; null or an empty block unchanged.
	 *
	 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md#requirement-the-owner-sentinel-limits-an-action-to-the-records-owner
	 * @spec openspec/changes/access-owner-and-condition-scopes/specs/authorization-rbac/spec.md#requirement-a-condition-scope-admits-rows-whose-field-matches
	 */
	public function normalise(?array $authorization): ?array {
		if ($authorization === null || $authorization === []) {
			return $authorization;
		}

		$conditions = ($authorization[self::CONDITIONS_KEY] ?? null);
		unset($authorization[self::CONDITIONS_KEY]);

		foreach ($authorization as $action => $rules) {
			if (is_array($rules) === true && array_is_list($rules) === true) {
				$authorization[$action] = $this->withoutCreator(rules: $rules);
			}
		}

		if (is_array($conditions) === false) {
			return $authorization;
		}

		foreach ($conditions as $action => $declared) {
			$authorization = $this->withConditions(authorization: $authorization, action: (string)$action, declared: $declared);
		}

		return $authorization;
	}//end normalise()

	/**
	 * An action's rules without the owner sentinel, reindexed.
	 *
	 * @param list<mixed> $rules The rules.
	 *
	 * @return list<mixed> The rules.
	 */
	private function withoutCreator(array $rules): array {
		return array_values(array_filter($rules, static fn (mixed $rule): bool => $rule !== self::CREATOR));
	}//end withoutCreator()

	/**
	 * The block with an action's readable conditions appended as conditional rules.
	 *
	 * @param array<string, mixed> $authorization The block so far.
	 * @param string               $action        The action.
	 * @param mixed                $declared      One condition, or a list of them.
	 *
	 * @return array<string, mixed> The block.
	 */
	private function withConditions(array $authorization, string $action, mixed $declared): array {
		if (is_array($declared) === false || $action === '') {
			return $authorization;
		}

		$list = [$declared];
		if (array_is_list($declared) === true) {
			$list = $declared;
		}

		foreach ($list as $condition) {
			$rule = $this->conditionalRule(condition: $condition);
			if ($rule === null) {
				continue;
			}

			$rules = ($authorization[$action] ?? []);
			if (is_array($rules) === false || array_is_list($rules) === false) {
				$rules = [];
			}

			$rules[] = $rule;
			$authorization[$action] = $rules;
		}

		return $authorization;
	}//end withConditions()

	/**
	 * The native rule for one condition, or null when it cannot be read.
	 *
	 * @param mixed $condition The condition.
	 *
	 * @return array{group: string, match: array<string, mixed>}|null The rule.
	 */
	private function conditionalRule(mixed $condition): ?array {
		if (is_array($condition) === false) {
			return null;
		}

		$field = $condition['field'] ?? null;
		$value = $condition['value'] ?? null;
		if (is_string($field) === false || trim($field) === '' || ($condition['operator'] ?? null) !== self::EQUALS) {
			return null;
		}

		// A literal that starts with `$` would be read as a native variable
		// (`$userId`, `$organisation`, `$now`) and widen the rule; only the
		// documented `@user.uid` names the caller.
		if (is_scalar($value) === false || (is_string($value) === true && str_starts_with($value, '$') === true)) {
			return null;
		}

		if ($value === self::CALLER_UID) {
			$value = self::USER_ID_VARIABLE;
		}

		return ['group' => 'authenticated', 'match' => [trim($field) => $value]];
	}//end conditionalRule()
}//end class
