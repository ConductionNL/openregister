<?php

/**
 * EntityRbacTrait: whether the caller may create, read, update or delete an entity type.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Trait
 * @package  OCA\OpenRegister\Db\Tenancy
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Db\Tenancy;

use Exception;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Service\AnonymousEvaluationContext;
use OCA\OpenRegister\Service\SystemOperationContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * The entity RBAC half of MultiTenancyTrait.
 *
 * Checks RBAC permissions from the active organisation's authorization
 * configuration, shaped entityType -> action -> allowed groups:
 * {
 *   "authorization": {
 *     "schema": {
 *       "create": ["group-name-1", "group-name-2"],
 *       "read": ["group-name-1"],
 *       "update": ["group-name-1"],
 *       "delete": []
 *     }
 *   }
 * }
 *
 * Composed only by MultiTenancyTrait; a mapper uses that trait, never this one.
 * Deliberately without strict_types, like the trait it was split from, so the
 * moved code coerces exactly as it always did.
 *
 * @package OCA\OpenRegister\Db\Tenancy
 */
trait EntityRbacTrait {

	/**
	 * Check if the current user has permission to perform an action.
	 *
	 * Admins always have all permissions. Without a session user the caller is
	 * a trusted system operation or is denied (isTrustedSystemCaller()).
	 *
	 * @param string $action     The action to check (create, read, update, delete)
	 * @param string $entityType The type of entity (e.g., 'schema', 'register', 'configuration')
	 *
	 * @return bool True if user has permission, false otherwise
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	protected function hasRbacPermission(string $action, string $entityType): bool {
		if ($this->isCurrentUserAdmin() === true) {
			return true;
		}

		if ($this->getCurrentUserId() === null) {
			return $this->isTrustedSystemCaller();
		}

		return $this->organisationPolicyAllows(action: $action, entityType: $entityType);
	}//end hasRbacPermission()

	/**
	 * Whether a caller without a session user is a trusted system operation.
	 *
	 * CLI context (occ commands, repair steps, cron jobs, system listeners) is
	 * trusted — unless the call asked to be judged as an anonymous caller
	 * (WOO-578). Explicitly-scoped system operations (app config imports at
	 * boot, webcron jobs run via ObjectService::runAsSystem()) are trusted the
	 * same way: app boot runs BEFORE the session user is resolved and webcron
	 * never has one. Anything else without a user is denied.
	 *
	 * @return bool
	 */
	private function isTrustedSystemCaller(): bool {
		if (PHP_SAPI === 'cli' && AnonymousEvaluationContext::isActive() === false) {
			return true;
		}

		// NOSEMGREP: php.lang.security.exec-use.exec-use -- SystemOperationContext is a scope flag, no process is started.
		return SystemOperationContext::isActive() === true;
	}//end isTrustedSystemCaller()

	/**
	 * Whether the active organisation's authorization config lets the session user act.
	 *
	 * OPT-IN, AND DEFAULTED OFF ON EVIDENCE: enforcing the stored
	 * `authorization` config broke register creation and object sharing in CI's
	 * e2e, because those configs grant only the `admin` group while the app
	 * legitimately acts as other identities. Turning it on is a data migration
	 * (#2833), so the flag is read through OrganisationMapper, NOT
	 * `$this->appConfig`, which not every host declares.
	 *
	 * ABSENCE OF AN ORGANISATION IS ABSENCE OF A POLICY, NOT A DENIAL: with no
	 * active organisation, or one that cannot be loaded, there is no config to
	 * apply and the behaviour every caller has ever seen is allow. There is no
	 * membership gate either: sharing exists to give access to someone the
	 * normal scope excludes. The real authorization is the group config.
	 *
	 * @param string $action     The action to check
	 * @param string $entityType The type of entity
	 *
	 * @return bool
	 */
	private function organisationPolicyAllows(string $action, string $entityType): bool {
		if ($this->organisationMapper->isEntityRbacEnforcementEnabled() === false) {
			return true;
		}

		$activeOrg = $this->policyOrganisation();

		// No group manager: allow access (backward compatibility).
		if ($activeOrg === null || isset($this->groupManager) === false) {
			return true;
		}

		$rule = ($activeOrg->getAuthorization()[$entityType][$action] ?? null);
		return $this->sessionGroupsMayAct(allowedGroups: $this->allowedGroups(rule: $rule));
	}//end organisationPolicyAllows()

	/**
	 * The active organisation whose authorization config applies, or null when there is none.
	 *
	 * @return Organisation|null
	 */
	private function policyOrganisation(): ?Organisation {
		$activeOrgUuid = $this->getActiveOrganisationUuid();
		if ($activeOrgUuid === null) {
			return null;
		}

		try {
			return $this->organisationMapper->findByUuid($activeOrgUuid);
		} catch (\Throwable $e) {
			// An organisation that cannot be loaded supplies no policy to apply;
			// denying would take the whole instance down on one bad row.
			return null;
		}
	}//end policyOrganisation()

	/**
	 * Whether the session user's groups satisfy the organisation's rule for this action.
	 *
	 * The `$user === null` branch is unreachable today (hasRbacPermission()
	 * already returned for a session without a user); it is kept so the two
	 * blocks cannot drift if that early return is ever relaxed. It still runs
	 * before the unrestricted (null) rule is honoured.
	 *
	 * @param mixed $allowedGroups The groups the organisation's config allows
	 *                             for this action, or null when it does not restrict it.
	 *
	 * @return bool
	 */
	private function sessionGroupsMayAct(mixed $allowedGroups): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->isTrustedSystemCaller();
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		if ($allowedGroups === null) {
			return true;
		}

		return $this->groupsGrant(userGroups: $userGroups, allowedGroups: $allowedGroups);
	}//end sessionGroupsMayAct()

	/**
	 * The groups allowed to act, or null when the config does not restrict this action.
	 *
	 * No config, an entity type or action that is not configured, and an empty
	 * group list all mean no restriction (backward compatibility): each leaves
	 * the config's entry for the entity type and action empty.
	 *
	 * @param mixed $rule The config's entry for the entity type and action, or null.
	 *
	 * @return mixed The allowed groups, or null.
	 */
	private function allowedGroups(mixed $rule): mixed {
		if (empty($rule) === true) {
			return null;
		}

		return $rule;
	}//end allowedGroups()

	/**
	 * Whether any of the user's groups, or the wildcard, is allowed.
	 *
	 * @param array $userGroups    The user's group ids.
	 * @param mixed $allowedGroups The allowed group ids.
	 *
	 * @return bool
	 */
	private function groupsGrant(array $userGroups, mixed $allowedGroups): bool {
		foreach ($userGroups as $groupId) {
			if (in_array($groupId, $allowedGroups) === true) {
				return true;
			}
		}

		return in_array('*', $allowedGroups) === true;
	}//end groupsGrant()

	/**
	 * Verify RBAC permission and throw exception if denied.
	 *
	 * @param string $action     The action to check (create, read, update, delete)
	 * @param string $entityType The type of entity
	 *
	 * @return void
	 *
	 * @throws \Exception If user doesn't have permission
	 */
	protected function verifyRbacPermission(string $action, string $entityType): void {
		if ($this->hasRbacPermission(action: $action, entityType: $entityType) === false) {
			throw new Exception(
				"Access denied: You do not have permission to {$action} {$entityType} entities.",
				Response::HTTP_FORBIDDEN
			);
		}
	}//end verifyRbacPermission()
}//end trait
