<?php
/**
 * OpenRegister Source
 *
 * This file contains the class for handling source related operations
 * in the OpenRegister application.
 *
 * @category  Database
 * @package   OCA\OpenRegister\Db
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Source entity class
 *
 * Represents a source in the OpenRegister application
 */
class Source extends Entity implements JsonSerializable
{
    /**
     * Unique identifier for the source
     *
     * @var string|null Unique identifier for the source
     */
    protected ?string $uuid = NULL;

    /**
     * Title of the source
     *
     * @var string|null Title of the source
     */
    protected ?string $title = NULL;

    /**
     * Version of the source
     *
     * @var string|null Version of the source
     */
    protected ?string $version = NULL;

    /**
     * Description of the source
     *
     * @var string|null Description of the source
     */
    protected ?string $description = NULL;

    /**
     * Database URL of the source
     *
     * @var string|null Database URL of the source
     */
    protected ?string $databaseUrl = NULL;

    /**
     * Type of the source
     *
     * @var string|null Type of the source
     */
    protected ?string $type = NULL;

    /**
     * Last update timestamp
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = NULL;

    /**
     * Creation timestamp
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = NULL;

    /**
     * Constructor for the Source class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'databaseUrl', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');

    }//end __construct()

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

        if (isset($object['metadata']) === FALSE) {
            $object['metadata'] = [];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === TRUE && $value === []) {
                $value = NULL;
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
        $updated = NULL;
        if (isset($this->updated) === TRUE) {
            $updated = $this->updated->format('c');
        }

        $created = NULL;
        if (isset($this->created) === TRUE) {
            $created = $this->created->format('c');
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'version' => $this->version,
            'description' => $this->description,
            'databaseUrl' => $this->databaseUrl,
            'type' => $this->type,
            'updated' => $updated,
            'created' => $created,
        ];

    }//end jsonSerialize()

}//end class
