<?php

/**
 * Authorization rule evaluator — answers whether one rule list grants one group a verb.
 *
 * The object plane (Service\Object\PermissionHandler) and the entity plane
 * (Db\Tenancy\EntityRbacTrait) used to each carry their own reading of a rule
 * list. Both now ask this class, so the anonymous fail-closed write rule
 * (#1955) and the meaning of an explicit `public` grant cannot be honoured on
 * one plane and forgotten on the other.
 *
 * It holds no collaborators: a rule list and a group in, a verdict out. The
 * only state is the per-request memory of which refusals were already
 * recorded, so a list endpoint refusing many rows logs one line, not one per
 * row.
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
 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The one reader of "does this rule list grant this group this verb".
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
class AuthorizationRuleEvaluator {

	/**
	 * The group an anonymous caller (no Nextcloud session) acts as.
	 */
	public const PUBLIC_GROUP = 'public';

	/**
	 * Verbs that are fail-closed for an anonymous caller.
	 *
	 * An anonymous caller is refused these unless the rule list EXPLICITLY
	 * grants the `public` group the verb (#1955). Reads are not listed: a
	 * missing read rule is a separate policy question, decided by each plane.
	 *
	 * @var list<string>
	 */
	public const ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS = [
		'create',
		'update',
		'delete',
		'destroy',
		'addFiles',
		'deleteFiles',
	];

	/**
	 * The (plane, entity type, action, cause) refusals already recorded in this request.
	 *
	 * @var array<string, true>
	 */
	private static array $recordedRefusals = [];

	/**
	 * Whether one entry of an action's rule list names the group.
	 *
	 * An entry is a bare group id (`"public"`) or an object naming a `group`
	 * (with or without a `match` clause; a condition is evaluated by the plane
	 * that understands it, not here).
	 *
	 * @param mixed  $entry One entry from an action's rule list.
	 * @param string $group The group.
	 *
	 * @return bool True when the entry names the group.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
	 */
	public function entryNamesGroup(mixed $entry, string $group): bool {
		if (is_array($entry) === true) {
			return (($entry['group'] ?? null) === $group);
		}

		return ($entry === $group);
	}//end entryNamesGroup()

	/**
	 * Whether a rule list EXPLICITLY names the group.
	 *
	 * Absence is never a grant here: a missing list, a non-list and an empty
	 * list all answer false. That is what separates this from the planes'
	 * own default-open readings of a missing rule.
	 *
	 * @param mixed  $rules The rule list of one action, or null.
	 * @param string $group The group.
	 *
	 * @return bool True only when an entry names the group.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
	 */
	public function rulesNameGroup(mixed $rules, string $group): bool {
		if (is_array($rules) === false) {
			return false;
		}

		foreach ($rules as $entry) {
			if ($this->entryNamesGroup(entry: $entry, group: $group) === true) {
				return true;
			}
		}

		return false;
	}//end rulesNameGroup()

	/**
	 * Whether an authorization block (action => rule list) explicitly grants the group an action.
	 *
	 * @param array|null $authorization The authorization block, keyed by action.
	 * @param string     $action        The verb.
	 * @param string     $group         The group.
	 *
	 * @return bool True only when the action's list names the group.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
	 */
	public function explicitlyGrants(?array $authorization, string $action, string $group): bool {
		return $this->rulesNameGroup(rules: ($authorization[$action] ?? null), group: $group);
	}//end explicitlyGrants()

	/**
	 * Whether the anonymous fail-closed write rule refuses this verb (#1955).
	 *
	 * True for a write verb the block does not explicitly grant the `public`
	 * group; false for a read, and for a write the block does grant `public`
	 * (a declared public-submission schema).
	 *
	 * @param array|null $authorization The authorization block, keyed by action.
	 * @param string     $action        The verb.
	 *
	 * @return bool True when an anonymous caller must be refused outright.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
	 */
	public function refusesAnonymousWrite(?array $authorization, string $action): bool {
		return in_array(needle: $action, haystack: self::ANONYMOUS_FAIL_CLOSED_WRITE_ACTIONS, strict: true) === true
			&& $this->explicitlyGrants(authorization: $authorization, action: $action, group: self::PUBLIC_GROUP) === false;
	}//end refusesAnonymousWrite()

	/**
	 * Whether a rule list admits an anonymous caller: it must name `public` explicitly.
	 *
	 * Used where absence is refusal (the entity plane): no list, an empty list
	 * and a list without `public` all refuse. The `*` wildcard means "any of
	 * the caller's groups" and an anonymous caller has none, so it does not
	 * admit one.
	 *
	 * @param mixed $rules The rule list of one action, or null.
	 *
	 * @return bool True when `public` is named.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-an-entity-may-declare-anonymous-access-and-it-must-be-honoured
	 */
	public function admitsAnonymous(mixed $rules): bool {
		return $this->rulesNameGroup(rules: $rules, group: self::PUBLIC_GROUP);
	}//end admitsAnonymous()

	/**
	 * Whether any of the caller's groups, or the `*` wildcard, is in a plain list of allowed group ids.
	 *
	 * The entity plane's list shape: group ids only, compared loosely as that
	 * plane always has.
	 *
	 * @param array $userGroups    The caller's group ids.
	 * @param array $allowedGroups The allowed group ids.
	 *
	 * @return bool True when a group of the caller, or `*`, is allowed.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-one-evaluator-decides-anonymous-access-on-both-planes
	 */
	public function anyGroupAllowed(array $userGroups, array $allowedGroups): bool {
		foreach ($userGroups as $groupId) {
			// Loose on purpose: the entity plane has always matched numeric group ids this way.
			if (in_array($groupId, $allowedGroups) === true) {
				return true;
			}
		}

		return in_array('*', $allowedGroups) === true;
	}//end anyGroupAllowed()

	/**
	 * Whether this refusal is the first of its kind in this request, remembering it.
	 *
	 * Keyed by plane, entity type, action and cause, so a list endpoint that
	 * refuses a thousand rows for one caller records one line, not a thousand.
	 *
	 * @param string $plane      The plane that refused (`object` or `entity`).
	 * @param string $entityType The entity type.
	 * @param string $action     The verb.
	 * @param string $cause      Why: `no-rule` (no authorization for the verb) or `rule-refused`.
	 *
	 * @return bool True the first time, false after.
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-a-refusal-and-a-grant-must-both-be-observable
	 */
	public function firstRefusal(string $plane, string $entityType, string $action, string $cause): bool {
		$key = $plane . "\0" . $entityType . "\0" . $action . "\0" . $cause;
		if (isset(self::$recordedRefusals[$key]) === true) {
			return false;
		}

		self::$recordedRefusals[$key] = true;
		return true;
	}//end firstRefusal()

	/**
	 * Forget the refusals recorded so far (a new request, or a test).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consolidate-permission-handling/specs/consolidate-permission-handling/spec.md#requirement-a-refusal-and-a-grant-must-both-be-observable
	 */
	public function forgetRecordedRefusals(): void {
		self::$recordedRefusals = [];
	}//end forgetRecordedRefusals()
}//end class
