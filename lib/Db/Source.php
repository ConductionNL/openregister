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
use OCA\OpenRegister\Db\Source\SourceFieldTypes;
use OCA\OpenRegister\Db\Source\SourceJson;
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
	use JsonFieldsTrait;
	use OrganisationFieldTrait;
	use ManagedByConfigurationTrait;
	use HydrateEmptyJsonAsNullTrait;

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
		foreach (SourceFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Convert entity to JSON serializable array; see SourceJson.
	 *
	 * @return array<string, mixed> Array of serializable entity data
	 */
	public function jsonSerialize(): array {
		return (new SourceJson())->serialise(fields: get_object_vars($this));
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
		foreach ([$this->title, $this->uuid] as $name) {
			if ($name !== null && $name !== '') {
				return $name;
			}
		}

		if ($this->id !== null) {
			return 'Source #' . $this->id;
		}

		return 'Source';
	}//end __toString()

	/**
	 * The ids a configuration lists as its sources, see ManagedByConfigurationTrait.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array|null
	 */
	protected function configurationMembers(Configuration $configuration): ?array {
		return $configuration->getSources();
	}//end configurationMembers()

}//end class
