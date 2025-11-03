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
     * Whether the view is public
     *
     * @var bool Whether the view is public
     */
    protected bool $isPublic = false;

    /**
     * Whether the view is the user's default
     *
     * @var bool Whether the view is the default
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
        $this->addType('isPublic', 'boolean');
        $this->addType('isDefault', 'boolean');
        $this->addType('query', 'json');
        $this->addType('favoredBy', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');
    }//end __construct()


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
            'id'          => $this->id,
            'uuid'        => $this->uuid,
            'name'        => $this->name,
            'description' => $this->description,
            'owner'       => $this->owner,
            'isPublic'    => $this->isPublic,
            'isDefault'   => $this->isDefault,
            'query'       => $this->query,
            'favoredBy'   => $favoredBy,
            'quota'       => [
                'storage'   => null, // To be set via admin configuration
                'bandwidth' => null, // To be set via admin configuration
                'requests'  => null, // To be set via admin configuration
                'users'     => null, // To be set via admin configuration
                'groups'    => null, // To be set via admin configuration
            ],
            'usage'       => [
                'storage'   => 0, // To be calculated from actual usage
                'bandwidth' => 0, // To be calculated from actual usage
                'requests'  => 0, // To be calculated from actual usage (query executions)
                'users'     => count($favoredBy), // Number of users who favorited this view
                'groups'    => 0, // Views don't have groups
            ],
            'created'     => isset($this->created) === true ? $this->created->format('c') : null,
            'updated'     => isset($this->updated) === true ? $this->updated->format('c') : null,
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


}//end class

