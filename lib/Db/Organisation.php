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
     * @var int|null Storage quota in bytes
     */
    protected ?int $storageQuota = null;

    /**
     * Bandwidth/traffic quota allocated to this organisation in bytes per month
     * NULL = unlimited bandwidth
     *
     * @var int|null Bandwidth quota in bytes per month
     */
    protected ?int $bandwidthQuota = null;

    /**
     * API request quota allocated to this organisation per day
     * NULL = unlimited API requests
     *
     * @var int|null API request quota per day
     */
    protected ?int $requestQuota = null;


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
     * @return self Returns this organisation for method chaining
     */
    public function addUser(string $userId): self
    {
        if ($this->users === null) {
            $this->users = [];
        }

        if (!in_array($userId, $this->users)) {
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
     * @return self Returns this organisation for method chaining
     */
    public function removeUser(string $userId): self
    {
        if ($this->users === null) {
            return $this;
        }

        $originalCount = count($this->users);
        $this->users = array_values(
                array_filter(
                $this->users,
                function ($id) use ($userId) {
                    return $id !== $userId;
                }
                )
                );

        // Only mark as updated if a user was actually removed
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
     * @return self Returns this organisation for method chaining
     */
    public function addRole(array $role): self
    {
        if ($this->roles === null) {
            $this->roles = [];
        }

        // Check if role with same ID already exists
        $roleId = $role['id'] ?? $role['name'] ?? null;
        if ($roleId !== null) {
            $exists = false;
            foreach ($this->roles as $existingRole) {
                $existingId = $existingRole['id'] ?? $existingRole['name'] ?? null;
                if ($existingId === $roleId) {
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
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
     * @return self Returns this organisation for method chaining
     */
    public function removeRole(string $roleId): self
    {
        if ($this->roles === null) {
            return $this;
        }

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
        if ($this->roles === null) {
            return null;
        }

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
     * @return self Returns this organisation for method chaining
     */
    public function setGroups(?array $groups): self
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
     * @return self Returns this organisation for method chaining
     */
    public function setActive(mixed $active): self
    {
        // Handle various input types defensively (including empty strings from API)
        if ($active === '' || $active === null) {
            parent::setActive(true); // Default to true for organisations
        } else {
            parent::setActive((bool)$active);
        }
        $this->markFieldUpdated('active');
        return $this;

    }//end setActive()


    /**
     * JSON serialization for API responses
     *
     * @return array Serialized organisation data
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'users'       => $this->getUserIds(),
            'userCount'   => count($this->getUserIds()),
            'groups'      => $this->getGroups(),
            'groupCount'  => count($this->getGroups()),
            'owner'       => $this->owner,
            'active'      => $this->getActive(),
            'created'     => $this->created ? $this->created->format('c') : null,
            'updated'     => $this->updated ? $this->updated->format('c') : null,
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
        // Generate new UUID if none exists or is empty
        if ($this->uuid === null || $this->uuid === '') {
            $this->uuid = Uuid::v4()->toRfc4122();
        }

        return $this->uuid;

    }//end __toString()


}//end class
