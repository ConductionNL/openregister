<?php

/**
 * MagicMapper Organization Handler
 *
 * This handler provides multi-tenancy support for dynamic schema-based tables.
 * It implements organization-based filtering to ensure that users can only access
 * objects that belong to their active organization, maintaining data isolation
 * between different tenants.
 *
 * KEY RESPONSIBILITIES:
 * - Apply organization-based filtering to dynamic table queries
 * - Handle multi-tenancy isolation between organizations
 * - Support for default organization special behaviors
 * - Integration with user session and organization context
 * - Admin override capabilities for cross-organization access
 *
 * MULTI-TENANCY FEATURES:
 * - Organization-based object isolation
 * - Default organization special handling
 * - Admin users cross-organization access (configurable)
 * - Unauthenticated user organization filtering
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Handler
 * @package   OCA\OpenRegister\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://www.OpenRegister.app
 *
 * @since 2.0.0 Initial implementation for MagicMapper organization support
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Organization filtering handler for MagicMapper dynamic tables
 *
 * This class provides multi-tenancy support for dynamically created schema-based
 * tables, ensuring proper data isolation between organizations while supporting
 * appropriate cross-organization access patterns.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MagicOrganizationHandler {

	/**
	 * Every row is visible — a trusted system context, or an admin with the
	 * bypass enabled outside SaaS mode.
	 */
	public const SCOPE_ALL = 'all';

	/**
	 * No row is visible — a non-admin with no active organisation.
	 */
	public const SCOPE_NONE = 'none';

	/**
	 * Only rows with NO organisation — an admin with no active organisation.
	 */
	public const SCOPE_NULL_ONLY = 'null-only';

	/**
	 * Rows in the caller's active organisation(s).
	 */
	public const SCOPE_IN = 'in';

	/**
	 * Rows in the caller's active organisation(s), PLUS rows with no
	 * organisation at all. Admins only. This is the mode the aggregation API
	 * used to get wrong: it rendered only the `IN` half, and SQL `=` / `IN`
	 * never match NULL, so org-less rows vanished from every KPI.
	 */
	public const SCOPE_IN_OR_NULL = 'in-or-null';

	/**
	 * Constructor for MagicOrganizationHandler.
	 *
	 * @param IUserSession $userSession User session manager
	 * @param IGroupManager $groupManager Group manager
	 * @param IAppConfig $appConfig Application configuration
	 * @param ContainerInterface $container Container for lazy loading services
	 * @param LoggerInterface $logger Logger for logging operations
	 */
	public function __construct(
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly IAppConfig $appConfig,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply organization-based filtering to a query builder
	 *
	 * This method implements multi-tenancy filtering:
	 * 1. Admin users can optionally bypass organization filtering
	 * 2. Objects belonging to the user's active organization are accessible
	 * 3. Objects belonging to parent organizations are accessible
	 * 4. Objects with null organization are accessible to all (legacy/global data)
	 *
	 * @param IQueryBuilder $qb Query builder to modify
	 * @param bool $adminBypassEnabled Whether admin users can bypass org filtering
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 */
	public function applyOrganizationFilter(
		IQueryBuilder $qb,
		bool $adminBypassEnabled = false,
	): void {
		$scope = $this->resolveOrganizationScope(adminBypassEnabled: $adminBypassEnabled);

		if ($scope['mode'] === self::SCOPE_ALL) {
			return;
		}

		if ($scope['mode'] === self::SCOPE_NONE) {
			$qb->andWhere('1 = 0');
			return;
		}

		if ($scope['mode'] === self::SCOPE_NULL_ONLY) {
			$qb->andWhere($qb->expr()->isNull('t._organisation'));
			return;
		}

		// Condition 1: objects belonging to the caller's active organisation(s).
		$conditions = [];
		$conditions[] = $qb->expr()->in(
			't._organisation',
			$qb->createNamedParameter($scope['uuids'], IQueryBuilder::PARAM_STR_ARRAY)
		);
		if (count($scope['uuids']) === 1) {
			array_pop($conditions);
			$conditions[] = $qb->expr()->eq(
				't._organisation',
				$qb->createNamedParameter($scope['uuids'][0])
			);
		}

		// Condition 2: objects with no organisation — ONLY for admin users.
		if ($scope['mode'] === self::SCOPE_IN_OR_NULL) {
			$conditions[] = $qb->expr()->isNull('t._organisation');
		}

		$qb->andWhere($qb->expr()->orX(...$conditions));

	}//end applyOrganizationFilter()

	/**
	 * Decide WHICH rows the current caller may see, without building any SQL.
	 *
	 * This is the single source of truth for the organisation boundary. It was
	 * extracted from {@see applyOrganizationFilter()} because a SECOND
	 * implementation had grown in `AggregationRunner::tryNativeAggregation()`,
	 * where the whole rule had been flattened to a bare
	 * `_organisation = :activeOrg`. SQL `=` never matches NULL, so every object
	 * with no organisation was invisible to the aggregation API while the list
	 * API returned it — measured 2026-08-30 on decidiq/meeting: four meetings
	 * listed, one of them org-less, `count` answered 3, and
	 * `filter[lifecycle]=closed` answered 0 for a meeting that plainly exists.
	 * Every KPI tile in the fleet reads that endpoint, so each one silently
	 * under-reported.
	 *
	 * Returning a DECISION rather than a query fragment is the point: a caller
	 * that cannot render one of these modes must refuse to run rather than
	 * approximate it, and the rule itself now lives in exactly one place.
	 *
	 * @param bool $adminBypassEnabled Whether the caller honours the admin bypass (disabled in SaaS mode).
	 *
	 * @return array{mode: string, uuids: array<int, string>} `mode` is one of the SCOPE_* constants.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *   Moved here with the code they were written for: this method IS the
	 *   branchy decision that used to sit inline in applyOrganizationFilter,
	 *   which has carried these three suppressions since it was written. The
	 *   branches are the tenancy rule itself (system context, admin, bypass,
	 *   SaaS, active-org set) and collapsing them would hide it.
	 */
	public function resolveOrganizationScope(bool $adminBypassEnabled = false): array {
		$user = $this->userSession->getUser();

		// CLI / no-session system context (occ commands, repair steps, cron
		// jobs, background calculations, system listeners) is a trusted system
		// operation and must see all org-owned rows. See isSystemContext().
		if ($this->isSystemContext(user: $user) === true) {
			return ['mode' => self::SCOPE_ALL, 'uuids' => []];
		}

		// Admins can see all objects, including those with no organisation.
		$isAdmin = false;
		if ($user !== null) {
			$userGroups = $this->groupManager->getUserGroupIds($user);
			$isAdmin = in_array('admin', $userGroups, true);
		}

		if ($adminBypassEnabled === true && $isAdmin === true) {
			// In SaaS mode, never bypass the organisation boundary.
			$saasMode = $this->isSaasModeEnabled();
			if ($saasMode === true) {
				$this->logger->debug(
					message: '[MagicOrganizationHandler] SaaS mode active — admin bypass disabled for org boundary',
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}

			if ($saasMode !== true) {
				return ['mode' => self::SCOPE_ALL, 'uuids' => []];
			}
		}

		$activeOrgUuids = $this->getActiveOrganizationUuids();

		if (empty($activeOrgUuids) === true) {
			// No active organisation: admins still see org-less rows, others see nothing.
			$emptyMode = self::SCOPE_NONE;
			if ($isAdmin === true) {
				$emptyMode = self::SCOPE_NULL_ONLY;
			}

			return ['mode' => $emptyMode, 'uuids' => []];
		}

		$scopedMode = self::SCOPE_IN;
		if ($isAdmin === true) {
			$scopedMode = self::SCOPE_IN_OR_NULL;
		}

		return [
			'mode' => $scopedMode,
			'uuids' => array_values($activeOrgUuids),
		];

	}//end resolveOrganizationScope()

	/**
	 * Determine whether the current call is a trusted system (CLI/no-session)
	 * context that must bypass org filtering.
	 *
	 * OCC commands, repair steps, cron jobs, background calculations and system
	 * listeners have no user session. Clamping them to `1 = 0` silently empties
	 * app list views and background calcs (e.g. larpingapp). This mirrors the
	 * established CLI bypass in MultiTenancyTrait::hasRbacPermission(). SaaS mode
	 * still enforces the org boundary for genuine multi-tenant deployments.
	 *
	 * @param \OCP\IUser|null $user The resolved session user (null in CLI).
	 *
	 * @return bool True when the org filter should be bypassed.
	 */
	private function isSystemContext(?\OCP\IUser $user): bool {
		if ($user !== null || PHP_SAPI !== 'cli' || $this->isSaasModeEnabled() === true) {
			return false;
		}

		return true;
	}//end isSystemContext()

	/**
	 * Get the active organization UUID(s) for the current user
	 *
	 * Returns an array of organization UUIDs that the current user has access to,
	 * including the active organization and its parent organizations.
	 *
	 * @return string[] Array of organization UUIDs
	 */
	public function getActiveOrganizationUuids(): array {
		try {
			// Get OrganisationService from container (lazy loading to avoid circular dependencies).
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');

			// Get active organisations including parent chain.
			$orgUuids = $organisationService->getUserActiveOrganisations();

			if (empty($orgUuids) === false) {
				return $orgUuids;
			}

			// Fallback: try to get just the active organisation.
			$activeOrg = $organisationService->getActiveOrganisation();
			if ($activeOrg !== null) {
				return [$activeOrg->getUuid()];
			}

			return [];
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicOrganizationHandler] Failed to get active organisation',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'error' => $e->getMessage(),
				]
			);
			return [];
		}//end try
	}//end getActiveOrganizationUuids()

	/**
	 * Get the primary active organization UUID for the current user
	 *
	 * @return string|null The active organization UUID or null if none
	 */
	public function getActiveOrganizationUuid(): ?string {
		$uuids = $this->getActiveOrganizationUuids();
		return $uuids[0] ?? null;
	}//end getActiveOrganizationUuid()

	/**
	 * Check if an object belongs to the user's active organization
	 *
	 * @param string|null $objectOrganisation The organization UUID of the object
	 *
	 * @return bool True if object belongs to user's organization
	 */
	public function belongsToActiveOrganization(?string $objectOrganisation): bool {
		if ($objectOrganisation === null) {
			// Objects with null organization are only accessible to admin users.
			$user = $this->userSession->getUser();
			if ($user !== null) {
				$userGroups = $this->groupManager->getUserGroupIds($user);
				return in_array('admin', $userGroups, true);
			}

			return false;
		}

		$activeOrgUuids = $this->getActiveOrganizationUuids();

		return in_array($objectOrganisation, $activeOrgUuids, true);
	}//end belongsToActiveOrganization()

	/**
	 * Get the default organization UUID from app config
	 *
	 * @return string|null The default organization UUID or null
	 */
	public function getDefaultOrganizationUuid(): ?string {
		$defaultOrgId = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
		if ($defaultOrgId !== '') {
			return $defaultOrgId;
		}

		return null;
	}//end getDefaultOrganizationUuid()

	/**
	 * Check if admin users should bypass multi-tenancy filtering
	 *
	 * This reads the adminOverride setting from the multitenancy config,
	 * ensuring consistent behavior with MultiTenancyTrait.
	 *
	 * @return bool True if admin users can bypass organization filtering
	 */
	public function isAdminOverrideEnabled(): bool {
		$multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');

		// Default to true when no config exists (matches ConfigurationSettingsHandler defaults).
		if (empty($multitenancyConfig) === true) {
			return true;
		}

		$multitenancyData = json_decode($multitenancyConfig, true);
		if ($multitenancyData === null) {
			return true;
		}

		// Default to true if not explicitly set (matches ConfigurationSettingsHandler).
		return $multitenancyData['adminOverride'] ?? true;
	}//end isAdminOverrideEnabled()

	/**
	 * Check if the current user is logged in (not anonymous)
	 *
	 * @return bool True if a user is logged in, false for anonymous access
	 */
	public function isUserLoggedIn(): bool {
		return $this->userSession->getUser() !== null;
	}//end isUserLoggedIn()

	/**
	 * Check if SaaS mode is enabled in multitenancy configuration.
	 *
	 * When SaaS mode is enabled, organisation boundaries cannot be bypassed
	 * even with admin override.
	 *
	 * @return bool True if SaaS mode is enabled
	 */
	private function isSaasModeEnabled(): bool {
		$multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
		if (empty($multitenancyConfig) === true) {
			return false;
		}

		$multitenancyData = json_decode($multitenancyConfig, true);
		return ($multitenancyData['saasMode'] ?? false) === true;
	}//end isSaasModeEnabled()
}//end class
