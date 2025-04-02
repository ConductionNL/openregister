<?php
/**
 * OpenRegister Schema
 *
 * This file contains the class for handling schema related operations
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
use OCP\DB\Types;
use OCP\IURLGenerator;
use stdClass;

/**
 * Class Schema
 *
 * Entity class representing a Schema
 *
 * @package OCA\OpenRegister\Db
 */
class Schema extends Entity implements JsonSerializable
{
    /**
     * Unique identifier for the schema
     *
     * @var string|null Unique identifier for the schema
     */
    protected ?string $uuid = NULL;

    /**
     * Slug of the schema
     *
     * @var string|null Slug of the schema
     */
    protected ?string $slug = NULL;

    /**
     * Title of the schema
     *
     * @var string|null Title of the schema
     */
    protected ?string $title = NULL;

    /**
     * Description of the schema
     *
     * @var string|null Description of the schema
     */
    protected ?string $description = NULL;

    /**
     * Version of the schema
     *
     * @var string|null Version of the schema
     */
    protected ?string $version = NULL;

    /**
     * Summary of the schema
     *
     * @var string|null Summary of the schema
     */
    protected ?string $summary = NULL;

    /**
     * Required fields of the schema
     *
     * @var array|null Required fields of the schema
     */
    protected ?array $required = [];

    /**
     * Properties of the schema
     *
     * @var array|null Properties of the schema
     */
    protected ?array $properties = [];

    /**
     * Archive data of the schema
     *
     * @var array|null Archive data of the schema
     */
    protected ?array $archive = [];

    /**
     * Source of the schema
     *
     * @var string|null Source of the schema
     */
    protected ?string $source = NULL;

    /**
     * Whether hard validation is enabled
     *
     * @var boolean Whether hard validation is enabled
     */
    protected bool $hardValidation = FALSE;

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
     * Maximum depth of the schema
     *
     * @var integer Maximum depth of the schema
     */
    protected int $maxDepth = 0;

    /**
     * The Nextcloud user that owns this schema
     *
     * @var string|null The Nextcloud user that owns this schema
     */
    protected ?string $owner = NULL;

    /**
     * The application name
     *
     * @var string|null The application name
     */
    protected ?string $application = NULL;

    /**
     * The organisation name
     *
     * @var string|null The organisation name
     */
    protected ?string $organisation = NULL;

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
    protected ?DateTime $deleted = NULL;

    /**
     * Constructor for the Schema class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'summary', type: 'string');
        $this->addType(fieldName: 'required', type: 'json');
        $this->addType(fieldName: 'properties', type: 'json');
        $this->addType(fieldName: 'archive', type: 'json');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'hardValidation', type: Types::BOOLEAN);
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'maxDepth', type: Types::INTEGER);
        // @todo this is being missed used so needs a refactor, sub onjects should be based on schema property config.
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'datetime');

    }//end __construct()

    /**
     * Get the required data
     *
     * @return array The required data or empty array if null
     */
    public function getRequired(): array
    {
        return ($this->required ?? []);

    }//end getRequired()

    /**
     * Get the properties data
     *
     * @return array The properties data or empty array if null
     */
    public function getProperties(): array
    {
        return ($this->properties ?? []);

    }//end getProperties()

    /**
     * Get the archive data
     *
     * @return array The archive data or empty array if null
     */
    public function getArchive(): array
    {
        return ($this->archive ?? []);

    }//end getArchive()

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
     * Serializes the schema to an array
     *
     * Converts entity data to a JSON serializable array
     *
     * @return array<string, mixed> The serialized schema data
     */
    public function jsonSerialize(): array
    {
        $required = ($this->required ?? []);
        $properties = [];

        if (isset($this->properties) === TRUE) {
            foreach ($this->properties as $title => $property) {
                $title = ($property['title'] ?? $title);

                $isRequired = (isset($property['required']) === TRUE && $property['required'] === TRUE);
                $notInRequired = in_array($title, $required) === FALSE;

                if ($isRequired === TRUE && $notInRequired === TRUE) {
                    $required[] = $title;
                }

                $properties[$title] = $property;
            }
        }

        $updated = NULL;
        if (isset($this->updated) === TRUE) {
            $updated = $this->updated->format('c');
        }

        $created = NULL;
        if (isset($this->created) === TRUE) {
            $created = $this->created->format('c');
        }

        $deleted = NULL;
        if (isset($this->deleted) === TRUE) {
            $deleted = $this->deleted->format('c');
        }

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'version' => $this->version,
            'summary' => $this->summary,
            'required' => $required,
            'properties' => $properties,
            'archive' => $this->archive,
            'source' => $this->source,
            'hardValidation' => $this->hardValidation,
            'updated' => $updated,
            'created' => $created,
            'maxDepth' => $this->maxDepth,
            'owner' => $this->owner,
            'application' => $this->application,
            'organisation' => $this->organisation,
            'authorization' => $this->authorization,
            'deleted' => $deleted,
        ];

    }//end jsonSerialize()

    /**
     * Converts schema to an object representation
     *
     * Creates a standard object representation of the schema for API use
     *
     * @param IURLGenerator $urlGenerator The URL generator for URLs in the schema
     *
     * @return object A standard object representation of the schema
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): object
    {
        $schema = new stdClass();
        $schema->title = $this->title;
        $schema->description = $this->description;
        $schema->version = $this->version;
        $schema->type = 'object';
        $schema->required = $this->required;
        $schema->$schema = 'https://json-schema.org/draft/2020-12/schema';
        $schema->$id = $urlGenerator->getBaseUrl().'/apps/openregister/api/v1/schemas/'.$this->uuid;
        $schema->properties = new stdClass();

        foreach ($this->properties as $propertyName => $property) {
            if (isset($property['properties']) === TRUE) {
                $nestedProperties = new stdClass();
                $nestedProperty = new stdClass();
                $nestedProperty->type = 'object';
                $nestedProperty->title = $property['title'];
                $nestedProperty->required = [];

                if (isset($property['properties']) === TRUE) {
                    foreach ($property['properties'] as $subName => $subProperty) {
                        if ((isset($subProperty['required']) === TRUE) && ($subProperty['required'] === TRUE)) {
                            $nestedProperty->required[] = $subName;
                        }

                        $nestedProp = new stdClass();
                        foreach ($subProperty as $key => $value) {
                            $nestedProp->$key = $value;
                        }

                        $nestedProperties->$subName = $nestedProp;
                    }
                }

                $nestedProperty->properties = $nestedProperties;
                $schema->properties->$propertyName = $nestedProperty;
            } else {
                $prop = new stdClass();
                foreach ($property as $key => $value) {
                    // Skip 'required' property on this level.
                    if ($key !== 'required') {
                        $prop->$key = $value;
                    }
                }

                $schema->properties->$propertyName = $prop;
            }//end if
        }//end foreach

        return $schema;

    }//end getSchemaObject()

}//end class
