<?php

/**
 * GroupRuleEntries - reads one entry of an action's authorization rule list
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 * @spec openspec/specs/rbac-zaaktype/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\ConditionMatcher;

/**
 * Whether one rule entry grants the request: a user-level override, or a group entry.
 *
 * Conditional `match` evaluation stays with the shared ConditionMatcher (ADR-011).
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final class GroupRuleEntries {

	/**
	 * Create the reader.
	 *
	 * @param ConditionMatcher $conditionMatcher The shared match evaluator.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function __construct(
		private readonly ConditionMatcher $conditionMatcher,
	) {
	}//end __construct()

	/**
	 * Whether the entry grants the request.
	 *
	 * A user-level override is purely additive: when it does not match, the group
	 * reading of the same entry still decides.
	 *
	 * @param mixed            $entry   One entry of the action's rule list.
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True when the entry grants.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function grants(mixed $entry, GroupRuleRequest $request): bool {
		if ($this->userOverrideMatches(entry: $entry, request: $request) === true) {
			return true;
		}

		if (is_string($entry) === true) {
			return ($entry === $request->groupId);
		}

		return $this->groupEntryMatches(entry: $entry, request: $request);
	}//end grants()

	/**
	 * Whether the entry is a user-level override that grants the caller the action.
	 *
	 * User-level overrides implement zaaktype-scoped DELEGATION (rbac-zaaktype):
	 * a permission granted to an individual user independent of group membership,
	 * used for external advisors, temporary replacements, and escalation paths.
	 * They are expressed inside the same schema `authorization[$action]` list as
	 * either:
	 *   - a bare string `"user:<uid>"`; or
	 *   - a complex entry `{ "user": "<uid>", "match": { ... } }` whose optional
	 *     `match` clause is evaluated by the shared {@see ConditionMatcher} (so an
	 *     expiry can be encoded as e.g. `{ "_expires": { "$gt": "$now" } }` on the
	 *     object, or any other object-data predicate).
	 *
	 * SECURITY: fail closed and never widen group access.
	 *   - Only an EXACT uid match grants. A missing/blank uid, a non-matching uid,
	 *     or a malformed entry returns false.
	 *   - Anonymous principals can never match a user override.
	 *
	 * @param mixed            $entry   One entry of the action's rule list.
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True only for an override of the caller whose clause, if any, is satisfied.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function userOverrideMatches(mixed $entry, GroupRuleRequest $request): bool {
		if ($request->userId === null || $request->userId === '') {
			return false;
		}

		if ($this->overrideTarget(entry: $entry) !== $request->userId) {
			return false;
		}

		return $this->overrideClausePasses(clause: ($entry['match'] ?? null), request: $request);
	}//end userOverrideMatches()

	/**
	 * The uid an override entry names, or null when the entry is no override.
	 *
	 * @param mixed $entry One entry of the action's rule list.
	 *
	 * @return string|null The uid.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function overrideTarget(mixed $entry): ?string {
		if (is_string($entry) === true && str_starts_with($entry, 'user:') === true) {
			return substr($entry, strlen('user:'));
		}

		$uid = ($entry['user'] ?? null);
		if (is_string($uid) === true) {
			return $uid;
		}

		return null;
	}//end overrideTarget()

	/**
	 * Whether an override's clause passes; an override without a clause does, a malformed clause does not.
	 *
	 * @param mixed            $clause  The entry's `match` value.
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True when the clause passes.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function overrideClausePasses(mixed $clause, GroupRuleRequest $request): bool {
		if (in_array($clause, [null, []], true) === true) {
			return true;
		}

		if (is_array($clause) === false) {
			return false;
		}

		$conditions = $this->clauseForAction(clause: $clause, action: $request->action);
		if ($conditions === []) {
			return true;
		}

		return $this->conditionMatcher->objectMatchesConditions(object: $request->matchEnvelope(), match: $conditions);
	}//end overrideClausePasses()

	/**
	 * The conditions that apply to the action: on create there is no existing object to match organisation against.
	 *
	 * @param array  $clause The entry's `match` clause.
	 * @param string $action The action asked for.
	 *
	 * @return array The conditions to evaluate.
	 *
	 * @spec openspec/specs/rbac-zaaktype/spec.md
	 */
	private function clauseForAction(array $clause, string $action): array {
		if ($action === 'create') {
			return $this->conditionMatcher->filterOrganisationMatchForCreate(match: $clause);
		}

		return $clause;
	}//end clauseForAction()

	/**
	 * Whether a complex entry names the group and any `match` conditions all pass.
	 *
	 * @param mixed            $entry   One entry of the action's rule list.
	 * @param GroupRuleRequest $request The question.
	 *
	 * @return bool True when the entry grants the group.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function groupEntryMatches(mixed $entry, GroupRuleRequest $request): bool {
		if (is_array($entry) === false || ($entry['group'] ?? null) !== $request->groupId) {
			return false;
		}

		if (empty($entry['match']) === true) {
			return true;
		}

		return $this->conditionMatcher->objectMatchesConditions(object: $request->matchEnvelope(), match: $entry['match']);
	}//end groupEntryMatches()
}//end class
