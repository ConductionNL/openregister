<?php
/**
 * OpenRegister Configuration Entity
 *
 * This file contains the Configuration entity class for the OpenRegister application.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Ruben Linde <ruben@nextcloud.com>
 * @copyright Copyright (c) 2024, Ruben Linde (https://github.com/rubenlinde)
 * @license   AGPL-3.0
 * @version   1.0.0
 * @link      https://github.com/cloud-py-api/openregister
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
     * @var string Title of the configuration
     */
    protected $title = null;

    /**
     * @var string|null Description of the configuration
     */
    protected $description = null;

    /**
     * @var string Type of the configuration
     */
    protected $type = null;

    /**
     * @var string Owner of the configuration
     */
    protected $owner = null;

    /**
     * @var string Version of the configuration
     */
    protected $version = null;

    /**
     * @var array|null Array of registers of the configuration
     */
    protected ?array $registers = [];

    /**
     * @var DateTime Creation timestamp
     */
    protected $created = null;

    /**
     * @var DateTime Last update timestamp
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
        $this->addType('owner', 'string');
        $this->addType('version', 'string');
        $this->addType('registers', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * Get the registers of the configuration
     *
     * @return array<string> Array of registers
     */
    public function getRegisters(): array
    {
        return ($this->registers ?? []);

    }//end getRegisters()


    /**
     * Set the registers of the configuration
     *
     * @param array<string> $registers Array of registers
     */


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
            'owner'       => $this->owner,
            'version'     => $this->version,
            'registers'   => $this->registers,
            'created'     => $this->created ? $this->created->format('c') : null,
            'updated'     => $this->updated ? $this->updated->format('c') : null,
        ];

    }//end jsonSerialize()


}//end class
