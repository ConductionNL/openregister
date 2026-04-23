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
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-55
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-56
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-57
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Permission evaluation requires per-action and per-role branching
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class PermissionHandler
{

    /**
     * Per-request cache for register authorization lookups.
     *
     * Maps register ID to its authorization array (or null if no authorization).
     * Avoids repeated DB queries when checking permissions for multiple schemas
     * in the same register within a single request.
     *
     * @var array<int, array|null>
     */
    private array $cachedRegisterAuth = [];

    /**
     * Per-request cache for register configuration (roles).
     *
     * Maps register ID to its configuration array.
     *
     * @var array<int, array|null>
     */
    private array $cachedRegisterConfig = [];

    /**
     * PermissionHandler constructor.
     *
     * @param IUserSession       $userSession        User session for getting current user.
     * @param IUserManager       $userManager        User manager for getting user objects.
     * @param IGroupManager      $groupManager       Group manager for checking user groups.
     * @param SchemaMapper       $schemaMapper       Mapper for schema operations.
     * @param MagicMapper        $objectEntityMapper Mapper for object entity operations.
     * @param LoggerInterface    $logger             Logger for permission auditing.
     * @param ContainerInterface $container          Container for lazy loading services.
     */
    public function __construct(
        private readonly IUserSession $userSession,
        private readonly IUserManager $userManager,
        private readonly IGroupManager $groupManager,
        private readonly SchemaMapper $schemaMapper,
        private readonly MagicMapper $objectEntityMapper,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container
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
     * @param Schema            $schema      The schema to check permissions for.
     * @param string            $action      The CRUD action (create, read, update, delete).
     * @param string|null       $userId      Optional user ID (defaults to current user).
     * @param string|null       $objectOwner Optional object owner for ownership check.
     * @param bool              $_rbac       Whether to apply RBAC checks (default: true).
     * @param ObjectEntity|null $object      Optional object entity for conditional authorization matching.
     *
     * @return bool True if user has permission, false otherwise
     *
     * @throws Exception If user session is invalid or user groups cannot be determined
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) RBAC permission checks require multiple conditional paths
     * @SuppressWarnings(PHPMD.NPathComplexity)      User/group/owner permission combinations create many paths
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC flag follows established API patterns
     */
    public function hasPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $_rbac=true,
        ?ObjectEntity $object=null
    ): bool {
        // If RBAC is disabled, always return true (bypass all permission checks).
        if ($_rbac === false) {
            return true;
        }

        // Resolve object context for conditional authorization matching.
        $objectData         = null;
        $objectOrganisation = null;
        $activeOrganisation = null;

        if ($object !== null) {
            $objectData         = $object->getObject();
            $objectOrganisation = $object->getOrganisation();
        }

        // Get the user's active organisation for $organisation variable resolution.
        try {
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
            $activeOrg           = $organisationService->getActiveOrganisation();
            if ($activeOrg !== null) {
                $activeOrganisation = $activeOrg->getUuid();
            }
        } catch (\Throwable $e) {
            // OrganisationService not available, conditional matching will be limited.
        }

        $authorization = $this->resolveAuthorization(schema: $schema);

        // Get current user if not provided.
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if ($user === null) {
                // For unauthenticated requests, check if 'public' group has permission.
                return $this->hasGroupPermission(
                    authorization: $authorization,
                    groupId: 'public',
                    action: $action,
                    userId: null,
                    userGroup: null,
                    objectOwner: $objectOwner,
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation,
                    activeOrganisation: $activeOrganisation
                );
            }

            $userId = $user->getUID();
        }

        // Get user object from user ID.
        $userObj = $this->userManager->get($userId);
        if ($userObj === null) {
            // User doesn't exist, treat as public.
            return $this->hasGroupPermission(
                authorization: $authorization,
                groupId: 'public',
                action: $action,
                userId: null,
                userGroup: null,
                objectOwner: $objectOwner,
                objectData: $objectData,
                objectOrganisation: $objectOrganisation,
                activeOrganisation: $activeOrganisation
            );
        }

        $userGroups = $this->groupManager->getUserGroupIds($userObj);

        // Check if user is admin (admin group always has all permissions).
        if (in_array('admin', $userGroups) === true) {
            return true;
        }

        // Check schema permissions for each user group.
        foreach ($userGroups as $groupId) {
            if ($this->hasGroupPermission(
                    authorization: $authorization,
                    groupId: $groupId,
                    action: $action,
                    userId: $userId,
                    objectOwner: $objectOwner,
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation,
                    activeOrganisation: $activeOrganisation
                ) === true
            ) {
                return true;
            }
        }//end foreach

        // Logged-in users should also have at least the same rights as 'public' users.
        if ($this->hasGroupPermission(
                authorization: $authorization,
                groupId: 'public',
                action: $action,
                userId: $userId,
                objectOwner: $objectOwner,
                objectData: $objectData,
                objectOrganisation: $objectOrganisation,
                activeOrganisation: $activeOrganisation
            ) === true
        ) {
            return true;
        }

        return false;
    }//end hasPermission()

    /**
     * Check permission and throw exception if not granted
     *
     * @param Schema            $schema      Schema to check permissions for.
     * @param string            $action      Action to check permission for.
     * @param string|null       $userId      User ID to check permissions for.
     * @param string|null       $objectOwner Object owner ID.
     * @param bool              $_rbac       Whether to enforce RBAC checks.
     * @param ObjectEntity|null $object      Optional object entity for conditional authorization matching.
     *
     * @return void
     *
     * @throws Exception If permission is not granted
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC flag follows established API patterns
     */
    public function checkPermission(
        Schema $schema,
        string $action,
        ?string $userId=null,
        ?string $objectOwner=null,
        bool $_rbac=true,
        ?ObjectEntity $object=null
    ): void {
        if ($this->hasPermission(
                schema: $schema,
                action: $action,
                userId: $userId,
                objectOwner: $objectOwner,
                _rbac: $_rbac,
                object: $object
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
     * @param array<array<string, mixed>> $objects       Array of objects to filter.
     * @param bool                        $_rbac         Whether to apply RBAC filtering.
     * @param bool                        $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return array[] Filtered array of objects
     *
     * @psalm-return list<array<string, mixed>>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Permission filtering requires multiple conditional checks
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags follow established API patterns
     */
    public function filterObjectsForPermissions(array $objects, bool $_rbac, bool $_multitenancy): array
    {
        $filteredObjects = [];
        $currentUser     = $this->userSession->getUser();
        $userId          = null;
        if ($currentUser !== null) {
            $userId = $currentUser->getUID();
        }

        $activeOrganisation = $this->getActiveOrganisationForContext();

        foreach ($objects as $object) {
            $self = $object['@self'] ?? [];

            // Check RBAC permissions if enabled.
            if ($_rbac === true && $userId !== null) {
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
                                _rbac: $_rbac
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
            if ($_multitenancy === true && $activeOrganisation !== null) {
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
     * @param array<string> $uuids         Array of object UUIDs to filter.
     * @param bool          $_rbac         Whether to apply RBAC filtering.
     * @param bool          $_multitenancy Whether to apply multitenancy filtering.
     *
     * @return string[] Filtered array of UUIDs
     *
     * @psalm-return list<string>
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) UUID filtering with permission checks requires multiple conditions
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  RBAC/multitenancy flags follow established API patterns
     */
    public function filterUuidsForPermissions(array $uuids, bool $_rbac, bool $_multitenancy): array
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
            if ($_rbac === true && $userId !== null) {
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
                                _rbac: $_rbac
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
            if ($_multitenancy === true && $activeOrganisation !== null) {
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
            // Use container to lazy load OrganisationService to avoid circular dependencies.
            $organisationService = $this->container->get('OCA\\OpenRegister\\Service\\OrganisationService');

            // Get active organisation including parent chain.
            $orgUuids = $organisationService->getUserActiveOrganisations();

            if (empty($orgUuids) === false) {
                // Return the first (primary) active organisation.
                return $orgUuids[0];
            }

            // Fallback: try to get just the active organisation.
            $activeOrg = $organisationService->getActiveOrganisation();
            if ($activeOrg !== null) {
                return $activeOrg->getUuid();
            }

            return null;
        } catch (Exception $e) {
            $this->logger->warning(
                message: '[PermissionHandler] Failed to get active organisation',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'error' => $e->getMessage(),
                ]
            );
            return null;
        }//end try
    }//end getActiveOrganisationForContext()

    /**
     * Check if a specific group has permission for a CRUD action on a schema
     *
     * Rules:
     * - Admin group always has all permissions
     * - Object owner always has all permissions for their specific objects
     * - If no authorization is set, everyone has permission
     * - If authorization is set but action is not specified, everyone has permission
     *
     * @param array|null  $authorization      The schema's authorization array
     * @param string      $groupId            The group ID to check
     * @param string      $action             The CRUD action (create, read, update, delete)
     * @param string|null $userId             Optional user ID for owner check
     * @param string|null $userGroup          Optional user group for admin check
     * @param string|null $objectOwner        Optional object owner for ownership check
     * @param array|null  $objectData         Optional object data for conditional matching
     * @param string|null $objectOrganisation Optional object organisation
     * @param string|null $activeOrganisation Optional active organisation UUID
     *
     * @return bool True if the group has permission
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function hasGroupPermission(
        ?array $authorization,
        string $groupId,
        string $action,
        ?string $userId=null,
        ?string $userGroup=null,
        ?string $objectOwner=null,
        ?array $objectData=null,
        ?string $objectOrganisation=null,
        ?string $activeOrganisation=null
    ): bool {
        // Admin group always has all permissions.
        if ($groupId === 'admin' || $userGroup === 'admin') {
            return true;
        }

        // Object owner always has all permissions for their specific objects.
        if ($userId !== null && $objectOwner !== null && $objectOwner === $userId) {
            return true;
        }

        // If no authorization is set, everyone has all permissions.
        if (empty($authorization) === true) {
            return true;
        }

        // If action is not specified in authorization, everyone has permission.
        if (isset($authorization[$action]) === false) {
            return true;
        }

        // Check each authorization entry for this action.
        foreach ($authorization[$action] as $entry) {
            // Simple string entry: direct group match.
            if (is_string($entry) === true) {
                if ($entry === $groupId) {
                    return true;
                }

                continue;
            }

            // Complex entry with match conditions.
            if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
                // If no match conditions, the group match alone is sufficient.
                if (isset($entry['match']) === false || empty($entry['match']) === true) {
                    return true;
                }

                // Evaluate all match conditions (all must pass).
                if ($this->evaluateMatchConditions(
                    conditions: $entry['match'],
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation,
                    activeOrganisation: $activeOrganisation
                ) === true
                ) {
                    return true;
                }
            }
        }//end foreach

        return false;
    }//end hasGroupPermission()

    /**
     * Evaluate match conditions from a conditional authorization entry
     *
     * Supports variable substitution:
     * - $organisation -> replaced with the user's active organisation UUID
     *
     * Supports special field prefixes:
     * - _organisation -> matches against the object's @self.organisation
     * - Other fields -> matched against the object data
     *
     * @param array       $conditions         Key-value pairs of field => expected value
     * @param array|null  $objectData         The object's data fields
     * @param string|null $objectOrganisation The object's @self.organisation
     * @param string|null $activeOrganisation The user's active organisation UUID
     *
     * @return bool True if all conditions are satisfied
     */
    public function evaluateMatchConditions(
        array $conditions,
        ?array $objectData,
        ?string $objectOrganisation,
        ?string $activeOrganisation
    ): bool {
        foreach ($conditions as $field => $expectedValue) {
            // Resolve $organisation variable in the expected value.
            if ($expectedValue === '$organisation') {
                if ($activeOrganisation === null) {
                    return false;
                }

                $expectedValue = $activeOrganisation;
            }

            // Get the actual value to compare against.
            // Regular field: match against object data; special _organisation field: match against @self.organisation.
            $actualValue = $objectData[$field] ?? null;
            if ($field === '_organisation') {
                $actualValue = $objectOrganisation;
            }

            // If the actual value is an array with an 'id' key (resolved relation), use the id.
            if (is_array($actualValue) === true && isset($actualValue['id']) === true) {
                $actualValue = $actualValue['id'];
            }

            // Compare values.
            if ($actualValue !== $expectedValue) {
                return false;
            }
        }//end foreach

        return true;
    }//end evaluateMatchConditions()

    /**
     * Get all groups that have permission for a specific action
     *
     * @param array|null $authorization The schema's authorization array
     * @param string     $action        The CRUD action to check
     *
     * @return array Array of group IDs that have permission, or empty array if all groups have permission
     */
    public function getAuthorizedGroups(?array $authorization, string $action): array
    {
        // If no authorization is set, return empty array (meaning all groups).
        if (empty($authorization) === true) {
            return [];
        }

        // If action is not specified, return empty array (meaning all groups).
        if (isset($authorization[$action]) === false) {
            return [];
        }

        // Return the specific groups that have permission.
        return $authorization[$action] ?? [];
    }//end getAuthorizedGroups()

    /**
     * Resolve the effective authorization for a schema.
     *
     * If the schema has its own authorization block, use it directly.
     * If not, fall back to the parent register's authorization block.
     * Role references in the authorization are expanded to action-level permissions.
     *
     * @param Schema $schema The schema to resolve authorization for.
     *
     * @return array|null The effective authorization array, or null if none configured.
     */
    public function resolveAuthorization(Schema $schema): ?array
    {
        $authorization = $schema->getAuthorization();

        // If schema has its own authorization, expand roles and return.
        if (empty($authorization) === false) {
            return $this->expandRoles(authorization: $authorization, schema: $schema);
        }

        // Fall back to register authorization.
        $register = $this->getRegisterForSchema(schema: $schema);
        if ($register === null) {
            return null;
        }

        $registerAuth = $this->getRegisterAuthorization(registerId: $register->getId());
        if (empty($registerAuth) === false) {
            return $this->expandRoles(authorization: $registerAuth, schema: $schema);
        }

        return null;
    }//end resolveAuthorization()

    /**
     * Get the parent register for a schema.
     *
     * Uses RegisterMapper::getFirstRegisterWithSchema() to find the register
     * that contains the given schema.
     *
     * @param Schema $schema The schema to find the register for.
     *
     * @return Register|null The parent register, or null if not found.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-55
     */
    private function getRegisterForSchema(Schema $schema): ?Register
    {
        try {
            $registerMapper = $this->container->get(RegisterMapper::class);
            $registerId     = $registerMapper->getFirstRegisterWithSchema($schema->getId());
            if ($registerId === null) {
                return null;
            }

            return $registerMapper->find($registerId);
        } catch (\Throwable $e) {
            $this->logger->warning(
                message: '[PermissionHandler] Failed to get register for schema',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schema->getId(),
                    'error'    => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end getRegisterForSchema()

    /**
     * Get register authorization with per-request caching.
     *
     * Caches the authorization array for each register ID to avoid
     * repeated database lookups within a single request.
     *
     * @param int $registerId The register ID to get authorization for.
     *
     * @return array|null The register's authorization array.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-56
     */
    private function getRegisterAuthorization(int $registerId): ?array
    {
        if (array_key_exists($registerId, $this->cachedRegisterAuth) === true) {
            return $this->cachedRegisterAuth[$registerId];
        }

        try {
            $registerMapper = $this->container->get(RegisterMapper::class);
            $register       = $registerMapper->find($registerId);
            $auth           = $register->getAuthorization();

            $this->cachedRegisterAuth[$registerId]   = $auth;
            $this->cachedRegisterConfig[$registerId] = $register->getConfiguration();

            return $auth;
        } catch (\Throwable $e) {
            $this->cachedRegisterAuth[$registerId] = null;
            return null;
        }
    }//end getRegisterAuthorization()

    /**
     * Get register configuration with per-request caching.
     *
     * @param int $registerId The register ID to get configuration for.
     *
     * @return array|null The register's configuration array.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-57
     */
    private function getRegisterConfiguration(int $registerId): ?array
    {
        if (array_key_exists($registerId, $this->cachedRegisterConfig) === true) {
            return $this->cachedRegisterConfig[$registerId];
        }

        // Calling getRegisterAuthorization populates both caches.
        $this->getRegisterAuthorization(registerId: $registerId);

        return $this->cachedRegisterConfig[$registerId] ?? null;
    }//end getRegisterConfiguration()

    /**
     * Expand role references in an authorization block to action-level permissions.
     *
     * If the authorization contains a 'roles' key mapping role names to group arrays,
     * this method resolves each role's actions from the parent register's configuration
     * and merges the resulting group-to-action mappings into the authorization.
     *
     * Example input:
     *   authorization: { "roles": { "viewer": ["public"], "editor": ["behandelaars"] } }
     *   register roles: [{ name: "viewer", actions: ["read"] }, { name: "editor", actions: ["read","create","update"] }]
     *
     * Example output:
     *   { "read": ["public", "behandelaars"], "create": ["behandelaars"], "update": ["behandelaars"] }
     *
     * @param array  $authorization The authorization block to expand.
     * @param Schema $schema        The schema (used to find parent register for role definitions).
     *
     * @return array The authorization with roles expanded to action-level entries.
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function expandRoles(array $authorization, Schema $schema): array
    {
        if (isset($authorization['roles']) === false || is_array($authorization['roles']) === false) {
            return $authorization;
        }

        $roleAssignments = $authorization['roles'];
        unset($authorization['roles']);

        // Get role definitions from the parent register.
        $roleDefinitions = $this->getRoleDefinitionsForSchema(schema: $schema);
        if (empty($roleDefinitions) === true) {
            $this->logger->warning(
                message: '[PermissionHandler] Schema has role references but register has no role definitions',
                context: [
                    'file'     => __FILE__,
                    'line'     => __LINE__,
                    'schemaId' => $schema->getId(),
                ]
            );
            return $authorization;
        }

        // Build a lookup map: roleName => actions array.
        $roleMap = [];
        foreach ($roleDefinitions as $roleDef) {
            if (isset($roleDef['name']) === true && isset($roleDef['actions']) === true) {
                $roleMap[$roleDef['name']] = $roleDef['actions'];
            }
        }

        // Expand each role assignment into action-level entries.
        foreach ($roleAssignments as $roleName => $groups) {
            if (isset($roleMap[$roleName]) === false) {
                $this->logger->warning(
                    message: '[PermissionHandler] Unknown role name referenced in authorization',
                    context: [
                        'file'     => __FILE__,
                        'line'     => __LINE__,
                        'roleName' => $roleName,
                        'schemaId' => $schema->getId(),
                    ]
                );
                continue;
            }

            $actions = $roleMap[$roleName];
            foreach ($actions as $action) {
                if (isset($authorization[$action]) === false) {
                    $authorization[$action] = [];
                }

                // Merge groups, avoiding duplicates.
                foreach ((array) $groups as $group) {
                    if (in_array($group, $authorization[$action], true) === false) {
                        $authorization[$action][] = $group;
                    }
                }
            }
        }//end foreach

        return $authorization;
    }//end expandRoles()

    /**
     * Get role definitions for a schema from its parent register.
     *
     * Looks up the parent register's configuration.roles array.
     *
     * @param Schema $schema The schema to find role definitions for.
     *
     * @return array Array of role definitions, each with 'name', 'description', 'actions'.
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-57
     */
    private function getRoleDefinitionsForSchema(Schema $schema): array
    {
        $register = $this->getRegisterForSchema(schema: $schema);
        if ($register === null) {
            return [];
        }

        $config = $this->getRegisterConfiguration(registerId: $register->getId());
        if ($config === null || isset($config['roles']) === false) {
            return [];
        }

        return $config['roles'];
    }//end getRoleDefinitionsForSchema()
}//end class
