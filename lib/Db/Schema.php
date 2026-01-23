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
use InvalidArgumentException;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use OCP\IURLGenerator;
use stdClass;
use Exception;
use RuntimeException;
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
 * @method void setRequired(?array $required)
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
     * Set the required data
     *
     * Always ensures required is an array, never NULL.
     * This prevents database errors during schema validation.
     *
     * @param array|null $required The required field names
     *
     * @return void
     */
    public function setRequired(?array $required): void
    {
        // Always ensure required is an array, never NULL.
        // This is critical for schema validation to work correctly.
        $this->required = ($required ?? []);
        $this->markFieldUpdated('required');
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
        // Check if properties are set and not empty.
        if (empty($this->properties) === true) {
            return true;
        }

        // Validate and normalize inversedBy properties to ensure they are strings.
        // TODO: Move writeBack, removeAfterWriteBack, and inversedBy
        // from items property to configuration property.
        $this->normalizeInversedByProperties();

        return $validator->validateProperties($this->properties);
    }//end validateProperties()

    /**
     * Validate the authorization structure for RBAC
     *
     * Validates that the authorization array follows the correct structure:
     * - Keys must be valid CRUD actions (create, read, update, delete)
     * - Values must be arrays of group IDs (strings)
     * - Group IDs must be non-empty strings
     *
     * Also validates property-level authorization if any properties have authorization defined.
     *
     * @throws \InvalidArgumentException If the authorization structure is invalid
     *
     * @return true True if the authorization structure is valid
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    public function validateAuthorization(): bool
    {
        // Validate schema-level authorization.
        $this->validateAuthorizationRules(authorization: $this->authorization, context: 'schema');

        // Validate property-level authorization.
        $this->validatePropertyAuthorization();

        return true;
    }//end validateAuthorization()

    /**
     * Validate an authorization rules array
     *
     * @param array|null $authorization The authorization rules to validate
     * @param string     $context       Context for error messages (e.g., 'schema' or 'property "fieldName"')
     *
     * @throws \InvalidArgumentException If the authorization structure is invalid
     *
     * @return void
     */
    private function validateAuthorizationRules(?array $authorization, string $context): void
    {
        if (empty($authorization) === true) {
            return;
        }

        $validActions = ['create', 'read', 'update', 'delete'];

        foreach ($authorization as $action => $rules) {
            // Validate action is a valid CRUD operation.
            if (in_array($action, $validActions) === false) {
                $validList = implode(', ', $validActions);
                $msg       = "Invalid authorization action '{$action}' in {$context}. Must be one of: {$validList}";
                throw new InvalidArgumentException($msg);
            }

            // Validate rules is an array.
            if (is_array($rules) === false) {
                throw new InvalidArgumentException(
                    "Authorization rules for action '{$action}' in {$context} must be an array"
                );
            }

            // Validate each rule is either a string (simple) or a valid conditional object.
            foreach ($rules as $rule) {
                $this->validateAuthorizationRule(rule: $rule, action: $action, context: $context);
            }
        }//end foreach
    }//end validateAuthorizationRules()

    /**
     * Validate property-level authorization
     *
     * Iterates through all properties and validates their authorization rules
     * using the same structure as schema-level authorization.
     *
     * @throws \InvalidArgumentException If any property authorization is invalid
     *
     * @return void
     */
    private function validatePropertyAuthorization(): void
    {
        if (empty($this->properties) === true) {
            return;
        }

        foreach ($this->properties as $propertyName => $propertyConfig) {
            if (is_array($propertyConfig) === false) {
                continue;
            }

            $authorization = $propertyConfig['authorization'] ?? null;
            if (empty($authorization) === true) {
                continue;
            }

            if (is_array($authorization) === false) {
                throw new InvalidArgumentException(
                    "Authorization for property '{$propertyName}' must be an array"
                );
            }

            $this->validateAuthorizationRules(
                authorization: $authorization,
                context: "property '{$propertyName}'"
            );
        }//end foreach
    }//end validatePropertyAuthorization()

    /**
     * Validate a single authorization rule
     *
     * Rules can be:
     * - Simple: a non-empty string (group name)
     * - Conditional: an array with 'group' (required) and 'match' (optional)
     *
     * @param mixed  $rule    The rule to validate
     * @param string $action  The CRUD action for error messages
     * @param string $context Context for error messages (default: 'schema')
     *
     * @return void
     *
     * @throws InvalidArgumentException If the rule is invalid
     */
    private function validateAuthorizationRule(mixed $rule, string $action, string $context = 'schema'): void
    {
        // Simple rule: non-empty string (group name).
        if (is_string($rule) === true) {
            if (trim($rule) === '') {
                throw new InvalidArgumentException(
                    "Group ID in authorization for action '{$action}' in {$context} must be a non-empty string"
                );
            }

            return;
        }

        // Conditional rule: array with 'group' key.
        if (is_array($rule) === true) {
            // Validate 'group' key exists and is a non-empty string.
            if (isset($rule['group']) === false) {
                throw new InvalidArgumentException(
                    "Conditional authorization rule for action '{$action}' in {$context} must have a 'group' key"
                );
            }

            if (is_string($rule['group']) === false || trim($rule['group']) === '') {
                throw new InvalidArgumentException(
                    "Conditional authorization 'group' for action '{$action}' in {$context} must be a non-empty string"
                );
            }

            // Validate 'match' key if present.
            if (isset($rule['match']) === true) {
                if (is_array($rule['match']) === false) {
                    throw new InvalidArgumentException(
                        "Conditional authorization 'match' for action '{$action}' in {$context} must be an array"
                    );
                }
            }

            return;
        }//end if

        // Invalid rule type.
        throw new InvalidArgumentException(
            "Authorization rule for action '{$action}' in {$context} must be a string or conditional object"
        );
    }//end validateAuthorizationRule()

    /**
     * Check if a user group has permission for a specific CRUD action
     *
     * Rules:
     * - If no authorization is set, all groups have all permissions
     * - If authorization is set but action is not specified, all groups have permission for that action
     * - The 'admin' group always has all permissions
     * - Object owner always has all permissions for their specific objects
     *
     * TODO: Extend this method to support property-level permission checks
     * Add optional $propertyName parameter to check property-specific authorization.
     * When $propertyName is provided, check the property's authorization array first,
     * then fall back to schema-level authorization if no property-level authorization exists.
     *
     * @param string $groupId     The group ID to check
     * @param string $action      The CRUD action (create, read, update, delete)
     * @param string $userId      Optional user ID for owner check
     * @param string $userGroup   Optional user group for admin check
     * @param string $objectOwner Optional object owner for ownership check
     *
     * @return bool True if the group has permission for the action
     */
    public function hasPermission(
        string $groupId,
        string $action,
        ?string $userId=null,
        ?string $userGroup=null,
        ?string $objectOwner=null
    ): bool {
        // Admin group always has all permissions.
        if ($groupId === 'admin' || $userGroup === 'admin') {
            return true;
        }

        // Object owner always has all permissions for their specific objects.
        if ($userId !== null && $objectOwner !== null && $objectOwner === $userId) {
            return true;
        }

        // If no authorization is set, everyone has all permissions.
        if (empty($this->authorization) === true) {
            return true;
        }

        // If action is not specified in authorization, everyone has permission.
        if (isset($this->authorization[$action]) === false) {
            return true;
        }

        // Check if group is in the allowed groups for this action.
        return in_array($groupId, $this->authorization[$action] ?? []);
    }//end hasPermission()

    /**
     * Get all groups that have permission for a specific action
     *
     * @param string $action The CRUD action to check
     *
     * @return array Array of group IDs that have permission, or empty array if all groups have permission
     */
    public function getAuthorizedGroups(string $action): array
    {
        // If no authorization is set, return empty array (meaning all groups).
        if (empty($this->authorization) === true) {
            return [];
        }

        // If action is not specified, return empty array (meaning all groups).
        if (isset($this->authorization[$action]) === false) {
            return [];
        }

        // Return the specific groups that have permission.
        return $this->authorization[$action] ?? [];
    }//end getAuthorizedGroups()

    /**
     * Normalize inversedBy properties to ensure they are always strings
     *
     * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function normalizeInversedByProperties(): void
    {
        if (empty($this->properties) === true) {
            return;
        }

        foreach ($this->properties as $propertyName => $property) {
            // Handle regular object properties.
            // TODO: Move writeBack, removeAfterWriteBack, and inversedBy
            // from items property to configuration property.
            if (($property['inversedBy'] ?? null) !== null) {
                $inversedById = ($property['inversedBy']['id'] ?? null);
                if (is_array($property['inversedBy']) === true && $inversedById !== null) {
                    $this->properties[$propertyName]['inversedBy'] = $property['inversedBy']['id'];
                    continue;
                }

                if (is_string($property['inversedBy']) === false) {
                    // Remove invalid inversedBy if it's not a string or object with id.
                    unset($this->properties[$propertyName]['inversedBy']);
                }
            }

            // Handle array items with inversedBy.
            // TODO: Move writeBack, removeAfterWriteBack, and inversedBy
            // from items property to configuration property.
            if (($property['items']['inversedBy'] ?? null) !== null) {
                $itemsInversedById = ($property['items']['inversedBy']['id'] ?? null);
                if (is_array($property['items']['inversedBy']) === true && $itemsInversedById !== null) {
                    $this->properties[$propertyName]['items']['inversedBy'] = $property['items']['inversedBy']['id'];
                    continue;
                }

                if (is_string($property['items']['inversedBy']) === false) {
                    // Remove invalid inversedBy if it's not a string or object with id.
                    unset($this->properties[$propertyName]['items']['inversedBy']);
                }
            }
        }//end foreach
    }//end normalizeInversedByProperties()

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

                $this->setRequired($value);
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
                $this->markFieldUpdated('hardValidation');
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

                    $this->setConfiguration($value);
                } catch (\Exception $exception) {
                    // Silently ignore invalid configuration and set to null.
                    $this->configuration = null;
                    $this->markFieldUpdated('configuration');
                }

                continue;
            }//end if

            // Convert datetime strings to DateTime objects for datetime fields.
            if (in_array($key, ['published', 'depublished', 'created', 'updated', 'deleted'], true) === true) {
                if (is_string($value) === true && $value !== '') {
                    try {
                        $value = new \DateTime($value);
                    } catch (\Exception $e) {
                        // If parsing fails, set to null.
                        $value = null;
                    }
                } else if ($value !== null && ($value instanceof \DateTime) === false) {
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
            $this->validateProperties($validator);
        }

        // Validate authorization structure.
        if (($object['authorization'] ?? null) !== null) {
            $this->validateAuthorization();
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
        ];
    }//end jsonSerialize()

    /**
     * Converts schema to an object representation
     *
     * Creates a standard object representation of the schema for API use
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
            $this->markFieldUpdated('configuration');
            return;
        }

        $parsedConfig = $this->parseConfigurationInput($configuration);
        if ($parsedConfig === null) {
            $this->configuration = null;
            $this->markFieldUpdated('configuration');
            return;
        }

        $validatedConfig = $this->validateConfigurationArray($parsedConfig);

        $this->configuration = null;
        if (empty($validatedConfig) === false) {
            $this->configuration = $validatedConfig;
        }

        $this->markFieldUpdated('configuration');
    }//end setConfiguration()

    /**
     * Parse configuration input into an array
     *
     * @param mixed $configuration Configuration input
     *
     * @return array|null Parsed array or null if invalid
     */
    private function parseConfigurationInput(mixed $configuration): array|null
    {
        if (is_array($configuration) === true) {
            return $configuration;
        }

        if (is_string($configuration) === true) {
            $decoded = json_decode($configuration, true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }//end parseConfigurationInput()

    /**
     * Validate configuration array
     *
     * @param array $configuration Configuration array to validate
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return array Validated configuration
     */
    private function validateConfigurationArray(array $configuration): array
    {
        $validatedConfig = [];
        $stringFields    = ['objectNameField', 'objectDescriptionField', 'objectSummaryField', 'objectImageField'];
        $boolFields      = ['allowFiles', 'autoPublish'];
        $passThrough     = ['unique', 'facetCacheTtl'];

        foreach ($configuration as $key => $value) {
            if (in_array($key, $stringFields, true) === true) {
                $validatedConfig[$key] = $this->validateStringConfigValue(key: $key, value: $value);
                continue;
            }

            if (in_array($key, $boolFields, true) === true) {
                $this->validateBoolConfigValue(key: $key, value: $value);
                $validatedConfig[$key] = $value;
                continue;
            }

            if ($key === 'allowedTags') {
                $this->validateAllowedTagsValue($value);
                $validatedConfig[$key] = $value;
                continue;
            }

            if (in_array($key, $passThrough, true) === true) {
                $validatedConfig[$key] = $value;
            }
        }//end foreach

        return $validatedConfig;
    }//end validateConfigurationArray()

    /**
     * Validate a string configuration value
     *
     * @param string $key   Configuration key
     * @param mixed  $value Configuration value
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return string|null Validated value
     */
    private function validateStringConfigValue(string $key, mixed $value): string|null
    {
        if ($value !== null && $value !== '' && is_string($value) === false) {
            throw new InvalidArgumentException("Configuration '{$key}' must be a string or null");
        }

        if ($value === '') {
            return null;
        }

        return $value;
    }//end validateStringConfigValue()

    /**
     * Validate a boolean configuration value
     *
     * @param string $key   Configuration key
     * @param mixed  $value Configuration value
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return void
     */
    private function validateBoolConfigValue(string $key, mixed $value): void
    {
        if ($value !== null && is_bool($value) === false) {
            throw new InvalidArgumentException("Configuration '{$key}' must be a boolean or null");
        }
    }//end validateBoolConfigValue()

    /**
     * Validate the allowedTags configuration value
     *
     * @param mixed $value Configuration value
     *
     * @throws \InvalidArgumentException If validation fails
     *
     * @return void
     */
    private function validateAllowedTagsValue(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value) === false) {
            throw new InvalidArgumentException("Configuration 'allowedTags' must be an array or null");
        }

        foreach ($value as $tag) {
            if (is_string($tag) === false) {
                throw new InvalidArgumentException("All values in 'allowedTags' must be strings");
            }
        }
    }//end validateAllowedTagsValue()

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
        $this->markFieldUpdated('searchable');
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
     * **PERFORMANCE OPTIMIZATION**: Returns pre-analyzed facetable fields stored
     * in the schema to eliminate runtime analysis during _facetable=true requests.
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
     * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration
     * The database stores facets as JSON strings, but we want to work with arrays in PHP.
     *
     * @param array|string|null $facets The facet configuration array or JSON string
     *
     * @return void
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

            $this->markFieldUpdated('facets');

            return;
        }

        $this->facets = $facets;
        $this->markFieldUpdated('facets');
    }//end setFacets()

    /**
     * Regenerate facets from current schema properties
     *
     * **PERFORMANCE OPTIMIZATION**: This method analyzes the current schema properties
     * and automatically generates facet configurations for fields marked with 'facetable': true.
     * This eliminates the need for runtime analysis during search operations.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function regenerateFacetsFromProperties(): void
    {
        $properties = $this->getProperties();

        if (empty($properties) === true) {
            $this->setFacets(null);
            return;
        }

        $facetConfig = [
            'object_fields'  => [],
            'generated_at'   => time(),
            'schema_version' => $this->getVersion() ?? '1.0',
        ];

        // Analyze each property for facetable configuration.
        foreach ($properties as $propertyKey => $property) {
            // Skip properties that are not marked as facetable.
            if (isset($property['facetable']) === false || $property['facetable'] !== true) {
                continue;
            }

            // Determine appropriate facet type based on property configuration.
            $facetType = $this->determineFacetType($property);

            if ($facetType !== null) {
                $facetConfig['object_fields'][$propertyKey] = [
                    'type'           => $facetType,
                    'title'          => $property['title'] ?? $propertyKey,
                    'description'    => $property['description'] ?? null,
                    'data_type'      => $property['type'] ?? 'string',
                    'queryParameter' => $propertyKey,
                ];

                // Add type-specific configuration.
                if ($facetType === 'date_histogram') {
                    $facetConfig['object_fields'][$propertyKey]['default_interval']    = 'month';
                    $facetConfig['object_fields'][$propertyKey]['supported_intervals'] = ['day', 'week', 'month', 'year'];
                } else if ($facetType === 'range') {
                    $facetConfig['object_fields'][$propertyKey]['supports_custom_ranges'] = true;
                } else if ($facetType === 'terms' && (($property['enum'] ?? null) !== null)) {
                    $facetConfig['object_fields'][$propertyKey]['predefined_values'] = $property['enum'];
                }
            }
        }//end foreach

        // Set the generated facet configuration.
        $this->setFacets($facetConfig);
    }//end regenerateFacetsFromProperties()

    /**
     * Determine the appropriate facet type for a property
     *
     * @param array $property The property configuration
     *
     * @phpstan-param array<string, mixed> $property
     *
     * @psalm-param array<string, mixed> $property
     *
     * @return string The facet type
     *
     * @phpstan-return string|null
     *
     * @psalm-return 'date_histogram'|'range'|'terms'
     */
    private function determineFacetType(array $property): string
    {
        $type   = $property['type'] ?? 'string';
        $format = $property['format'] ?? null;

        // Date/datetime fields use date_histogram.
        if ($type === 'string' && ($format === 'date' || $format === 'date-time')) {
            return 'date_histogram';
        }

        // Numeric fields can use range facets.
        if ($type === 'number' || $type === 'integer') {
            return 'range';
        }

        // String fields with enums or categorical data use terms.
        if ($type === 'string' || $type === 'boolean') {
            return 'terms';
        }

        // Arrays typically use terms (for categorical values).
        if ($type === 'array') {
            return 'terms';
        }

        // Default to terms for other types.
        return 'terms';
    }//end determineFacetType()

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
        $this->markFieldUpdated('allOf');
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
        $this->markFieldUpdated('oneOf');
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
        $this->markFieldUpdated('anyOf');
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
        $this->markFieldUpdated('published');
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
        $this->markFieldUpdated('depublished');
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
