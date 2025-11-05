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
use Symfony\Component\Uid\Uuid;

/**
 * Configuration entity class
 */
class Configuration extends Entity implements JsonSerializable
{

    /**
     * Unique identifier for the configuration
     *
     * @var string|null UUID of the configuration
     */
    protected ?string $uuid = null;

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
     * Source type of the configuration (local, github, gitlab, url, manual)
     *
     * @var string|null
     */
    protected $sourceType = null;

    /**
     * Source URL where the configuration file is located
     *
     * @var string|null
     */
    protected $sourceUrl = null;

    /**
     * Currently loaded/local version of the configuration
     *
     * @var string|null
     */
    protected $localVersion = null;

    /**
     * Latest available remote version of the configuration
     *
     * @var string|null
     */
    protected $remoteVersion = null;

    /**
     * Last time the remote version was checked
     *
     * @var DateTime|null
     */
    protected $lastChecked = null;

    /**
     * Whether to automatically update when new version is available
     *
     * @var bool
     */
    protected $autoUpdate = false;

    /**
     * Array of group IDs that should receive update notifications
     *
     * @var array|null
     */
    protected ?array $notificationGroups = [];

    /**
     * GitHub repository name (optional, for GitHub operations)
     *
     * @var string|null
     */
    protected $githubRepo = null;

    /**
     * GitHub branch to push to (optional, default: main)
     *
     * @var string|null
     */
    protected $githubBranch = null;

    /**
     * GitHub folder path in repository (optional)
     *
     * @var string|null
     */
    protected $githubPath = null;

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
     * Organisation UUID associated with this configuration
     *
     * @var string|null
     */
    protected $organisation = null;

    /**
     * Owner of the configuration (user ID)
     *
     * @var string|null
     */
    protected $owner = null;

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
        $this->addType('uuid', 'string');
        $this->addType('title', 'string');
        $this->addType('description', 'string');
        $this->addType('type', 'string');
        $this->addType('app', 'string');
        $this->addType('version', 'string');
        $this->addType('sourceType', 'string');
        $this->addType('sourceUrl', 'string');
        $this->addType('localVersion', 'string');
        $this->addType('remoteVersion', 'string');
        $this->addType('lastChecked', 'datetime');
        $this->addType('autoUpdate', 'boolean');
        $this->addType('notificationGroups', 'json');
        $this->addType('githubRepo', 'string');
        $this->addType('githubBranch', 'string');
        $this->addType('githubPath', 'string');
        $this->addType('registers', 'json');
        $this->addType('schemas', 'json');
        $this->addType('objects', 'json');
        $this->addType('organisation', 'string');
        $this->addType('owner', 'string');
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
     * Get the organisation UUID
     *
     * @return string|null The organisation UUID
     */
    public function getOrganisation(): ?string
    {
        return $this->organisation;

    }//end getOrganisation()


    /**
     * Set the organisation UUID
     *
     * @param string|null $organisation The organisation UUID
     *
     * @return void
     */
    public function setOrganisation(?string $organisation): void
    {
        $this->organisation = $organisation;
        $this->markFieldUpdated('organisation');

    }//end setOrganisation()


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
     * @param array<int>|null $registers Array of register IDs or null
     *
     * @return void
     */
    public function setRegisters(?array $registers): void
    {
        $this->registers = $registers ?? [];

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
     * @param array<int>|null $schemas Array of schema IDs or null
     *
     * @return void
     */
    public function setSchemas(?array $schemas): void
    {
        $this->schemas = $schemas ?? [];

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
     * @param array<int>|null $objects Array of object IDs or null
     *
     * @return void
     */
    public function setObjects(?array $objects): void
    {
        $this->objects = $objects ?? [];

    }//end setObjects()


    /**
     * Get the owner of the configuration (backwards compatibility - maps to app field)
     *
     * @return string|null Owner/App identifier
     */
    public function getOwner(): ?string
    {
        return $this->app;

    }//end getOwner()


    /**
     * Set the owner of the configuration (backwards compatibility - maps to app field)
     *
     * @param string|null $owner Owner/App identifier
     *
     * @return void
     */
    public function setOwner(?string $owner): void
    {
        $this->app = $owner;

    }//end setOwner()


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

        // Map 'application' to 'app' for frontend compatibility
        if (isset($object['application']) && !isset($object['app'])) {
            $object['app'] = $object['application'];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

            // Skip 'application' as it's already mapped to 'app'
            if ($key === 'application') {
                continue;
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
            'id'                 => $this->id,
            'uuid'               => $this->uuid,
            'title'              => $this->title,
            'description'        => $this->description,
            'type'               => $this->type,
            'app'                => $this->app,
            'application'        => $this->app, // Alias for frontend compatibility
            'version'            => $this->version,
            'sourceType'         => $this->sourceType,
            'sourceUrl'          => $this->sourceUrl,
            'localVersion'       => $this->localVersion,
            'remoteVersion'      => $this->remoteVersion,
            'lastChecked'        => ($this->lastChecked !== null) ? $this->lastChecked->format('c') : null,
            'autoUpdate'         => $this->autoUpdate,
            'notificationGroups' => $this->notificationGroups,
            'githubRepo'         => $this->githubRepo,
            'githubBranch'       => $this->githubBranch,
            'githubPath'         => $this->githubPath,
            'organisation'       => $this->organisation,
            'owner'              => $this->owner,
            'registers'          => $this->registers,
            'schemas'            => $this->schemas,
            'objects'            => $this->objects,
            'created'            => ($this->created !== null) ? $this->created->format('c') : null,
            'updated'            => ($this->updated !== null) ? $this->updated->format('c') : null,
        ];

    }//end jsonSerialize()


    /**
     * Check if a remote update is available
     *
     * Compares the remoteVersion with localVersion to determine if an update is available.
     *
     * @return bool True if remote version is newer than local version
     */
    public function hasUpdateAvailable(): bool
    {
        if ($this->remoteVersion === null || $this->localVersion === null) {
            return false;
        }

        return version_compare($this->remoteVersion, $this->localVersion, '>');

    }//end hasUpdateAvailable()


    /**
     * Check if this configuration is from a remote source
     *
     * @return bool True if source type is github, gitlab, or url
     */
    public function isRemoteSource(): bool
    {
        return in_array($this->sourceType, ['github', 'gitlab', 'url']);

    }//end isRemoteSource()


    /**
     * Check if this configuration is local
     *
     * @return bool True if source type is local
     */
    public function isLocalSource(): bool
    {
        return $this->sourceType === 'local';

    }//end isLocalSource()


    /**
     * Check if this configuration is manually created
     *
     * @return bool True if source type is manual
     */
    public function isManualSource(): bool
    {
        return $this->sourceType === 'manual';

    }//end isManualSource()


    /**
     * String representation of the configuration
     *
     * This magic method is required for proper entity handling in Nextcloud
     * when the framework needs to convert the object to a string.
     *
     * @return string String representation of the configuration
     */
    public function __toString(): string
    {
        // Return the title if available, otherwise return a descriptive string
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        // Fallback to type if available
        if ($this->type !== null && $this->type !== '') {
            return 'Config: '.$this->type;
        }

        // Fallback to ID if available
        if ($this->id !== null) {
            return 'Configuration #'.$this->id;
        }

        // Final fallback
        return 'Configuration';

    }//end __toString()


}//end class
