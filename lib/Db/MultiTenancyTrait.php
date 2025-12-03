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

use OCP\AppFramework\Db\Entity;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Security\ISecureRandom;
use Symfony\Component\HttpFoundation\Response;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Trait MultiTenancyTrait
 *
 * Provides common multi-tenancy and RBAC functionality that can be mixed into mappers.
 *
 * Requirements for using this trait:
 * - The entity must have an 'organisation' property (string UUID)
 * - The mapper must inject OrganisationService ($this->organisationService)
 * - The mapper must inject IGroupManager ($this->groupManager - for RBAC)
 * - The mapper must inject IUserSession ($this->userSession - for current user)
 * - The mapper must have access to IDBConnection via $this->db (from QBMapper parent)
 * 
 * Optional dependencies for advanced features:
 * - IAppConfig ($this->appConfig) - for multitenancy config settings
 * - LoggerInterface ($this->logger) - for debug logging
 *
 * @package OCA\OpenRegister\Db
 */
trait MultiTenancyTrait
{


    /**
     * Get the active organisation UUID from the session.
     *
     * @return string|null The active organisation UUID or null if none set
     */
    protected function getActiveOrganisationUuid(): ?string
    {
        if (isset($this->organisationService) === false) {
            return null;
        }

        $activeOrg = $this->organisationService->getActiveOrganisation();
        return $activeOrg ? $activeOrg->getUuid() : null;

    }//end getActiveOrganisationUuid()


    /**
     * Get active organisation UUIDs (active + all parents)
     * 
     * Returns array of organisation UUIDs that the current user can access.
     * Includes the active organisation and all parent organisations in the hierarchy.
     * Used for filtering queries to allow access to parent resources.
     * 
     * @return array Array of organisation UUIDs
     */
    protected function getActiveOrganisationUuids(): array
    {
        if (isset($this->organisationService) === false) {
            return [];
        }
        
        return $this->organisationService->getUserActiveOrganisations();

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
            return false; // Default to false if appConfig not available
        }

        $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
        if (empty($multitenancyConfig) === true) {
            return false; // Default to false for security
        }

        $multitenancyData = json_decode($multitenancyConfig, true);
        $bypassEnabled = $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false;
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
        return $user ? $user->getUID() : null;

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
     * @param IQueryBuilder $qb              The query builder
     * @param string        $columnName      The column name for organisation (default: 'organisation')
     * @param bool          $allowNullOrg    Whether admins can see NULL organisation entities
     * @param string        $tableAlias      Optional table alias for published/depublished columns
     * @param bool          $enablePublished Whether to enable published entity bypass (works for any table with published/depublished columns)
     * @param bool          $multiTenancyEnabled Whether multitenancy is enabled (default: true)
     *
     * @return void
     */
    protected function applyOrganisationFilter(
        IQueryBuilder $qb, 
        string $columnName = 'organisation', 
        bool $allowNullOrg = false,
        string $tableAlias = '',
        bool $enablePublished = false,
        bool $multiTenancyEnabled = true
    ): void {
        // If multitenancy is explicitly disabled via parameter, skip all filtering immediately
        if ($multiTenancyEnabled === false) {
            return;
        }
        
        // Check if multitenancy is enabled (if appConfig is available)
        // Only check app config if parameter was not explicitly set to false
        if (isset($this->appConfig) === true) {
            $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
            if (empty($multitenancyConfig) === false) {
                $multitenancyData = json_decode($multitenancyConfig, true);
                $configMultitenancyEnabled = $multitenancyData['enabled'] ?? true;
                
                if ($configMultitenancyEnabled === false) {
                    // Multitenancy is disabled in config, no filtering
                    return;
                }
            }
        }
        
        // Get current user
        $user = $this->userSession->getUser();
        $userId = $user ? $user->getUID() : null;

        // For unauthenticated requests, no automatic access
        if ($userId === null) {
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] Unauthenticated request, no automatic access');
            }
            // @todo this prevents non loged in access to published objects, we need to allow this so htofix this
            //return $qb;
        }

        // Get active organisation UUIDs (active + all parents)
        $activeOrganisationUuids = $this->getActiveOrganisationUuids();
        
        // Build fully qualified column name
        $organisationColumn = $tableAlias ? $tableAlias . '.' . $columnName : $columnName;
        
        // Check if published entities should bypass multi-tenancy (works for objects, schemas, registers)
        $publishedBypassEnabled = false;
        if ($enablePublished === true && isset($this->appConfig) === true) {
            $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
            if (empty($multitenancyConfig) === false) {
                $multitenancyData = json_decode($multitenancyConfig, true);
                $publishedBypassEnabled = $multitenancyData['publishedObjectsBypassMultiTenancy'] ?? false;
            }
        }
        
        // CASE 1: No active organisation set
        if (empty($activeOrganisationUuids) === true) {
            // Build conditions for users without active organisation
            $orgConditions = $qb->expr()->orX();

            // Check if user is admin - only if user exists
            $isAdmin = false;
            if ($user !== null && isset($this->groupManager) === true) {
                $userGroups = $this->groupManager->getUserGroupIds($user);
                $isAdmin = in_array('admin', $userGroups);
            }

            // Admins can see NULL organisation entities (legacy data)
            if ($isAdmin === true && $allowNullOrg === true) {
                $orgConditions->add($qb->expr()->isNull($organisationColumn));
            }

            // Include published entities if bypass is enabled (works for objects, schemas, registers)
            // Note: Organizations can see their own depublished items via the organization filter above.
            // The depublished check here only applies to the published bypass (entities from OTHER organizations).
            if ($publishedBypassEnabled === true && $enablePublished === true) {
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $publishedColumn = $tableAlias ? $tableAlias . '.published' : 'published';
                $depublishedColumn = $tableAlias ? $tableAlias . '.depublished' : 'depublished';
                
                // Published bypass condition: entity must be published AND not depublished
                // This ensures depublished entities from OTHER organizations are never visible via published bypass
                $orgConditions->add(
                    $qb->expr()->andX(
                        $qb->expr()->isNotNull($publishedColumn),
                        $qb->expr()->lte($publishedColumn, $qb->createNamedParameter($now)),
                        // Depublished check: must be NULL (never depublished) OR in the future (not yet depublished)
                        $qb->expr()->orX(
                            $qb->expr()->isNull($depublishedColumn),
                            $qb->expr()->gt($depublishedColumn, $qb->createNamedParameter($now))
                        )
                    )
                );
            }

            // If no conditions were added, deny all access
            if ($orgConditions->count() === 0) {
                $qb->andWhere($qb->expr()->eq('1', $qb->createNamedParameter('0'))); // Always false
            } else {
                $qb->andWhere($orgConditions);
            }
            return;
        }
        
        // CASE 2: Active organisation(s) set

        // Check admin status and admin override setting - only if user exists
        $isAdmin = false;
        if ($user !== null && isset($this->groupManager) === true) {
            $userGroups = $this->groupManager->getUserGroupIds($user);
            $isAdmin = in_array('admin', $userGroups);
        }
        
        $adminOverrideEnabled = false;
        if ($isAdmin === true && isset($this->appConfig) === true) {
            $multitenancyConfig = $this->appConfig->getValueString('openregister', 'multitenancy', '');
            if (empty($multitenancyConfig) === false) {
                $multitenancyData = json_decode($multitenancyConfig, true);
                $adminOverrideEnabled = $multitenancyData['adminOverride'] ?? false;
            }
        }
        
        // Apply admin override logic
        if ($isAdmin === true && $adminOverrideEnabled === true) {
            // Admin override enabled - admins see everything
            return;
        }
        

        // Build organisation filter conditions
        $orgConditions = $qb->expr()->orX();

        // Prepare published/depublished column names for checks
        $publishedColumn = $tableAlias ? $tableAlias . '.published' : 'published';
        $depublishedColumn = $tableAlias ? $tableAlias . '.depublished' : 'depublished';
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Get the direct active organization (not parents) to allow depublished items from own org
        $directActiveOrgUuid = $this->getActiveOrganisationUuid();


        // Include entities from active organisation(s) and parents
        // IMPORTANT: Users can see their own organization's depublished items
        // Children can see ALL parent organization items (including depublished)
        // Logic:
        // 1. Entity is from direct active organization - includes ALL items (including depublished)
        // 2. Entity is from parent organization(s) - includes ALL items (including depublished)
        // 3. Entity is published and not depublished from ANY organization (via published bypass)
        if ($directActiveOrgUuid !== null) {
            // Condition 1: Entity from direct active organization (allows depublished from own org)
            $orgConditions->add(
                $qb->expr()->eq($organisationColumn, $qb->createNamedParameter($directActiveOrgUuid, IQueryBuilder::PARAM_STR))
            );
            
            // Condition 2: Entity from parent organizations (children can see all parent objects, including depublished)
            // Only add this if there are parent organizations
            $parentOrgs = array_filter($activeOrganisationUuids, function($uuid) use ($directActiveOrgUuid) {
                return $uuid !== $directActiveOrgUuid;
            });
            if (count($parentOrgs) > 0) {
                $orgConditions->add(
                    $qb->expr()->in($organisationColumn, $qb->createNamedParameter($parentOrgs, IQueryBuilder::PARAM_STR_ARRAY))
                );
            }
        } else {
            // No direct active org, just match active orgs (children can see all parent items)
            $orgConditions->add(
                $qb->expr()->in($organisationColumn, $qb->createNamedParameter($activeOrganisationUuids, IQueryBuilder::PARAM_STR_ARRAY))
            );
        }
        

        // Include published entities if bypass is enabled (works for objects, schemas, registers)
        // 
        // BEHAVIOR WHEN ENABLED:
        // - Published objects bypass RBAC read permissions (anyone can read published objects)
        // - Published objects bypass organization filtering (visible from ANY organization)
        // - Published objects do NOT bypass RBAC create/update/delete (still require permissions)
        //
        // IMPORTANT: Depublished entities from OTHER organizations are excluded from published bypass.
        // Organizations can still see their own depublished items via the organization filter above.
        // The depublished check here only applies to entities accessed via published bypass (from other organizations).
        //
        // RESULT: Users see:
        // - ALL published (but not depublished) objects from ALL organizations (via published bypass)
        // - ALL objects (including depublished) from their own organization (via organization filter)
        // This is intentional when publishedObjectsBypassMultiTenancy is enabled in config
        //
        // If users report seeing too many objects from other organizations, check:
        // 1. Is publishedObjectsBypassMultiTenancy enabled in config? (should be false for strict multi-tenancy)
        // 2. How many objects are currently published?
        // 3. Are objects being published unintentionally?
        if ($publishedBypassEnabled === true && $enablePublished === true) {
            // Published bypass condition: entity must be published AND not depublished
            // This ensures depublished entities from OTHER organizations are never visible via published bypass
            $orgConditions->add(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull($publishedColumn),
                    $qb->expr()->lte($publishedColumn, $qb->createNamedParameter($now)),
                    // Depublished check: must be NULL (never depublished) OR in the future (not yet depublished)
                    $qb->expr()->orX(
                        $qb->expr()->isNull($depublishedColumn),
                        $qb->expr()->gt($depublishedColumn, $qb->createNamedParameter($now))
                    )
                )
            );
        }

        // Include NULL organisation entities for admins (legacy data)
        if ($isAdmin === true && $allowNullOrg === true) {
            $orgConditions->add($qb->expr()->isNull($organisationColumn));
        }

        // Apply the conditions
        $qb->andWhere($orgConditions);

    }//end applyOrganisationFilter()


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
        // Only set organisation if the entity has an organisation property
        if (!method_exists($entity, 'getOrganisation') || !method_exists($entity, 'setOrganisation')) {
            return;
        }

        // SECURITY: Always use active organisation from session, ignore frontend input
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
        // Only set owner if the entity has an owner property
        if (!method_exists($entity, 'getOwner') || !method_exists($entity, 'setOwner')) {
            return;
        }

        // Only set owner if not already set (allow explicit owner assignment)
        if ($entity->getOwner() !== null && $entity->getOwner() !== '') {
            return;
        }

        // Get current user from session
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
        // Check if entity has organisation property
        if (!method_exists($entity, 'getOrganisation')) {
            return;
        }

        $entityOrgUuid = $entity->getOrganisation();
        $activeOrgUuid = $this->getActiveOrganisationUuid();

        // If entity has no organisation set, allow it
        if ($entityOrgUuid === null) {
            return;
        }

        // Verify the organisations match (applies to everyone including admins)
        if ($entityOrgUuid !== $activeOrgUuid) {
            throw new \Exception(
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
     * @param string $action      The action to check (create, read, update, delete)
     * @param string $entityType  The type of entity (e.g., 'schema', 'register', 'configuration')
     *
     * @return bool True if user has permission, false otherwise
     */
    protected function hasRbacPermission(string $action, string $entityType): bool
    {
        // Admins always have all permissions
        if ($this->isCurrentUserAdmin() === true) {
            return true;
        }

        // Get current user
        $userId = $this->getCurrentUserId();
        if ($userId === null) {
            // No user logged in, deny access
            return false;
        }

        // Get active organisation
        if (isset($this->organisationService) === false) {
            // No organisation service, allow access (backward compatibility)
            return true;
        }

        $activeOrg = $this->organisationService->getActiveOrganisation();
        if ($activeOrg === null) {
            // No active organisation, deny access
            return false;
        }

        // Check if user is in the organisation's users list
        $orgUsers = $activeOrg->getUserIds();
        if (is_array($orgUsers) === true && in_array($userId, $orgUsers) === true) {
            // User is explicitly listed in the organisation - check authorization
        } else {
            // User is not in the organisation
            return false;
        }

        // Get user's groups
        if (isset($this->groupManager) === false) {
            // No group manager, allow access (backward compatibility)
            return true;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $userGroups = $this->groupManager->getUserGroupIds($user);

        // Get organisation's authorization configuration
        $authorization = $activeOrg->getAuthorization();
        if ($authorization === null || empty($authorization) === true) {
            // No RBAC configured, allow access (backward compatibility)
            return true;
        }

        // Check if the entity type exists in authorization
        if (isset($authorization[$entityType]) === false) {
            // Entity type not in authorization, allow access (backward compatibility)
            return true;
        }

        // Check if the action exists for this entity type
        if (isset($authorization[$entityType][$action]) === false) {
            // Action not configured, allow access (backward compatibility)
            return true;
        }

        $allowedGroups = $authorization[$entityType][$action];

        // If the array is empty, it means no restrictions (allow all)
        if (empty($allowedGroups) === true) {
            return true;
        }

        // Check if user is in any of the allowed groups
        foreach ($userGroups as $groupId) {
            if (in_array($groupId, $allowedGroups) === true) {
                return true;
            }
        }

        // Check for wildcard group
        if (in_array('*', $allowedGroups) === true) {
            return true;
        }

        // No matching permission found
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
        if ($this->hasRbacPermission($action, $entityType) === false) {
            throw new \Exception(
                "Access denied: You do not have permission to {$action} {$entityType} entities.",
                Response::HTTP_FORBIDDEN
            );
        }

    }//end verifyRbacPermission()


}//end trait


