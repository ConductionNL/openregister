<?php
/**
 * OpenRegister Application Entity
 *
 * This file contains the Application entity class for the OpenRegister application.
 * Applications represent software applications or modules within an organisation.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Application entity class
 *
 * Represents an application or module within an organisation.
 * Applications can have configurations, registers, and schemas associated with them.
 *
 * @package OCA\OpenRegister\Db
 */
class Application extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the application
     *
     * @var string|null UUID of the application
     */
    protected ?string $uuid = null;

    /**
     * Name of the application
     *
     * @var string|null The application name
     */
    protected ?string $name = null;

    /**
     * Description of the application
     *
     * @var string|null The application description
     */
    protected ?string $description = null;

    /**
     * Version of the application
     *
     * @var string|null Version string (e.g., "1.0.0")
     */
    protected ?string $version = null;

    /**
     * Organisation ID that owns this application
     *
     * @var int|null Foreign key to organisation
     */
    protected ?int $organisation = null;

    /**
     * Array of configuration IDs associated with this application
     *
     * @var array|null Array of configuration IDs
     */
    protected ?array $configurations = [];

    /**
     * Array of register IDs managed by this application
     *
     * @var array|null Array of register IDs
     */
    protected ?array $registers = [];

    /**
     * Array of schema IDs used by this application
     *
     * @var array|null Array of schema IDs
     */
    protected ?array $schemas = [];

    /**
     * Owner of the application (user ID)
     *
     * @var string|null The user ID who owns this application
     */
    protected ?string $owner = null;

    /**
     * Whether this application is active
     *
     * @var bool|null Whether this application is active
     */
    protected ?bool $active = true;

    /**
     * Storage quota allocated to this application in bytes
     * NULL = unlimited storage
     *
     * @var int|null Storage quota in bytes
     */
    protected ?int $storageQuota = null;

    /**
     * Bandwidth/traffic quota allocated to this application in bytes per month
     * NULL = unlimited bandwidth
     *
     * @var int|null Bandwidth quota in bytes per month
     */
    protected ?int $bandwidthQuota = null;

    /**
     * API request quota allocated to this application per day
     * NULL = unlimited API requests
     *
     * @var int|null API request quota per day
     */
    protected ?int $requestQuota = null;

    /**
     * Array of Nextcloud group IDs that have access to this application
     * Stored as simple array of group ID strings for efficiency
     *
     * @var array|null Array of group IDs (strings)
     */
    protected ?array $groups = [];

    /**
     * Authorization rules for this application
     * 
     * Simple CRUD structure defining permissions:
     * {
     *   "create": [],
     *   "read": [],
     *   "update": [],
     *   "delete": []
     * }
     *
     * @var array|null Authorization rules as JSON structure
     */
    protected ?array $authorization = null;

    /**
     * Date when the application was created
     *
     * @var DateTime|null Creation timestamp
     */
    protected ?DateTime $created = null;

    /**
     * Date when the application was last updated
     *
     * @var DateTime|null Last update timestamp
     */
    protected ?DateTime $updated = null;


    /**
     * Application constructor
     *
     * Sets up the entity type mappings for proper database handling.
     */
    public function __construct()
    {
        $this->addType('uuid', 'string');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('version', 'string');
        $this->addType('organisation', 'integer');
        $this->addType('configurations', 'json');
        $this->addType('registers', 'json');
        $this->addType('schemas', 'json');
        $this->addType('owner', 'string');
        $this->addType('active', 'boolean');
        $this->addType('storage_quota', 'integer');
        $this->addType('bandwidth_quota', 'integer');
        $this->addType('request_quota', 'integer');
        $this->addType('groups', 'json');
        $this->addType('authorization', 'json');
        $this->addType('created', 'datetime');
        $this->addType('updated', 'datetime');

    }//end __construct()


    /**
     * Validate UUID format
     *
     * @param string $uuid The UUID to validate
     *
     * @return bool True if UUID format is valid
     */
    public static function isValidUuid(string $uuid): bool
    {
        try {
            Uuid::fromString($uuid);
            return true;
        } catch (\InvalidArgumentException $e) {
            return false;
        }

    }//end isValidUuid()


    /**
     * Get configurations associated with this application
     *
     * @return array Array of configuration IDs
     */
    public function getConfigurations(): array
    {
        return $this->configurations ?? [];

    }//end getConfigurations()


    /**
     * Set configurations for this application
     *
     * @param array|null $configurations Array of configuration IDs
     *
     * @return self Returns this application for method chaining
     */
    public function setConfigurations(?array $configurations): self
    {
        $this->configurations = $configurations ?? [];
        $this->markFieldUpdated('configurations');
        return $this;

    }//end setConfigurations()


    /**
     * Get registers managed by this application
     *
     * @return array Array of register IDs
     */
    public function getRegisters(): array
    {
        return $this->registers ?? [];

    }//end getRegisters()


    /**
     * Set registers for this application
     *
     * @param array|null $registers Array of register IDs
     *
     * @return self Returns this application for method chaining
     */
    public function setRegisters(?array $registers): self
    {
        $this->registers = $registers ?? [];
        $this->markFieldUpdated('registers');
        return $this;

    }//end setRegisters()


    /**
     * Get schemas used by this application
     *
     * @return array Array of schema IDs
     */
    public function getSchemas(): array
    {
        return $this->schemas ?? [];

    }//end getSchemas()


    /**
     * Set schemas for this application
     *
     * @param array|null $schemas Array of schema IDs
     *
     * @return self Returns this application for method chaining
     */
    public function setSchemas(?array $schemas): self
    {
        $this->schemas = $schemas ?? [];
        $this->markFieldUpdated('schemas');
        return $this;

    }//end setSchemas()


    /**
     * Get whether this application is active
     *
     * @return bool Whether this application is active
     */
    public function getActive(): bool
    {
        return $this->active ?? true;

    }//end getActive()


    /**
     * Set whether this application is active
     *
     * @param bool|null|string $active Whether this should be active
     *
     * @return self Returns this application for method chaining
     */
    public function setActive(mixed $active): self
    {
        // Handle various input types defensively (including empty strings from API)
        if ($active === '' || $active === null) {
            $this->active = true; // Default to true for applications
        } else {
            $this->active = (bool)$active;
        }
        $this->markFieldUpdated('active');
        return $this;

    }//end setActive()


    /**
     * Get groups that have access to this application
     *
     * @return array Array of group definitions
     */
    public function getGroups(): array
    {
        return $this->groups ?? [];

    }//end getGroups()


    /**
     * Set groups that have access to this application
     *
     * @param array|null $groups Array of group definitions
     *
     * @return self Returns this application for method chaining
     */
    public function setGroups(?array $groups): self
    {
        $this->groups = $groups ?? [];
        $this->markFieldUpdated('groups');
        return $this;

    }//end setGroups()


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
     * Get default authorization structure for applications
     *
     * Provides sensible defaults with empty arrays for all CRUD permissions
     *
     * @return array Default authorization structure
     */
    private function getDefaultAuthorization(): array
    {
        return [
            'create' => [],
            'read'   => [],
            'update' => [],
            'delete' => [],
        ];

    }//end getDefaultAuthorization()


    /**
     * Get authorization rules for this application
     *
     * @return array Authorization rules structure
     */
    public function getAuthorization(): array
    {
        return $this->authorization ?? $this->getDefaultAuthorization();

    }//end getAuthorization()


    /**
     * Set authorization rules for this application
     *
     * @param array|null $authorization Authorization rules structure
     *
     * @return self Returns this application for method chaining
     */
    public function setAuthorization(?array $authorization): self
    {
        $this->authorization = $authorization ?? $this->getDefaultAuthorization();
        $this->markFieldUpdated('authorization');
        return $this;

    }//end setAuthorization()


    /**
     * JSON serialization for API responses
     *
     * @return array Serialized application data
     */
    public function jsonSerialize(): array
    {
        $groups = $this->getGroups();

        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'description'    => $this->description,
            'version'        => $this->version,
            'organisation'   => $this->organisation,
            'configurations' => $this->getConfigurations(),
            'registers'      => $this->getRegisters(),
            'schemas'        => $this->getSchemas(),
            'owner'          => $this->owner,
            'active'         => $this->getActive(),
            'groups'         => $groups,
            'quota'          => [
                'storage'   => $this->storageQuota,
                'bandwidth' => $this->bandwidthQuota,
                'requests'  => $this->requestQuota,
                'users'     => null, // To be set via admin configuration
                'groups'    => null, // To be set via admin configuration
            ],
            'usage'          => [
                'storage'   => 0, // To be calculated from actual usage
                'bandwidth' => 0, // To be calculated from actual usage
                'requests'  => 0, // To be calculated from actual usage
                'users'     => 0, // Applications don't have direct users
                'groups'    => count($groups),
            ],
            'authorization'  => $this->authorization ?? $this->getDefaultAuthorization(),
            'created'        => $this->created ? $this->created->format('c') : null,
            'updated'        => $this->updated ? $this->updated->format('c') : null,
        ];

    }//end jsonSerialize()


    /**
     * String representation of the application
     *
     * This magic method returns the application UUID. If no UUID exists,
     * it creates a new one, sets it to the application, and returns it.
     *
     * @return string UUID of the application
     */
    public function __toString(): string
    {
        // Generate new UUID if none exists or is empty
        if ($this->uuid === null || $this->uuid === '') {
            $this->uuid = Uuid::v4()->toRfc4122();
        }

        return $this->uuid;

    }//end __toString()


}//end class

