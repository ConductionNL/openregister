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

use OCA\OpenRegister\Service\OrganisationContext;
use OCA\OpenRegister\Service\SharedMasterDataServiceInterface;
use OCA\OpenRegister\Service\TenancyCollaborators;
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
	 * organisation at all. Admins, or a caller whose register-cascade grant
	 * admits org-less rows (see admitOrganisationless()). This is the mode the aggregation API
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
	 * @param int|null $registerId The register this table belongs to, for shared master data
	 * @param int|null $schemaId The schema this table belongs to, for shared master data
	 * @param bool $withOrgless Whether the caller's RBAC grant also admits org-less rows
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	public function applyOrganizationFilter(
		IQueryBuilder $qb,
		bool $adminBypassEnabled = false,
		?int $registerId = null,
		?int $schemaId = null,
		bool $withOrgless = false,
	): void {
		$scope = $this->resolveOrganizationScope(adminBypassEnabled: $adminBypassEnabled, registerId: $registerId, schemaId: $schemaId);
		if ($withOrgless === true) {
			$scope = self::admitOrganisationless(scope: $scope);
		}

		(new OrganisationScopeCondition())->apply(qb: $qb, scope: $scope);

	}//end applyOrganizationFilter()

	/**
	 * Widen an organisation scope by the rows with NO organisation.
	 *
	 * Used when the caller's RBAC grant comes from the register cascade (see
	 * MagicRbacHandler::admitsOrganisationlessRowsThroughCascade()): org-less
	 * rows belong to no tenant, so the grant reaches them, while the rows of
	 * other organisations stay outside the scope. Only the two modes that
	 * exclude org-less rows change; every other mode, an unknown one included,
	 * is returned untouched so each renderer keeps its own fail-closed answer.
	 *
	 * @param array{mode: string, uuids: array<int, string>} $scope The decided scope.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The widened scope.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md#requirement-register-level-authorization-cascade
	 */
	public static function admitOrganisationless(array $scope): array {
		$mode = ($scope['mode'] ?? null);

		if ($mode === self::SCOPE_IN) {
			$scope['mode'] = self::SCOPE_IN_OR_NULL;
		}

		if ($mode === self::SCOPE_NONE) {
			$scope['mode'] = self::SCOPE_NULL_ONLY;
			$scope['uuids'] = [];
		}

		return $scope;
	}//end admitOrganisationless()

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
	 * @param int|null $registerId The register of the table being read, for shared master data.
	 * @param int|null $schemaId The schema of the table being read, for shared master data.
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
	 * @SuppressWarnings(PHPMD.StaticAccess) OrganisationContext is an ambient scope marker, read statically by design.
	 */
	public function resolveOrganizationScope(
		bool $adminBypassEnabled = false,
		?int $registerId = null,
		?int $schemaId = null,
	): array {
		// Code acting for an organisation reads that organisation (and its
		// parents and shared master data holders), never more: the session
		// user, the admin bypass and the system context do not widen it.
		$table = new OrganisationScopeTable(registerId: $registerId, schemaId: $schemaId);
		if (OrganisationContext::isActive() === true) {
			return $this->contextOrganizationScope(table: $table);
		}

		return $this->callerOrganizationScope(adminBypassEnabled: $adminBypassEnabled, table: $table);

	}//end resolveOrganizationScope()

	/**
	 * The organisation scope of the session caller, outside an organisation context.
	 *
	 * @param bool                   $adminBypassEnabled Whether the caller honours the admin bypass.
	 * @param OrganisationScopeTable $table              The table being read, for shared master data.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The scope.
	 */
	private function callerOrganizationScope(bool $adminBypassEnabled, OrganisationScopeTable $table): array {
		$user = $this->userSession->getUser();

		// CLI / no-session system context (occ commands, repair steps, cron
		// jobs, background calculations, system listeners) is a trusted system
		// operation and must see all org-owned rows. See isSystemContext().
		if ($this->isSystemContext(user: $user) === true) {
			return ['mode' => self::SCOPE_ALL, 'uuids' => []];
		}

		// Admins can see all objects, including those with no organisation.
		$isAdmin = $this->isAdmin(user: $user);
		if ($this->bypassesBoundary(adminBypassEnabled: $adminBypassEnabled, isAdmin: $isAdmin) === true) {
			return ['mode' => self::SCOPE_ALL, 'uuids' => []];
		}

		return $this->activeOrganizationScope(isAdmin: $isAdmin, table: $table);
	}//end callerOrganizationScope()

	/**
	 * Whether the caller is in the admin group. No user is no admin.
	 *
	 * @param \OCP\IUser|null $user The session user.
	 *
	 * @return bool True for a member of the admin group.
	 */
	private function isAdmin(?\OCP\IUser $user): bool {
		if ($user === null) {
			return false;
		}

		return in_array('admin', $this->groupManager->getUserGroupIds($user), true);
	}//end isAdmin()

	/**
	 * Whether an admin asked for, and may use, the bypass of the organisation boundary.
	 *
	 * @param bool $adminBypassEnabled Whether the caller honours the admin bypass.
	 * @param bool $isAdmin            Whether the caller is an admin.
	 *
	 * @return bool True when every row is visible.
	 */
	private function bypassesBoundary(bool $adminBypassEnabled, bool $isAdmin): bool {
		return $adminBypassEnabled === true && $isAdmin === true && $this->adminBypassAllowed() === true;
	}//end bypassesBoundary()

	/**
	 * The scope of a caller outside an organisation context, from their active organisation(s).
	 *
	 * No active organisation: admins still see org-less rows, others see nothing.
	 * Otherwise the active organisation(s) and the shared master data holders,
	 * plus the org-less rows for an admin.
	 *
	 * Shared master data (REQ-SLE-001). The holder UUIDs go into `uuids`
	 * rather than into a new key, and that is the whole point: every
	 * renderer of this decision — applyOrganizationFilter() here, and
	 * AggregationRunner's native SQL — honours the share with no change of
	 * its own. A second key would be a second definition of the boundary,
	 * and resolveOrganizationScope() exists BECAUSE a second definition once
	 * drifted and made every KPI tile under-report.
	 *
	 * The widening is safe to fold in this way only because it is resolved
	 * from the register+schema pair the caller named, and a magic table
	 * holds exactly one such pair. A caller that names neither gets no
	 * widening, which is the honest answer for a query whose table nobody
	 * identified.
	 *
	 * @param bool                   $isAdmin Whether the caller is an admin.
	 * @param OrganisationScopeTable $table   The table being read, for shared master data.
	 *
	 * @return array{mode: string, uuids: array<int, string>} The scope.
	 */
	private function activeOrganizationScope(bool $isAdmin, OrganisationScopeTable $table): array {
		$activeOrgUuids = $this->getActiveOrganizationUuids();
		if (empty($activeOrgUuids) === true) {
			return ['mode' => $this->unscopedMode(isAdmin: $isAdmin), 'uuids' => []];
		}

		return [
			'mode' => $this->scopedMode(isAdmin: $isAdmin),
			'uuids' => $this->withSharedHolders(orgUuids: $activeOrgUuids, table: $table),
		];
	}//end activeOrganizationScope()

	/**
	 * The mode for a caller with no active organisation.
	 *
	 * @param bool $isAdmin Whether the caller is an admin.
	 *
	 * @return string SCOPE_NULL_ONLY for an admin, SCOPE_NONE for anybody else.
	 */
	private function unscopedMode(bool $isAdmin): string {
		if ($isAdmin === true) {
			return self::SCOPE_NULL_ONLY;
		}

		return self::SCOPE_NONE;
	}//end unscopedMode()

	/**
	 * The mode for a caller with an active organisation.
	 *
	 * @param bool $isAdmin Whether the caller is an admin.
	 *
	 * @return string SCOPE_IN_OR_NULL for an admin, SCOPE_IN for anybody else.
	 */
	private function scopedMode(bool $isAdmin): string {
		if ($isAdmin === true) {
			return self::SCOPE_IN_OR_NULL;
		}

		return self::SCOPE_IN;
	}//end scopedMode()

	/**
	 * The caller's organisations widened by the shared master data holders of the table.
	 *
	 * @param array<int, string>     $orgUuids The caller's organisation and its parents.
	 * @param OrganisationScopeTable $table    The table being read.
	 *
	 * @return array<int, string> The organisation UUIDs, without duplicates.
	 */
	private function withSharedHolders(array $orgUuids, OrganisationScopeTable $table): array {
		$sharedHolders = $this->sharedHolders(table: $table, consumerOrgUuids: $orgUuids);

		return array_values(array_unique(array_merge($orgUuids, $sharedHolders)));
	}//end withSharedHolders()

	/**
	 * The organisation scope inside an organisation context.
	 *
	 * @param OrganisationScopeTable $table The table being read, for shared master data.
	 *
	 * @return array{mode: string, uuids: array<int, string>} SCOPE_IN, or SCOPE_NONE when the organisation is unknown.
	 *
	 * @spec openspec/changes/act-for-an-organisation/specs/rbac-scopes/spec.md
	 */
	private function contextOrganizationScope(OrganisationScopeTable $table): array {
		$orgUuids = $this->getActiveOrganizationUuids();
		if ($orgUuids === []) {
			return ['mode' => self::SCOPE_NONE, 'uuids' => []];
		}

		return [
			'mode' => self::SCOPE_IN,
			'uuids' => $this->withSharedHolders(orgUuids: $orgUuids, table: $table),
		];
	}//end contextOrganizationScope()

	/**
	 * Whether an admin with the bypass enabled may read every organisation.
	 *
	 * In SaaS mode the organisation boundary is never bypassed.
	 *
	 * @return bool True outside SaaS mode.
	 */
	private function adminBypassAllowed(): bool {
		if ($this->isSaasModeEnabled() === true) {
			$this->logger->debug(
				message: '[MagicOrganizationHandler] SaaS mode active — admin bypass disabled for org boundary',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}

		return true;
	}//end adminBypassAllowed()

	/**
	 * The holder organisations whose rows in ONE register+schema pair the caller may read.
	 *
	 * Resolved lazily through the container, the same way this class already
	 * reaches OrganisationService, and failing to an empty list: a resolver
	 * that cannot answer must cost a consumer its shared rows, never hand
	 * anybody a row nobody declared them a consumer of.
	 *
	 * @param OrganisationScopeTable $table            The register+schema pair being read.
	 * @param array<int, string>     $consumerOrgUuids The caller's organisation and its parents.
	 *
	 * @return array<int, string> The holder UUIDs to widen by.
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	private function sharedHolders(OrganisationScopeTable $table, array $consumerOrgUuids): array {
		if ($table->isNamed() === false) {
			return [];
		}

		$resolver = $this->sharedMasterDataResolver();
		if ($resolver === null) {
			return [];
		}

		return $resolver->holdersForResource(
			registerId: $table->registerId,
			schemaId: $table->schemaId,
			consumerOrgUuids: $consumerOrgUuids
		);

	}//end sharedHolders()

	/**
	 * The shared master data resolver, or null when the container cannot hand one out.
	 *
	 * @return SharedMasterDataServiceInterface|null The resolver.
	 */
	private function sharedMasterDataResolver(): ?SharedMasterDataServiceInterface {
		try {
			$resolver = TenancyCollaborators::sharedMasterDataFrom(container: $this->container);
		} catch (\Throwable $e) {
			$this->logger->debug(
				message: '[MagicOrganizationHandler] Shared master data resolver unavailable',
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		if (($resolver instanceof SharedMasterDataServiceInterface) === false) {
			return null;
		}

		return $resolver;
	}//end sharedMasterDataResolver()

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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AnonymousEvaluationContext is an ambient-context
	 *   marker; a static read is the whole point of it (WOO-578).
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function isSystemContext(?\OCP\IUser $user): bool {
		if ($user !== null || $this->isSaasModeEnabled() === true) {
			return false;
		}

		return $this->isSystemCaller();
	}//end isSystemContext()

	/**
	 * Whether a call without a user, outside SaaS mode, is the system.
	 *
	 * @return bool True for the system, false for a caller.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	private function isSystemCaller(): bool {
		// The command line, or code inside ObjectService::runAsSystem() in any
		// SAPI: a system write in a web request (dossiq's portal Woo intake)
		// resolves its calculation references here too. Same rule as
		// MagicRbacHandler::isTrustedSystemCaller().
		if ($this->runsAsSystem() === false) {
			return false;
		}

		// A forced-anonymous evaluation (WOO-578) has no user on purpose; it is
		// the one CLI case that is a caller, not the system.
		return \OCA\OpenRegister\Service\AnonymousEvaluationContext::isActive() === false;
	}//end isSystemCaller()

	/**
	 * Whether the call runs on the command line or inside ObjectService::runAsSystem().
	 *
	 * @return bool True for the command line or a system operation.
	 */
	private function runsAsSystem(): bool {
		return $this->isCommandLine() === true
			|| \OCA\OpenRegister\Service\SystemOperationContext::isActive() === true;
	}//end runsAsSystem()

	/**
	 * Whether PHP runs on the command line. A seam, so a test can read as a web request.
	 *
	 * @return bool True under the CLI SAPI.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	protected function isCommandLine(): bool {
		return PHP_SAPI === 'cli';
	}//end isCommandLine()

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
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationServiceInterface');

			return $this->activeOrganisationUuidsOf(organisationService: $organisationService);
		} catch (\Exception $e) {
			$this->logger->warning(
				message: '[MagicOrganizationHandler] Failed to get active organisation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end getActiveOrganizationUuids()

	/**
	 * The active organisations including the parent chain, or else the single active organisation.
	 *
	 * @param mixed $organisationService The organisation service, as the container hands it out (not type-checked, as before).
	 *
	 * @return string[] Array of organization UUIDs
	 */
	private function activeOrganisationUuidsOf(mixed $organisationService): array {
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
	}//end activeOrganisationUuidsOf()

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
