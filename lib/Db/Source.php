<?php

/**
 * OpenRegister Source
 *
 * This file contains the class for handling source related operations
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
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

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Source entity class
 *
 * Represents a source in the OpenRegister application
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getDatabaseUrl()
 * @method void setDatabaseUrl(?string $databaseUrl)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method array|null getConfiguration()
 * @method void setConfiguration(?array $configuration)
 * @method bool|null getSyncEnabled()
 * @method void setSyncEnabled(?bool $syncEnabled)
 * @method string|null getSyncSchedule()
 * @method void setSyncSchedule(?string $syncSchedule)
 * @method int|null getSyncInterval()
 * @method void setSyncInterval(?int $syncInterval)
 * @method DateTime|null getLastSyncDate()
 * @method void setLastSyncDate(?DateTime $lastSyncDate)
 * @method string|null getLastSyncStatus()
 * @method void setLastSyncStatus(?string $lastSyncStatus)
 * @method string|null getLastSyncToken()
 * @method void setLastSyncToken(?string $lastSyncToken)
 * @method string|null getAuthType()
 * @method void setAuthType(?string $authType)
 * @method array|null getAuthConfig()
 * @method void setAuthConfig(?array $authConfig)
 * @method int|null getMappingId()
 * @method void setMappingId(?int $mappingId)
 * @method string|null getTargetRegister()
 * @method void setTargetRegister(?string $targetRegister)
 * @method string|null getTargetSchema()
 * @method void setTargetSchema(?string $targetSchema)
 * @method string|null getConflictStrategy()
 * @method void setConflictStrategy(?string $conflictStrategy)
 * @method string|null getDeleteStrategy()
 * @method void setDeleteStrategy(?string $deleteStrategy)
 * @method int|null getBatchSize()
 * @method void setBatchSize(?int $batchSize)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Source extends Entity implements JsonSerializable {

	/**
	 * Unique identifier for the source
	 *
	 * @var string|null Unique identifier for the source
	 */
	protected ?string $uuid = null;

	/**
	 * Title of the source
	 *
	 * @var string|null Title of the source
	 */
	protected ?string $title = null;

	/**
	 * Version of the source
	 *
	 * @var string|null Version of the source
	 */
	protected ?string $version = null;

	/**
	 * Description of the source
	 *
	 * @var string|null Description of the source
	 */
	protected ?string $description = null;

	/**
	 * Database URL of the source
	 *
	 * @var string|null Database URL of the source
	 */
	protected ?string $databaseUrl = null;

	/**
	 * Type of the source
	 *
	 * @var string|null Type of the source
	 */
	protected ?string $type = null;

	/**
	 * Organisation UUID this source belongs to
	 *
	 * @var string|null Organisation UUID
	 */
	protected ?string $organisation = null;

	/**
	 * Configuration that manages this source (transient, not stored in DB)
	 *
	 * @var Configuration|null
	 */
	private ?Configuration $managedByConfig = null;

	/**
	 * Whether scheduled/harvest sync is enabled for this source
	 *
	 * @var boolean|null Sync enabled flag
	 */
	protected ?bool $syncEnabled = null;

	/**
	 * Cron expression describing the sync schedule (informational; interval drives the timed job)
	 *
	 * @var string|null Cron schedule expression
	 */
	protected ?string $syncSchedule = null;

	/**
	 * Sync interval in hours used by the timed job to decide whether a source is due
	 *
	 * @var integer|null Sync interval in hours
	 */
	protected ?int $syncInterval = null;

	/**
	 * Timestamp of the last successful sync checkpoint
	 *
	 * @var DateTime|null Last sync date
	 */
	protected ?DateTime $lastSyncDate = null;

	/**
	 * Status of the last sync execution (success|partial|failed|running)
	 *
	 * @var string|null Last sync status
	 */
	protected ?string $lastSyncStatus = null;

	/**
	 * Incremental sync checkpoint token (e.g. OData delta token or cursor)
	 *
	 * @var string|null Last sync token
	 */
	protected ?string $lastSyncToken = null;

	/**
	 * Authentication type for the external source (none|apikey|basic|oauth2|certificate)
	 *
	 * @var string|null Authentication type
	 */
	protected ?string $authType = null;

	/**
	 * Encrypted authentication configuration (credentials stored at rest)
	 *
	 * @var array|null Authentication config
	 */
	protected ?array $authConfig = null;

	/**
	 * Reference to the Mapping entity used to transform source records
	 *
	 * @var integer|null Mapping entity id
	 */
	protected ?int $mappingId = null;

	/**
	 * Target register slug/id that synced objects are imported into
	 *
	 * @var string|null Target register
	 */
	protected ?string $targetRegister = null;

	/**
	 * Target schema slug/id that synced objects are validated against
	 *
	 * @var string|null Target schema
	 */
	protected ?string $targetSchema = null;

	/**
	 * Conflict resolution strategy (source-wins|local-wins|newest-wins|manual)
	 *
	 * @var string|null Conflict strategy
	 */
	protected ?string $conflictStrategy = null;

	/**
	 * Delete handling strategy (soft-delete|hard-delete|ignore)
	 *
	 * @var string|null Delete strategy
	 */
	protected ?string $deleteStrategy = null;

	/**
	 * Batch size used by the harvest pipeline when processing records
	 *
	 * @var integer|null Batch size
	 */
	protected ?int $batchSize = null;

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
	 * Constructor for the Source class
	 *
	 * Sets up field types for all properties
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'databaseUrl', type: 'string');
		$this->addType(fieldName: 'type', type: 'string');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'syncEnabled', type: 'boolean');
		$this->addType(fieldName: 'syncSchedule', type: 'string');
		$this->addType(fieldName: 'syncInterval', type: 'integer');
		$this->addType(fieldName: 'lastSyncDate', type: 'datetime');
		$this->addType(fieldName: 'lastSyncStatus', type: 'string');
		$this->addType(fieldName: 'lastSyncToken', type: 'string');
		$this->addType(fieldName: 'authType', type: 'string');
		$this->addType(fieldName: 'authConfig', type: 'json');
		$this->addType(fieldName: 'mappingId', type: 'integer');
		$this->addType(fieldName: 'targetRegister', type: 'string');
		$this->addType(fieldName: 'targetSchema', type: 'string');
		$this->addType(fieldName: 'conflictStrategy', type: 'string');
		$this->addType(fieldName: 'deleteStrategy', type: 'string');
		$this->addType(fieldName: 'batchSize', type: 'integer');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');
	}//end __construct()

	/**
	 * Get JSON fields from the entity
	 *
	 * Returns all fields that are of type 'json'
	 *
	 * @return string[] List of JSON field names
	 *
	 * @psalm-return list<string>
	 */
	public function getJsonFields(): array {
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
	public function hydrate(array $object): static {
		$jsonFields = $this->getJsonFields();

		if (isset($object['metadata']) === false) {
			$object['metadata'] = [];
		}

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields) === true && $value === []) {
				$value = null;
			}

			$method = 'set' . ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore invalid properties.
			}
		}

		return $this;
	}//end hydrate()

	/**
	 * Get the organisation UUID
	 *
	 * @return string|null The organisation UUID
	 */
	public function getOrganisation(): ?string {
		return $this->organisation;
	}//end getOrganisation()

	/**
	 * Set the organisation UUID
	 *
	 * @param string|null $organisation The organisation UUID
	 *
	 * @return void
	 */
	public function setOrganisation(?string $organisation): void {
		$this->organisation = $organisation;
		$this->markFieldUpdated(attribute: 'organisation');
	}//end setOrganisation()

	/**
	 * Convert entity to JSON serializable array
	 *
	 * Prepares the entity data for JSON serialization
	 *
	 * @return array<string, mixed> The serialized source data
	 */
	public function jsonSerialize(): array {
		$updated = null;
		if ($this->updated !== null) {
			$updated = $this->updated->format('c');
		}

		$created = null;
		if ($this->created !== null) {
			$created = $this->created->format('c');
		}

		$lastSyncDate = null;
		if ($this->lastSyncDate !== null) {
			$lastSyncDate = $this->lastSyncDate->format('c');
		}

		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'title' => $this->title,
			'version' => $this->version,
			'description' => $this->description,
			'databaseUrl' => $this->databaseUrl,
			'type' => $this->type,
			'organisation' => $this->organisation,
			'syncEnabled' => $this->syncEnabled,
			'syncSchedule' => $this->syncSchedule,
			'syncInterval' => $this->syncInterval,
			'lastSyncDate' => $lastSyncDate,
			'lastSyncStatus' => $this->lastSyncStatus,
			'lastSyncToken' => $this->lastSyncToken,
			'authType' => $this->authType,
			// Never expose the encrypted credential blob; only signal whether one is configured.
			'authConfigured' => ($this->authConfig !== null && $this->authConfig !== []),
			'mappingId' => $this->mappingId,
			'targetRegister' => $this->targetRegister,
			'targetSchema' => $this->targetSchema,
			'conflictStrategy' => $this->conflictStrategy,
			'deleteStrategy' => $this->deleteStrategy,
			'batchSize' => $this->batchSize,
			'updated' => $updated,
			'created' => $created,
			'managedByConfiguration' => $this->getManagedByConfigurationData(),
		];
	}//end jsonSerialize()

	/**
	 * String representation of the source
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string.
	 *
	 * @return string String representation of the source
	 */
	public function __toString(): string {
		// Return the title if available, otherwise return a descriptive string.
		if ($this->title !== null && $this->title !== '') {
			return $this->title;
		}

		// Fallback to UUID if available.
		if ($this->uuid !== null && $this->uuid !== '') {
			return $this->uuid;
		}

		// Fallback to ID if available.
		if ($this->id !== null) {
			return 'Source #' . $this->id;
		}

		// Final fallback.
		return 'Source';
	}//end __toString()

	/**
	 * Get the configuration that manages this source (transient property)
	 *
	 * @return Configuration|null The managing configuration or null
	 */
	public function getManagedByConfigurationEntity(): ?Configuration {
		return $this->managedByConfig;
	}//end getManagedByConfigurationEntity()

	/**
	 * Set the configuration that manages this source (transient property)
	 *
	 * @param Configuration|null $configuration The managing configuration
	 *
	 * @return void
	 */
	public function setManagedByConfigurationEntity(?Configuration $configuration): void {
		$this->managedByConfig = $configuration;
	}//end setManagedByConfigurationEntity()

	/**
	 * Check if this source is managed by a configuration
	 *
	 * Returns true if this source's ID appears in any of the provided
	 * configurations' sources arrays.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return bool True if managed by a configuration, false otherwise
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 */
	public function isManagedByConfiguration(array $configurations): bool {
		if (empty($configurations) === true || $this->id === null) {
			return false;
		}

		foreach ($configurations as $configuration) {
			$sources = $configuration->getSources();
			if (in_array($this->id, $sources ?? [], true) === true) {
				return true;
			}
		}

		return false;
	}//end isManagedByConfiguration()

	/**
	 * Get the configuration that manages this source
	 *
	 * Returns the first configuration that has this source's ID in its sources array.
	 * Returns null if the source is not managed by any configuration.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return Configuration|null The configuration managing this source, or null
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 */
	public function getManagedByConfiguration(array $configurations): ?Configuration {
		if (empty($configurations) === true || $this->id === null) {
			return null;
		}

		foreach ($configurations as $configuration) {
			$sources = $configuration->getSources();
			if (in_array($this->id, $sources ?? [], true) === true) {
				return $configuration;
			}
		}

		return null;
	}//end getManagedByConfiguration()

	/**
	 * Get managed by configuration data as array or null
	 *
	 * @return (int|null|string)[]|null Configuration data or null
	 *
	 * @psalm-return array{id: int, uuid: null|string, title: null|string}|null
	 */
	private function getManagedByConfigurationData(): ?array {
		if ($this->managedByConfig === null) {
			return null;
		}

		return [
			'id' => $this->managedByConfig->getId(),
			'uuid' => $this->managedByConfig->getUuid(),
			'title' => $this->managedByConfig->getTitle(),
		];
	}//end getManagedByConfigurationData()
}//end class
