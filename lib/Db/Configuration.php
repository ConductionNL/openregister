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
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getApp()
 * @method void setApp(?string $app)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getSourceType()
 * @method void setSourceType(?string $sourceType)
 * @method string|null getSourceUrl()
 * @method void setSourceUrl(?string $sourceUrl)
 * @method string|null getLocalVersion()
 * @method void setLocalVersion(?string $localVersion)
 * @method string|null getRemoteVersion()
 * @method void setRemoteVersion(?string $remoteVersion)
 * @method DateTime|null getLastChecked()
 * @method void setLastChecked(?DateTime $lastChecked)
 * @method bool getAutoUpdate()
 * @method void setAutoUpdate(bool $autoUpdate)
 * @method array|null getNotificationGroups()
 * @method void setNotificationGroups(?array $notificationGroups)
 * @method string|null getGithubRepo()
 * @method void setGithubRepo(?string $githubRepo)
 * @method string|null getGithubBranch()
 * @method void setGithubBranch(?string $githubBranch)
 * @method string|null getGithubPath()
 * @method void setGithubPath(?string $githubPath)
 * @method bool getIsLocal()
 * @method void setIsLocal(bool $isLocal)
 * @method bool getSyncEnabled()
 * @method void setSyncEnabled(bool $syncEnabled)
 * @method int getSyncInterval()
 * @method void setSyncInterval(int $syncInterval)
 * @method DateTime|null getLastSyncDate()
 * @method void setLastSyncDate(?DateTime $lastSyncDate)
 * @method string getSyncStatus()
 * @method void setSyncStatus(string $syncStatus)
 * @method string|null getOpenregister()
 * @method void setOpenregister(?string $openregister)
 * @method array|null getRegisters()
 * @method void setRegisters(?array $registers)
 * @method array|null getSchemas()
 * @method void setSchemas(?array $schemas)
 * @method array|null getObjects()
 * @method void setObjects(?array $objects)
 * @method array|null getViews()
 * @method void setViews(?array $views)
 * @method array|null getAgents()
 * @method void setAgents(?array $agents)
 * @method array|null getSources()
 * @method void setSources(?array $sources)
 * @method array|null getApplications()
 * @method void setApplications(?array $applications)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
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
     * @var boolean
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
     * Whether this configuration is maintained locally (true) or imported from external source (false)
     * Local configurations are created/maintained in this installation
     * External configurations are imported and synchronized from remote sources
     *
     * @var boolean
     */
    protected bool $isLocal = true;

    /**
     * Whether automatic synchronization is enabled for this configuration
     * Only applicable for external configurations (isLocal = false)
     *
     * @var boolean
     */
    protected bool $syncEnabled = false;

    /**
     * Synchronization interval in hours
     * How often to check for updates from the source
     *
     * @var integer
     */
    protected int $syncInterval = 24;

    /**
     * Last time the configuration was synchronized with its source
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastSyncDate = null;

    /**
     * Status of the last synchronization attempt
     * Possible values: 'success', 'failed', 'pending', 'never'
     *
     * @var string
     */
    protected string $syncStatus = 'never';

    /**
     * Required OpenRegister version constraint (Composer notation)
     * Examples: '^v8.14.0', '~1.2.0', '>=1.0.0 <2.0.0'
     *
     * @var string|null
     */
    protected ?string $openregister = null;

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
     * Array of view IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $views = [];

    /**
     * Array of agent IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $agents = [];

    /**
     * Array of source IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $sources = [];

    /**
     * Array of application IDs managed by this configuration
     *
     * @var array|null
     */
    protected ?array $applications = [];

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
        $this->addType('isLocal', 'boolean');
        $this->addType('syncEnabled', 'boolean');
        $this->addType('syncInterval', 'integer');
        $this->addType('lastSyncDate', 'datetime');
        $this->addType('syncStatus', 'string');
        $this->addType('openregister', 'string');
        $this->addType('registers', 'json');
        $this->addType('schemas', 'json');
        $this->addType('objects', 'json');
        $this->addType('views', 'json');
        $this->addType('agents', 'json');
        $this->addType('sources', 'json');
        $this->addType('applications', 'json');
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

        // Map 'application' to 'app' for frontend compatibility.
        if (isset($object['application']) === true && isset($object['app']) === false) {
            $object['app'] = $object['application'];
        }

        foreach ($object as $key => $value) {
            if (in_array($key, $jsonFields) === true && $value === []) {
                $value = null;
            }

            // Skip 'application' as it's already mapped to 'app'.
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
     * @return (array|bool|int|null|string)[] The serialized entity
     *
     * @psalm-return array{
     *     id: int,
     *     uuid: null|string,
     *     title: string,
     *     description: null|string,
     *     type: string,
     *     app: string,
     *     application: string,
     *     version: string,
     *     sourceType: null|string,
     *     sourceUrl: null|string,
     *     localVersion: null|string,
     *     remoteVersion: null|string,
     *     lastChecked: null|string,
     *     autoUpdate: bool,
     *     notificationGroups: array|null,
     *     githubRepo: null|string,
     *     githubBranch: null|string,
     *     githubPath: null|string,
     *     isLocal: bool,
     *     syncEnabled: bool,
     *     syncInterval: int,
     *     lastSyncDate: null|string,
     *     syncStatus: string,
     *     openregister: null|string,
     *     organisation: null|string,
     *     owner: null|string,
     *     registers: array|null,
     *     schemas: array|null,
     *     objects: array|null,
     *     views: array|null,
     *     agents: array|null,
     *     sources: array|null,
     *     applications: array|null,
     *     created: null|string,
     *     updated: null|string
     * }
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
            'application'        => $this->app,
        // Alias for frontend compatibility.
            'version'            => $this->version,
            'sourceType'         => $this->sourceType,
            'sourceUrl'          => $this->sourceUrl,
            'localVersion'       => $this->localVersion,
            'remoteVersion'      => $this->remoteVersion,
            'lastChecked'        => $this->getLastCheckedFormatted(),
            'autoUpdate'         => $this->autoUpdate,
            'notificationGroups' => $this->notificationGroups,
            'githubRepo'         => $this->githubRepo,
            'githubBranch'       => $this->githubBranch,
            'githubPath'         => $this->githubPath,
            'isLocal'            => $this->isLocal,
            'syncEnabled'        => $this->syncEnabled,
            'syncInterval'       => $this->syncInterval,
            'lastSyncDate'       => $this->getLastSyncDateFormatted(),
            'syncStatus'         => $this->syncStatus,
            'openregister'       => $this->openregister,
            'organisation'       => $this->organisation,
            'owner'              => $this->owner,
            'registers'          => $this->registers,
            'schemas'            => $this->schemas,
            'objects'            => $this->objects,
            'views'              => $this->views,
            'agents'             => $this->agents,
            'sources'            => $this->sources,
            'applications'       => $this->applications,
            'created'            => $this->getCreatedFormatted(),
            'updated'            => $this->getUpdatedFormatted(),
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
        // Return the title if available, otherwise return a descriptive string.
        if ($this->title !== null && $this->title !== '') {
            return $this->title;
        }

        // Fallback to type if available.
        if ($this->type !== null && $this->type !== '') {
            return 'Config: '.$this->type;
        }

        // Fallback to ID if available.
        if ($this->id !== null) {
            return 'Configuration #'.$this->id;
        }

        // Final fallback.
        return 'Configuration';

    }//end __toString()


    /**
     * Get lastChecked date formatted as ISO 8601 string or null
     *
     * @return string|null Formatted date or null
     */
    private function getLastCheckedFormatted(): ?string
    {
        if ($this->lastChecked !== null) {
            return $this->lastChecked->format('c');
        }

        return null;

    }//end getLastCheckedFormatted()


    /**
     * Get lastSyncDate formatted as ISO 8601 string or null
     *
     * @return string|null Formatted date or null
     */
    private function getLastSyncDateFormatted(): ?string
    {
        if ($this->lastSyncDate !== null) {
            return $this->lastSyncDate->format('c');
        }

        return null;

    }//end getLastSyncDateFormatted()


    /**
     * Get created date formatted as ISO 8601 string or null
     *
     * @return string|null Formatted date or null
     */
    private function getCreatedFormatted(): ?string
    {
        if ($this->created !== null) {
            return $this->created->format('c');
        }

        return null;

    }//end getCreatedFormatted()


    /**
     * Get updated date formatted as ISO 8601 string or null
     *
     * @return string|null Formatted date or null
     */
    private function getUpdatedFormatted(): ?string
    {
        if ($this->updated !== null) {
            return $this->updated->format('c');
        }

        return null;

    }//end getUpdatedFormatted()


}//end class
