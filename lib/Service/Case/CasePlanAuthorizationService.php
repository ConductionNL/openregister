<?php

/**
 * Decides, fail-closed, who may enable, attach, transition or administer.
 *
 * The reference returned the authorization LIST for the REST layer to
 * compare (`procest/lib/Service/Cmmn/CaseModelEngine.php:244-268`). This
 * service inverts that: it DECIDES before any write, and every indeterminate
 * answer (no acting identity, no group backend, a role that resolves to no
 * group, a malformed rule) is a denial. There is no nullable "could not
 * determine" return a caller can read as "check skipped"
 * (`hydra-gate-unsafe-auth-resolver`).
 *
 * Rules are a list of strings: a group id, `user:<uid>` or `role:<name>`. A
 * role resolves to the group of the same name and MUST exist, or the
 * decision is indeterminate and denies naming the role. An item without
 * rules of its own derives them from its nearest ancestor that has any,
 * then from the plan root (`settings.authorization`). A plan that declares
 * none anywhere is administrable by administrators only: fail-closed, and
 * an ad-hoc item cannot declare itself unguarded because it may not declare
 * rules at all.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Exception\CaseAccessDeniedException;
use OCP\IGroupManager;
use Throwable;

/**
 * The case layer's authorization decisions.
 *
 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
 */
class CasePlanAuthorizationService {

	/**
	 * Constructor.
	 *
	 * @param IGroupManager|null $groupManager Resolves membership, roles and
	 *                                         administrators. Nullable so the
	 *                                         service stays constructible
	 *                                         bare; ABSENT, every
	 *                                         membership-dependent decision
	 *                                         DENIES.
	 */
	public function __construct(
		private readonly ?IGroupManager $groupManager = null,
	) {

	}//end __construct()

	/**
	 * Assert an acting identity exists at all. No verb is anonymous.
	 *
	 * @param string|null $uid The acting identity.
	 * @param string $verb The verb, for the message.
	 *
	 * @return string The trimmed identity.
	 *
	 * @throws CaseAccessDeniedException Without one.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function assertIdentified(?string $uid, string $verb): string {
		$identity = trim((string)$uid);
		if ($identity === '') {
			throw new CaseAccessDeniedException(message: sprintf("Verb '%s' denied: no acting identity.", $verb));
		}

		return $identity;
	}//end assertIdentified()

	/**
	 * Whether a uid is an administrator. Fail-closed: no backend, no admin.
	 *
	 * @param string|null $uid The acting identity.
	 *
	 * @return boolean True only when the group backend affirms it.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function isAdministrator(?string $uid): bool {
		if ($uid === null || trim($uid) === '' || $this->groupManager === null) {
			return false;
		}

		try {
			return $this->groupManager->isAdmin($uid);
		} catch (Throwable) {
			return false;
		}
	}//end isAdministrator()

	/**
	 * Assert a caller may act on an item (enable, transition, attach under it).
	 *
	 * @param string $verb The verb attempted.
	 * @param CaseItem|null $item The item acted on, or null for the plan root.
	 * @param CasePlanTree $tree The plan (for ancestors and root settings).
	 * @param string|null $uid The acting identity.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException When denied or indeterminate.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function assertMayAct(string $verb, ?CaseItem $item, CasePlanTree $tree, ?string $uid): void {
		$identity = $this->assertIdentified(uid: $uid, verb: $verb);
		if ($this->isAdministrator(uid: $identity) === true) {
			return;
		}

		$this->assertHolds(verb: $verb, rules: $this->effectiveRules(item: $item, tree: $tree), uid: $identity);
	}//end assertMayAct()

	/**
	 * Assert a caller may administer a plan: create it, delete it, complete
	 * the case. Judged against the ROOT rules alone.
	 *
	 * @param string $verb The verb attempted.
	 * @param array<string, mixed> $settings The plan settings (root rules under `authorization`).
	 * @param string|null $uid The acting identity.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException When denied or indeterminate.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function assertMayAdminister(string $verb, array $settings, ?string $uid): void {
		$identity = $this->assertIdentified(uid: $uid, verb: $verb);
		if ($this->isAdministrator(uid: $identity) === true) {
			return;
		}

		$this->assertHolds(verb: $verb, rules: ($settings['authorization'] ?? null), uid: $identity);
	}//end assertMayAdminister()

	/**
	 * The rules that govern an item: its own, else its nearest ancestor's,
	 * else the plan root's. Null when nowhere declares any.
	 *
	 * @param CaseItem|null $item The item, or null for the root.
	 * @param CasePlanTree $tree The plan.
	 *
	 * @return mixed The rule list, or null.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	public function effectiveRules(?CaseItem $item, CasePlanTree $tree): mixed {
		if ($item !== null) {
			$own = $item->getAuthorizationRules();
			if (is_array($own) === true && $own !== []) {
				return $own;
			}

			foreach ($tree->ancestors(item: $item) as $ancestor) {
				$rules = $ancestor->getAuthorizationRules();
				if (is_array($rules) === true && $rules !== []) {
					return $rules;
				}
			}
		}

		return ($tree->settings()['authorization'] ?? null);
	}//end effectiveRules()

	/**
	 * The caller must satisfy at least one rule; anything indeterminate denies.
	 *
	 * @param string $verb The verb, for the message.
	 * @param mixed $rules The rule list.
	 * @param string $uid The acting identity.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException When no rule admits the caller.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function assertHolds(string $verb, mixed $rules, string $uid): void {
		if (is_array($rules) === false || $rules === []) {
			throw new CaseAccessDeniedException(
				message: sprintf("Verb '%s' denied: no authorization is declared for this item or its plan, so only an administrator may perform it.", $verb)
			);
		}

		foreach ($rules as $rule) {
			if ($this->ruleAdmits(verb: $verb, rule: $rule, uid: $uid) === true) {
				return;
			}
		}

		throw new CaseAccessDeniedException(
			message: sprintf("Verb '%s' denied: the caller holds none of the item's authorizations.", $verb)
		);
	}//end assertHolds()

	/**
	 * Whether one rule admits the caller. A role that does not resolve is
	 * indeterminate and DENIES naming the role, never "no check applicable".
	 *
	 * @param string $verb The verb, for the message.
	 * @param mixed $rule One rule.
	 * @param string $uid The acting identity.
	 *
	 * @return boolean True only when the rule affirmatively admits.
	 *
	 * @throws CaseAccessDeniedException When a role cannot be resolved.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function ruleAdmits(string $verb, mixed $rule, string $uid): bool {
		if (is_string($rule) === false || trim($rule) === '') {
			return false;
		}

		$rule = trim($rule);
		if (str_starts_with($rule, 'user:') === true) {
			return substr($rule, 5) === $uid;
		}

		if (str_starts_with($rule, 'role:') === true) {
			$role = substr($rule, 5);
			$this->assertRoleResolvable(verb: $verb, role: $role);

			return $this->isInGroup(uid: $uid, groupId: $role);
		}

		return $this->isInGroup(uid: $uid, groupId: $rule);
	}//end ruleAdmits()

	/**
	 * Membership through the group backend, denying when it is absent.
	 *
	 * @param string $uid The acting identity.
	 * @param string $groupId The group to test.
	 *
	 * @return boolean True only when the backend affirms membership.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function isInGroup(string $uid, string $groupId): bool {
		if ($this->groupManager === null || $groupId === '') {
			return false;
		}

		try {
			return $this->groupManager->isInGroup($uid, $groupId);
		} catch (Throwable) {
			return false;
		}
	}//end isInGroup()

	/**
	 * A role must resolve to the group of the same name.
	 *
	 * @param string $verb The verb, for the message.
	 * @param string $role The role name.
	 *
	 * @return void
	 *
	 * @throws CaseAccessDeniedException When it does not, or cannot be checked.
	 *
	 * @spec openspec/changes/flow-cmmn-case-semantics/specs/flow-cases/spec.md#requirement-a-caseworker-may-attach-work-no-author-drew
	 */
	private function assertRoleResolvable(string $verb, string $role): void {
		if ($this->groupManager === null) {
			throw new CaseAccessDeniedException(
				message: sprintf("Verb '%s' denied: role '%s' cannot be resolved because no group backend is available.", $verb, $role)
			);
		}

		$exists = false;
		try {
			$exists = $this->groupManager->groupExists($role);
		} catch (Throwable) {
			$exists = false;
		}

		if ($exists === false) {
			throw new CaseAccessDeniedException(
				message: sprintf("Verb '%s' denied: role '%s' does not resolve to any group.", $verb, $role)
			);
		}
	}//end assertRoleResolvable()
}//end class
