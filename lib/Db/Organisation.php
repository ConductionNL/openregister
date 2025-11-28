<?php
/**
 * OpenRegister Organisation Entity
 *
 * This file contains the class for the Organisation entity.
 * The Organisation entity manages multi-tenancy in OpenRegister by linking users
 * to organisations and providing organisational context for all data.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Organisation Entity
 *
 * Manages organisational data and user relationships for multi-tenancy.
 * Each organisation can have multiple users, and users can belong to multiple organisations.
 * Organisations can define custom roles/groups for role-based access control (RBAC).
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getUsers()
 * @method void setUsers(?array $users)
 * @method array|null getGroups()
 * @method static setGroups(?array $groups)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method bool|null getActive()
 * @method static setActive(mixed $active)
 * @method int|null getStorageQuota()
 * @method void setStorageQuota(?int $storageQuota)
 * @method int|null getBandwidthQuota()
 * @method void setBandwidthQuota(?int $bandwidthQuota)
 * @method int|null getRequestQuota()
 * @method void setRequestQuota(?int $requestQuota)
 * @method array|null getAuthorization()
 * @method static setAuthorization(?array $authorization)
 * @method string|null getParent()
 * @method static setParent(?string $parent)
 */
class Organisation extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the organisation
     *
     * @var string|null UUID of the organisation
     */
    protected ?string $uuid = null;

    /**
     * Slug of the organisation (URL-friendly identifier)
     *
     * @var string|null Slug of the organisation
     */
    protected ?string $slug = null;

    /**
     * Name of the organisation
     *
     * @var string|null The organisation name
     */
    protected ?string $name = null;

    /**
     * Description of the organisation
     *
     * @var string|null The organisation description
     */
    protected ?string $description = null;

    /**
     * Array of user IDs that belong to this organisation
     *
     * @var array|null Array of user IDs (Nextcloud user IDs)
     */
    protected ?array $users = [];

    /**
     * Array of Nextcloud group IDs assigned to this organisation
     * Stored as simple array of group ID strings for efficiency
     *
     * @var array|null Array of group IDs (strings)
     */
    protected ?array $groups = [];

    /**
     * Owner of the organisation (user ID)
     *
     * @var string|null The user ID who owns this organisation
     */
    protected ?string $owner = null;

    /**
     * Date when the organisation was created
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Date when the organisation was last updated
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;

    /**
     * Whether this organisation is active
     *
     * @var boolean|null Whether this organisation is active
     */
    protected ?bool $active = true;

    /**
     * Storage quota allocated to this organisation in bytes
     * NULL = unlimited storage
     *
     * @var integer|null Storage quota in bytes
     */
    protected ?int $storageQuota = null;

    /**
     * Bandwidth/traffic quota allocated to this organisation in bytes per month
     * NULL = unlimited bandwidth
     *
     * @var integer|null Bandwidth quota in bytes per month
     */
    protected ?int $bandwidthQuota = null;

    /**
     * API request quota allocated to this organisation per day
     * NULL = unlimited API requests
     *
     * @var integer|null API request quota per day
     */
    protected ?int $requestQuota = null;

    /**
     * Authorization rules for this organisation
     *
     * Hierarchical structure defining CRUD permissions per entity type
     * and special rights. Uses singular entity names for easier authorization checks.
     * Structure:
     * {
     *   "register": {"create": [], "read": [], "update": [], "delete": []},
     *   "schema": {"create": [], "read": [], "update": [], "delete": []},
     *   "object": {"create": [], "read": [], "update": [], "delete": []},
     *   "view": {"create": [], "read": [], "update": [], "delete": []},
     *   "agent": {"create": [], "read": [], "update": [], "delete": []},
     *   "object_publish": [],
     *   "agent_use": [],
     *   "dashboard_view": [],
     *   "llm_use": []
     * }
     *
     * @var array|null Authorization rules as JSON structure
     */
    protected ?array $authorization = null;

    /**
     * UUID of parent organisation for hierarchical organisation structures
     *
     * Enables parent-child relationships where children inherit access
     * to parent resources (schemas, registers, configurations, etc.).
     * NULL indicates this is a root-level organisation with no parent.
     *
     * @var string|null Parent organisation UUID
     */
    protected ?string $parent = null;

    /**
     * Array of child organisation UUIDs (computed, not stored in database)
     *
     * This property is populated on-demand via OrganisationMapper::findChildrenChain()
     * and is used primarily for UI display and administrative purposes.
     * Children can view parent resources but parents cannot view child resources.
     *
     * @var array|null Array of child organisation UUIDs
     */
    protected ?array $children = null;


    /**
     * Organisation constructor
     *
     * Sets up the entity type mappings for proper database handling.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('slug', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('users', 'json');
        $this->addType('groups', 'json');
        $this->addType('owner', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
        $this->addType('active', 'boolean');
        $this->addType('storage_quota', 'integer');
        $this->addType('bandwidth_quota', 'integer');
        $this->addType('request_quota', 'integer');
        $this->addType('authorization', 'json');
        $this->addType('parent', 'string');

    }//end __construct()


    /**
     * Validate UUID format
     *
     * @param string $uuid The UUID to validate
     *
     * @return bool True if UUID format is valid
     */
    public static function isValidUuid(string $uuid): bool
    {
        try {
            Uuid::fromString($uuid);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }

    }//end isValidUuid()


    /**
     * Add a user to this organisation
     *
     * @param string $userId The Nextcloud user ID to add
     *
     * @return static Returns this organisation for method chaining
     */
    public function addUser(string $userId): static
    {
        if ($this->users === null) {
            $this->users = [];
        }

        if (in_array($userId, $this->users) === false) {
            $this->users[] = $userId;
            $this->markFieldUpdated('users');
        }

        return $this;

    }//end addUser()


    /**
     * Remove a user from this organisation
     *
     * @param string $userId The Nextcloud user ID to remove
     *
     * @return static Returns this organisation for method chaining
     */
    public function removeUser(string $userId): static
    {
        if ($this->users === null) {
            return $this;
        }

        $originalCount = count($this->users);
        $this->users   = array_values(
                array_filter(
                $this->users,
                function ($id) use ($userId) {
                    return $id !== $userId;
                }
                )
                );

        // Only mark as updated if a user was actually removed.
        if (count($this->users) !== $originalCount) {
            $this->markFieldUpdated('users');
        }

        return $this;

    }//end removeUser()


    /**
     * Check if a user belongs to this organisation
     *
     * @param string $userId The Nextcloud user ID to check
     *
     * @return bool True if user belongs to this organisation
     */
    public function hasUser(string $userId): bool
    {
        return $this->users !== null && in_array($userId, $this->users);

    }//end hasUser()


    /**
     * Get all users in this organisation
     *
     * @return array Array of user IDs
     */
    public function getUserIds(): array
    {
        return $this->users ?? [];

    }//end getUserIds()


    /**
     * Add a role to this organisation
     *
     * @param array $role The role definition to add (e.g., ['id' => 'admin', 'name' => 'Administrator', 'permissions' => [...]])
     *
     * @return static Returns this organisation for method chaining
     */
    public function addRole(array $role): static
    {
        //
        if ($this->roles === null) {
            //
            $this->roles = [];
        }

        // Check if role with same ID already exists.
        $roleId = $role['id'] ?? $role['name'] ?? null;
        if ($roleId !== null) {
            $exists = false;
            //
            foreach ($this->roles as $existingRole) {
                $existingId = $existingRole['id'] ?? $existingRole['name'] ?? null;
                if ($existingId === $roleId) {
                    $exists = true;
                    break;
                }
            }

            if ($exists === false) {
                //
                $this->roles[] = $role;
            }
        }

        return $this;

    }//end addRole()


    /**
     * Remove a role from this organisation
     *
     * @param string $roleId The role ID or name to remove
     *
     * @return static Returns this organisation for method chaining
     */
    public function removeRole(string $roleId): static
    {
        //
        if ($this->roles === null) {
            return $this;
        }

        //
        $this->roles = array_values(
                array_filter(
                $this->roles,
                function ($role) use ($roleId) {
                    $currentId = $role['id'] ?? $role['name'] ?? null;
                    return $currentId !== $roleId;
                }
                )
                );

        return $this;

    }//end removeRole()


    /**
     * Check if a role exists in this organisation
     *
     * @param string $roleId The role ID or name to check
     *
     * @return bool True if role exists in this organisation
     */
    public function hasRole(string $roleId): bool
    {
        //
        if ($this->roles === null) {
            return false;
        }

        foreach ($this->roles as $role) {
            $currentId = $role['id'] ?? $role['name'] ?? null;
            if ($currentId === $roleId) {
                return true;
            }
        }

        return false;

    }//end hasRole()


    /**
     * Get a specific role by ID or name
     *
     * @param string $roleId The role ID or name to retrieve
     *
     * @return array|null The role definition or null if not found
     */
    public function getRole(string $roleId): ?array
    {
        //
        if ($this->roles === null) {
            return null;
        }

        //
        foreach ($this->roles as $role) {
            $currentId = $role['id'] ?? $role['name'] ?? null;
            if ($currentId === $roleId) {
                return $role;
            }
        }

        return null;

    }//end getRole()


    /**
     * Get all groups in this organisation
     *
     * @return array Array of Nextcloud group IDs
     */
    public function getGroups(): array
    {
        return $this->groups ?? [];

    }//end getGroups()


    /**
     * Set all groups for this organisation
     *
     * @param array|null $groups Array of Nextcloud group IDs
     *
     * @return static Returns this organisation for method chaining
     */
    public function setGroups(?array $groups): static
    {
        $this->groups = $groups ?? [];
        $this->markFieldUpdated('groups');
        return $this;

    }//end setGroups()


    /**
     * Get whether this organisation is active
     *
     * @return bool Whether this organisation is active
     */
    public function getActive(): bool
    {
        return $this->active ?? true;

    }//end getActive()


    /**
     * Set whether this organisation is active
     *
     * @param bool|null|string $active Whether this should be the active organisation
     *
     * @return static Returns this organisation for method chaining
     */
    public function setActive(mixed $active): static
    {
        // Handle various input types defensively (including empty strings from API).
        if ($active === '' || $active === null) {
            parent::setActive(true);
            // Default to true for organisations.
        } else {
            parent::setActive((bool) $active);
        }

        $this->markFieldUpdated('active');
        return $this;

    }//end setActive()


    /**
     * Get default authorization structure for organisations
     *
     * Provides sensible defaults with empty arrays for all permissions
     * Uses singular entity names for easier authorization checks based on entity type
     *
     * @return array[][] Default authorization structure
     *
     * @psalm-return array{
     *     register: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     schema: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     object: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     view: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     agent: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     configuration: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     application: array{
     *         create: array<never, never>,
     *         read: array<never, never>,
     *         update: array<never, never>,
     *         delete: array<never, never>
     *     },
     *     object_publish: array<never, never>,
     *     agent_use: array<never, never>,
     *     dashboard_view: array<never, never>,
     *     llm_use: array<never, never>
     * }
     */
    private function getDefaultAuthorization(): array
    {
        return [
            'register'       => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'schema'         => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'object'         => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'view'           => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'agent'          => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'configuration'  => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'application'    => [
                'create' => [],
                'read'   => [],
                'update' => [],
                'delete' => [],
            ],
            'object_publish' => [],
            'agent_use'      => [],
            'dashboard_view' => [],
            'llm_use'        => [],
        ];

    }//end getDefaultAuthorization()


    /**
     * Get authorization rules for this organisation
     *
     * @return array Authorization rules structure
     */
    public function getAuthorization(): array
    {
        return $this->authorization ?? $this->getDefaultAuthorization();

    }//end getAuthorization()


    /**
     * Set authorization rules for this organisation
     *
     * @param array|null $authorization Authorization rules structure
     *
     * @return static Returns this organisation for method chaining
     */
    public function setAuthorization(?array $authorization): static
    {
        $this->authorization = $authorization ?? $this->getDefaultAuthorization();
        $this->markFieldUpdated('authorization');
        return $this;

    }//end setAuthorization()


    /**
     * Get parent organisation UUID
     *
     * @return string|null The parent organisation UUID or null if no parent
     */
    public function getParent(): ?string
    {
        return $this->parent;

    }//end getParent()


    /**
     * Set parent organisation UUID
     *
     * @param string|null $parent The parent organisation UUID
     *
     * @return static Returns this organisation for method chaining
     */
    public function setParent(?string $parent): static
    {
        $this->parent = $parent;
        $this->markFieldUpdated('parent');
        return $this;

    }//end setParent()


    /**
     * Check if this organisation has a parent
     *
     * @return bool True if organisation has a parent, false otherwise
     */
    public function hasParent(): bool
    {
        return $this->parent !== null && $this->parent !== '';

    }//end hasParent()


    /**
     * Get child organisation UUIDs
     *
     * This property is computed and populated via OrganisationMapper::findChildrenChain().
     * It is not stored in the database.
     *
     * @return array Array of child organisation UUIDs
     */
    public function getChildren(): array
    {
        return $this->children ?? [];

    }//end getChildren()


    /**
     * Set child organisation UUIDs
     *
     * This is used to populate the computed children property for API responses.
     * Children are not stored in the database, only loaded on demand.
     *
     * @param array|null $children Array of child organisation UUIDs
     *
     * @return static Returns this organisation for method chaining
     */
    public function setChildren(?array $children): static
    {
        $this->children = $children;
        return $this;

    }//end setChildren()


    /**
     * Check if this organisation has children
     *
     * @return bool True if organisation has children, false otherwise
     */
    public function hasChildren(): bool
    {
        return !empty($this->children);

    }//end hasChildren()


    /**
     * JSON serialization for API responses
     *
     * @return (array|bool|int|null|string)[] Serialized organisation data
     *
     * @psalm-return array{
     *     id: int,
     *     uuid: null|string,
     *     slug: null|string,
     *     name: null|string,
     *     description: null|string,
     *     users: array,
     *     groups: array|null,
     *     owner: null|string,
     *     active: bool|null,
     *     parent: null|string,
     *     children: array,
     *     quota: array{
     *         storage: int|null,
     *         bandwidth: int|null,
     *         requests: int|null,
     *         users: null,
     *         groups: null
     *     },
     *     usage: array{
     *         storage: 0,
     *         bandwidth: 0,
     *         requests: 0,
     *         users: int<0, max>,
     *         groups: int<0, max>
     *     },
     *     authorization: array,
     *     created: null|string,
     *     updated: null|string
     * }
     */
    public function jsonSerialize(): array
    {
        $users  = $this->getUserIds();
        $groups = $this->getGroups();

        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'slug'          => $this->slug,
            'name'          => $this->name,
            'description'   => $this->description,
            'users'         => $users,
            'groups'        => $groups,
            'owner'         => $this->owner,
            'active'        => $this->getActive(),
            'parent'        => $this->parent,
            'children'      => $this->children ?? [],
            'quota'         => [
                'storage'   => $this->storageQuota,
                'bandwidth' => $this->bandwidthQuota,
                'requests'  => $this->requestQuota,
                'users'     => null,
        // To be set via admin configuration.
                'groups'    => null,
        // To be set via admin configuration.
            ],
            'usage'         => [
                'storage'   => 0,
            // To be calculated from actual usage.
                'bandwidth' => 0,
            // To be calculated from actual usage.
                'requests'  => 0,
            // To be calculated from actual usage.
                'users'     => count($users),
                'groups'    => count($groups),
            ],
            'authorization' => $this->authorization ?? $this->getDefaultAuthorization(),
            'created'       => $this->getCreatedFormatted(),
            'updated'       => $this->getUpdatedFormatted(),
        ];

    }//end jsonSerialize()


    /**
     * String representation of the organisation
     *
     * This magic method returns the organisation UUID. If no UUID exists,
     * it creates a new one, sets it to the organisation, and returns it.
     * This ensures every organisation has a unique identifier.
     *
     * @return string UUID of the organisation
     */
    public function __toString(): string
    {
        // Generate new UUID if none exists or is empty.
        if ($this->uuid === null || $this->uuid === '') {
            $this->uuid = Uuid::v4()->toRfc4122();
        }

        return $this->uuid;

    }//end __toString()


    /**
     * Get created date formatted as ISO 8601 string or null
     *
     * @return string|null Formatted date or null
     */
    private function getCreatedFormatted(): ?string
    {
        if ($this->created !== null) {
            return $this->created->format('c');
        }

        return null;

    }//end getCreatedFormatted()


    /**
     * Get updated date formatted as ISO 8601 string or null
     *
     * @return string|null Formatted date or null
     */
    private function getUpdatedFormatted(): ?string
    {
        if ($this->updated !== null) {
            return $this->updated->format('c');
        }

        return null;

    }//end getUpdatedFormatted()


}//end class
