<?php

/**
 * OrganisationMapperInterface: the public surface of OrganisationMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use Exception;
use OCA\OpenRegister\Exception\OrganisationSlugConflictException;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;

/**
 * Every public method of OrganisationMapper. Callers depend on this interface, not on the class.
 */
interface OrganisationMapperInterface {

	/**
	 * Insert a new organisation
	 *
	 * @param Organisation $entity Organisation entity to insert
	 *
	 * @return Organisation The inserted organisation with updated ID
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Update an existing organisation
	 *
	 * @param Organisation $entity Organisation entity to update
	 *
	 * @return Organisation The updated organisation
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Delete an organisation
	 *
	 * @param Organisation $entity Organisation entity to delete
	 *
	 * @return Organisation The deleted organisation
	 */
	public function delete(Entity $entity): Entity;

	/**
	 * Find organisation by UUID
	 *
	 * @param string $uuid The organisation UUID
	 *
	 * @return Organisation The organisation entity
	 *
	 * @throws DoesNotExistException If organisation not found
	 * @throws MultipleObjectsReturnedException If multiple organisations found
	 */
	public function findByUuid(string $uuid): Organisation;

	/**
	 * Find multiple organisations by UUIDs using a single optimized query
	 *
	 * This method performs a single database query to fetch multiple organisations,
	 * significantly improving performance compared to individual queries.
	 *
	 * @param array $uuids Array of organisation UUIDs to find
	 *
	 * @return Organisation&Organisation[]
	 *
	 * @psalm-return array<Entity&Organisation>
	 */
	public function findMultipleByUuid(array $uuids): array;

	/**
	 * Find all organisations for a specific user
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 */
	public function findByUserId(string $userId): array;

	/**
	 * Get all organisations with user count
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 */
	public function findAllWithUserCount(): array;

	/**
	 * Insert or update organisation with UUID generation
	 *
	 * @param Organisation $organisation The organisation to save
	 *
	 * @return Organisation The saved organisation
	 *
	 * @throws \Exception If UUID is invalid or already exists
	 * @throws OrganisationSlugConflictException If a new organisation's slug is taken
	 *
	 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-organisationservice-must-resolve-per-user-organisation-membership-and-active-context
	 */
	public function save(Organisation $organisation): Organisation;

	/**
	 * Check if a UUID already exists
	 *
	 * @param string $uuid The UUID to check
	 * @param int|null $excludeId Optional organisation ID to exclude from check (for updates)
	 *
	 * @return bool True if UUID already exists
	 */
	public function uuidExists(string $uuid, ?int $excludeId = null): bool;

	/**
	 * Find organisations that are tenants OF THIS INSTALLATION.
	 *
	 * A named method rather than one more filter every caller must remember,
	 * because forgetting it is destructive: the tenant background jobs select on
	 * `status` alone and TenantPurgeJob PERMANENTLY DELETES what it selects, so
	 * a federated counterparty that happens to be archived would be deleted as
	 * though it were a spent tenant. The name is the guarantee, and it is
	 * greppable.
	 *
	 * 🔴 A NULL counts as a tenant. `is_local_tenant` was added by migration and
	 * every row written before it holds NULL, so a plain `= true` would make
	 * every pre-existing tenant invisible to all three jobs at once — tenants
	 * would stop being deprovisioned, purged and metered, and nothing would
	 * report it. Only a row explicitly marked false is excluded.
	 *
	 * @param int   $limit   Maximum number of results.
	 * @param int   $offset  Number of results to skip.
	 * @param array $filters Column => value equality filters.
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 *
	 * @spec openspec/specs/organisation-tenancy-scope/spec.md
	 */
	public function findLocalTenants(int $limit = 50, int $offset = 0, ?array $filters = []): array;

	/**
	 * Find all organisations with pagination and optional column filters
	 *
	 * The `$filters` parameter is NOT optional sugar: three tenant-lifecycle
	 * background jobs (TenantDeprovisionJob, TenantPurgeJob, TenantUsageSyncJob)
	 * already call `findAll(filters: ['status' => ...])`. Until this parameter
	 * existed those calls raised `Error: Unknown named parameter $filters`, which
	 * is an `\Error` and therefore slipped straight past their `catch (\Exception)`
	 * — every run of all three jobs died uncaught. See openregister#2282.
	 *
	 * @param int $limit Maximum number of results to return (default 50)
	 * @param int $offset Number of results to skip (default 0)
	 * @param array $filters Column => value equality filters (also accepts the
	 *                       'IS NULL' / 'IS NOT NULL' sentinels used fleet-wide)
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 */
	public function findAll(int $limit = 50, int $offset = 0, ?array $filters = []): array;

	/**
	 * Find the organisations that are tenants of this installation (a NULL
	 * `is_local_tenant` counts as a tenant), one list query at a time.
	 *
	 * @param ListQuery $query The limit, offset and column => value equality filters.
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 */
	public function findLocalTenantList(ListQuery $query): array;

	/**
	 * Find the organisations one list query asks for, ordered by name.
	 *
	 * @param ListQuery $query The limit, offset and column => value equality filters.
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 */
	public function findList(ListQuery $query): array;

	/**
	 * Find the organisations whose name contains a pattern, one page at a time.
	 *
	 * @param string    $name  The name pattern to search for.
	 * @param ListQuery $query The limit and offset (its filters are not applied).
	 *
	 * @return Organisation[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Organisation>
	 */
	public function findListByName(string $name, ListQuery $query): array;

	/**
	 * Find an organisation by its slug
	 *
	 * @param string $slug The slug to search for
	 *
	 * @return Organisation The found organisation
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If not found
	 */
	public function findBySlug(string $slug): Organisation;

	/**
	 * Get organisation statistics
	 *
	 * @return int[] Statistics about organisations
	 *
	 * @psalm-return array{total: int}
	 */
	public function getStatistics(): array;

	/**
	 * Add user to organisation by UUID
	 *
	 * @param string $organisationUuid The organisation UUID
	 * @param string $userId The user ID to add
	 *
	 * @return Organisation The updated organisation
	 *
	 * @throws DoesNotExistException If organisation not found
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function addUserToOrganisation(string $organisationUuid, string $userId): Organisation;

	/**
	 * Remove user from organisation by UUID
	 *
	 * @param string $organisationUuid The organisation UUID
	 * @param string $userId The user ID to remove
	 *
	 * @return Organisation The updated organisation
	 *
	 * @throws DoesNotExistException If organisation not found
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function removeUserFromOrganisation(string $organisationUuid, string $userId): Organisation;

	/**
	 * Find all parent organisations recursively for a given organisation UUID
	 *
	 * Uses recursive Common Table Expression (CTE) for efficient hierarchical queries.
	 * Returns array of parent organisation UUIDs ordered from direct parent to root.
	 * Maximum depth is limited to 10 levels to prevent infinite loops.
	 *
	 * Example:
	 * - Organisation A (root)
	 * - Organisation B (parent: A)
	 * - Organisation C (parent: B)
	 *
	 * findParentChain(C) returns: [B, A]
	 *
	 * @param string $organisationUuid The starting organisation UUID
	 *
	 * @return array Array of parent organisation UUIDs ordered by level (direct parent first)
	 *
	 * @psalm-return list{0?: mixed,...}
	 */
	public function findParentChain(string $organisationUuid): array;

	/**
	 * Find all child organisations recursively for a given organisation UUID
	 *
	 * Uses recursive Common Table Expression (CTE) for efficient hierarchical queries.
	 * Returns array of all child organisation UUIDs (direct and indirect children).
	 * Maximum depth is limited to 10 levels to prevent infinite loops.
	 *
	 * Example:
	 * - Organisation A (root)
	 * - Organisation B (parent: A)
	 * - Organisation C (parent: A)
	 * - Organisation D (parent: B)
	 *
	 * findChildrenChain(A) returns: [B, C, D]
	 *
	 * @param string $organisationUuid The parent organisation UUID
	 *
	 * @return array Array of child organisation UUIDs
	 *
	 * @psalm-return list{0?: mixed,...}
	 */
	public function findChildrenChain(string $organisationUuid): array;

	/**
	 * Validate parent assignment to prevent circular references and enforce max depth
	 *
	 * Checks that setting a parent organisation will not create:
	 * - Circular references (A -> B -> A)
	 * - Excessive depth (> 10 levels)
	 * - Self-reference (organisation pointing to itself)
	 *
	 * @param string $organisationUuid The organisation UUID to update
	 * @param string|null $newParentUuid The new parent UUID to assign (null to remove parent)
	 *
	 * @return void
	 *
	 * @throws \Exception If validation fails (circular reference, max depth exceeded, etc.)
	 */
	public function validateParentAssignment(string $organisationUuid, ?string $newParentUuid): void;

	/**
	 * Get active organisation UUID for a user from preferences
	 *
	 * This retrieves the user's currently active organisation from user preferences.
	 * Returns null if no active organisation is set.
	 *
	 * @param string $userId The user ID
	 *
	 * @return string|null The active organisation UUID or null
	 */
	public function getActiveOrganisationUuidForUser(string $userId): ?string;

	/**
	 * Get active organisation with fallback to default and set on session
	 *
	 * This method retrieves the active organisation for a user from preferences.
	 * If no active organisation is set, it falls back to the default organisation
	 * from configuration and sets it as the active organisation in preferences.
	 *
	 * This consolidates the logic of getting an organisation UUID with proper fallbacks,
	 * ensuring users always have an organisation context.
	 *
	 * @param string $userId The user ID
	 *
	 * @return string|null The organisation UUID (active or default) or null if neither exists
	 */
	public function getActiveOrganisationWithFallback(string $userId): ?string;

	/**
	 * Set active organisation for a user in preferences
	 *
	 * @param string $userId The user ID
	 * @param string $organisationUuid The organisation UUID to set as active
	 *
	 * @return void
	 *
	 * @throws Exception If the database operation fails
	 */
	public function setActiveOrganisationForUser(string $userId, string $organisationUuid): void;

	/**
	 * Whether entity-level RBAC enforcement is switched on for this instance.
	 *
	 * Lives here rather than in MultiTenancyTrait because the trait must not
	 * read `$this->appConfig`: it does not declare the property, and five of the
	 * twelve classes using it (Agent, Application, Configuration, Endpoint and
	 * Mapping mappers) do not declare it either. Reading it there is precisely
	 * the defect this whole change exists to fix — a property nothing supplies —
	 * and phpstan caught me doing it. OrganisationMapper already holds
	 * `IAppConfig`, and every class using the trait already holds this mapper,
	 * so the question gets one home instead of five new constructor arguments.
	 *
	 * Defaults to OFF, and only the exact string `enabled` turns it on: an unset
	 * or malformed value must keep today's behaviour rather than enable, by
	 * accident, a control that is known to break sharing until every
	 * organisation's `authorization` has been audited. See openregister#2833.
	 *
	 * @return bool True when the check should be enforced.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function isEntityRbacEnforcementEnabled(): bool;

	/**
	 * Get default organisation UUID from configuration
	 *
	 * @return string|null The default organisation UUID or null if not configured
	 */
	public function getDefaultOrganisationFromConfig(): ?string;

	/**
	 * Get organisation hierarchy (organisation + all parents)
	 *
	 * Returns an array of organisation UUIDs including the given organisation
	 * and all its parent organisations up the hierarchy.
	 *
	 * @param string $organisationUuid The organisation UUID
	 *
	 * @return string[] Array of organisation UUIDs (current + parents)
	 */
	public function getOrganisationHierarchy(string $organisationUuid): array;

	/**
	 * Follow a merge chain to the organisation that is authoritative today
	 *
	 * An organisation that was merged away no longer owns its data — the
	 * survivor does. Resolving a tenant therefore has to follow `merged_into`
	 * before the UUID is used as a scope, or every query issued against the old
	 * UUID reads the survivor's rows under a boundary that no longer applies.
	 *
	 * The walk is bounded and cycle-guarded: a merge chain is data, and data can
	 * be wrong. On a cycle or an unresolvable link this returns the LAST UUID it
	 * could actually load, never null and never a UUID it did not verify — a
	 * resolver that fails open would hand the caller an unscoped identifier.
	 *
	 * @param string $organisationUuid The (possibly merged-away) organisation UUID
	 *
	 * @return string The UUID of the surviving organisation
	 */
	public function resolveMergeTarget(string $organisationUuid): string;

	/**
	 * Find an organisation, following any merge to the surviving one
	 *
	 * The read counterpart of {@see resolveMergeTarget()}. Use this wherever a
	 * UUID arrives from outside (a request, a stored reference, a federation
	 * peer) and is about to be used as a tenant scope.
	 *
	 * @param string $uuid The organisation UUID
	 *
	 * @return Organisation The surviving organisation
	 *
	 * @throws DoesNotExistException If no organisation resolves
	 */
	public function findByUuidFollowingMerge(string $uuid): Organisation;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param Organisation $entity The entity.
	 *
	 * @return Organisation The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
