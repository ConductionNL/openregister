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
     * - Published object bypass for multi-tenancy (objects table only)
     * - Admin override capabilities
     * - System default organisation special handling
     * - NULL organisation legacy data access for admins
     * - Unauthenticated request handling
     *
     * Features:
     * 1. Hierarchical Access: Users see entities from their active org AND parent orgs
     * 2. Published Objects: Can bypass multi-tenancy if configured (objects table only)
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
     * @param bool          $enablePublished Whether to enable published object bypass (objects table only)
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
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] Multitenancy disabled via parameter, skipping filter');
            }
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
                    if (isset($this->logger) === true) {
                        $this->logger->debug('[MultiTenancyTrait] Multitenancy disabled in config, skipping filter');
                    }
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
        
        // Check if published objects should bypass multi-tenancy (objects table only)
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
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] No active organisation', [
                    'publishedBypassEnabled' => $publishedBypassEnabled
                ]);
            }
            
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

            // Include published objects if bypass is enabled (objects table only)
            if ($publishedBypassEnabled === true && $enablePublished === true) {
                $now = (new \DateTime())->format('Y-m-d H:i:s');
                $publishedColumn = $tableAlias ? $tableAlias . '.published' : 'published';
                $depublishedColumn = $tableAlias ? $tableAlias . '.depublished' : 'depublished';
                
                $orgConditions->add(
                    $qb->expr()->andX(
                        $qb->expr()->isNotNull($publishedColumn),
                        $qb->expr()->lte($publishedColumn, $qb->createNamedParameter($now)),
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
        
        // CASE 2: Active organisation(s) set - check for system default organisation
        // Get default organisation UUID from configuration (not deprecated is_default column)
        $systemDefaultOrgUuid = null;
        if (isset($this->organisationService) === true) {
            $systemDefaultOrgUuid = $this->organisationService->getDefaultOrganisationId();
        }

        // Check if active organisation is the system default
        $isSystemDefaultOrg = $systemDefaultOrgUuid !== null && 
                             in_array($systemDefaultOrgUuid, $activeOrganisationUuids);

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
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] Admin override enabled, no filtering');
            }
            return;
        }
        
        // If admin has system default organisation, they see everything (backward compatibility)
        if ($isAdmin === true && $isSystemDefaultOrg === true) {
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] Admin with default org, no filtering');
            }
            return;
        }

        // Build organisation filter conditions
        $orgConditions = $qb->expr()->orX();

        // Include entities from active organisation(s) and parents
        $orgConditions->add(
            $qb->expr()->in($organisationColumn, $qb->createNamedParameter($activeOrganisationUuids, IQueryBuilder::PARAM_STR_ARRAY))
        );
        
        if (isset($this->logger) === true) {
            $this->logger->debug('[MultiTenancyTrait] Added organisation filter', [
                'organisationCount' => count($activeOrganisationUuids),
                'columnName' => $columnName,
                'tableAlias' => $tableAlias
            ]);
        }

        // Include published objects if bypass is enabled (objects table only)
        if ($publishedBypassEnabled === true && $enablePublished === true) {
            $now = (new \DateTime())->format('Y-m-d H:i:s');
            $publishedColumn = $tableAlias ? $tableAlias . '.published' : 'published';
            $depublishedColumn = $tableAlias ? $tableAlias . '.depublished' : 'depublished';
            
            $orgConditions->add(
                $qb->expr()->andX(
                    $qb->expr()->isNotNull($publishedColumn),
                    $qb->expr()->lte($publishedColumn, $qb->createNamedParameter($now)),
                    $qb->expr()->orX(
                        $qb->expr()->isNull($depublishedColumn),
                        $qb->expr()->gt($depublishedColumn, $qb->createNamedParameter($now))
                    )
                )
            );
            
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] Added published objects bypass');
            }
        }

        // Include NULL organisation entities for admins with default org (legacy data)
        if ($isSystemDefaultOrg === true && $isAdmin === true && $allowNullOrg === true) {
            $orgConditions->add($qb->expr()->isNull($organisationColumn));
            
            if (isset($this->logger) === true) {
                $this->logger->debug('[MultiTenancyTrait] Added NULL org access for admin with default org');
            }
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
        if ($this->isCurrentUserAdmin()) {
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
        if (!$this->hasRbacPermission($action, $entityType)) {
            throw new \Exception(
                "Access denied: You do not have permission to {$action} {$entityType} entities.",
                Response::HTTP_FORBIDDEN
            );
        }

    }//end verifyRbacPermission()


}//end trait


