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
 * - The mapper must inject OrganisationService
 * - The mapper must inject IGroupManager (for RBAC)
 * - The mapper must inject IUserSession (for current user)
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
        if (!isset($this->organisationService)) {
            return null;
        }

        $activeOrg = $this->organisationService->getActiveOrganisation();
        return $activeOrg ? $activeOrg->getUuid() : null;

    }//end getActiveOrganisationUuid()


    /**
     * Get the current user ID.
     *
     * @return string|null The current user ID or null if no user is logged in
     */
    protected function getCurrentUserId(): ?string
    {
        if (!isset($this->userSession)) {
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

        if (!isset($this->groupManager)) {
            return false;
        }

        return $this->groupManager->isAdmin($userId);

    }//end isCurrentUserAdmin()


    /**
     * Apply organisation filter to a query builder.
     *
     * Filters results to only show entities from the active organisation.
     * This applies to ALL users including admins - admins must set an active
     * organisation to work within that organisational context.
     *
     * @param IQueryBuilder $qb              The query builder
     * @param string        $columnName      The column name for organisation (default: 'organisation')
     * @param bool          $allowNullOrg    Whether to include entities with null organisation
     *
     * @return void
     */
    protected function applyOrganisationFilter(IQueryBuilder $qb, string $columnName='organisation', bool $allowNullOrg=false): void
    {
        $activeOrgUuid = $this->getActiveOrganisationUuid();
        if ($activeOrgUuid === null) {
            // No active organisation, return empty results
            // Admins must also have an active organisation set
            $qb->andWhere($qb->expr()->eq('1', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
            return;
        }

        if ($allowNullOrg) {
            // Allow entities with matching organisation OR null organisation
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->eq($columnName, $qb->createNamedParameter($activeOrgUuid, IQueryBuilder::PARAM_STR)),
                    $qb->expr()->isNull($columnName)
                )
            );
        } else {
            // Only allow entities with matching organisation
            $qb->andWhere(
                $qb->expr()->eq($columnName, $qb->createNamedParameter($activeOrgUuid, IQueryBuilder::PARAM_STR))
            );
        }

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
        if (!isset($this->organisationService)) {
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
        if (is_array($orgUsers) && in_array($userId, $orgUsers)) {
            // User is explicitly listed in the organisation - check authorization
        } else {
            // User is not in the organisation
            return false;
        }

        // Get user's groups
        if (!isset($this->groupManager)) {
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
        if ($authorization === null || empty($authorization)) {
            // No RBAC configured, allow access (backward compatibility)
            return true;
        }

        // Check if the entity type exists in authorization
        if (!isset($authorization[$entityType])) {
            // Entity type not in authorization, allow access (backward compatibility)
            return true;
        }

        // Check if the action exists for this entity type
        if (!isset($authorization[$entityType][$action])) {
            // Action not configured, allow access (backward compatibility)
            return true;
        }

        $allowedGroups = $authorization[$entityType][$action];

        // If the array is empty, it means no restrictions (allow all)
        if (empty($allowedGroups)) {
            return true;
        }

        // Check if user is in any of the allowed groups
        foreach ($userGroups as $groupId) {
            if (in_array($groupId, $allowedGroups)) {
                return true;
            }
        }

        // Check for wildcard group
        if (in_array('*', $allowedGroups)) {
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


