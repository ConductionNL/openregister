<?php
/**
 * OpenRegister Configuration Entity
 *
 * This file contains the Configuration entity class for the OpenRegister application.
 *
 * @category Entity
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

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Configuration entity class
 */
class Configuration extends Entity implements JsonSerializable
{

    /**
     * Title of the configuration
     *
     * @var string
     */
    protected $title = null;

    /**
     * Description of the configuration
     *
     * @var string|null
     */
    protected $description = null;

    /**
     * Type of the configuration
     *
     * @var string
     */
    protected $type = null;

    /**
     * Application identifier that owns the configuration
     *
     * @var string
     */
    protected $app = null;

    /**
     * Version of the configuration
     *
     * @var string
     */
    protected $version = null;

    /**
     * Array of register IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $registers = [];

    /**
     * Array of schema IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $schemas = [];

    /**
     * Array of object IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $objects = [];

    /**
     * Creation timestamp
     *
     * @var DateTime
     */
    protected $created = null;

    /**
     * Last update timestamp
     *
     * @var DateTime
     */
    protected $updated = null;


    /**
     * Constructor to set up the entity with required types
     */
    public function __construct()
    {
        $this->addType('id', 'integer');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('type', 'string');
        $this->addType('app', 'string');
        $this->addType('version', 'string');
        $this->addType('registers', 'json');
        $this->addType('schemas', 'json');
        $this->addType('objects', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * Get the registers of the configuration
     *
     * @return array<int> Array of register IDs
     */
    public function getRegisters(): array
    {
        return ($this->registers ?? []);

    }//end getRegisters()


    /**
     * Set the registers of the configuration
     *
     * @param array<int> $registers Array of register IDs
     * 
     * @return void
     */
    public function setRegisters(array $registers): void
    {
        $this->registers = $registers;

    }//end setRegisters()


    /**
     * Get the schemas of the configuration
     *
     * @return array<int> Array of schema IDs
     */
    public function getSchemas(): array
    {
        return ($this->schemas ?? []);

    }//end getSchemas()


    /**
     * Set the schemas of the configuration
     *
     * @param array<int> $schemas Array of schema IDs
     * 
     * @return void
     */
    public function setSchemas(array $schemas): void
    {
        $this->schemas = $schemas;

    }//end setSchemas()


    /**
     * Get the objects of the configuration
     *
     * @return array<int> Array of object IDs
     */
    public function getObjects(): array
    {
        return ($this->objects ?? []);

    }//end getObjects()


    /**
     * Set the objects of the configuration
     *
     * @param array<int> $objects Array of object IDs
     * 
     * @return void
     */
    public function setObjects(array $objects): void
    {
        $this->objects = $objects;

    }//end setObjects()


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
     * Serialize the entity to JSON
     *
     * @return array<string, mixed> The serialized entity
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'description' => $this->description,
            'type'        => $this->type,
            'app'         => $this->app,
            'version'     => $this->version,
            'registers'   => $this->registers,
            'schemas'     => $this->schemas,
            'objects'     => $this->objects,
            'created'     => ($this->created !== null) ? $this->created->format('c') : null,
            'updated'     => ($this->updated !== null) ? $this->updated->format('c') : null,
        ];

    }//end jsonSerialize()


}//end class
