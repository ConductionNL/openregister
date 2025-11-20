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
        // Add types for automatic JSON (de)serialization
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
     * Set the organisation UUID
     *
     * @param string|null $organisation The organisation UUID
     *
     * @return void
     */
    public function setOrganisation(?string $organisation): void
    {
        $this->organisation = $organisation;
        $this->markFieldUpdated('organisation');

    }//end setOrganisation()


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
        // To be set via admin configuration
                'bandwidth' => null,
        // To be set via admin configuration
                'requests'  => null,
        // To be set via admin configuration
                'users'     => null,
        // To be set via admin configuration
                'groups'    => null,
        // To be set via admin configuration
            ],
            'usage'                  => [
                'storage'   => 0,
            // To be calculated from actual usage
                'bandwidth' => 0,
            // To be calculated from actual usage
                'requests'  => 0,
            // To be calculated from actual usage (query executions)
                'users'     => count($favoredBy),
            // Number of users who favorited this view
                'groups'    => 0,
            // Views don't have groups
            ],
            'created'                => isset($this->created) === true ? $this->created->format('c') : null,
            'updated'                => isset($this->updated) === true ? $this->updated->format('c') : null,
            'managedByConfiguration' => $this->managedByConfiguration !== null ? [
                'id'    => $this->managedByConfiguration->getId(),
                'uuid'  => $this->managedByConfiguration->getUuid(),
                'title' => $this->managedByConfiguration->getTitle(),
            ] : null,
        ];

    }//end jsonSerialize()


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
        $this->setUuid(isset($object['uuid']) === true ? $object['uuid'] : null);
        $this->setName(isset($object['name']) === true ? $object['name'] : null);
        $this->setDescription(isset($object['description']) === true ? $object['description'] : null);
        $this->setOwner(isset($object['owner']) === true ? $object['owner'] : null);
        $this->setIsPublic(isset($object['isPublic']) === true ? $object['isPublic'] : false);
        $this->setIsDefault(isset($object['isDefault']) === true ? $object['isDefault'] : false);
        $this->setQuery(isset($object['query']) === true ? $object['query'] : []);
        $this->setFavoredBy(isset($object['favoredBy']) === true ? $object['favoredBy'] : []);

        return $this;

    }//end hydrate()


    /**
     * Get the configuration that manages this view (transient property)
     *
     * @return Configuration|null The managing configuration or null
     */
    public function getManagedByConfigurationEntity(): ?Configuration
    {
        return $this->managedByConfiguration;

    }//end getManagedByConfigurationEntity()


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
     * Check if this view is managed by a configuration
     *
     * Returns true if this view's ID appears in any of the provided configurations' views arrays.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return bool True if managed by a configuration, false otherwise
     *
     * @phpstan-param array<Configuration> $configurations
     * @psalm-param   array<Configuration> $configurations
     */
    public function isManagedByConfiguration(array $configurations): bool
    {
        if (empty($configurations) === true || $this->id === null) {
            return false;
        }

        foreach ($configurations as $configuration) {
            $views = $configuration->getViews();
            if (in_array($this->id, $views, true) === true) {
                return true;
            }
        }

        return false;

    }//end isManagedByConfiguration()


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
