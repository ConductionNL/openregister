<?php

/**
 * Multi-Tenancy Trait
 *
 * This trait provides reusable multi-tenancy and RBAC functionality for mappers.
 * It handles organisation filtering, permission checks, and security validation.
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

use Exception;
use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use DateTime;
use DateInterval;
use Symfony\Component\HttpFoundation\Response;
use OCP\AppFramework\Http\JSONResponse;

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
 * Note: The trait does not declare the $appConfig and $logger properties to avoid conflicts.
 * Classes using this trait should declare these properties with their preferred visibility
 * (private/protected) and nullability. The trait methods check isset() before using them.
 *
 * @package OCA\OpenRegister\Db
 */
trait MultiTenancyTrait
{
    /**
     * Get the active organisation UUID from the session.
     *
     * Falls back to the default organisation from config if no active organisation is set.
     * Automatically sets the default as active if user has no active organisation.
     *
     * @return string|null The active organisation UUID or default organisation UUID, or null if neither set
     */
    protected function getActiveOrganisationUuid(): ?string
    {
        if (isset($this->logger) === true) {
            $this->logger->info('🔹 MultiTenancyTrait: getActiveOrganisationUuid called');
        }

        // Get current user.
        if (isset($this->userSession) === false) {
            return null;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return $this->getDefaultOrganisationUuid();
        }

        // Use OrganisationMapper to get active org with automatic fallback to default.
        if (isset($this->organisationMapper) === true) {
            $organisationMapper = $this->organisationMapper;
            if (isset($this->logger) === true) {
                $this->logger->info(
                    'MultiTenancyTrait: Calling getActiveOrganisationWithFallback for user: '.$user->getUID()
                );
            }

            // @psalm-suppress UndefinedMethod
            return $organisationMapper->getActiveOrganisationWithFallback($user->getUID());
        }

        // Fallback if mapper not available.
        return $this->getDefaultOrganisationUuid();
    }//end getActiveOrganisationUuid()

    /**
     * Get default organisation UUID from config
     *
     * This method provides a fallback for when OrganisationMapper is not available.
     * Prefer using OrganisationMapper::getDefaultOrganisationFromConfig() when possible.
     *
     * @return string|null Default organisation UUID or null if not set
     */
    protected function getDefaultOrganisationUuid(): ?string
    {
        // Prefer using OrganisationMapper if available.
        if (isset($this->organisationMapper) === true) {
            $organisationMapper = $this->organisationMapper;
            // @psalm-suppress UndefinedMethod
            return $organisationMapper->getDefaultOrganisationFromConfig();
        }

        // Fallback to direct config access if mapper not available.
        if (isset($this->appConfig) === false) {
            return null;
        }

        // Try direct config key (newer format).
        $defaultOrg = $this->appConfig->getValueString('openregister', 'defaultOrganisation', '');
        if (empty($defaultOrg) === false) {
            return $defaultOrg;
        }

        // Try nested organisation config (legacy format).
        $organisationConfig = $this->appConfig->getValueString('openregister', 'organisation', '');
        if (empty($organisationConfig) === false) {
            $storedData = json_decode($organisationConfig, true);
            if (isset($storedData['default_organisation']) === true) {
                return $storedData['default_organisation'];
            }
        }

        return null;
    }//end getDefaultOrganisationUuid()

    /**
     * Get active organisation UUIDs (active + all parents)
     *
     * Returns array of organisation UUIDs that the current user can access.
     * Includes the active organisation and all parent organisations in the hierarchy.
     * Falls back to default organisation if no active organisation is set.
     * Used for filtering queries to allow access to parent resources.
     *
     * @return (mixed|null|string)[] Array of organisation UUIDs
     *
     * @psalm-return array{0?: mixed|null|string,...}
     */
    protected function getActiveOrganisationUuids(): array
    {
        $activeOrgUuid = $this->getActiveOrganisationUuid();
        if ($activeOrgUuid === null) {
            return [];
        }

        // If we have OrganisationMapper, get the full hierarchy (active + parents).
        if (isset($this->organisationMapper) === true) {
            try {
                $organisationMapper = $this->organisationMapper;
                // @psalm-suppress UndefinedMethod
                $uuids = $organisationMapper->getOrganisationHierarchy($activeOrgUuid);
                if (empty($uuids) === false) {
                    return $uuids;
                }
            } catch (\Exception $e) {
                // Fall back to just the active org.
                if (isset($this->logger) === true) {
                    $this->logger->warning(
                        'Failed to get organisation hierarchy: '.$e->getMessage(),
                        ['activeOrgUuid' => $activeOrgUuid]
                    );
                }
            }
        }//end if

        // Fall back to just the active organisation.
        return [$activeOrgUuid];
    }//end getActiveOrganisationUuids()

    /**
     * Check if published objects should bypass multi-tenancy filtering.
     *
     * This checks the app configuration to determine if published entities
     * (objects, schemas, registers) should bypass organization filtering.
     *
     * @return bool True if published bypass is enabled in config, false otherwise
     */
    protected function shouldPublishedObjectsBypassMultiTenancy(): bool
    {
        if (isset($this->appConfig) === false) {
            return false;
            // Default to false if appConfig not available.
        }

        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
            // Default to false for security.
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        $bypassEnabled    = $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false;
        return $bypassEnabled;
    }//end shouldPublishedObjectsBypassMultiTenancy()

    /**
     * Get the current user ID.
     *
     * @return string|null The current user ID or null if no user is logged in
     */
    protected function getCurrentUserId(): ?string
    {
        if (isset($this->userSession) === false) {
            return null;
        }

        $user = $this->userSession->getUser();
        if (($user !== null) === false) {
            return null;
        }

        return $user->getUID();
    }//end getCurrentUserId()

    /**
     * Check if the current user is an admin.
     *
     * @return bool True if the current user is an admin, false otherwise
     */
    protected function isCurrentUserAdmin(): bool
    {
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            return false;
        }

        if (isset($this->groupManager) === false) {
            return false;
        }

        return $this->groupManager->isAdmin($userId);
    }//end isCurrentUserAdmin()

    /**
     * Apply organisation filter to a query builder with advanced multi-tenancy support.
     *
     * This method provides comprehensive organisation filtering including:
     * - Hierarchical organisation support (active org + all parents)
     * - Published entity bypass for multi-tenancy (works for objects, schemas, registers)
     * - Admin override capabilities
     * - System default organisation special handling
     * - NULL organisation legacy data access for admins
     * - Unauthenticated request handling
     *
     * Features:
     * 1. Hierarchical Access: Users see entities from their active org AND parent orgs
     * 2. Published Entities: Can bypass multi-tenancy if configured (any table with published/depublished columns)
     * 3. Admin Override: Admins can see all entities if enabled in config
     * 4. Default Org: Special behavior for system-wide default organisation
     * 5. Legacy Data: Admins can access NULL organisation entities
     *
     * Example hierarchy:
     * - Organisation A (root)
     * - Organisation B (parent: A)
     * - Organisation C (parent: B)
     * When C is active, entities from A, B, and C are visible.
     *
     * @param IQueryBuilder $qb                  The query builder
     * @param string        $columnName          The column name for organisation
     * @param bool          $allowNullOrg        Whether admins can see NULL organisation entities
     * @param string        $tableAlias          Optional table alias for published/depublished
     * @param bool          $enablePublished     Whether to enable published entity bypass
     * @param bool          $multiTenancyEnabled Whether multitenancy is enabled (default: true)
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control multitenancy filtering behavior
     */
    protected function applyOrganisationFilter(
        IQueryBuilder $qb,
        string $columnName='organisation',
        bool $allowNullOrg=false,
        string $tableAlias='',
        bool $enablePublished=false,
        bool $multiTenancyEnabled=true
    ): void {
        if ($this->shouldSkipFiltering($multiTenancyEnabled) === true) {
            return;
        }

        $user = $this->getUserFromSession();
        if ($user === null && isset($this->userSession) === false) {
            return;
        }

        $activeOrgUuids     = $this->getActiveOrganisationUuids();
        $organisationColumn = $this->buildQualifiedColumnName(columnName: $columnName, tableAlias: $tableAlias);
        $pubBypassEnabled   = $this->isPublishedBypassEnabled($enablePublished);

        if (empty($activeOrgUuids) === true) {
            $this->applyNoActiveOrgFilter(
                qb: $qb,
                user: $user,
                allowNullOrg: $allowNullOrg,
                organisationColumn: $organisationColumn,
                tableAlias: $tableAlias,
                enablePublished: $enablePublished,
                pubBypassEnabled: $pubBypassEnabled
            );
            return;
        }

        $this->applyActiveOrgFilter(
            qb: $qb,
            user: $user,
            activeOrgUuids: $activeOrgUuids,
            allowNullOrg: $allowNullOrg,
            organisationColumn: $organisationColumn,
            tableAlias: $tableAlias,
            enablePublished: $enablePublished,
            pubBypassEnabled: $pubBypassEnabled
        );
    }//end applyOrganisationFilter()

    /**
     * Check if filtering should be skipped entirely
     *
     * @param bool $multiTenancyEnabled Whether multitenancy is enabled via parameter
     *
     * @return bool True if filtering should be skipped
     */
    private function shouldSkipFiltering(bool $multiTenancyEnabled): bool
    {
        if ($multiTenancyEnabled === false) {
            return true;
        }

        if (isset($this->appConfig) === false) {
            return false;
        }

        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return ($multitenancyData['enabled'] ?? true) === false;
    }//end shouldSkipFiltering()

    /**
     * Get the current user from the session
     *
     * @return mixed|null The user object or null
     */
    private function getUserFromSession(): mixed
    {
        if (isset($this->userSession) === false) {
            if (($this->logger ?? null) !== null) {
                $this->logger->debug('[MultiTenancyTrait] UserSession not available, skipping filter');
            }

            return null;
        }

        $user = $this->userSession->getUser();
        if ($user === null && isset($this->logger) === true) {
            $this->logger->debug('[MultiTenancyTrait] Unauthenticated request, no automatic access');
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
    private function buildQualifiedColumnName(string $columnName, string $tableAlias): string
    {
        if ($tableAlias !== null && $tableAlias !== '') {
            return $tableAlias.'.'.$columnName;
        }

        return $columnName;
    }//end buildQualifiedColumnName()

    /**
     * Check if published bypass is enabled in config
     *
     * @param bool $enablePublished Whether published bypass is requested
     *
     * @return bool True if bypass is enabled
     */
    private function isPublishedBypassEnabled(bool $enablePublished): bool
    {
        if ($enablePublished === false || isset($this->appConfig) === false) {
            return false;
        }

        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false;
    }//end isPublishedBypassEnabled()

    /**
     * Check if user is an admin
     *
     * @param mixed $user The user object
     *
     * @return bool True if user is admin
     */
    private function isUserAdmin(mixed $user): bool
    {
        if ($user === null || isset($this->groupManager) === false) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);
        return in_array('admin', $userGroups);
    }//end isUserAdmin()

    /**
     * Check if admin override is enabled
     *
     * @return bool True if admin override is enabled
     */
    private function isAdminOverrideEnabled(): bool
    {
        if (isset($this->appConfig) === false) {
            return false;
        }

        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false;
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        return $multitenancyData['adminOverride'] ?? false;
    }//end isAdminOverrideEnabled()

    /**
     * Apply filter when no active organisation is set
     *
     * @param IQueryBuilder $qb                 Query builder
     * @param mixed         $user               User object
     * @param bool          $allowNullOrg       Allow NULL organisation
     * @param string        $organisationColumn Organisation column name
     * @param string        $tableAlias         Table alias
     * @param bool          $enablePublished    Enable published bypass
     * @param bool          $pubBypassEnabled   Published bypass enabled
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control multitenancy filtering behavior
     */
    private function applyNoActiveOrgFilter(
        IQueryBuilder $qb,
        mixed $user,
        bool $allowNullOrg,
        string $organisationColumn,
        string $tableAlias,
        bool $enablePublished,
        bool $pubBypassEnabled
    ): void {
        $isAdmin = $this->isUserAdmin($user);

        if ($isAdmin === true && $this->isAdminOverrideEnabled() === true) {
            return;
        }

        $conditions = [];

        if ($isAdmin === true && $allowNullOrg === true) {
            $conditions[] = $qb->expr()->isNull($organisationColumn);
        }

        if ($pubBypassEnabled === true && $enablePublished === true) {
            $conditions[] = $this->buildPublishedBypassCondition(qb: $qb, tableAlias: $tableAlias);
        }

        if (empty($conditions) === true) {
            $qb->andWhere('1 = 0');
            return;
        }

        $orgConditions = call_user_func_array([$qb->expr(), 'orX'], $conditions);
        $qb->andWhere($orgConditions);
    }//end applyNoActiveOrgFilter()

    /**
     * Apply filter when active organisation(s) are set
     *
     * @param IQueryBuilder $qb                 Query builder
     * @param mixed         $user               User object
     * @param array         $activeOrgUuids     Active organisation UUIDs
     * @param bool          $allowNullOrg       Allow NULL organisation
     * @param string        $organisationColumn Organisation column name
     * @param string        $tableAlias         Table alias
     * @param bool          $enablePublished    Enable published bypass
     * @param bool          $pubBypassEnabled   Published bypass enabled
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control multitenancy filtering behavior
     */
    private function applyActiveOrgFilter(
        IQueryBuilder $qb,
        mixed $user,
        array $activeOrgUuids,
        bool $allowNullOrg,
        string $organisationColumn,
        string $tableAlias,
        bool $enablePublished,
        bool $pubBypassEnabled
    ): void {
        $isAdmin = $this->isUserAdmin($user);

        if ($isAdmin === true && $this->isAdminOverrideEnabled() === true) {
            return;
        }

        $orgConditions = $qb->expr()->orX();

        $this->addOrganisationConditions(
            qb: $qb,
            orgConditions: $orgConditions,
            activeOrgUuids: $activeOrgUuids,
            organisationColumn: $organisationColumn
        );

        if ($pubBypassEnabled === true && $enablePublished === true) {
            $orgConditions->add($this->buildPublishedBypassCondition(qb: $qb, tableAlias: $tableAlias));
        }

        if ($allowNullOrg === true) {
            $orgConditions->add($qb->expr()->isNull($organisationColumn));
        }

        $qb->andWhere($orgConditions);
    }//end applyActiveOrgFilter()

    /**
     * Add organisation conditions to the query
     *
     * @param IQueryBuilder $qb                 Query builder
     * @param mixed         $orgConditions      Organisation conditions object
     * @param array         $activeOrgUuids     Active organisation UUIDs
     * @param string        $organisationColumn Organisation column name
     *
     * @return void
     */
    private function addOrganisationConditions(
        IQueryBuilder $qb,
        mixed $orgConditions,
        array $activeOrgUuids,
        string $organisationColumn
    ): void {
        $directActiveOrgUuid = $this->getActiveOrganisationUuid();

        if ($directActiveOrgUuid !== null) {
            $orgConditions->add(
                $qb->expr()->eq(
                    $organisationColumn,
                    $qb->createNamedParameter($directActiveOrgUuid, IQueryBuilder::PARAM_STR)
                )
            );

            $parentOrgs = array_filter(
                $activeOrgUuids,
                function ($uuid) use ($directActiveOrgUuid) {
                    return $uuid !== $directActiveOrgUuid;
                }
            );

            if (count($parentOrgs) > 0) {
                $orgConditions->add(
                    $qb->expr()->in(
                        $organisationColumn,
                        $qb->createNamedParameter($parentOrgs, IQueryBuilder::PARAM_STR_ARRAY)
                    )
                );
            }

            return;
        }//end if

        $orgConditions->add(
            $qb->expr()->in(
                $organisationColumn,
                $qb->createNamedParameter($activeOrgUuids, IQueryBuilder::PARAM_STR_ARRAY)
            )
        );
    }//end addOrganisationConditions()

    /**
     * Build the published bypass condition
     *
     * @param IQueryBuilder $qb         Query builder
     * @param string        $tableAlias Table alias
     *
     * @return mixed The condition expression
     */
    private function buildPublishedBypassCondition(IQueryBuilder $qb, string $tableAlias): mixed
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $publishedColumn   = $this->buildQualifiedColumnName(columnName: 'published', tableAlias: $tableAlias);
        $depublishedColumn = $this->buildQualifiedColumnName(columnName: 'depublished', tableAlias: $tableAlias);

        return $qb->expr()->andX(
            $qb->expr()->isNotNull($publishedColumn),
            $qb->expr()->lte($publishedColumn, $qb->createNamedParameter($now)),
            $qb->expr()->orX(
                $qb->expr()->isNull($depublishedColumn),
                $qb->expr()->gt($depublishedColumn, $qb->createNamedParameter($now))
            )
        );
    }//end buildPublishedBypassCondition()

    /**
     * Set organisation on an entity during creation.
     *
     * SECURITY: Always overwrites the organisation with the active organisation UUID
     * from the session, ignoring any value provided by the frontend.
     * This ensures users can only create entities in their active organisation.
     *
     * @param Entity $entity The entity to set organisation on
     *
     * @return void
     */
    protected function setOrganisationOnCreate(Entity $entity): void
    {
        // Only set organisation if the entity has an organisation property.
        if (method_exists($entity, 'getOrganisation') === false || method_exists($entity, 'setOrganisation') === false) {
            return;
        }

        // SECURITY: Always use active organisation from session, ignore frontend input.
        $activeOrgUuid = $this->getActiveOrganisationUuid();
        if ($activeOrgUuid !== null) {
            $entity->setOrganisation($activeOrgUuid);
        }
    }//end setOrganisationOnCreate()

    /**
     * Set the owner field on entity creation from the current user session
     *
     * This method automatically sets the owner field to the current logged-in user
     * when creating a new entity. It only sets the owner if:
     * - The entity has owner getter/setter methods
     * - The owner is not already set
     * - A user is currently logged in
     *
     * @param Entity $entity The entity being created
     *
     * @return void
     */
    protected function setOwnerOnCreate(Entity $entity): void
    {
        // Only set owner if the entity has an owner property.
        if (method_exists($entity, 'getOwner') === false || method_exists($entity, 'setOwner') === false) {
            return;
        }

        // Only set owner if not already set (allow explicit owner assignment).
        if ($entity->getOwner() !== null && $entity->getOwner() !== '') {
            return;
        }

        // Get current user from session.
        if (isset($this->userSession) === false) {
            return;
        }

        $user = $this->userSession->getUser();
        if ($user !== null) {
            $entity->setOwner($user->getUID());
        }
    }//end setOwnerOnCreate()

    /**
     * Verify that an entity belongs to the active organisation.
     *
     * Throws an exception if the entity's organisation doesn't match
     * the active organisation. This applies to ALL users including admins.
     *
     * @param Entity $entity The entity to verify
     *
     * @return void
     *
     * @throws \Exception If organisation doesn't match
     */
    protected function verifyOrganisationAccess(Entity $entity): void
    {
        // Check if entity has organisation property.
        if (method_exists($entity, 'getOrganisation') === false) {
            return;
        }

        $entityOrgUuid = $entity->getOrganisation();
        $activeOrgUuid = $this->getActiveOrganisationUuid();

        // If entity has no organisation set, allow it.
        if ($entityOrgUuid === null) {
            return;
        }

        // Verify the organisations match (applies to everyone including admins).
        if ($entityOrgUuid !== $activeOrgUuid) {
            throw new Exception(
                'Security violation: You do not have permission to access this resource from a different organisation.',
                Response::HTTP_FORBIDDEN
            );
        }
    }//end verifyOrganisationAccess()

    /**
     * Check if the current user has permission to perform an action.
     *
     * Checks RBAC permissions from the active organisation's authorization configuration.
     *
     * Expected authorization structure in Organization entity:
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
     * @param string $action     The action to check (create, read, update, delete)
     * @param string $entityType The type of entity (e.g., 'schema', 'register', 'configuration')
     *
     * @return bool True if user has permission, false otherwise
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)      RBAC permission checking requires many conditional paths
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    protected function hasRbacPermission(string $action, string $entityType): bool
    {
        // Admins always have all permissions.
        if ($this->isCurrentUserAdmin() === true) {
            return true;
        }

        // Get current user.
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            // No user logged in, deny access.
            return false;
        }

        // Get active organisation.
        if (isset($this->organisationService) === false) {
            // No organisation service, allow access (backward compatibility).
            return true;
        }

        $activeOrg = $this->organisationService->getActiveOrganisation();
        if ($activeOrg === null) {
            // No active organisation, deny access.
            return false;
        }

        // Check if user is in the organisation's users list.
        $orgUsers = $activeOrg->getUserIds();
        if (in_array($userId, $orgUsers) === true) {
            // User is explicitly listed in the organisation - check authorization.
        }

        // Check if user has access via organisation membership.
        // Note: $organisationUsers was intended for group-based access but is currently unused.
        // Access is determined by $orgUsers check above.
        // If (in_array($userId, $organisationUsers, true) === false) {
        // Return false;
        // }
        // Get user's groups.
        if (isset($this->groupManager) === false) {
            // No group manager, allow access (backward compatibility).
            return true;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);

        // Get organisation's authorization configuration.
        $authorization = $activeOrg->getAuthorization();
        if ($authorization === null || empty($authorization) === true) {
            // No RBAC configured, allow access (backward compatibility).
            return true;
        }

        // Check if the entity type exists in authorization.
        if (isset($authorization[$entityType]) === false) {
            // Entity type not in authorization, allow access (backward compatibility).
            return true;
        }

        // Check if the action exists for this entity type.
        if (isset($authorization[$entityType][$action]) === false) {
            // Action not configured, allow access (backward compatibility).
            return true;
        }

        $allowedGroups = $authorization[$entityType][$action];

        // If the array is empty, it means no restrictions (allow all).
        if (empty($allowedGroups) === true) {
            return true;
        }

        // Check if user is in any of the allowed groups.
        foreach ($userGroups as $groupId) {
            if (in_array($groupId, $allowedGroups) === true) {
                return true;
            }
        }

        // Check for wildcard group.
        if (in_array('*', $allowedGroups) === true) {
            return true;
        }

        // No matching permission found.
        return false;
    }//end hasRbacPermission()

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
    protected function verifyRbacPermission(string $action, string $entityType): void
    {
        if ($this->hasRbacPermission(action: $action, entityType: $entityType) === false) {
            throw new Exception(
                "Access denied: You do not have permission to {$action} {$entityType} entities.",
                Response::HTTP_FORBIDDEN
            );
        }
    }//end verifyRbacPermission()
}//end trait
