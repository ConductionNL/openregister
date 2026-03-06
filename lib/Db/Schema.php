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
use Exception;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;

/**
 * Class Schema
 *
 * Entity class representing a Schema
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getUri()
 * @method void setUri(?string $uri)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method array|null getRequired()
 * @method void setRequired(array|string|null $required)
 * @method array|null getProperties()
 * @method void setProperties(?array $properties)
 * @method array|null getArchive()
 * @method void setArchive(?array $archive)
 * @method array|null getFacets()
 * @method void setFacets(?array $facets)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method bool getHardValidation()
 * @method void setHardValidation(bool $hardValidation)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method int getMaxDepth()
 * @method void setMaxDepth(int $maxDepth)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method array|null getAuthorization()
 * @method void setAuthorization(?array $authorization)
 * @method DateTime|null getDeleted()
 * @method void setDeleted(?DateTime $deleted)
 * @method array|null getConfiguration()
 * @method void setConfiguration(?array $configuration)
 * @method array|null getHooks()
 * @method void setHooks(?array $hooks)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
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
     * Pre-computed facet configuration based on schema properties
     *
     * **PERFORMANCE OPTIMIZATION**: This field stores pre-analyzed facetable fields
     * to eliminate runtime schema analysis when _facetable=true is requested.
     * The facets are automatically generated from schema properties marked with 'facetable': true.
     *
     * @var array|null Facet configuration with field types and options
     */

    /**
     * Facets configuration for the schema
     *
     * @var array|string|null
     */
    protected $facets = null;

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
    protected bool $hardValidation = true;

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
     * Organisation UUID this schema belongs to (for multi-tenancy)
     *
     * @var string|null Organisation UUID this schema belongs to
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
     * Use setConfiguration() method to ensure proper validation of configuration values.
     * See setConfiguration() method documentation for supported options and their validation rules.
     *
     * @var         array|null
     * @phpstan-var array<string, mixed>|null
     * @psalm-var   array<string, mixed>|null
     */

    /**
     * Configuration data for the schema
     *
     * @var array|string|null
     */
    protected $configuration = null;

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
     * Whether objects of this schema should be indexed in SOLR for searching
     *
     * When set to false, objects of this schema will be excluded from SOLR indexing,
     * making them unsearchable through the search functionality but still accessible
     * through direct API calls.
     *
     * @var boolean Whether this schema should be searchable (default: true)
     */
    protected bool $searchable = true;

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
     * @var array<string, array<string>>|null
     */
    protected ?array $groups = [];

    /**
     * Array of schema references that this schema must validate against (all schemas).
     * Implements JSON Schema 'allOf' for multiple inheritance/composition.
     * The instance must validate against ALL schemas in the array.
     * Only additional constraints are allowed (Liskov Substitution Principle).
     * Metadata (title, description, order) can be overridden.
     *
     * @var array|null Array of schema IDs, UUIDs, or slugs
     */
    protected ?array $allOf = null;

    /**
     * Array of schema references where instance must validate against exactly one.
     * Implements JSON Schema 'oneOf' for mutually exclusive options.
     * The instance must validate against EXACTLY ONE schema in the array.
     *
     * @var array|null Array of schema IDs, UUIDs, or slugs
     */
    protected ?array $oneOf = null;

    /**
     * Array of schema references where instance must validate against at least one.
     * Implements JSON Schema 'anyOf' for flexible composition.
     * The instance must validate against AT LEAST ONE schema in the array.
     *
     * @var array|null Array of schema IDs, UUIDs, or slugs
     */
    protected ?array $anyOf = null;

    /**
     * Publication timestamp.
     *
     * When set, this schema becomes publicly accessible regardless of organisation restrictions
     * if published bypass is enabled. The schema is considered published when:
     * - published <= now AND
     * - (depublished IS NULL OR depublished > now)
     *
     * @var DateTime|null Publication timestamp
     */
    protected ?DateTime $published = null;

    /**
     * Depublication timestamp.
     *
     * When set, this schema becomes inaccessible after this date/time.
     * Used together with published to control publication lifecycle.
     *
     * @var DateTime|null Depublication timestamp
     */
    protected ?DateTime $depublished = null;

    /**
     * Hook configurations for schema lifecycle events
     *
     * @var array|null Array of hook configuration objects
     */
    protected ?array $hooks = [];

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
        $this->addType(fieldName: 'facets', type: 'json');
        $this->addType(fieldName: 'allOf', type: 'json');
        $this->addType(fieldName: 'oneOf', type: 'json');
        $this->addType(fieldName: 'anyOf', type: 'json');
        $this->addType(fieldName: 'source', type: 'string');
        $this->addType(fieldName: 'hardValidation', type: Types::BOOLEAN);
        $this->addType(fieldName: 'immutable', type: Types::BOOLEAN);
        $this->addType(fieldName: 'searchable', type: Types::BOOLEAN);
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
        $this->addType(fieldName: 'published', type: 'datetime');
        $this->addType(fieldName: 'depublished', type: 'datetime');
        $this->addType(fieldName: 'hooks', type: 'json');
    }//end __construct()

    /**
     * Get the required data
     *
     * @return array The required data or empty array if null
     */
    public function getRequired(): array
    {
        if ($this->required === null) {
            return [];
        }

        // If it's already an array, return it directly.
        if (is_array($this->required) === true) {
            return $this->required;
        }

        // If it's a JSON string, decode it.
        if (is_string($this->required) === true) {
            $decoded = json_decode($this->required, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
                return $decoded;
            }
        }

        // If we get here, something is wrong - return empty array.
        return [];
    }//end getRequired()

    /**
     * Set the required data
     *
     * Always ensures required is an array, never NULL.
     * This prevents database errors during schema validation.
     *
     * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration.
     * The database stores required as JSON strings, but we want to work with arrays in PHP.
     *
     * @param array|string|null $required The required field names (array or JSON string)
     *
     * @return void
     */
    public function setRequired(array|string|null $required): void
    {
        // **DATABASE COMPATIBILITY**: Handle JSON string from database.
        if (is_string($required) === true) {
            try {
                $decoded = json_decode($required, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
                    $this->required = $decoded;
                } else {
                    // Invalid JSON, set to empty array.
                    $this->required = [];
                }
            } catch (Exception $e) {
                // If decoding fails, set to empty array.
                $this->required = [];
            }

            $this->markFieldUpdated(attribute: 'required');
            return;
        }

        // Always ensure required is an array, never NULL.
        // This is critical for schema validation to work correctly.
        $this->required = ($required ?? []);
        $this->markFieldUpdated(attribute: 'required');
    }//end setRequired()

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
     * Check if any property in the schema has authorization rules defined.
     *
     * This is used to determine if property-level RBAC filtering needs to be applied
     * during object rendering or validation.
     *
     * @return bool True if at least one property has non-empty authorization
     */
    public function hasPropertyAuthorization(): bool
    {
        if (empty($this->properties) === true) {
            return false;
        }

        foreach ($this->properties as $propertyConfig) {
            if (is_array($propertyConfig) === true
                && isset($propertyConfig['authorization']) === true
                && empty($propertyConfig['authorization']) === false
            ) {
                return true;
            }
        }

        return false;
    }//end hasPropertyAuthorization()

    /**
     * Get the authorization rules for a specific property.
     *
     * @param string $propertyName The name of the property
     *
     * @return array|null The authorization rules or null if none defined
     */
    public function getPropertyAuthorization(string $propertyName): ?array
    {
        if (empty($this->properties) === true) {
            return null;
        }

        $propertyConfig = $this->properties[$propertyName] ?? null;
        if ($propertyConfig === null || is_array($propertyConfig) === false) {
            return null;
        }

        $authorization = $propertyConfig['authorization'] ?? null;
        if (empty($authorization) === true) {
            return null;
        }

        return $authorization;
    }//end getPropertyAuthorization()

    /**
     * Get all properties that have authorization rules defined.
     *
     * @return array<string, array> Map of property names to their authorization rules
     */
    public function getPropertiesWithAuthorization(): array
    {
        $result = [];

        if (empty($this->properties) === true) {
            return $result;
        }

        foreach ($this->properties as $propertyName => $propertyConfig) {
            if (is_array($propertyConfig) === true
                && isset($propertyConfig['authorization']) === true
                && empty($propertyConfig['authorization']) === false
            ) {
                $result[$propertyName] = $propertyConfig['authorization'];
            }
        }

        return $result;
    }//end getPropertiesWithAuthorization()

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
     * @return string[] List of JSON field names
     *
     * @psalm-return list<string>
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
     * @param PropertyValidatorHandler $validator The schema property validator
     *
     * @throws \Exception If the properties are invalid
     *
     * @return true True if the properties are valid
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function validateProperties(PropertyValidatorHandler $validator): bool
    {
        if (empty($this->properties) === true) {
            return true;
        }

        return $validator->validateProperties($this->properties);
    }//end validateProperties()

    /**
     * Check if a user group has permission for a specific CRUD action
     *
     * @param string $groupId            The group ID to check
     * @param string $action             The CRUD action (create, read, update, delete)
     * @param string $userId             Optional user ID for owner check
     * @param string $userGroup          Optional user group for admin check
     * @param string $objectOwner        Optional object owner for ownership check
     * @param array  $objectData         Optional object data for conditional match evaluation
     * @param string $objectOrganisation Optional object organisation UUID (@self.organisation)
     * @param string $activeOrganisation Optional user's active organisation UUID for $organisation variable
     *
     * @deprecated Use PermissionHandler::hasGroupPermission() instead.
     *             This method is kept for backward compatibility during the refactoring.
     *
     * @return bool True if the group has permission for the action
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) Conditional authorization rules require multiple checks
     * @SuppressWarnings(PHPMD.NPathComplexity)      Match condition evaluation creates multiple paths
     */
    public function hasPermission(
        string $groupId,
        string $action,
        ?string $userId=null,
        ?string $userGroup=null,
        ?string $objectOwner=null,
        ?array $objectData=null,
        ?string $objectOrganisation=null,
        ?string $activeOrganisation=null
    ): bool {
        // Inline implementation kept for backward compatibility.
        // New code should use PermissionHandler::hasGroupPermission() instead.
        if ($groupId === 'admin' || $userGroup === 'admin') {
            return true;
        }

        if ($userId !== null && $objectOwner !== null && $objectOwner === $userId) {
            return true;
        }

        if (empty($this->authorization) === true) {
            return true;
        }

        if (isset($this->authorization[$action]) === false) {
            return true;
        }

        foreach ($this->authorization[$action] as $entry) {
            if (is_string($entry) === true && $entry === $groupId) {
                return true;
            }

            if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
                if (isset($entry['match']) === false || empty($entry['match']) === true) {
                    return true;
                }
            }
        }//end foreach

        return false;
    }//end hasPermission()

    /**
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array                    $object    The data array to hydrate from
     * @param PropertyValidatorHandler $validator Optional validator for properties
     *
     * @throws \Exception If property validation fails
     *
     * @return static Returns $this for method chaining
     *
     * @SuppressWarnings(PHPMD.NPathComplexity)      Hydration requires handling many optional fields
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function hydrate(array $object, ?PropertyValidatorHandler $validator=null): static
    {
        $jsonFields = $this->getJsonFields();

        if (isset($object['metadata']) === false) {
            $object['metadata'] = [];
        }

        // Default required to empty array if not provided.
        // This ensures validation works correctly.
        if (isset($object['required']) === false) {
            $object['required'] = [];
        }

        // Default hardValidation to true if not explicitly provided.
        // This ensures schemas validate by default unless explicitly disabled.
        if (isset($object['hardValidation']) === false) {
            $object['hardValidation'] = true;
        }

        foreach ($object as $key => $value) {
            // Special handling for 'required' field - must always be an array, never NULL.
            if ($key === 'required') {
                if ($value === null || $value === []) {
                    $value = [];
                }

                $this->setRequired(required: $value);
                continue;
            }

            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

            // Force hardValidation to be set explicitly to override database default.
            // The database column defaults to 0/false, but we want schemas to validate by default.
            if ($key === 'hardValidation') {
                // Explicitly set the value and mark as updated to ensure it persists to database.
                $this->hardValidation = (bool) $value;
                $this->markFieldUpdated(attribute: 'hardValidation');
                continue;
            }

            // Use special validation for configuration.
            if ($key === 'configuration') {
                try {
                    // If it's a JSON string, decode it first.
                    if (is_string($value) === true) {
                        $decoded = json_decode($value, true);
                        // Default to null, only use decoded if valid JSON.
                        $value = null;
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $value = $decoded;
                        }
                    }

                    $this->setConfiguration(configuration: $value);
                } catch (\Exception $exception) {
                    // Silently ignore invalid configuration and set to null.
                    $this->configuration = null;
                    $this->markFieldUpdated(attribute: 'configuration');
                }

                continue;
            }//end if

            // Convert datetime strings to DateTime objects for datetime fields.
            if (in_array($key, ['published', 'depublished', 'created', 'updated', 'deleted'], true) === true) {
                if (is_string($value) === true && $value !== '') {
                    try {
                        $value = new DateTime($value);
                    } catch (\Exception $e) {
                        // If parsing fails, set to null.
                        $value = null;
                    }
                } else if ($value !== null && ($value instanceof DateTime) === false) {
                    $value = null;
                }
            }

            $method = 'set'.ucfirst($key);

            try {
                $this->$method($value);
            } catch (\Exception $exception) {
                // Silently ignore invalid properties.
            }
        }//end foreach

        // Validate properties if validator is provided.
        if ($validator !== null && (($object['properties'] ?? null) !== null)) {
            $this->validateProperties(validator: $validator);
        }

        return $this;
    }//end hydrate()

    /**
     * Serializes the schema to an array
     *
     * Converts entity data to a JSON serializable array
     *
     * @return ((mixed|string[])[]|bool|int|null|string)[] The serialized schema data
     *
     * @psalm-return array{id: int, uuid: null|string, uri: null|string,
     *     slug: null|string, title: null|string, description: null|string,
     *     version: null|string, summary: null|string, icon: null|string,
     *     required: array, properties: array, archive: array|null,
     *     source: null|string, hardValidation: bool, immutable: bool,
     *     searchable: bool, updated: null|string, created: null|string,
     *     maxDepth: int, owner: null|string, application: null|string,
     *     organisation: null|string,
     *     groups: array<string, list<string>>|null, authorization: array|null,
     *     deleted: null|string, published: null|string,
     *     depublished: null|string, configuration: array|null|string,
     *     allOf: array|null, oneOf: array|null, anyOf: array|null}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function jsonSerialize(): array
    {
        $required   = ($this->required ?? []);
        $properties = [];

        if (($this->properties ?? null) !== null) {
            foreach ($this->properties ?? [] as $propertyKey => $property) {
                $isRequired    = (isset($property['required']) && $property['required'] === true);
                $notInRequired = in_array($propertyKey, $required) === false;

                if ($isRequired === true && $notInRequired === true) {
                    $required[] = $propertyKey;
                }

                $properties[$propertyKey] = $property;
            }
        }

        $updated = null;
        if ($this->updated !== null) {
            $updated = $this->updated->format('c');
        }

        $created = null;
        if ($this->created !== null) {
            $created = $this->created->format('c');
        }

        $deleted = null;
        if ($this->deleted !== null) {
            $deleted = $this->deleted->format('c');
        }

        $published = null;
        if (isset($this->published) === true) {
            $published = $this->published->format('c');
        }

        $depublished = null;
        if (isset($this->depublished) === true) {
            $depublished = $this->depublished->format('c');
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
            'searchable'     => $this->searchable,
        // @todo: should be refactored to strict.
            'updated'        => $updated,
            'created'        => $created,
            'maxDepth'       => $this->maxDepth,
            'owner'          => $this->owner,
            'application'    => $this->application,
            'organisation'   => $this->organisation,
            'groups'         => $this->groups,
            'authorization'  => $this->authorization,
            'deleted'        => $deleted,
            'published'      => $published,
            'depublished'    => $depublished,
            'configuration'  => $this->configuration,
            'allOf'          => $this->allOf,
            'oneOf'          => $this->oneOf,
            'anyOf'          => $this->anyOf,
            'facets'         => $this->facets,
            'hooks'          => ($this->hooks ?? []),
        ];
    }//end jsonSerialize()

    /**
     * Converts schema to an object representation
     *
     * @param IURLGenerator $urlGenerator The URL generator for URLs in the schema
     *
     * @return stdClass A standard object representation of the schema
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function getSchemaObject(IURLGenerator $urlGenerator): stdClass
    {
        $schema        = new stdClass();
        $schema->title = $this->title;
        $schema->description = $this->description;
        $schema->version     = $this->version;
        $schema->type        = 'object';
        $schema->required    = $this->required;
        $schema->{'$schema'} = 'https://json-schema.org/draft/2020-12/schema';
        $schema->{'$id'}     = $urlGenerator->getBaseUrl().'/apps/openregister/api/v1/schemas/'.$this->uuid;
        $schema->properties  = new stdClass();

        foreach ($this->properties ?? [] as $propertyName => $property) {
            if (($property['properties'] ?? null) !== null) {
                $nestedProperties         = new stdClass();
                $nestedProperty           = new stdClass();
                $nestedProperty->type     = 'object';
                $nestedProperty->title    = $property['title'];
                $nestedProperty->required = [];

                if (($property['properties'] ?? null) !== null) {
                    foreach ($property['properties'] as $subName => $subProperty) {
                        $isRequired = (($subProperty['required'] ?? null) !== null);
                        if ($isRequired === true && ($subProperty['required'] === true) === true) {
                            $nestedProperty->required[] = $subName;
                        }

                        $nestedProp = new stdClass();
                        foreach ($subProperty as $key => $value) {
                            if ($key === 'oneOf' && empty($value) === true) {
                                continue;
                            }

                            $nestedProp->{$key} = $value;
                        }

                        $nestedProperties->{$subName} = $nestedProp;
                    }
                }

                $nestedProperty->properties          = $nestedProperties;
                $schema->properties->{$propertyName} = $nestedProperty;
                continue;
            }//end if

            $prop = new stdClass();
            foreach ($property as $key => $value) {
                // Skip 'required' property on this level.
                if ($key !== 'required' && (empty($value) === false)) {
                    $prop->{$key} = $value;
                }
            }

            $schema->properties->{$propertyName} = $prop;
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
        // Preserve original case for slug to support camelCase schema names like 'moduleVersie'.
        // Schema slugs should match exactly as defined in the configuration.
        $this->slug = $slug;
        $this->markFieldUpdated(attribute: 'slug');
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
        $this->markFieldUpdated(attribute: 'icon');
    }//end setIcon()

    /**
     * Get the configuration for the schema
     *
     * Ensures that configuration is always returned as an array,
     * automatically decoding JSON strings if necessary.
     *
     * @return array|null The configuration array or null if not set
     */
    public function getConfiguration(): ?array
    {
        if ($this->configuration === null) {
            return null;
        }

        // If it's already an array, return it directly.
        if (is_array($this->configuration) === true) {
            return $this->configuration;
        }

        // If it's a JSON string, decode it.
        if (is_string($this->configuration) === true) {
            $decoded = json_decode($this->configuration, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // If we get here, something is wrong - return null.
        return null;
    }//end getConfiguration()

    /**
     * Set the configuration for the schema with validation
     *
     * Validates and sets the configuration array for the schema.
     *
     * Supported configuration options:
     * - 'objectNameField': (string) A dot-notation path to the field within an object's data
     *   that should be used as its name. Example: 'person.firstName'
     * - 'objectDescriptionField': (string) A dot-notation path to the field for the object's description.
     *   Example: 'case.summary'
     * - 'objectSummaryField': (string) A dot-notation path to the field for the object's summary.
     *   Example: 'article.abstract'
     * - 'objectImageField': (string) A dot-notation path to the field for the object's image.
     *   Example: 'profile.avatar' (should contain base64 encoded image data)
     * - 'allowFiles': (bool) Whether this schema allows file attachments
     * - 'allowedTags': (array) Array of allowed file tags/types for file filtering
     *
     * @param array|string|null $configuration The configuration array/string to validate and set
     *
     * @throws \InvalidArgumentException If configuration contains invalid values
     *
     * @return void
     */
    public function setConfiguration($configuration): void
    {
        if ($configuration === null) {
            $this->configuration = null;
            $this->markFieldUpdated(attribute: 'configuration');
            return;
        }

        try {
            $schemaService   = \OCP\Server::get(\OCA\OpenRegister\Service\SchemaService::class);
            $validatedConfig = $schemaService->validateConfiguration($configuration);
            if (empty($validatedConfig) === false) {
                $this->configuration = $validatedConfig;
            } else {
                $this->configuration = null;
            }
        } catch (\Throwable $e) {
            // Fallback: if service not available, store as-is (during bootstrap/migration).
            if (is_string($configuration) === true) {
                $decoded = json_decode($configuration, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->configuration = $decoded;
                } else {
                    $this->configuration = null;
                }
            } else {
                if (is_array($configuration) === true) {
                    $this->configuration = $configuration;
                } else {
                    $this->configuration = null;
                }
            }
        }//end try

        $this->markFieldUpdated(attribute: 'configuration');
    }//end setConfiguration()

    /**
     * Check whether this schema should be searchable in SOLR
     *
     * @return bool True if schema objects should be indexed in SOLR
     */
    public function isSearchable(): bool
    {
        return $this->searchable;
    }//end isSearchable()

    /**
     * Set whether this schema should be searchable in SOLR
     *
     * @param bool $searchable Whether schema objects should be indexed in SOLR
     *
     * @return void
     */
    public function setSearchable(bool $searchable): void
    {
        $this->searchable = $searchable;
        $this->markFieldUpdated(attribute: 'searchable');
    }//end setSearchable()

    /**
     * String representation of the schema
     *
     * This magic method is required for proper entity handling in Nextcloud
     * when the framework needs to convert the object to a string.
     *
     * @return string String representation of the schema
     */
    public function __toString(): string
    {
        // Return the schema slug if available, otherwise return a descriptive string.
        if ($this->slug !== null && $this->slug !== '') {
            return $this->slug;
        }

        // Fallback to title if slug is not available.
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        // Final fallback with ID.
        return 'Schema #'.($this->id ?? 'unknown');
    }//end __toString()

    /**
     * Get the pre-computed facet configuration
     *
     * @deprecated Since runtime facet computation was implemented, this method is no longer
     *             used for faceting. Facets are now computed at runtime from property-level
     *             `facetable: true` settings. This method is kept for backward compatibility
     *             but the `facets` column can be considered deprecated.
     *             Use schema properties with `facetable: true` instead.
     *
     * @return array|null The facet configuration or null if not computed
     *
     * @phpstan-return array<string, mixed>|null
     * @psalm-return   array<string, mixed>|null
     */
    public function getFacets(): ?array
    {
        if ($this->facets === null) {
            return null;
        }

        // If it's a JSON string, decode it.
        if (is_string($this->facets) === true) {
            $decoded = json_decode($this->facets, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            return null;
        }

        // Otherwise, it's already an array.
        return $this->facets;
    }//end getFacets()

    /**
     * Set the facet configuration
     *
     * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration.
     * The database stores facets as JSON strings, but we want to work with arrays in PHP.
     *
     * @param array|string|null $facets The facet configuration array or JSON string.
     *
     * @return void
     *
     * @deprecated Since runtime facet computation was implemented, this method is no longer
     *             needed. Facets are now computed at runtime from property-level `facetable: true`
     *             settings. Set `facetable: true` on individual properties instead.
     */
    public function setFacets(array|string|null $facets): void
    {
        // **DATABASE COMPATIBILITY**: Handle JSON string from database.
        if (is_string($facets) === true) {
            try {
                $this->facets = json_decode($facets, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    // Invalid JSON, set to null.
                    $this->facets = null;
                }
            } catch (Exception $e) {
                $this->facets = null;
            }

            $this->markFieldUpdated(attribute: 'facets');

            return;
        }

        $this->facets = $facets;
        $this->markFieldUpdated(attribute: 'facets');
    }//end setFacets()

    /**
     * Regenerate facets from current schema properties
     *
     * @deprecated Use SchemaService::regenerateFacetsFromProperties() instead.
     *
     * @return void
     */
    public function regenerateFacetsFromProperties(): void
    {
        try {
            $schemaService = \OCP\Server::get(\OCA\OpenRegister\Service\SchemaService::class);
            $schemaService->regenerateFacetsFromProperties($this);
        } catch (\Throwable $e) {
            // Silently skip if container is not available (e.g. unit tests).
        }
    }//end regenerateFacetsFromProperties()

    /**
     * Get the array of schema references that this schema must validate against (allOf)
     *
     * The instance must validate against ALL schemas in the array.
     * This implements JSON Schema 'allOf' for multiple inheritance/composition.
     *
     * @return array|null Array of schema IDs, UUIDs, or slugs
     */
    public function getAllOf(): ?array
    {
        return $this->allOf;
    }//end getAllOf()

    /**
     * Set the array of schema references that this schema must validate against (allOf)
     *
     * The instance must validate against ALL schemas in the array.
     * Only additional constraints are allowed (Liskov Substitution Principle).
     * Metadata (title, description, order) can be overridden.
     *
     * @param array|null $allOf Array of schema IDs, UUIDs, or slugs
     *
     * @return void
     */
    public function setAllOf(?array $allOf): void
    {
        $this->allOf = $allOf;
        $this->markFieldUpdated(attribute: 'allOf');
    }//end setAllOf()

    /**
     * Get the array of schema references where instance must validate against exactly one (oneOf)
     *
     * The instance must validate against EXACTLY ONE schema in the array.
     * This implements JSON Schema 'oneOf' for mutually exclusive options.
     *
     * @return array|null Array of schema IDs, UUIDs, or slugs
     */
    public function getOneOf(): ?array
    {
        return $this->oneOf;
    }//end getOneOf()

    /**
     * Set the array of schema references where instance must validate against exactly one (oneOf)
     *
     * The instance must validate against EXACTLY ONE schema in the array.
     * This implements JSON Schema 'oneOf' for mutually exclusive options.
     *
     * @param array|null $oneOf Array of schema IDs, UUIDs, or slugs
     *
     * @return void
     */
    public function setOneOf(?array $oneOf): void
    {
        $this->oneOf = $oneOf;
        $this->markFieldUpdated(attribute: 'oneOf');
    }//end setOneOf()

    /**
     * Get the array of schema references where instance must validate against at least one (anyOf)
     *
     * The instance must validate against AT LEAST ONE schema in the array.
     * This implements JSON Schema 'anyOf' for flexible composition.
     *
     * @return array|null Array of schema IDs, UUIDs, or slugs
     */
    public function getAnyOf(): ?array
    {
        return $this->anyOf;
    }//end getAnyOf()

    /**
     * Set the array of schema references where instance must validate against at least one (anyOf)
     *
     * The instance must validate against AT LEAST ONE schema in the array.
     * This implements JSON Schema 'anyOf' for flexible composition.
     *
     * @param array|null $anyOf Array of schema IDs, UUIDs, or slugs
     *
     * @return void
     */
    public function setAnyOf(?array $anyOf): void
    {
        $this->anyOf = $anyOf;
        $this->markFieldUpdated(attribute: 'anyOf');
    }//end setAnyOf()

    /**
     * Get the publication timestamp
     *
     * @return DateTime|null Publication timestamp
     */
    public function getPublished(): ?DateTime
    {
        return $this->published;
    }//end getPublished()

    /**
     * Set the publication timestamp
     *
     * @param DateTime|string|null $published Publication timestamp (DateTime object or ISO 8601 string)
     *
     * @return void
     */
    public function setPublished(DateTime|string|null $published): void
    {
        if (is_string($published) === true) {
            $published = new DateTime($published);
        }

        $this->published = $published;
        $this->markFieldUpdated(attribute: 'published');
    }//end setPublished()

    /**
     * Get the depublication timestamp
     *
     * @return DateTime|null Depublication timestamp
     */
    public function getDepublished(): ?DateTime
    {
        return $this->depublished;
    }//end getDepublished()

    /**
     * Set the depublication timestamp
     *
     * @param DateTime|string|null $depublished Depublication timestamp (DateTime object or ISO 8601 string)
     *
     * @return void
     */
    public function setDepublished(DateTime|string|null $depublished): void
    {
        if (is_string($depublished) === true) {
            $depublished = new DateTime($depublished);
        }

        $this->depublished = $depublished;
        $this->markFieldUpdated(attribute: 'depublished');
    }//end setDepublished()

    /**
     * Check if this schema is managed by any configuration
     *
     * This method checks if the schema's ID is present in the schemas array
     * of any provided configuration entities.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return bool True if this schema is managed by at least one configuration
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
            $schemas = $configuration->getSchemas();
            if (in_array($this->id, $schemas ?? [], true) === true) {
                return true;
            }
        }

        return false;
    }//end isManagedByConfiguration()

    /**
     * Get the configuration that manages this schema
     *
     * Returns the first configuration that has this schema's ID in its schemas array.
     * Returns null if the schema is not managed by any configuration.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return Configuration|null The configuration managing this schema, or null
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
            $schemas = $configuration->getSchemas();
            if (in_array($this->id, $schemas ?? [], true) === true) {
                return $configuration;
            }
        }

        return null;
    }//end getManagedByConfiguration()
}//end class
