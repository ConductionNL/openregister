<?php
/**
 * OpenRegister Schema
 *
 * This file contains the class for handling schema related operations
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
use OCP\DB\Types;
use OCP\IURLGenerator;
use stdClass;
use OCA\OpenRegister\Service\SchemaPropertyValidatorService;

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
    protected ?string $uuid = null;

    /**
     * URI of the schema
     *
     * @var string|null URI of the schema
     */
    protected ?string $uri = null;

    /**
     * Slug of the schema
     *
     * @var string|null Slug of the schema
     */
    protected ?string $slug = null;

    /**
     * Title of the schema
     *
     * @var string|null Title of the schema
     */
    protected ?string $title = null;

    /**
     * Description of the schema
     *
     * @var string|null Description of the schema
     */
    protected ?string $description = null;

    /**
     * Version of the schema
     *
     * @var string|null Version of the schema
     */
    protected ?string $version = null;

    /**
     * Summary of the schema
     *
     * @var string|null Summary of the schema
     */
    protected ?string $summary = null;

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
    protected ?string $source = null;

    /**
     * Whether hard validation is enabled
     *
     * @var boolean Whether hard validation is enabled
     */
    protected bool $hardValidation = false;

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
     * Configuration of the schema.
     *
     * This array can hold various configuration options for the schema.
     * Currently supported options:
     * - 'objectNameField': (string) A dot-notation path to the field within an object's data that should be used as its name.
     *   Example: 'person.firstName'
     * - 'objectDescriptionField': (string) A dot-notation path to the field for the object's description.
     *   Example: 'case.summary'
     *
     * @var array|null
     * @phpstan-var array<string, mixed>|null
     * @psalm-var array<string, mixed>|null
     */
    protected ?array $configuration = null;

    /**
     * The icon for the schema from Material Design Icons
     *
     * @var string|null The icon reference from https://pictogrammers.com/library/mdi/
     */
    protected ?string $icon = null;

    /**
     * Whether this schema is immutable (cannot be changed after creation)
     *
     * @var boolean
     */
    protected bool $immutable = false;

    /**
     * An array defining group-based permissions for CRUD actions.
     * The keys are the CRUD actions ('create', 'read', 'update', 'delete'),
     * and the values are arrays of group IDs that are permitted to perform that action.
     * If an action is not present as a key, or its value is an empty array,
     * it is assumed that all users have permission for that action.
     *
     * Example:
     * [
     *   'create' => ['group-admin', 'group-editors'],
     *   'read'   => ['group-viewers'],
     *   'update' => ['group-editors'],
     *   'delete' => ['group-admin']
     * ]
     *
     * @var array|null
     * @phpstan-var array<string, array<string>>|null
     * @psalm-var array<string, list<string>>|null
     */
    protected ?array $groups = [];

    /**
     * Constructor for the Schema class
     *
     * Sets up field types for all properties
     */
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'uri', type: 'string');
        $this->addType(fieldName: 'slug', type: 'string');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'description', type: 'string');
        $this->addType(fieldName: 'version', type: 'string');
        $this->addType(fieldName: 'summary', type: 'string');
        $this->addType(fieldName: 'icon', type: 'string');
        $this->addType(fieldName: 'required', type: 'json');
        $this->addType(fieldName: 'properties', type: 'json');
        $this->addType(fieldName: 'archive', type: 'json');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'hardValidation', type: Types::BOOLEAN);
        $this->addType(fieldName: 'immutable', type: Types::BOOLEAN);
        $this->addType(fieldName: 'updated', type: 'datetime');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'maxDepth', type: Types::INTEGER);
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'application', type: 'string');
        $this->addType(fieldName: 'organisation', type: 'string');
        $this->addType(fieldName: 'authorization', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'datetime');
        $this->addType(fieldName: 'configuration', type: 'json');
        $this->addType(fieldName: 'groups', type: 'json');

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
     * Validate the schema properties
     *
     * @param SchemaPropertyValidatorService $validator The schema property validator
     *
     * @throws Exception If the properties are invalid
     *
     * @return bool True if the properties are valid
     */
    public function validateProperties(SchemaPropertyValidatorService $validator): bool
    {
        // Check if properties are set and not empty.
        if (empty($this->properties) === true) {
            return true;
        }

        // Validate and normalize inversedBy properties to ensure they are strings
        $this->normalizeInversedByProperties();

        return $validator->validateProperties($this->properties);

    }//end validateProperties()

    /**
     * Normalize inversedBy properties to ensure they are always strings
     *
     * @return void
     */
    private function normalizeInversedByProperties(): void
    {
        if (empty($this->properties) === true) {
            return;
        }

        foreach ($this->properties as $propertyName => $property) {
            // Handle regular object properties
            if (isset($property['inversedBy']) === true) {
                if (is_array($property['inversedBy']) === true && isset($property['inversedBy']['id']) === true) {
                    $this->properties[$propertyName]['inversedBy'] = $property['inversedBy']['id'];
                } elseif (is_string($property['inversedBy']) === false) {
                    // Remove invalid inversedBy if it's not a string or object with id
                    unset($this->properties[$propertyName]['inversedBy']);
                }
            }

            // Handle array items with inversedBy
            if (isset($property['items']['inversedBy']) === true) {
                if (is_array($property['items']['inversedBy']) === true && isset($property['items']['inversedBy']['id']) === true) {
                    $this->properties[$propertyName]['items']['inversedBy'] = $property['items']['inversedBy']['id'];
                } elseif (is_string($property['items']['inversedBy']) === false) {
                    // Remove invalid inversedBy if it's not a string or object with id
                    unset($this->properties[$propertyName]['items']['inversedBy']);
                }
            }
        }

    }//end normalizeInversedByProperties()


    /**
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array                          $object    The data array to hydrate from
     * @param SchemaPropertyValidatorService $validator Optional validator for properties
     *
     * @throws Exception If property validation fails
     * @return self Returns $this for method chaining
     */
    public function hydrate(array $object, ?SchemaPropertyValidatorService $validator=null): self
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

        // Validate properties if validator is provided.
        if ($validator !== null && isset($object['properties']) === true) {
            $this->validateProperties($validator);
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
        $required   = ($this->required ?? []);
        $properties = [];

        if (isset($this->properties) === true) {
            foreach ($this->properties as $propertyKey => $property) {
                $isRequired    = (isset($property['required']) === true && $property['required'] === true);
                $notInRequired = in_array($propertyKey, $required) === false;

                if ($isRequired === true && $notInRequired === true) {
                    $required[] = $propertyKey;
                }

                $properties[$propertyKey] = $property;
            }
        }

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
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'uri'            => $this->uri,
            'slug'           => $this->slug,
            'title'          => $this->title,
            'description'    => $this->description,
            'version'        => $this->version,
            'summary'        => $this->summary,
            'icon'           => $this->icon,
            'required'       => $required,
            'properties'     => $properties,
            'archive'        => $this->archive,
            'source'         => $this->source,
            'hardValidation' => $this->hardValidation,
            'immutable'      => $this->immutable,
        // @todo: should be refactored to strict
            'updated'        => $updated,
            'created'        => $created,
            'maxDepth'       => $this->maxDepth,
            'owner'          => $this->owner,
            'application'    => $this->application,
            'organisation'   => $this->organisation,
            'groups'         => $this->groups,
            'authorization'  => $this->authorization,
            'deleted'        => $deleted,
            'configuration'  => $this->configuration,
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
        $schema        = new stdClass();
        $schema->title = $this->title;
        $schema->description = $this->description;
        $schema->version     = $this->version;
        $schema->type        = 'object';
        $schema->required    = $this->required;
        $schema->{'$schema'}     = 'https://json-schema.org/draft/2020-12/schema';
        $schema->{'$id'}         = $urlGenerator->getBaseUrl().'/apps/openregister/api/v1/schemas/'.$this->uuid;
        $schema->properties  = new stdClass();

        foreach ($this->properties as $propertyName => $property) {
            if (isset($property['properties']) === true) {
                $nestedProperties         = new stdClass();
                $nestedProperty           = new stdClass();
                $nestedProperty->type     = 'object';
                $nestedProperty->title    = $property['title'];
                $nestedProperty->required = [];

                if (isset($property['properties']) === true) {
                    foreach ($property['properties'] as $subName => $subProperty) {
                        if ((isset($subProperty['required']) === true) && ($subProperty['required'] === true)) {
                            $nestedProperty->required[] = $subName;
                        }

                        $nestedProp = new stdClass();
                        foreach ($subProperty as $key => $value) {
                            $nestedProp->{$key} = $value;
                        }

                        $nestedProperties->{$subName} = $nestedProp;
                    }
                }

                $nestedProperty->properties        = $nestedProperties;
                $schema->properties->{$propertyName} = $nestedProperty;
            } else {
                $prop = new stdClass();
                foreach ($property as $key => $value) {
                    // Skip 'required' property on this level.
                    if ($key !== 'required' && ($value !== null && $value !== '')) {
                        $prop->{$key} = $value;
                    }
                }

                $schema->properties->{$propertyName} = $prop;
            }//end if
        }//end foreach

        return $schema;

    }//end getSchemaObject()


    /**
     * Set the slug, ensuring it is always lowercase
     *
     * @param string|null $slug The slug to set
     *
     * @return void
     */
    public function setSlug(?string $slug): void
    {
        if ($slug !== null) {
            $slug = strtolower($slug);
        }

        $this->slug = $slug;
        $this->markFieldUpdated('slug');

    }//end setSlug()


    /**
     * Get the icon for the schema
     *
     * @return string|null The icon reference from Material Design Icons
     */
    public function getIcon(): ?string
    {
        return $this->icon;

    }//end getIcon()


    /**
     * Set the icon for the schema
     *
     * @param string|null $icon The icon reference from Material Design Icons
     *
     * @return void
     */
    public function setIcon(?string $icon): void
    {
        $this->icon = $icon;
        $this->markFieldUpdated('icon');

    }//end setIcon()


}//end class
