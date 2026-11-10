<?php

/**
 * OpenRegister Application Entity
 *
 * This file contains the Application entity class for the OpenRegister application.
 * Applications represent software applications or modules within an organisation.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
use OCA\OpenRegister\Db\Application\ApplicationFieldTypes;
use OCA\OpenRegister\Db\Application\ApplicationQuota;
use OCP\AppFramework\Db\Entity;
use Symfony\Component\Uid\Uuid;

/**
 * Application entity class
 *
 * Represents an application or module within an organisation.
 * Applications can have configurations, registers, and schemas associated with them.
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method array|null getConfigurations()
 * @method self setConfigurations(?array $configurations)
 * @method array|null getRegisters()
 * @method self setRegisters(?array $registers)
 * @method array|null getSchemas()
 * @method self setSchemas(?array $schemas)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method bool|null getActive()
 * @method self setActive(?bool $active)
 * @method int|null getStorageQuota()
 * @method void setStorageQuota(?int $storageQuota)
 * @method int|null getBandwidthQuota()
 * @method void setBandwidthQuota(?int $bandwidthQuota)
 * @method int|null getRequestQuota()
 * @method void setRequestQuota(?int $requestQuota)
 * @method int|null getUserQuota()
 * @method void setUserQuota(?int $userQuota)
 * @method int|null getGroupQuota()
 * @method void setGroupQuota(?int $groupQuota)
 * @method array|null getGroups()
 * @method self setGroups(?array $groups)
 * @method array|null getAuthorization()
 * @method self setAuthorization(array|string|null $authorization)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PossiblyUnusedMethod
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class Application extends Entity implements JsonSerializable {
	use IsoTimestampsTrait;
	use AuthorizationRulesTrait;
	use JsonFieldsTrait;
	use OrganisationFieldTrait;
	use ManagedByConfigurationTrait;
	use HydrateEmptyJsonAsNullTrait;

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
	 * Organisation UUID that owns this application
	 *
	 * @var string|null Organisation UUID
	 */
	protected ?string $organisation = null;

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
	 * @var boolean|null Whether this application is active
	 */
	protected ?bool $active = true;

	/**
	 * Storage quota allocated to this application in bytes
	 * NULL = unlimited storage
	 *
	 * @var integer|null Storage quota in bytes
	 */
	protected ?int $storageQuota = null;

	/**
	 * Bandwidth/traffic quota allocated to this application in bytes per month
	 * NULL = unlimited bandwidth
	 *
	 * @var integer|null Bandwidth quota in bytes per month
	 */
	protected ?int $bandwidthQuota = null;

	/**
	 * API request quota allocated to this application per day
	 * NULL = unlimited API requests
	 *
	 * @var integer|null API request quota per day
	 */
	protected ?int $requestQuota = null;

	/**
	 * Maximum number of users allowed for this application
	 * NULL = unlimited users
	 *
	 * @var integer|null User quota
	 */
	protected ?int $userQuota = null;

	/**
	 * Maximum number of groups allowed for this application
	 * NULL = unlimited groups
	 *
	 * @var integer|null Group quota
	 */
	protected ?int $groupQuota = null;

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
	public function __construct() {
		foreach (ApplicationFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Get configurations associated with this application
	 *
	 * @return array Array of configuration IDs
	 */
	public function getConfigurations(): array {
		return $this->configurations ?? [];
	}//end getConfigurations()

	/**
	 * Set configurations for this application
	 *
	 * @param array|null $configurations Array of configuration IDs
	 *
	 * @return static Returns this application for method chaining
	 */
	public function setConfigurations(?array $configurations): static {
		$this->configurations = $configurations ?? [];
		$this->markFieldUpdated(attribute: 'configurations');
		return $this;
	}//end setConfigurations()

	/**
	 * Get registers managed by this application
	 *
	 * @return array Array of register IDs
	 */
	public function getRegisters(): array {
		return $this->registers ?? [];
	}//end getRegisters()

	/**
	 * Set registers for this application
	 *
	 * @param array|null $registers Array of register IDs
	 *
	 * @return static Returns this application for method chaining
	 */
	public function setRegisters(?array $registers): static {
		$this->registers = $registers ?? [];
		$this->markFieldUpdated(attribute: 'registers');
		return $this;
	}//end setRegisters()

	/**
	 * Get schemas used by this application
	 *
	 * @return array Array of schema IDs
	 */
	public function getSchemas(): array {
		return $this->schemas ?? [];
	}//end getSchemas()

	/**
	 * Set schemas for this application
	 *
	 * @param array|null $schemas Array of schema IDs
	 *
	 * @return static Returns this application for method chaining
	 */
	public function setSchemas(?array $schemas): static {
		$this->schemas = $schemas ?? [];
		$this->markFieldUpdated(attribute: 'schemas');
		return $this;
	}//end setSchemas()

	/**
	 * Check whether this application is active
	 *
	 * @return bool Whether this application is active
	 */
	public function isActive(): bool {
		return $this->active ?? true;
	}//end isActive()

	/**
	 * Set whether this application is active
	 *
	 * @param bool|null|string $active Whether this should be active
	 *
	 * @return static Returns this application for method chaining
	 */
	public function setActive(mixed $active): static {
		// Handle various input types defensively (including empty strings from API).
		$this->active = true;
		// Default to true for applications.
		if ($active !== '' && $active !== null) {
			$this->active = (bool)$active;
		}

		$this->markFieldUpdated(attribute: 'active');
		return $this;
	}//end setActive()

	/**
	 * Get groups that have access to this application
	 *
	 * @return array Array of group definitions
	 */
	public function getGroups(): array {
		return $this->groups ?? [];
	}//end getGroups()

	/**
	 * Set groups that have access to this application
	 *
	 * @param array|null $groups Array of group definitions
	 *
	 * @return static Returns this application for method chaining
	 */
	public function setGroups(?array $groups): static {
		$this->groups = $groups ?? [];
		$this->markFieldUpdated(attribute: 'groups');
		return $this;
	}//end setGroups()

	/**
	 * Get default authorization structure for applications
	 *
	 * Provides sensible defaults with empty arrays for all CRUD permissions
	 *
	 * @return array[] Default authorization structure
	 *
	 * @psalm-return array{create: array<never, never>,
	 *     read: array<never, never>, update: array<never, never>,
	 *     delete: array<never, never>}
	 */
	private function getDefaultAuthorization(): array {
		return [
			'create' => [],
			'read' => [],
			'update' => [],
			'delete' => [],
		];
	}//end getDefaultAuthorization()

	/**
	 * Get authorization rules for this application
	 *
	 * @return array Authorization rules structure
	 */
	public function getAuthorization(): array {
		return $this->authorization ?? $this->getDefaultAuthorization();
	}//end getAuthorization()

	/**
	 * Set the quota allocations from the nested API structure
	 *
	 * The API exposes quota as one nested object (see self::getQuotaData())
	 * while the entity stores a column per allocation. Without this setter
	 * hydrate()'s generic set{Key} call resolves to a non-existent setQuota(),
	 * which Entity rejects and hydrate() swallows — silently dropping every
	 * quota posted to /api/applications.
	 *
	 * @param array|string|null $quota Nested quota structure or JSON string
	 *
	 * @return static Returns this application for method chaining
	 *
	 * @spec exclude Entity field-mapping boilerplate: unpacks the nested API
	 *     quota object onto the per-allocation columns. Quota enforcement is
	 *     the tenant-quotas capability's contract, not this setter's.
	 */
	public function setQuota(array|string|null $quota): static {
		foreach ((new ApplicationQuota())->allocations(quota: $quota) as $setter => $value) {
			$this->$setter($value);
		}

		return $this;
	}//end setQuota()

	/**
	 * JSON serialization for API responses
	 *
	 * @return (array|bool|int|null|string)[]
	 *
	 * @psalm-return array{id: int, uuid: null|string, name: null|string,
	 *     description: null|string, version: null|string,
	 *     organisation: null|string, configurations: array|null,
	 *     registers: array|null, schemas: array|null, owner: null|string,
	 *     active: bool|null, groups: array|null,
	 *     quota: array{storage: int|null, bandwidth: int|null,
	 *     requests: int|null, users: int|null, groups: int|null},
	 *     usage: array{storage: 0, bandwidth: 0, requests: 0, users: 0,
	 *     groups: int<0, max>}, authorization: array,
	 *     created: null|string, updated: null|string,
	 *     managedByConfiguration: array{id: int, uuid: null|string,
	 *     title: null|string}|null}
	 */
	public function jsonSerialize(): array {
		return array_merge($this->identityJson(), $this->accessJson());
	}//end jsonSerialize()

	/**
	 * The serialised identity, membership and owner.
	 *
	 * @return array<string, mixed>
	 */
	private function identityJson(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'name' => $this->name,
			'description' => $this->description,
			'version' => $this->version,
			'organisation' => $this->organisation,
			'configurations' => $this->getConfigurations(),
			'registers' => $this->getRegisters(),
			'schemas' => $this->getSchemas(),
			'owner' => $this->owner,
		];
	}//end identityJson()

	/**
	 * The serialised activity, groups, quota, usage, authorization and timestamps.
	 *
	 * @return array<string, mixed>
	 */
	private function accessJson(): array {
		$groups = $this->getGroups();

		return [
			'active' => $this->isActive(),
			'groups' => $groups,
			'quota' => $this->getQuotaData(),
			'usage' => $this->getUsageData(groups: $groups),
			'authorization' => $this->authorization ?? $this->getDefaultAuthorization(),
			'created' => $this->getCreatedFormatted(),
			'updated' => $this->getUpdatedFormatted(),
			'managedByConfiguration' => null,
		];
	}//end accessJson()

	/**
	 * String representation of the application
	 *
	 * This magic method returns the application UUID. If no UUID exists,
	 * it creates a new one, sets it to the application, and returns it.
	 *
	 * @return string UUID of the application
	 */
	public function __toString(): string {
		// Generate new UUID if none exists or is empty.
		if ($this->uuid === null || $this->uuid === '') {
			$this->uuid = Uuid::v4()->toRfc4122();
		}

		return $this->uuid;
	}//end __toString()

	/**
	 * The ids a configuration lists as its applications, see ManagedByConfigurationTrait.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array|null
	 */
	protected function configurationMembers(Configuration $configuration): ?array {
		return $configuration->getApplications();
	}//end configurationMembers()

	/**
	 * Get quota data for JSON serialization.
	 *
	 * Returns the quota allocation structure for storage, bandwidth, requests,
	 * users, and groups. NULL means unlimited for every allocation.
	 *
	 * @return array{storage: int|null, bandwidth: int|null, requests: int|null, users: int|null, groups: int|null}
	 */
	private function getQuotaData(): array {
		return [
			'storage' => $this->storageQuota,
			'bandwidth' => $this->bandwidthQuota,
			'requests' => $this->requestQuota,
			'users' => $this->userQuota,
			'groups' => $this->groupQuota,
		];
	}//end getQuotaData()

	/**
	 * Get usage data for JSON serialization.
	 *
	 * Returns the current usage statistics structure.
	 *
	 * @param array $groups The groups array to calculate group count from.
	 *
	 * @return array{storage: 0, bandwidth: 0, requests: 0, users: 0, groups: int<0, max>}
	 */
	private function getUsageData(array $groups): array {
		return [
			'storage' => 0,
			// To be calculated from actual usage.
			'bandwidth' => 0,
			// To be calculated from actual usage.
			'requests' => 0,
			// To be calculated from actual usage.
			'users' => 0,
			// Applications don't have direct users.
			'groups' => count($groups ?? []),
		];
	}//end getUsageData()
}//end class
