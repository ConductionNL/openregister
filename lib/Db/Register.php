<?php
/**
 * OpenRegister Register
 *
 * This file contains the class for handling register related operations
 * in the OpenRegister application.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * Entity class representing a Register
 *
 * Manages register-related data and operations
 *
 * @package OCA\OpenRegister\Db
 */
class Register extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the register
     *
     * @var string|null Unique identifier for the register
     */
    protected ?string $uuid = null;

    /**
     * Slug of the register
     *
     * @var string|null Slug of the register
     */
    protected ?string $slug = null;

    /**
     * Title of the register
     *
     * @var string|null Title of the register
     */
    protected ?string $title = null;

    /**
     * Version of the register
     *
     * @var string|null Version of the register
     */
    protected ?string $version = null;

    /**
     * Description of the register
     *
     * @var string|null Description of the register
     */
    protected ?string $description = null;

    /**
     * Schemas associated with the register
     *
     * @var array|null Schemas associated with the register
     */
    protected ?array $schemas = [];

    /**
     * Source of the register
     *
     * @var string|null Source of the register
     */
    protected ?string $source = null;

    /**
     * Prefix for database tables
     *
     * @var string|null Prefix for database tables
     */
    protected ?string $tablePrefix = null;

    /**
     * Nextcloud folder path where register is stored
     *
     * @var string|null Nextcloud folder path where register is stored
     */
    protected ?string $folder = null;

    /**
     * Last update timestamp
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;

    /**
     * Creation timestamp
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * The Nextcloud user that owns this register
     *
     * @var string|null The Nextcloud user that owns this register
     */
    protected ?string $owner = null;

    /**
     * The application name
     *
     * @var string|null The application name
     */
    protected ?string $application = null;

    /**
     * The organisation name
     *
     * @var string|null The organisation name
     */
    protected ?string $organisation = null;

    /**
     * JSON object describing authorizations
     *
     * @var array|null JSON object describing authorizations
     */
    protected ?array $authorization = [];

    /**
     * Deletion timestamp
     *
     * @var DateTime|null Deletion timestamp
     */
    protected ?DateTime $deleted = null;


    /**
     * Constructor for the Register class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'schemas', type: 'json');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'tablePrefix', type: 'string');
        $this->addType(fieldName: 'folder', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'datetime');

    }//end __construct()


    /**
     * Get the schemas data
     *
     * @return array The schemas data or empty array if null
     */
    public function getSchemas(): array
    {
        return ($this->schemas ?? []);

    }//end getSchemas()


    /**
     * Get JSON fields from the entity
     *
     * Returns all fields that are of type 'json'
     *
     * @return array<string> List of JSON field names
     */
    public function getJsonFields(): array
    {
        return array_keys(
            array_filter(
                $this->getFieldTypes(),
                function ($field) {
                    return $field === 'json';
                }
            )
        );

    }//end getJsonFields()


    /**
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array $object The data array to hydrate from
     *
     * @return self Returns $this for method chaining
     */
    public function hydrate(array $object): self
    {
        $jsonFields = $this->getJsonFields();

        if (isset($object['metadata']) === false) {
            $object['metadata'] = [];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
            }
        }

        return $this;

    }//end hydrate()


    /**
     * Convert entity to JSON serializable array
     *
     * Prepares the entity data for JSON serialization
     *
     * @return array<string, mixed> Array of serializable entity data
     */
    public function jsonSerialize(): array
    {
        $updated = null;
        if (isset($this->updated) === true) {
            $updated = $this->updated->format('c');
        }

        $created = null;
        if (isset($this->created) === true) {
            $created = $this->created->format('c');
        }

        $deleted = null;
        if (isset($this->deleted) === true) {
            $deleted = $this->deleted->format('c');
        }

        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'slug'          => $this->slug,
            'title'         => $this->title,
            'version'       => $this->version,
            'description'   => $this->description,
            'schemas'       => $this->schemas,
            'source'        => $this->source,
            'tablePrefix'   => $this->tablePrefix,
            'folder'        => $this->folder,
            'updated'       => $updated,
            'created'       => $created,
            'owner'         => $this->owner,
            'application'   => $this->application,
            'organisation'  => $this->organisation,
            'authorization' => $this->authorization,
            'deleted'       => $deleted,
        ];

    }//end jsonSerialize()


}//end class
