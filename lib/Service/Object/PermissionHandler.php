<?php

/**
 * PermissionHandler - RBAC and Permission Management Handler
 *
 * Handles all permission checking, RBAC enforcement, and multi-tenancy filtering.
 * This handler centralizes authorization logic that was previously scattered
 * throughout ObjectService, making security policies more maintainable.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\ObjectEntityMapper;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * PermissionHandler class
 *
 * Handles permission operations including:
 * - RBAC permission checking
 * - User and group authorization
 * - Multi-tenancy filtering
 * - Object ownership verification
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service\Objects
 */
class PermissionHandler
{


    /**
     * PermissionHandler constructor.
     *
     * @param IUserSession        $userSession         User session for getting current user.
     * @param IUserManager        $userManager         User manager for getting user objects.
     * @param IGroupManager       $groupManager        Group manager for checking user groups.
     * @param SchemaMapper        $schemaMapper        Mapper for schema operations.
     * @param ObjectEntityMapper  $objectEntityMapper  Mapper for object entity operations.
     * @param OrganisationService $organisationService Service for organisation operations.
     * @param LoggerInterface     $logger              Logger for permission auditing.
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly SchemaMapper $schemaMapper,
        private readonly ObjectEntityMapper $objectEntityMapper,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()


    /**
     * Check if current user has permission to perform action on schema
     *
     * Implements the RBAC permission checking logic:
     * - Admin group always has all permissions
     * - Object owner always has all permissions for their specific objects
     * - If no authorization configured, all users have all permissions
     * - Otherwise, check if user's groups match the required groups for the action
     *
     * TODO: Implement property-level RBAC checks
     * Properties can have their own authorization arrays that provide fine-grained access control.
     *
     * @param Schema      $schema      The schema to check permissions for.
     * @param string      $action      The CRUD action (create, read, update, delete).
     * @param string|null $userId      Optional user ID (defaults to current user).
     * @param string|null $objectOwner Optional object owner for ownership check.
     * @param bool        $rbac        Whether to apply RBAC checks (default: true).
     *
     * @return bool True if user has permission, false otherwise
     *
     * @throws Exception If user session is invalid or user groups cannot be determined
     */
    public function hasPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $rbac=true
    ): bool {
        // If RBAC is disabled, always return true (bypass all permission checks).
        if ($rbac === false) {
            return true;
        }

        // Get current user if not provided.
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // For unauthenticated requests, check if 'public' group has permission.
                return $schema->hasPermission(
                    groupId: 'public',
                    action: $action,
                    userId: null,
                    userGroup: null,
                    objectOwner: $objectOwner
                );
            }

            $userId = $user->getUID();
        }

        // Get user object from user ID.
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            // User doesn't exist, treat as public.
            return $schema->hasPermission(
                groupId: 'public',
                action: $action,
                userId: null,
                userGroup: null,
                objectOwner: $objectOwner
            );
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);

        // Check if user is admin (admin group always has all permissions).
        if (in_array('admin', $userGroups) === true) {
            return true;
        }

        // Object owner permission check is now handled in schema->hasPermission() call below.
        // Check schema permissions for each user group.
        foreach ($userGroups as $groupId) {
            $isAdmin    = in_array('admin', $userGroups) === true;
            $adminGroup = null;
            if ($isAdmin === true) {
                $adminGroup = 'admin';
            }

            if ($schema->hasPermission(
                groupId: $groupId,
                action: $action,
                userId: $userId,
                userGroup: $adminGroup,
                objectOwner: $objectOwner
            ) === true
            ) {
                return true;
            }
        }

        return false;

    }//end hasPermission()


    /**
     * Check permission and throw exception if not granted
     *
     * @param Schema      $schema      Schema to check permissions for.
     * @param string      $action      Action to check permission for.
     * @param string|null $userId      User ID to check permissions for.
     * @param string|null $objectOwner Object owner ID.
     * @param bool        $rbac        Whether to enforce RBAC checks.
     *
     * @return void
     *
     * @throws Exception If permission is not granted
     */
    public function checkPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $rbac=true
    ): void {
        if ($this->hasPermission(
            schema: $schema,
            action: $action,
            userId: $userId,
            objectOwner: $objectOwner,
            rbac: $rbac
        ) === false
        ) {
            $user     = $this->userSession->getUser();
            $userName = 'Anonymous';
            if ($user !== null) {
                $userName = $user->getDisplayName();
            }

            throw new Exception(
                "User '{$userName}' does not have permission to '{$action}' objects in schema '{$schema->getTitle()}'"
            );
        }

    }//end checkPermission()


    /**
     * Filter objects array based on RBAC and multi-tenancy permissions
     *
     * Removes objects from the array that the current user doesn't have permission to access
     * or that belong to a different organization in multi-tenant mode.
     *
     * @param array<array<string, mixed>> $objects      Array of objects to filter.
     * @param bool                        $rbac         Whether to apply RBAC filtering.
     * @param bool                        $multitenancy Whether to apply multitenancy filtering.
     *
     * @return array<array<string, mixed>> Filtered array of objects
     */
    public function filterObjectsForPermissions(array $objects, bool $rbac, bool $multitenancy): array
    {
        $filteredObjects = [];
        $currentUser     = $this->userSession->getUser();
        if ($currentUser !== null) {
            $userId = $currentUser->getUID();
        } else {
            $userId = null;
        }

        $activeOrganisation = $this->getActiveOrganisationForContext();

        foreach ($objects as $object) {
            $self = $object['@self'] ?? [];

            // Check RBAC permissions if enabled.
            if ($rbac === true && $userId !== null) {
                $objectOwner  = $self['owner'] ?? null;
                $objectSchema = $self['schema'] ?? null;

                if ($objectSchema !== null) {
                    try {
                        $schema = $this->schemaMapper->find($objectSchema);
                        // TODO: Add property-level RBAC check for 'create' action here.
                        // Check individual property permissions before allowing property values to be set.
                        if ($this->hasPermission(
                            schema: $schema,
                            action: 'create',
                            userId: $userId,
                            objectOwner: $objectOwner,
                            rbac: $rbac
                        ) === false
                        ) {
                            continue;
                            // Skip this object if user doesn't have permission.
                        }
                    } catch (Exception $e) {
                        // Skip objects with invalid schemas.
                        continue;
                    }//end try
                }//end if
            }//end if

            // Check multi-organization filtering if enabled.
            if ($multitenancy === true && $activeOrganisation !== null) {
                $objectOrganisation = $self['organisation'] ?? null;
                if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
                    continue;
                    // Skip objects from different organizations.
                }
            }

            $filteredObjects[] = $object;
        }//end foreach

        return $filteredObjects;

    }//end filterObjectsForPermissions()


    /**
     * Filter UUIDs based on RBAC and multi-tenancy permissions
     *
     * Takes an array of UUIDs, loads the corresponding objects, and filters them
     * based on current user permissions and organization context.
     *
     * @param array<string> $uuids        Array of object UUIDs to filter.
     * @param bool          $rbac         Whether to apply RBAC filtering.
     * @param bool          $multitenancy Whether to apply multitenancy filtering.
     *
     * @return array<string> Filtered array of UUIDs
     */
    public function filterUuidsForPermissions(array $uuids, bool $rbac, bool $multitenancy): array
    {
        $filteredUuids = [];
        $currentUser   = $this->userSession->getUser();
        $userId        = null;
        if ($currentUser !== null) {
            $userId = $currentUser->getUID();
        }

        $activeOrganisation = $this->getActiveOrganisationForContext();

        // Get objects for permission checking.
        $objects = $this->objectEntityMapper->findAll(ids: $uuids, includeDeleted: true);

        foreach ($objects as $object) {
            $objectUuid = $object->getUuid();

            // Check RBAC permissions if enabled.
            if ($rbac === true && $userId !== null) {
                $objectOwner  = $object->getOwner();
                $objectSchema = $object->getSchema();

                if ($objectSchema !== null) {
                    try {
                        $schema = $this->schemaMapper->find($objectSchema);

                        // TODO: Add property-level RBAC check for 'delete' action here
                        // Check if user has permission to delete objects with specific property values.
                        if ($this->hasPermission(
                            schema: $schema,
                            action: 'delete',
                            userId: $userId,
                            objectOwner: $objectOwner,
                            rbac: $rbac
                        ) === false
                        ) {
                            continue;
                            // Skip this object - no permission.
                        }
                    } catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
                        // Skip this object - schema not found.
                        continue;
                    }//end try
                }//end if
            }//end if

            // Check multi-organization permissions if enabled.
            if ($multitenancy === true && $activeOrganisation !== null) {
                $objectOrganisation = $object->getOrganisation();

                if ($objectOrganisation !== null && $objectOrganisation !== $activeOrganisation) {
                    // Skip this object - different organization.
                    continue;
                }
            }

            if ($objectUuid !== null) {
                $filteredUuids[] = $objectUuid;
            }
        }//end foreach

        return array_values(array_filter($filteredUuids, fn($uuid) => $uuid !== null));

    }//end filterUuidsForPermissions()


    /**
     * Get the active organisation UUID for the current context
     *
     * @return string|null The active organisation UUID or null if none set
     */
    public function getActiveOrganisationForContext(): ?string
    {
        try {
            $activeOrganisation = null;
            // TODO: Get without service
            if (is_array($activeOrganisation) === true && isset($activeOrganisation['uuid']) === true) {
                return $activeOrganisation['uuid'];
            }

            if (is_string($activeOrganisation) === true) {
                return $activeOrganisation;
            }

            return null;
        } catch (Exception $e) {
            return null;
        }

    }//end getActiveOrganisationForContext()


}//end class
