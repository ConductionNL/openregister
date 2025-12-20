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
 * Entity class representing a Register
 *
 * Manages register-related data and operations
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getSchemas()
 * @method static setSchemas(array|string $schemas)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getTablePrefix()
 * @method void setTablePrefix(?string $tablePrefix)
 * @method string|null getFolder()
 * @method void setFolder(?string $folder)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getApplication()
 * @method void setApplication(?string $application)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method array|null getAuthorization()
 * @method void setAuthorization(?array $authorization)
 * @method array|null getGroups()
 * @method void setGroups(?array $groups)
 * @method DateTime|null getDeleted()
 * @method void setDeleted(?DateTime $deleted)
 * @method array|null getConfiguration()
 * @method void setConfiguration(?array $configuration)
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
     * @var         array|null
     * @phpstan-var array<string, array<string>>|null
     * @psalm-var   array<string, list<string>>|null
     */
    protected ?array $groups = [];

    /**
     * Deletion timestamp
     *
     * @var DateTime|null Deletion timestamp
     */
    protected ?DateTime $deleted = null;

    /**
     * Publication timestamp.
     *
     * When set, this register becomes publicly accessible regardless of organisation restrictions
     * if published bypass is enabled. The register is considered published when:
     * - published <= now AND
     * - (depublished IS NULL OR depublished > now)
     *
     * @var DateTime|null Publication timestamp
     */
    protected ?DateTime $published = null;

    /**
     * Depublication timestamp.
     *
     * When set, this register becomes inaccessible after this date/time.
     * Used together with published to control publication lifecycle.
     *
     * @var DateTime|null Depublication timestamp
     */
    protected ?DateTime $depublished = null;

    /**
     * Configuration settings for this register.
     *
     * Stores register-specific configuration including schema-level settings like magic mapping.
     *
     * Structure:
     * {
     *   "schemas": {
     *     "<schema_id>": {
     *       "magicMapping": bool,
     *       "autoCreateTable": bool,
     *       "comment": string
     *     }
     *   }
     * }
     *
     * @var array|null Configuration settings
     */
    protected ?array $configuration = [];

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
        $this->addType(fieldName: 'groups', type: 'json');
        $this->addType(fieldName: 'deleted', type: 'datetime');
        $this->addType(fieldName: 'published', type: 'datetime');
        $this->addType(fieldName: 'depublished', type: 'datetime');
        $this->addType(fieldName: 'configuration', type: 'json');

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
     * Set the schemas data
     *
     * @param array|string $schemas Array of schema IDs or JSON string
     *
     * @return static Returns self for method chaining
     */
    public function setSchemas($schemas): static
    {
        $schemas = [];
        if (is_string($schemas) === true) {
            $decoded = json_decode($schemas, true);
            if ($decoded !== null) {
                $schemas = $decoded;
            }
        }

        if (is_array($schemas) === false) {
            $schemas = [];
        }

        // Only keep IDs (int or string).
        $schemas = array_filter(
                $schemas,
                function ($item) {
                    return is_int($item) || is_string($item);
                }
                );

        parent::setSchemas($schemas);

        return $this;

    }//end setSchemas()

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
     * Hydrate the entity with data from an array
     *
     * Sets entity properties based on input array values
     *
     * @param array $object The data array to hydrate from
     *
     * @return static Returns $this for method chaining
     */
    public function hydrate(array $object): static
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
     * @return ((int|mixed|null|string[])[]|int|null|string)[] Array of serializable entity data
     *
     * @psalm-return array{
     *     id: int,
     *     uuid: null|string,
     *     slug: null|string,
     *     title: null|string,
     *     version: null|string,
     *     description: null|string,
     *     schemas: array<int|string>,
     *     source: null|string,
     *     tablePrefix: null|string,
     *     folder: null|string,
     *     updated: null|string,
     *     created: null|string,
     *     owner: null|string,
     *     application: null|string,
     *     organisation: null|string,
     *     authorization: array|null,
     *     groups: array<string, list<string>>,
     *     quota: array{
     *         storage: null,
     *         bandwidth: null,
     *         requests: null,
     *         users: null,
     *         groups: null
     *     },
     *     usage: array{
     *         storage: 0,
     *         bandwidth: 0,
     *         requests: 0,
     *         users: 0,
     *         groups: int<0, max>
     *     },
     *     deleted: null|string,
     *     published: null|string,
     *     depublished: null|string
     * }
     */
    public function jsonSerialize(): array
    {
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

        // Always return schemas as array of IDs (int/string).
        $schemas = array_filter(
                $this->schemas ?? [],
                function ($item) {
                    return is_int($item) || is_string($item);
                }
                );

        $groups = $this->groups ?? [];

        return [
            'id'            => $this->id,
            'uuid'          => $this->uuid,
            'slug'          => $this->slug,
            'title'         => $this->title,
            'version'       => $this->version,
            'description'   => $this->description,
            'schemas'       => $schemas,
            'source'        => $this->source,
            'tablePrefix'   => $this->tablePrefix,
            'folder'        => $this->folder,
            'updated'       => $updated,
            'created'       => $created,
            'owner'         => $this->owner,
            'application'   => $this->application,
            'organisation'  => $this->organisation,
            'authorization' => $this->authorization,
            'groups'        => $groups,
            'published'     => $published,
            'depublished'   => $depublished,
            'quota'         => [
                'storage'   => null,
        // To be set via admin configuration.
                'bandwidth' => null,
        // To be set via admin configuration.
                'requests'  => null,
        // To be set via admin configuration.
                'users'     => null,
        // To be set via admin configuration.
                'groups'    => null,
        // To be set via admin configuration.
            ],
            'usage'         => [
                'storage'   => 0,
            // To be calculated from actual usage.
                'bandwidth' => 0,
            // To be calculated from actual usage.
                'requests'  => 0,
            // To be calculated from actual usage.
                'users'     => 0,
            // Registers don't have direct users.
                'groups'    => count($groups),
            ],
            'deleted'       => $deleted,
        ];

    }//end jsonSerialize()

    /**
     * String representation of the register
     *
     * This magic method is required for proper entity handling in Nextcloud
     * when the framework needs to convert the object to a string.
     *
     * @return string String representation of the register
     */
    public function __toString(): string
    {
        // Return the register title if available, otherwise return a descriptive string.
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        // Fallback to slug if title is not available.
        if ($this->slug !== null && $this->slug !== '') {
            return $this->slug;
        }

        // Final fallback with ID.
        // Suppress redundant property initialization check.
        //
        return 'Register #'.($this->id ?? 'unknown');

    }//end __toString()

    /**
     * Check if this register is managed by any configuration
     *
     * This method checks if the register's ID is present in the registers array
     * of any provided configuration entities.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return bool True if this register is managed by at least one configuration
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
            $registers = $configuration->getRegisters();
            if (in_array($this->id, $registers ?? [], true) === true) {
                return true;
            }
        }

        return false;

    }//end isManagedByConfiguration()

    /**
     * Get the configuration that manages this register
     *
     * Returns the first configuration that has this register's ID in its registers array.
     * Returns null if the register is not managed by any configuration.
     *
     * @param array<Configuration> $configurations Array of Configuration entities to check against
     *
     * @return Configuration|null The configuration managing this register, or null
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
            $registers = $configuration->getRegisters();
            if (in_array($this->id, $registers ?? [], true) === true) {
                return $configuration;
            }
        }

        return null;

    }//end getManagedByConfiguration()

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
     * @param DateTime|null $published Publication timestamp
     *
     * @return void
     */
    public function setPublished(?DateTime $published): void
    {
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
     * @param DateTime|null $depublished Depublication timestamp
     *
     * @return void
     */
    public function setDepublished(?DateTime $depublished): void
    {
        $this->depublished = $depublished;
        $this->markFieldUpdated('depublished');

    }//end setDepublished()

    // ==================================================================================
    // MAGIC MAPPING CONFIGURATION HELPERS
    // ==================================================================================

    /**
     * Get configuration settings.
     *
     * @return array Configuration settings or empty array if null.
     */
    public function getConfiguration(): array
    {
        return ($this->configuration ?? []);

    }//end getConfiguration()

    /**
     * Set configuration settings.
     *
     * @param array|null $configuration Configuration settings.
     *
     * @return static Returns self for method chaining.
     */
    public function setConfiguration(?array $configuration): static
    {
        $this->configuration = $configuration;
        $this->markFieldUpdated('configuration');

        return $this;

    }//end setConfiguration()

    /**
     * Check if magic mapping is enabled for a specific schema in this register.
     *
     * @param int $schemaId The schema ID to check.
     *
     * @return bool True if magic mapping is enabled for this schema.
     */
    public function isMagicMappingEnabledForSchema(int $schemaId): bool
    {
        $config        = $this->getConfiguration();
        $schemaConfigs = $config['schemas'] ?? [];
        $schemaConfig  = $schemaConfigs[$schemaId] ?? [];

        return ($schemaConfig['magicMapping'] ?? false) === true;

    }//end isMagicMappingEnabledForSchema()

    /**
     * Check if auto-create table is enabled for a specific schema in this register.
     *
     * @param int $schemaId The schema ID to check.
     *
     * @return bool True if auto-create table is enabled for this schema.
     */
    public function isAutoCreateTableEnabledForSchema(int $schemaId): bool
    {
        $config        = $this->getConfiguration();
        $schemaConfigs = $config['schemas'] ?? [];
        $schemaConfig  = $schemaConfigs[$schemaId] ?? [];

        return ($schemaConfig['autoCreateTable'] ?? false) === true;

    }//end isAutoCreateTableEnabledForSchema()

    /**
     * Enable magic mapping for a specific schema in this register.
     *
     * @param int         $schemaId        The schema ID.
     * @param bool        $autoCreateTable Whether to auto-create the table (default: true).
     * @param string|null $comment         Optional comment describing why magic mapping is enabled.
     *
     * @return static Returns self for method chaining.
     */
    public function enableMagicMappingForSchema(int $schemaId, bool $autoCreateTable=true, ?string $comment=null): static
    {
        $config = $this->getConfiguration();

        if (isset($config['schemas']) === false) {
            $config['schemas'] = [];
        }

        $config['schemas'][$schemaId] = [
            'magicMapping'    => true,
            'autoCreateTable' => $autoCreateTable,
        ];

        if ($comment !== null) {
            $config['schemas'][$schemaId]['comment'] = $comment;
        }

        $this->setConfiguration($config);

        return $this;

    }//end enableMagicMappingForSchema()

    /**
     * Disable magic mapping for a specific schema in this register.
     *
     * @param int $schemaId The schema ID.
     *
     * @return static Returns self for method chaining.
     */
    public function disableMagicMappingForSchema(int $schemaId): static
    {
        $config = $this->getConfiguration();

        if (isset($config['schemas'][$schemaId]) === true) {
            $config['schemas'][$schemaId]['magicMapping'] = false;
            $this->setConfiguration($config);
        }

        return $this;

    }//end disableMagicMappingForSchema()

    /**
     * Get all schema IDs that have magic mapping enabled in this register.
     *
     * @return int[] Array of schema IDs with magic mapping enabled.
     *
     * @psalm-return list<int>
     */
    public function getSchemasWithMagicMapping(): array
    {
        $config        = $this->getConfiguration();
        $schemaConfigs = $config['schemas'] ?? [];
        $schemaIds     = [];

        foreach ($schemaConfigs as $schemaId => $schemaConfig) {
            if (($schemaConfig['magicMapping'] ?? false) === true) {
                $schemaIds[] = (int) $schemaId;
            }
        }

        return $schemaIds;

    }//end getSchemasWithMagicMapping()
}//end class
