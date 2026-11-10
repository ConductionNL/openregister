<?php

/**
 * Multi-Tenancy Trait
 *
 * This trait provides reusable multi-tenancy and RBAC functionality for mappers.
 * It handles organisation filtering, permission checks, and security validation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Trait
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\Tenancy\EntityRbacTrait;
use OCA\OpenRegister\Db\Tenancy\OrganisationFilterOptions;
use OCA\OpenRegister\Db\Tenancy\OrganisationFilterTrait;
use OCA\OpenRegister\Db\Tenancy\OrganisationWriteGuardTrait;
use OCA\OpenRegister\Db\Tenancy\TenancyIdentityTrait;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Trait MultiTenancyTrait
 *
 * Provides common multi-tenancy and RBAC functionality that can be mixed into mappers.
 *
 * Requirements for using this trait:
 * - The entity must have an 'organisation' property (string UUID)
 * - The mapper must inject OrganisationMapper ($this->organisationMapper)
 * - The mapper must inject IGroupManager ($this->groupManager - for RBAC)
 * - The mapper must inject IUserSession ($this->userSession - for current user)
 * - The mapper must have access to IDBConnection via $this->db (from QBMapper parent)
 *
 * Optional dependencies for advanced features:
 * - IAppConfig ($this->appConfig) - for multitenancy config settings
 *   Classes should define this property themselves if needed (e.g., private IAppConfig $appConfig)
 * - LoggerInterface ($this->logger) - for debug logging
 *   Classes should define this property themselves if needed (e.g., private LoggerInterface $logger)
 *
 * The behaviour is split over four role traits in lib/Db/Tenancy, composed
 * here so that a mapper keeps using this one trait: TenancyIdentityTrait (who
 * the caller is, which organisations they act in), OrganisationFilterTrait
 * (read scoping), OrganisationWriteGuardTrait (what a write stamps, and the
 * refusal of a cross-tenant write) and EntityRbacTrait (entity RBAC).
 *
 * Note: The trait does not declare the $appConfig and $logger properties to avoid conflicts.
 * Classes using this trait should declare these properties with their preferred visibility
 * (private/protected) and nullability. The trait methods check isset() before using them.
 *
 * @package OCA\OpenRegister\Db
 */
trait MultiTenancyTrait {
	use TenancyIdentityTrait;
	use OrganisationFilterTrait;
	use OrganisationWriteGuardTrait;
	use EntityRbacTrait;

	/**
	 * Apply organisation filter to a query builder with advanced multi-tenancy support.
	 *
	 * This method provides comprehensive organisation filtering including:
	 * - Hierarchical organisation support (active org + all parents)
	 * - Admin override capabilities
	 * - System default organisation special handling
	 * - NULL organisation legacy data access for admins
	 * - Unauthenticated request handling
	 *
	 * Features:
	 * 1. Hierarchical Access: Users see entities from their active org AND parent orgs
	 * 2. Admin Override: Admins can see all entities if enabled in config
	 * 3. Default Org: Special behavior for system-wide default organisation
	 * 4. Legacy Data: Admins can access NULL organisation entities
	 *
	 * Note: The published/depublished column bypass for Register/Schema multi-tenancy
	 * has been removed. Anonymous access is now controlled exclusively via RBAC
	 * (authorization.read containing 'public' in the register/schema entity).
	 *
	 * Example hierarchy:
	 * - Organisation A (root)
	 * - Organisation B (parent: A)
	 * - Organisation C (parent: B)
	 * When C is active, entities from A, B, and C are visible.
	 *
	 * @param IQueryBuilder $qb The query builder
	 * @param string $columnName The column name for organisation
	 * @param bool $allowNullOrg Whether admins can see NULL organisation entities
	 * @param string $tableAlias Optional table alias
	 * @param bool $multiTenancyEnabled Whether multitenancy is enabled (default: true)
	 *
	 * @spec openspec/specs/deprecate-published-metadata/spec.md#REQ-5 (MultiTenancyTrait Documentation —
	 *       published/depublished column bypass removed; Register/Schema visibility now governed by RBAC only)
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control multitenancy filtering behavior
	 */
	protected function applyOrganisationFilter(
		IQueryBuilder $qb,
		string $columnName = 'organisation',
		bool $allowNullOrg = false,
		string $tableAlias = '',
		bool $multiTenancyEnabled = true,
	): void {
		$options = new OrganisationFilterOptions(
			columnName: $columnName, allowNullOrg: $allowNullOrg,
			tableAlias: $tableAlias, multiTenancyEnabled: $multiTenancyEnabled
		);
		$this->applyOrganisationScope(qb: $qb, options: $options);
	}//end applyOrganisationFilter()

	/**
	 * Apply the organisation filter described by an options object.
	 *
	 * The same filter as applyOrganisationFilter(), which builds the options
	 * and delegates here.
	 *
	 * @param IQueryBuilder             $qb      The query builder
	 * @param OrganisationFilterOptions $options Column, alias, NULL-organisation and on/off settings
	 *
	 * @return void
	 */
	protected function applyOrganisationScope(IQueryBuilder $qb, OrganisationFilterOptions $options = new OrganisationFilterOptions()): void {
		if ($this->shouldSkipFiltering(multiTenancyEnabled: $options->multiTenancyEnabled) === true) {
			return;
		}

		$user = $this->getUserFromSession();
		if ($user !== null || isset($this->userSession) === true) {
			$this->applyScopedFilter(qb: $qb, scope: $this->organisationScope(options: $options, user: $user));
		}
	}//end applyOrganisationScope()

	/**
	 * Apply the filter for a resolved scope: with or without an active organisation.
	 *
	 * @param IQueryBuilder $qb    The query builder
	 * @param array         $scope The scope (see OrganisationFilterTrait) plus the session user under `user`.
	 *
	 * @return void
	 */
	private function applyScopedFilter(IQueryBuilder $qb, array $scope): void {
		if (empty($scope['activeOrgUuids']) === true) {
			$this->applyNoActiveOrgFilter(qb: $qb, scope: $scope);
			return;
		}

		$this->applyActiveOrgFilter(qb: $qb, scope: $scope);
	}//end applyScopedFilter()

	/**
	 * The scope an organisation filter is built from (see OrganisationFilterTrait).
	 *
	 * Shared master data (REQ-SLE-001): rows held by ANOTHER organisation that
	 * declared this one a consumer, OR-ed into the same predicate by ROW ID
	 * rather than by organisation, so a consumer of one code list gains that
	 * code list and nothing else the holder owns.
	 *
	 * @param OrganisationFilterOptions $options Column, alias and NULL-organisation settings.
	 * @param mixed                     $user    The session user, or null.
	 *
	 * @return array The scope.
	 */
	private function organisationScope(OrganisationFilterOptions $options, mixed $user): array {
		$activeOrgUuids = $this->getActiveOrganisationUuids();
		$organisationColumn = $this->buildQualifiedColumnName(columnName: $options->columnName, tableAlias: $options->tableAlias);
		return [
			'user' => $user,
			'activeOrgUuids' => $activeOrgUuids,
			'allowNullOrg' => $options->allowNullOrg,
			'organisationColumn' => $organisationColumn,
			'sharedIds' => $this->sharedMasterDataIds(activeOrgUuids: $activeOrgUuids),
			'sharedIdColumn' => $this->buildQualifiedColumnName(columnName: 'id', tableAlias: $options->tableAlias),
		];
	}//end organisationScope()
}//end trait
