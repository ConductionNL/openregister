<?php

/**
 * OrganisationFilterTrait: the predicates that scope a read to the caller's organisations.
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

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * The read-scoping half of MultiTenancyTrait, behind applyOrganisationFilter().
 *
 * Composed only by MultiTenancyTrait; a mapper uses that trait, never this one.
 * Deliberately without strict_types, like the trait it was split from, so the
 * moved code coerces exactly as it always did.
 *
 * The scope an active-organisation filter is built from travels as one array:
 * `activeOrgUuids` (the caller's organisation and its parents), `allowNullOrg`,
 * `organisationColumn`, `sharedIds` (rows readable through a shared master
 * data declaration) and `sharedIdColumn`.
 *
 * @package OCA\OpenRegister\Db\Tenancy
 */
trait OrganisationFilterTrait {

	/**
	 * Check if filtering should be skipped entirely
	 *
	 * @param bool $multiTenancyEnabled Whether multitenancy is enabled via parameter
	 *
	 * @return bool True if filtering should be skipped
	 */
	private function shouldSkipFiltering(bool $multiTenancyEnabled): bool {
		if ($multiTenancyEnabled === false) {
			return true;
		}

		$multitenancyData = $this->multitenancyConfig();
		if ($multitenancyData === null) {
			return false;
		}

		return ($multitenancyData['enabled'] ?? true) === false;
	}//end shouldSkipFiltering()

	/**
	 * The decoded `multitenancy` app config, or null when there is none.
	 *
	 * Null for a host without an app config and for an empty value; otherwise
	 * whatever json_decode() makes of the stored value.
	 *
	 * @return mixed The decoded config, or null.
	 */
	private function multitenancyConfig(): mixed {
		if (isset($this->appConfig) === false) {
			return null;
		}

		$multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
		if (empty($multitenancyConfig) === true) {
			return null;
		}

		return json_decode($multitenancyConfig, true);
	}//end multitenancyConfig()

	/**
	 * Get the current user from the session
	 *
	 * @return mixed|null The user object or null
	 */
	private function getUserFromSession(): mixed {
		if (isset($this->userSession) === false) {
			$this->debugTenancy(message: '[MultiTenancyTrait] UserSession not available, skipping filter');
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			$this->debugTenancy(message: '[MultiTenancyTrait] Unauthenticated request, no automatic access');
		}

		return $user;
	}//end getUserFromSession()

	/**
	 * Build a qualified column name with optional table alias
	 *
	 * @param string $columnName Column name
	 * @param string $tableAlias Optional table alias
	 *
	 * @return string Qualified column name
	 */
	private function buildQualifiedColumnName(string $columnName, string $tableAlias): string {
		if ($tableAlias !== '') {
			return $tableAlias . '.' . $columnName;
		}

		return $columnName;
	}//end buildQualifiedColumnName()

	/**
	 * Check if user is an admin
	 *
	 * @param mixed $user The user object
	 *
	 * @return bool True if user is admin
	 */
	private function isUserAdmin(mixed $user): bool {
		if ($user === null || isset($this->groupManager) === false) {
			return false;
		}

		$userGroups = $this->groupManager->getUserGroupIds($user);
		return in_array('admin', $userGroups);
	}//end isUserAdmin()

	/**
	 * Whether this user is an admin AND the admin override is on.
	 *
	 * @param mixed $user The user object
	 *
	 * @return bool
	 */
	private function adminOverrides(mixed $user): bool {
		return $this->isUserAdmin(user: $user) === true && $this->isAdminOverrideEnabled() === true;
	}//end adminOverrides()

	/**
	 * Check if admin override is enabled
	 *
	 * In SaaS mode, admin override is always disabled to enforce hard tenant boundaries.
	 *
	 * @return bool True if admin override is enabled
	 */
	private function isAdminOverrideEnabled(): bool {
		$multitenancyData = $this->multitenancyConfig();
		if ($multitenancyData === null) {
			return false;
		}

		// In SaaS mode, admin override is always disabled for organisation boundary.
		if (($multitenancyData['saasMode'] ?? false) === true) {
			$this->debugTenancy(message: '[MultiTenancyTrait] SaaS mode active — admin override disabled for organisation boundary');
			return false;
		}

		return $multitenancyData['adminOverride'] ?? false;
	}//end isAdminOverrideEnabled()

	/**
	 * Apply filter when no active organisation is set
	 *
	 * @param IQueryBuilder $qb    Query builder
	 * @param array         $scope The scope (see the trait's docblock) with the session user under `user`.
	 *
	 * @return void
	 */
	private function applyNoActiveOrgFilter(IQueryBuilder $qb, array $scope): void {
		if ($this->adminOverrides(user: $scope['user']) === true) {
			return;
		}

		// Allow null organisation entities when explicitly permitted by the caller.
		// This is used for system-wide resources like Registers and Schemas.
		if ($scope['allowNullOrg'] === true) {
			$qb->andWhere($qb->expr()->isNull($scope['organisationColumn']));
			return;
		}

		// No active org and no null-org permission: block all results.
		$qb->andWhere('1 = 0');
	}//end applyNoActiveOrgFilter()

	/**
	 * Apply filter when an active organisation is set
	 *
	 * Guard: IExpressionBuilder::orX() documents that calling it with zero
	 * arguments is deprecated, and NC34 already logs a deprecation on every
	 * zero-arg call. The scope is expected to always yield at least one
	 * predicate here, but the invariant is defended rather than ever handing
	 * orX() an empty list. Per openspec/specs/tenant-isolation-audit/spec.md an
	 * inability to build a positive tenant-match predicate MUST fail CLOSED (no
	 * rows), matching applyNoActiveOrgFilter() — never fail open by skipping
	 * the WHERE clause.
	 *
	 * @param IQueryBuilder $qb    Query builder
	 * @param array         $scope The scope (see the trait's docblock) with the session user under `user`.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	private function applyActiveOrgFilter(IQueryBuilder $qb, array $scope): void {
		if ($this->adminOverrides(user: $scope['user']) === true) {
			$this->logAdminOverride(user: $scope['user']);
			return;
		}

		$orgPredicates = $this->scopePredicates(qb: $qb, scope: $scope);
		if ($orgPredicates === []) {
			$this->failClosed(qb: $qb);
			return;
		}

		$qb->andWhere($qb->expr()->orX(...$orgPredicates));
	}//end applyActiveOrgFilter()

	/**
	 * Match no rows, saying why: no predicate could be built for an active organisation.
	 *
	 * @param IQueryBuilder $qb Query builder
	 *
	 * @return void
	 */
	private function failClosed(IQueryBuilder $qb): void {
		if (isset($this->logger) === true) {
			$this->logger->warning(
				message: '[MultiTenancyTrait] applyActiveOrgFilter built zero org predicates '
					. '— failing closed (no rows) instead of calling orX() with no arguments',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

		$qb->andWhere('1 = 0');
	}//end failClosed()

	/**
	 * Every predicate a row of the caller's scope may match.
	 *
	 * Shared master data (REQ-SLE-001) is matched by ROW ID, which is what keeps
	 * the widening honest: adding the holder's UUID to the organisation predicate
	 * would hand the consumer EVERY row the holder owns in this table, which is a
	 * cross-tenant hole wearing the shape of a feature.
	 *
	 * @param IQueryBuilder $qb    Query builder
	 * @param array         $scope The scope (see the trait's docblock).
	 *
	 * @return array The predicates, to be OR-ed.
	 */
	private function scopePredicates(IQueryBuilder $qb, array $scope): array {
		$organisationColumn = $scope['organisationColumn'];
		$orgPredicates = $this->buildOrganisationConditions(qb: $qb, scope: $scope);

		// Allow null organisation entities when explicitly permitted by the caller.
		// This is used for system-wide resources like Registers and Schemas.
		if ($scope['allowNullOrg'] === true) {
			$orgPredicates[] = $qb->expr()->isNull($organisationColumn);
		}

		if ($scope['sharedIds'] !== []) {
			$sharedIds = $qb->createNamedParameter($scope['sharedIds'], IQueryBuilder::PARAM_INT_ARRAY);
			$orgPredicates[] = $qb->expr()->in($scope['sharedIdColumn'], $sharedIds);
		}

		return $orgPredicates;
	}//end scopePredicates()

	/**
	 * Log a granted admin override, pseudonymously.
	 *
	 * REQ-SLE-003: the actor is a pseudonym, never the login name, and the
	 * context goes through the redactor.
	 *
	 * @param mixed $user The user object
	 *
	 * @return void
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/tenant-isolation-audit/spec.md#requirement-a-log-line-names-the-tenant-pseudonymously-and-never-carries-a-secret-req-sle-003
	 */
	private function logAdminOverride(mixed $user): void {
		if (isset($this->logger) === false) {
			return;
		}

		$userId = 'unknown';
		if ($user !== null && method_exists($user, 'getUID') === true) {
			$userId = $user->getUID();
		}

		$this->infoRedacted(
			message: '[MultiTenancyTrait] Admin override: cross-organisation access granted',
			context: ['type' => 'cross_tenant_access_admin_override', 'actor' => $userId]
		);
	}//end logAdminOverride()

	/**
	 * Build organisation filter conditions
	 *
	 * With a direct active organisation: that one, then its parents. Without
	 * one: every organisation of the scope's hierarchy, or nothing.
	 *
	 * @param IQueryBuilder $qb    Query builder
	 * @param array         $scope The scope (see the trait's docblock): its
	 *                             `activeOrgUuids` and `organisationColumn`.
	 *
	 * @return array Array of conditions
	 */
	private function buildOrganisationConditions(IQueryBuilder $qb, array $scope): array {
		$directActiveOrgUuid = $this->getActiveOrganisationUuid();
		if ($directActiveOrgUuid === null) {
			return $this->hierarchyConditions(qb: $qb, scope: $scope);
		}

		$column = $scope['organisationColumn'];
		$conditions = [];
		$conditions[] = $qb->expr()->eq($column, $qb->createNamedParameter($directActiveOrgUuid, IQueryBuilder::PARAM_STR));

		$parentOrgs = array_filter($scope['activeOrgUuids'], fn ($uuid) => $uuid !== $directActiveOrgUuid);
		if (count($parentOrgs) > 0) {
			$conditions[] = $qb->expr()->in($column, $qb->createNamedParameter($parentOrgs, IQueryBuilder::PARAM_STR_ARRAY));
		}

		return $conditions;
	}//end buildOrganisationConditions()

	/**
	 * The condition for a session without a direct active organisation: any
	 * organisation of the scope's hierarchy, or none when it is empty.
	 *
	 * @param IQueryBuilder $qb    Query builder
	 * @param array         $scope The scope (see the trait's docblock).
	 *
	 * @return array Array of conditions
	 */
	private function hierarchyConditions(IQueryBuilder $qb, array $scope): array {
		$activeOrgUuids = $scope['activeOrgUuids'];
		if (empty($activeOrgUuids) === true) {
			return [];
		}

		return [$qb->expr()->in($scope['organisationColumn'], $qb->createNamedParameter($activeOrgUuids, IQueryBuilder::PARAM_STR_ARRAY))];
	}//end hierarchyConditions()
}//end trait
