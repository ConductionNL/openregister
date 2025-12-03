<?php
/**
 * OpenRegister View
 *
 * This file contains the class for handling view related operations
 * in the OpenRegister application.
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

/**
 * Entity class representing a View
 *
 * Manages view-related data and operations for saved search configurations
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method bool getIsPublic()
 * @method void setIsPublic(bool $isPublic)
 * @method bool getIsDefault()
 * @method void setIsDefault(bool $isDefault)
 * @method array|null getQuery()
 * @method void setQuery(?array $query)
 * @method array|null getFavoritedBy()
 * @method void setFavoritedBy(?array $favoritedBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 */
class View extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the view
     *
     * @var string|null Unique identifier for the view
     */
    protected ?string $uuid = null;

    /**
     * Name of the view
     *
     * @var string|null Name of the view
     */
    protected ?string $name = null;

    /**
     * Description of the view
     *
     * @var string|null Description of the view
     */
    protected ?string $description = null;

    /**
     * Owner of the view (user ID)
     *
     * @var string|null Owner of the view
     */
    protected ?string $owner = null;

    /**
     * Organisation UUID this view belongs to
     *
     * @var string|null Organisation UUID
     */
    protected ?string $organisation = null;

    /**
     * Configuration that manages this view (transient, not stored in DB)
     *
     * @var Configuration|null
     */
    private ?Configuration $managedByConfiguration = null;

    /**
     * Whether the view is public
     *
     * @var boolean Whether the view is public
     */
    protected bool $isPublic = false;

    /**
     * Whether the view is the user's default
     *
     * @var boolean Whether the view is the default
     */
    protected bool $isDefault = false;

    /**
     * Query parameters stored as JSON
     *
     * @var array|null Query parameters (registers, schemas, filters)
     */
    protected ?array $query = [];

    /**
     * Array of user IDs who favorited this view
     *
     * @var array|null User IDs who favorited
     */
    protected ?array $favoredBy = [];

    /**
     * Creation timestamp
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Last update timestamp
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;


    /**
     * Constructor for View entity
     *
     * Initializes the view with default values
     */
    public function __construct()
    {
        // Add types for automatic JSON (de)serialization.
        $this->addType('organisation', 'string');
        $this->addType('isPublic', 'boolean');
        $this->addType('isDefault', 'boolean');
        $this->addType('query', 'json');
        $this->addType('favoredBy', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * Get the favoredBy array
     *
     * @return array Array of user IDs who favorited this view
     */
    public function getFavoredBy(): array
    {
        return $this->favoredBy ?? [];

    }//end getFavoredBy()


    /**
     * Set the favoredBy array
     *
     * @param array $favoredBy Array of user IDs who favorited this view
     *
     * @return void
     */
    public function setFavoredBy(array $favoredBy): void
    {
        $this->favoredBy = $favoredBy;
        $this->markFieldUpdated('favoredBy');

    }//end setFavoredBy()


    /**
     * Get the organisation UUID
     *
     * @return string|null The organisation UUID
     */
    public function getOrganisation(): ?string
    {
        return $this->organisation;

    }//end getOrganisation()


    /**
     * Get the array version of this entity
     *
     * Converts the entity to an array representation
     *
     * @return array Array representation of the entity
     */
    public function jsonSerialize(): array
    {
        $favoredBy = $this->favoredBy ?? [];

        return [
            'id'                     => $this->id,
            'uuid'                   => $this->uuid,
            'name'                   => $this->name,
            'description'            => $this->description,
            'owner'                  => $this->owner,
            'organisation'           => $this->organisation,
            'isPublic'               => $this->isPublic,
            'isDefault'              => $this->isDefault,
            'query'                  => $this->query,
            'favoredBy'              => $favoredBy,
            'quota'                  => [
                'storage'   => null,
        // To be set via admin configuration.
                'bandwidth' => null,
        // To be set via admin configuration.
                'requests'  => null,
        // To be set via admin configuration.
                'users'     => null,
        // To be set via admin configuration.
                'groups'    => null,
        // To be set via admin configuration.
            ],
            'usage'                  => [
                'storage'   => 0,
            // To be calculated from actual usage.
                'bandwidth' => 0,
            // To be calculated from actual usage.
                'requests'  => 0,
            // To be calculated from actual usage (query executions).
                'users'     => count($favoredBy),
            // Number of users who favorited this view.
                'groups'    => 0,
            // Views don't have groups.
            ],
            'created'                => $this->getCreatedFormatted(),
            'updated'                => $this->getUpdatedFormatted(),
            'managedByConfiguration' => $this->getManagedByConfigurationFormatted(),
        ];

    }//end jsonSerialize()


    /**
     * Get created timestamp formatted.
     *
     * @return string|null
     */
    private function getCreatedFormatted(): ?string
    {
        if (($this->created ?? null) !== null) {
            return $this->created->format('c');
        }

        return null;

    }//end getCreatedFormatted()


    /**
     * Get updated timestamp formatted.
     *
     * @return string|null
     */
    private function getUpdatedFormatted(): ?string
    {
        if (($this->updated ?? null) !== null) {
            return $this->updated->format('c');
        }

        return null;

    }//end getUpdatedFormatted()


    /**
     * Get managed by configuration formatted.
     *
     * @return array<string,mixed>|null
     */
    private function getManagedByConfigurationFormatted(): ?array
    {
        if ($this->managedByConfiguration !== null) {
            return [
                'id'    => $this->managedByConfiguration->getId(),
                'uuid'  => $this->managedByConfiguration->getUuid(),
                'title' => $this->managedByConfiguration->getTitle(),
            ];
        }

        return null;

    }//end getManagedByConfigurationFormatted()


    /**
     * Hydrate the entity from an array
     *
     * Populates entity properties from an array
     *
     * @param array $object Array containing entity data
     *
     * @return self Returns the hydrated entity
     */
    public function hydrate(array $object): self
    {
        if (($object['uuid'] ?? null) !== null) {
            $this->setUuid($object['uuid']);
        } else {
            $this->setUuid(null);
        }

        if (($object['name'] ?? null) !== null) {
            $this->setName($object['name']);
        } else {
            $this->setName(null);
        }

        if (($object['description'] ?? null) !== null) {
            $this->setDescription($object['description']);
        } else {
            $this->setDescription(null);
        }

        if (($object['owner'] ?? null) !== null) {
            $this->setOwner($object['owner']);
        } else {
            $this->setOwner(null);
        }

        if (($object['isPublic'] ?? null) !== null) {
            $this->setIsPublic($object['isPublic']);
        } else {
            $this->setIsPublic(false);
        }

        if (($object['isDefault'] ?? null) !== null) {
            $this->setIsDefault($object['isDefault']);
        } else {
            $this->setIsDefault(false);
        }

        if (($object['query'] ?? null) !== null) {
            $this->setQuery($object['query']);
        } else {
            $this->setQuery([]);
        }

        if (($object['favoredBy'] ?? null) !== null) {
            $this->setFavoredBy($object['favoredBy']);
        } else {
            $this->setFavoredBy([]);
        }

        return $this;

    }//end hydrate()


    /**
     * Set the configuration that manages this view (transient property)
     *
     * @param Configuration|null $configuration The managing configuration
     *
     * @return void
     */
    public function setManagedByConfigurationEntity(?Configuration $configuration): void
    {
        $this->managedByConfiguration = $configuration;

    }//end setManagedByConfigurationEntity()


    /**
     * Get the configuration that manages this view
     *
     * Returns the first configuration that has this view's ID in its views array.
     * Returns null if the view is not managed by any configuration.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return Configuration|null The configuration managing this view, or null
     *
     * @phpstan-param array<Configuration> $configurations
     * @psalm-param   array<Configuration> $configurations
     */
    public function getManagedByConfiguration(array $configurations): ?Configuration
    {
        if (empty($configurations) === true || $this->id === null) {
            return null;
        }

        foreach ($configurations as $configuration) {
            $views = $configuration->getViews();
            if (in_array($this->id, $views, true) === true) {
                return $configuration;
            }
        }

        return null;

    }//end getManagedByConfiguration()


}//end class
