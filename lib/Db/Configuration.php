<?php

/**
 * OpenRegister Configuration Entity
 *
 * This file contains the Configuration entity class for the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCA\OpenRegister\Db\Configuration\ConfigurationFieldTypes;
use OCP\AppFramework\Db\Entity;

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
 * @method array|null getMappings()
 * @method void setMappings(?array $mappings)
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
 *
 * @SuppressWarnings(PHPMD.TooManyFields) Domain entity requires many fields for complete configuration sync data
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Configuration extends Entity implements JsonSerializable {
	use IsoTimestampsTrait;
	use JsonFieldsTrait;
	use HydrateEmptyJsonAsNullTrait {
		hydrate as private hydrateEmptyJsonAsNull;
	}

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
	 * Array of mapping IDs managed by this configuration
	 *
	 * @var array|null
	 */
	protected ?array $mappings = [];

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
	public function __construct() {
		foreach (ConfigurationFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Hydrate the entity with data from an array
	 *
	 * Sets entity properties based on input array values
	 *
	 * @param array $object The data array to hydrate from
	 *
	 * @return static Returns $this for method chaining
	 */
	public function hydrate(array $object): static {
		// Map 'application' to 'app' for frontend compatibility.
		if (($object['application'] ?? null) !== null && (($object['app'] ?? null) === null) === true) {
			$object['app'] = $object['application'];
		}

		// Skip 'application' as it's already mapped to 'app'.
		unset($object['application']);

		return $this->hydrateEmptyJsonAsNull(object: $object);
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
	 *     mappings: array|null,
	 *     applications: array|null,
	 *     created: null|string,
	 *     updated: null|string
	 * }
	 */
	public function jsonSerialize(): array {
		$typed = new TypedFieldsJson();
		$json = $typed->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this));
		$ownership = $typed->pick(values: $json, names: ['organisation', 'owner']);

		// 'application' is an alias of 'app' for frontend compatibility; the
		// ownership fields are served before the exported entity lists.
		return $typed->inserted(
			json: array_diff_key($json, $ownership),
			entriesAfter: ['app' => ['application' => $this->app], 'openregister' => $ownership]
		);
	}//end jsonSerialize()

	/**
	 * Check if a remote update is available
	 *
	 * Compares the remoteVersion with localVersion to determine if an update is available.
	 *
	 * @return bool True if remote version is newer than local version
	 */
	public function hasUpdateAvailable(): bool {
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
	public function isRemoteSource(): bool {
		return in_array($this->sourceType, ['github', 'gitlab', 'url']);
	}//end isRemoteSource()

	/**
	 * String representation of the configuration
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string.
	 *
	 * @return string String representation of the configuration
	 */
	public function __toString(): string {
		// Return the title if available, otherwise return a descriptive string.
		if ($this->title !== null && $this->title !== '') {
			return $this->title;
		}

		// Fallback to type if available.
		if ($this->type !== null && $this->type !== '') {
			return 'Config: ' . $this->type;
		}

		// Fallback to ID if available.
		if ($this->id !== null) {
			return 'Configuration #' . $this->id;
		}

		// Final fallback.
		return 'Configuration';
	}//end __toString()
}//end class
