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
 * @method bool getAppendOnly()
 * @method void setAppendOnly(bool $appendOnly)
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
 * @method array|null getMail()
 * @method void setMail(?array $mail)
 * @method array|null getContacts()
 * @method void setContacts(?array $contacts)
 * @method array|null getNotes()
 * @method void setNotes(?array $notes)
 * @method array|null getTodos()
 * @method void setTodos(?array $todos)
 * @method array|null getCalendar()
 * @method void setCalendar(?array $calendar)
 * @method array|null getTalk()
 * @method void setTalk(?array $talk)
 * @method array|null getDeck()
 * @method void setDeck(?array $deck)
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 *
 * @psalm-suppress                                PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
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
     * Whether objects of this schema are append-only (INSERT allowed; UPDATE and DELETE rejected)
     *
     * When true, new objects can be created but existing objects cannot be mutated or removed.
     * This is used for append-only audit logs (e.g. xAPI statements, compliance attestations)
     * where immutability of past records is a business or legal requirement.
     *
     * @var boolean
     */
    protected bool $appendOnly = false;

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
     * Hooks configuration for the schema
     *
     * @var array|null Hooks configuration
     */
    protected ?array $hooks = null;

    /**
     * Linked mail entity IDs for this schema.
     *
     * @var array|null Linked mail entity IDs
     */
    protected ?array $mail = null;

    /**
     * Linked contact entity IDs for this schema.
     *
     * @var array|null Linked contact entity IDs
     */
    protected ?array $contacts = null;

    /**
     * Linked note entity IDs for this schema.
     *
     * @var array|null Linked note entity IDs
     */
    protected ?array $notes = null;

    /**
     * Linked todo entity IDs for this schema.
     *
     * @var array|null Linked todo entity IDs
     */
    protected ?array $todos = null;

    /**
     * Linked calendar event entity IDs for this schema.
     *
     * @var array|null Linked calendar event entity IDs
     */
    protected ?array $calendar = null;

    /**
     * Linked Talk conversation IDs for this schema.
     *
     * @var array|null Linked Talk conversation IDs
     */
    protected ?array $talk = null;

    /**
     * Linked Deck card IDs for this schema.
     *
     * @var array|null Linked Deck card IDs
     */
    protected ?array $deck = null;

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
        $this->addType(fieldName: 'appendOnly', type: Types::BOOLEAN);
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
        $this->addType(fieldName: 'mail', type: 'json');
        $this->addType(fieldName: 'contacts', type: 'json');
        $this->addType(fieldName: 'notes', type: 'json');
        $this->addType(fieldName: 'todos', type: 'json');
        $this->addType(fieldName: 'calendar', type: 'json');
        $this->addType(fieldName: 'talk', type: 'json');
        $this->addType(fieldName: 'deck', type: 'json');
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
                // Invalid JSON, set to empty array.
                $this->required = [];
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
                    $this->required = $decoded;
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
    private function validateAuthorizationRule(mixed $rule, string $action, string $context='schema'): void
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
     * @param string $groupId            The group ID to check
     * @param string $action             The CRUD action (create, read, update, delete)
     * @param string $userId             Optional user ID for owner check
     * @param string $userGroup          Optional user group for admin check
     * @param string $objectOwner        Optional object owner for ownership check
     * @param array  $objectData         Optional object data for conditional match evaluation
     * @param string $objectOrganisation Optional object organisation UUID (@self.organisation)
     * @param string $activeOrganisation Optional user's active organisation UUID for $organisation variable
     *
     * @return bool True if the group has permission for the action
     *
     * @deprecated This is a pre-unification duplicate of the RBAC matcher. The
     *             canonical evaluators are {@see \OCA\OpenRegister\Service\Object\PermissionHandler::hasPermission()}
     *             (PHP-side) and {@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::applyRbacFilters()}
     *             (SQL-side), both of which delegate conditional match evaluation
     *             to {@see \OCA\OpenRegister\Service\ConditionMatcher}. This method
     *             has no production callers (only test coverage via
     *             BasicCrudTest/RbacTest/RbacComprehensiveTest) and is retained
     *             solely to avoid churning the test suite in the #1336 unification
     *             change. Scheduled for removal in a follow-up cleanup; do not
     *             add new callers.
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

        // Check each authorization entry for this action.
        foreach ($this->authorization[$action] as $entry) {
            // Simple string entry: direct group match.
            if (is_string($entry) === true) {
                if ($entry === $groupId) {
                    return true;
                }

                continue;
            }

            // Complex entry with match conditions: {"group": "...", "match": {"field": "value"}}.
            if (is_array($entry) === true && isset($entry['group']) === true && $entry['group'] === $groupId) {
                // If no match conditions, the group match alone is sufficient.
                if (isset($entry['match']) === false || empty($entry['match']) === true) {
                    return true;
                }

                // Evaluate all match conditions (all must pass).
                if ($this->evaluateMatchConditions(
                    conditions: $entry['match'],
                    objectData: $objectData,
                    objectOrganisation: $objectOrganisation,
                    activeOrganisation: $activeOrganisation
                ) === true
                ) {
                    return true;
                }
            }
        }//end foreach

        return false;
    }//end hasPermission()

    /**
     * Evaluate match conditions from a conditional authorization entry.
     *
     * Supports variable substitution:
     * - $organisation → replaced with the user's active organisation UUID
     *
     * Supports special field prefixes:
     * - _organisation → matches against the object's @self.organisation
     * - Other fields → matched against the object data
     *
     * @param array  $conditions         Key-value pairs of field => expected value
     * @param array  $objectData         The object's data fields
     * @param string $objectOrganisation The object's @self.organisation
     * @param string $activeOrganisation The user's active organisation UUID
     *
     * @return bool True if all conditions are satisfied
     *
     * @deprecated See {@see self::hasPermission()} — this helper exists only to
     *             serve the deprecated entity-level matcher. Operator and
     *             dynamic-variable support is deliberately narrower than the
     *             canonical {@see \OCA\OpenRegister\Service\ConditionMatcher}.
     *             Do not add callers.
     */
    private function evaluateMatchConditions(
        array $conditions,
        ?array $objectData,
        ?string $objectOrganisation,
        ?string $activeOrganisation
    ): bool {
        foreach ($conditions as $field => $expectedValue) {
            // Resolve $organisation variable in the expected value.
            if ($expectedValue === '$organisation') {
                if ($activeOrganisation === null) {
                    return false;
                }

                $expectedValue = $activeOrganisation;
            }

            // Get the actual value to compare against.
            // Regular field: match against object data.
            $actualValue = $objectData[$field] ?? null;
            if ($field === '_organisation') {
                // Special field: match against @self.organisation.
                $actualValue = $objectOrganisation;
            }

            // If the actual value is an array with an 'id' key (resolved relation), use the id.
            if (is_array($actualValue) === true && isset($actualValue['id']) === true) {
                $actualValue = $actualValue['id'];
            }

            // Compare values.
            if ($actualValue !== $expectedValue) {
                return false;
            }
        }//end foreach

        return true;
    }//end evaluateMatchConditions()

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
                    // Legacy object format: {"id": "fieldName"} → normalize to string.
                    $this->properties[$propertyName]['inversedBy'] = $property['inversedBy']['id'];
                    continue;
                }

                // Allow arrays of strings (multi-field inversedBy, e.g., ["moduleA", "moduleB"]).
                if (is_array($property['inversedBy']) === true
                    && array_is_list($property['inversedBy']) === true
                ) {
                    continue;
                }

                if (is_string($property['inversedBy']) === false) {
                    // Remove invalid inversedBy if it's not a string, array of strings, or object with id.
                    unset($this->properties[$propertyName]['inversedBy']);
                }
            }

            // Handle array items with inversedBy.
            // TODO: Move writeBack, removeAfterWriteBack, and inversedBy
            // from items property to configuration property.
            if (($property['items']['inversedBy'] ?? null) !== null) {
                $itemsInversedById = ($property['items']['inversedBy']['id'] ?? null);
                if (is_array($property['items']['inversedBy']) === true && $itemsInversedById !== null) {
                    // Legacy object format: {"id": "fieldName"} → normalize to string.
                    $this->properties[$propertyName]['items']['inversedBy'] = $property['items']['inversedBy']['id'];
                    continue;
                }

                // Allow arrays of strings (multi-field inversedBy, e.g., ["moduleA", "moduleB"]).
                if (is_array($property['items']['inversedBy']) === true
                    && array_is_list($property['items']['inversedBy']) === true
                ) {
                    continue;
                }

                if (is_string($property['items']['inversedBy']) === false) {
                    // Remove invalid inversedBy if it's not a string, array of strings, or object with id.
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
                    } catch (Exception $e) {
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
     *     source: null|string, hardValidation: bool, immutable: bool, appendOnly: bool,
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
            'appendOnly'     => $this->appendOnly,
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
            'hooks'          => $this->hooks,
            '_mail'          => $this->mail,
            '_contacts'      => $this->contacts,
            '_notes'         => $this->notes,
            '_todos'         => $this->todos,
            '_calendar'      => $this->calendar,
            '_talk'          => $this->talk,
            '_deck'          => $this->deck,
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

            // Mark computed properties as readOnly in JSON Schema / OpenAPI output.
            if (isset($property['computed']) === true && is_array($property['computed']) === true) {
                $prop->readOnly = true;
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
     * Get the calendar provider configuration from the schema configuration
     *
     * Extracts the calendarProvider section from the configuration JSON.
     * Returns null if not present or if enabled is false.
     *
     * @return array|null The calendar provider config array, or null if disabled/absent
     */
    public function getCalendarProviderConfig(): ?array
    {
        $configuration = $this->getConfiguration();

        if ($configuration === null) {
            return null;
        }

        $calendarConfig = $configuration['calendarProvider'] ?? null;

        if ($calendarConfig === null || is_array($calendarConfig) === false) {
            return null;
        }

        if (empty($calendarConfig['enabled']) === true) {
            return null;
        }

        return $calendarConfig;
    }//end getCalendarProviderConfig()

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

        $parsedConfig = $this->parseConfigurationInput(configuration: $configuration);
        if ($parsedConfig === null) {
            $this->configuration = null;
            $this->markFieldUpdated(attribute: 'configuration');
            return;
        }

        $validatedConfig = $this->validateConfigurationArray(configuration: $parsedConfig);

        $this->configuration = null;
        if (empty($validatedConfig) === false) {
            $this->configuration = $validatedConfig;
        }

        $this->markFieldUpdated(attribute: 'configuration');
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
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function validateConfigurationArray(array $configuration): array
    {
        $validatedConfig = [];
        $stringFields    = ['objectNameField', 'objectDescriptionField', 'objectSummaryField', 'objectImageField'];
        $boolFields      = ['allowFiles', 'autoPublish'];
        $passThrough     = ['unique', 'facetCacheTtl', 'calendarProvider'];

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
                $this->validateAllowedTagsValue(value: $value);
                $validatedConfig[$key] = $value;
                continue;
            }

            if ($key === 'linkedTypes') {
                $this->validateLinkedTypesValue(value: $value);
                $validatedConfig[$key] = $value;
                continue;
            }

            if ($key === 'calendarProvider' && is_array($value) === true) {
                $this->validateCalendarProviderConfig(config: $value);
                $validatedConfig[$key] = $value;
                continue;
            }

            if (in_array($key, $passThrough, true) === true) {
                $validatedConfig[$key] = $value;
                continue;
            }

            // Allow declarative annotation extensions to round-trip
            // through the schema's configuration column. Validation of
            // their shape is done by the dedicated validators (e.g.
            // LifecycleAnnotationValidator) at schema-save time.
            //
            // The key MUST be in the declared vocabulary — unknown
            // `x-openregister-*` keys are silently dropped to surface
            // typos at save time rather than persisting them and having
            // them silently no-op (e.g. `x-openregister-lifecycl` would
            // otherwise round-trip without ever firing the listener).
            if (str_starts_with((string) $key, 'x-openregister-') === true) {
                if (in_array((string) $key, self::ANNOTATION_VOCABULARY, true) === true) {
                    $validatedConfig[$key] = $value;
                } else {
                    // R07: track unknown `x-openregister-*` keys (almost
                    // always typos like `x-openregister-lifecycl`) so
                    // SchemaMapper can log them via its structured
                    // logger after save. The entity has no DI surface
                    // for a logger and the ADR added in F06 bans the
                    // `\OC::$server` static accessor — collecting on
                    // the entity and bridging through the mapper is
                    // the cleanest path that still surfaces a signal.
                    $this->droppedAnnotationKeys[] = (string) $key;
                }//end if
            }//end if
        }//end foreach

        return $validatedConfig;
    }//end validateConfigurationArray()

    /**
     * R07: dropped `x-openregister-*` keys collected during the most
     * recent `validateConfigurationArray()` pass. SchemaMapper reads
     * this after `setConfiguration()` and emits a logger->warning()
     * for each entry so operators see a signal without us having to
     * inject a logger into the entity itself.
     *
     * @var array<int, string>
     */
    private array $droppedAnnotationKeys = [];

    /**
     * Return + reset the list of dropped annotation keys.
     *
     * Returns the keys collected since the last call (or instance
     * construction) and then clears the internal buffer so a single
     * dropped key isn't logged twice across the cleanObject() →
     * insert/update path.
     *
     * @return array<int, string>
     */
    public function consumeDroppedAnnotationKeys(): array
    {
        $dropped = $this->droppedAnnotationKeys;
        $this->droppedAnnotationKeys = [];
        return $dropped;
    }//end consumeDroppedAnnotationKeys()

    /**
     * Validate calendar provider configuration
     *
     * When calendarProvider.enabled is true, dtstart and titleTemplate are required.
     * Warns (but does not reject) if referenced property names don't exist in schema properties.
     *
     * @param array $config The calendarProvider config array
     *
     * @throws InvalidArgumentException If required fields are missing when enabled
     *
     * @return void
     */
    private function validateCalendarProviderConfig(array $config): void
    {
        // Only validate required fields when enabled.
        if (empty($config['enabled']) === true) {
            return;
        }

        if (empty($config['dtstart']) === true) {
            throw new InvalidArgumentException(
                'calendarProvider.dtstart is required when calendar provider is enabled'
            );
        }

        if (empty($config['titleTemplate']) === true) {
            throw new InvalidArgumentException(
                'calendarProvider.titleTemplate is required when calendar provider is enabled'
            );
        }
    }//end validateCalendarProviderConfig()

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
     * Declared `x-openregister-*` annotation keys.
     *
     * Keys outside this set are dropped at save time so a typo
     * (e.g. `x-openregister-lifecycl` instead of `…-lifecycle`) is
     * caught early instead of silently round-tripping through the
     * configuration column and having the corresponding listener
     * never fire.
     *
     * @var array<int, string>
     */
    private const ANNOTATION_VOCABULARY = [
        'x-openregister-lifecycle',
        'x-openregister-aggregations',
        'x-openregister-calculations',
        'x-openregister-notifications',
        'x-openregister-widgets',
        'x-openregister-relations',
        'x-openregister-processing-activity',
    ];

    /**
     * Valid linked type values for Nextcloud entity integration.
     *
     * @deprecated since pluggable-integration-registry — kept as
     * a backwards-compat fallback so existing schemas with values like
     * 'mail' / 'calendar' / 'talk' / 'deck' continue to validate
     * while the matching IntegrationProvider leaves land. Once every
     * leaf in the umbrella's Wave 1 ships, the registry is the only
     * authority and this constant is removed by
     * `cleanup-linked-entity-type-map`. New consumers MUST add a
     * provider via `IntegrationRegistry::addProvider()` rather than
     * append to this list.
     *
     * @see OCA\OpenRegister\Service\Integration\IntegrationRegistry::listIds()
     *
     * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-8
     */
    private const VALID_LINKED_TYPES = [
        'files',
        'mail',
        'contacts',
        'notes',
        'todos',
        'calendar',
        'talk',
        'deck',
    ];

    /**
     * Validate the linkedTypes configuration value.
     *
     * Registry-driven validation per AD-5 of pluggable-integration-registry:
     * an id is valid when it appears in EITHER the registry's listIds()
     * OR the legacy VALID_LINKED_TYPES fallback. The legacy fallback
     * keeps existing schemas (e.g. linkedTypes=['mail','calendar'])
     * working while the matching providers ship. New ids (e.g. 'xwiki')
     * become valid the moment their provider is registered.
     *
     * When the integration registry isn't available — i.e. the entity
     * is constructed outside a request context (unit tests building
     * Schema instances directly) — validation falls back to
     * VALID_LINKED_TYPES alone. This preserves the existing test
     * surface while letting production code benefit from the registry.
     *
     * @param mixed $value The linkedTypes value to validate.
     *
     * @throws InvalidArgumentException If validation fails.
     *
     * @return void
     *
     * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-7
     */
    private function validateLinkedTypesValue(mixed $value): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value) === false) {
            throw new InvalidArgumentException("Configuration 'linkedTypes' must be an array or null");
        }

        $registryIds = $this->resolveIntegrationRegistryIds();

        foreach ($value as $type) {
            if (is_string($type) === false) {
                throw new InvalidArgumentException("All values in 'linkedTypes' must be strings");
            }

            $valid = in_array($type, self::VALID_LINKED_TYPES, true)
                || in_array($type, $registryIds, true);

            if ($valid === false) {
                $combined = array_unique(array_merge(self::VALID_LINKED_TYPES, $registryIds));
                sort($combined);
                throw new InvalidArgumentException(
                    "Invalid linked type '$type'. Valid values: ".implode(', ', $combined)
                );
            }
        }
    }//end validateLinkedTypesValue()

    /**
     * Resolve the current set of registered integration ids.
     *
     * Schema is a Nextcloud Entity, not a service — DI doesn't
     * reach it. We pull the registry from the server container at
     * validation time. Failures (tests without a booted container,
     * missing service binding) fall through to an empty list so the
     * legacy VALID_LINKED_TYPES path keeps working.
     *
     * @return array<int,string> Registered integration ids, possibly empty.
     */
    private function resolveIntegrationRegistryIds(): array
    {
        if (class_exists('\OC') === false || isset(\OC::$server) === false) {
            return [];
        }

        try {
            $registry = \OC::$server->get(
                \OCA\OpenRegister\Service\Integration\IntegrationRegistry::class
            );
            if ($registry instanceof \OCA\OpenRegister\Service\Integration\IntegrationRegistry) {
                return $registry->listIds();
            }
        } catch (\Throwable $e) {
            // Registry binding not available — fall back to legacy list only.
        }

        return [];
    }//end resolveIntegrationRegistryIds()

    /**
     * Get the linked types from the schema configuration
     *
     * Returns the array of Nextcloud entity types this schema can link to.
     * Defaults to empty array if not configured.
     *
     * @return array The linked types array
     */
    public function getLinkedTypes(): array
    {
        $configuration = $this->getConfiguration();

        if ($configuration === null) {
            return [];
        }

        return $configuration['linkedTypes'] ?? [];
    }//end getLinkedTypes()

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
     * Check whether objects of this schema are append-only.
     *
     * When true, INSERT is permitted but UPDATE and DELETE are rejected with
     * HTTP 405 and error code SCHEMA_APPEND_ONLY.
     *
     * @return bool True if the schema is append-only
     */
    public function isAppendOnly(): bool
    {
        return $this->appendOnly;
    }//end isAppendOnly()

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
     * @deprecated This method is no longer needed since facets are now computed at runtime
     *             from property-level `facetable: true` settings. The system automatically
     *             reads facetable properties when processing facet requests.
     *             This method is kept for backward compatibility only.
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function regenerateFacetsFromProperties(): void
    {
        $properties = $this->getProperties();

        if (empty($properties) === true) {
            $this->setFacets(facets: null);
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
            $facetType = $this->determineFacetType(property: $property);

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
        $this->setFacets(facets: $facetConfig);
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
