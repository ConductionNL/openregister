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
     * Whether this organisation is the default organisation
     *
     * @var boolean|null Whether this is the default organisation
     */
    protected ?bool $isDefault = false;

    /**
     * Whether this organisation is active
     *
     * @var boolean|null Whether this organisation is active
     */
    protected ?bool $active = true;


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
        $this->addType('owner', 'string');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
        $this->addType('is_default', 'boolean');
        $this->addType('active', 'boolean');

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

        $this->users = array_values(
                array_filter(
                $this->users,
                function ($id) use ($userId) {
                    return $id !== $userId;
                }
                )
                );

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
     * Get whether this organisation is the default
     *
     * @return bool Whether this is the default organisation
     */
    public function getIsDefault(): bool
    {
        return $this->isDefault ?? false;

    }//end getIsDefault()


    /**
     * Set whether this organisation is the default
     *
     * @param bool|null $isDefault Whether this should be the default organisation
     *
     * @return self Returns this organisation for method chaining
     */
    public function setIsDefault(?bool $isDefault): self
    {
        $this->isDefault = $isDefault ?? false;
        return $this;

    }//end setIsDefault()


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
     * @param bool|null $active Whether this should be the active organisation
     *
     * @return self Returns this organisation for method chaining
     */
    public function setActive(?bool $active): self
    {
        parent::setActive($active ?? true);
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
            'owner'       => $this->owner,
            'isDefault'   => $this->getIsDefault(),
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
