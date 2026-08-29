<?php

/**
 * OpenRegister Organisation Entity
 *
 * This file contains the class for the Organisation entity.
 * The Organisation entity manages multi-tenancy in OpenRegister by linking users
 * to organisations and providing organisational context for all data.
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
use Symfony\Component\Uid\Uuid;

/**
 * Organisation Entity
 *
 * Manages organisational data and user relationships for multi-tenancy.
 * Each organisation can have multiple users, and users can belong to multiple organisations.
 * Organisations can define custom roles/groups for role-based access control (RBAC).
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getUsers()
 * @method void setUsers(?array $users)
 * @method array|null getGroups()
 * @method static setGroups(?array $groups)
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method static setActive(mixed $active)
 * @method int|null getStorageQuota()
 * @method void setStorageQuota(?int $storageQuota)
 * @method int|null getBandwidthQuota()
 * @method void setBandwidthQuota(?int $bandwidthQuota)
 * @method int|null getRequestQuota()
 * @method void setRequestQuota(?int $requestQuota)
 * @method array|null getAuthorization()
 * @method static setAuthorization(array|string|null $authorization)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getEnvironment()
 * @method void setEnvironment(?string $environment)
 * @method DateTime|null getProvisionedAt()
 * @method void setProvisionedAt(?DateTime $provisionedAt)
 * @method DateTime|null getSuspendedAt()
 * @method void setSuspendedAt(?DateTime $suspendedAt)
 * @method DateTime|null getDeprovisionedAt()
 * @method void setDeprovisionedAt(?DateTime $deprovisionedAt)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method string|null getOin()
 * @method void setOin(?string $oin)
 * @method string|null getTooi()
 * @method void setTooi(?string $tooi)
 * @method string|null getRsin()
 * @method void setRsin(?string $rsin)
 * @method string|null getKvk()
 * @method void setKvk(?string $kvk)
 * @method string|null getPki()
 * @method void setPki(?string $pki)
 * @method string|null getImage()
 * @method void setImage(?string $image)
 * @method string|null getRegistrationStatus()
 * @method void setRegistrationStatus(?string $registrationStatus)
 * @method string|null getMergedInto()
 * @method void setMergedInto(?string $mergedInto)
 * @method DateTime|null getMergedAt()
 * @method void setMergedAt(?DateTime $mergedAt)
 * @method string|null getParent()
 * @method static setParent(?string $parent)
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
 * @SuppressWarnings(PHPMD.TooManyFields)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Organisation extends Entity implements JsonSerializable {

	/**
	 * Unique identifier for the organisation
	 *
	 * @var string|null UUID of the organisation
	 */
	protected ?string $uuid = null;

	/**
	 * Slug of the organisation (URL-friendly identifier)
	 *
	 * @var string|null Slug of the organisation
	 */
	protected ?string $slug = null;

	/**
	 * Name of the organisation
	 *
	 * @var string|null The organisation name
	 */
	protected ?string $name = null;

	/**
	 * Description of the organisation
	 *
	 * @var string|null The organisation description
	 */
	protected ?string $description = null;

	/**
	 * Array of user IDs that belong to this organisation
	 *
	 * @var array|null Array of user IDs (Nextcloud user IDs)
	 */
	protected ?array $users = [];

	/**
	 * Array of Nextcloud group IDs assigned to this organisation
	 * Stored as simple array of group ID strings for efficiency
	 *
	 * @var array|null Array of group IDs (strings)
	 */
	protected ?array $groups = [];

	/**
	 * Owner of the organisation (user ID)
	 *
	 * @var string|null The user ID who owns this organisation
	 */
	protected ?string $owner = null;

	/**
	 * Date when the organisation was created
	 *
	 * @var DateTime|null Creation timestamp
	 */
	protected ?DateTime $created = null;

	/**
	 * Date when the organisation was last updated
	 *
	 * @var DateTime|null Last update timestamp
	 */
	protected ?DateTime $updated = null;

	/**
	 * Whether this organisation is active
	 *
	 * @var boolean|null Whether this organisation is active
	 */
	protected ?bool $active = true;

	/**
	 * Storage quota allocated to this organisation in bytes
	 * NULL = unlimited storage
	 *
	 * @var integer|null Storage quota in bytes
	 */
	protected ?int $storageQuota = null;

	/**
	 * Bandwidth/traffic quota allocated to this organisation in bytes per month
	 * NULL = unlimited bandwidth
	 *
	 * @var integer|null Bandwidth quota in bytes per month
	 */
	protected ?int $bandwidthQuota = null;

	/**
	 * API request quota allocated to this organisation per day
	 * NULL = unlimited API requests
	 *
	 * @var integer|null API request quota per day
	 */
	protected ?int $requestQuota = null;

	/**
	 * Authorization rules for this organisation
	 *
	 * Hierarchical structure defining CRUD permissions per entity type
	 * and special rights. Uses singular entity names for easier authorization checks.
	 * Structure:
	 * {
	 *   "register": {"create": [], "read": [], "update": [], "delete": []},
	 *   "schema": {"create": [], "read": [], "update": [], "delete": []},
	 *   "object": {"create": [], "read": [], "update": [], "delete": []},
	 *   "view": {"create": [], "read": [], "update": [], "delete": []},
	 *   "agent": {"create": [], "read": [], "update": [], "delete": []},
	 *   "object_publish": [],
	 *   "agent_use": [],
	 *   "dashboard_view": [],
	 *   "llm_use": []
	 * }
	 *
	 * @var array|null Authorization rules as JSON structure
	 */
	protected ?array $authorization = null;

	/**
	 * Tenant lifecycle status
	 *
	 * Valid values: provisioning, active, suspended, deprovisioning, archived
	 *
	 * @var string|null Lifecycle status
	 */
	protected ?string $status = 'active';

	/**
	 * OTAP environment type
	 *
	 * Valid values: development, test, acceptance, production
	 *
	 * @var string|null Environment type
	 */
	protected ?string $environment = 'production';

	/**
	 * Timestamp when the organisation was provisioned
	 *
	 * @var DateTime|null Provisioning timestamp
	 */
	protected ?DateTime $provisionedAt = null;

	/**
	 * Timestamp when the organisation was suspended
	 *
	 * @var DateTime|null Suspension timestamp
	 */
	protected ?DateTime $suspendedAt = null;

	/**
	 * Timestamp when the organisation deprovisioning started
	 *
	 * @var DateTime|null Deprovisioning timestamp
	 */
	protected ?DateTime $deprovisionedAt = null;

	/**
	 * UUID of parent organisation for hierarchical organisation structures
	 *
	 * Enables parent-child relationships where children inherit access
	 * to parent resources (schemas, registers, configurations, etc.).
	 * NULL indicates this is a root-level organisation with no parent.
	 *
	 * @var string|null Parent organisation UUID
	 */
	protected ?string $parent = null;

	/**
	 * Discriminator for what KIND of organisation this row describes.
	 *
	 * Every row is still a full organisation and still a valid tenant; this
	 * only records which facet the operator cares about, so a UI can present a
	 * vendor differently from a municipality. It is deliberately NOT an
	 * authorization input — see ADR-002 Rule 1: the UUID is the only tenant key.
	 *
	 * One of: organisation, government, vendor, collaboration, department.
	 *
	 * @var string|null Organisation type discriminator
	 */
	protected ?string $type = 'organisation';

	/**
	 * Short summary for overview pages (OpenCatalogi `summary`).
	 *
	 * @var string|null
	 */
	protected ?string $summary = null;

	/**
	 * Overheidsidentificatienummer (OIN).
	 *
	 * @var string|null
	 */
	protected ?string $oin = null;

	/**
	 * TOOI identifier for this organisation.
	 *
	 * @var string|null
	 */
	protected ?string $tooi = null;

	/**
	 * RSIN of the non-natural person (9 digits, 11-proef).
	 *
	 * @var string|null
	 */
	protected ?string $rsin = null;

	/**
	 * Chamber-of-Commerce (KvK) number.
	 *
	 * @var string|null
	 */
	protected ?string $kvk = null;

	/**
	 * PKIoverheid certificate reference.
	 *
	 * @var string|null
	 */
	protected ?string $pki = null;

	/**
	 * Logo/avatar as a URL or base64 data URI.
	 *
	 * @var string|null
	 */
	protected ?string $image = null;

	/**
	 * Registration lifecycle of this organisation as a catalogued party.
	 *
	 * Distinct from `status`, which is the TENANT lifecycle (provisioning,
	 * active, suspended...). A vendor can be `registered` here while its tenant
	 * has never been provisioned, and vice versa.
	 *
	 * One of: concept, submitted, registered, rejected, merged.
	 *
	 * @var string|null
	 */
	protected ?string $registrationStatus = null;

	/**
	 * UUID of the surviving organisation when this one was merged away.
	 *
	 * This is an AUTHORIZATION-BEARING field, not a display hint. A merged
	 * organisation must stop resolving as a tenant: if it kept resolving, every
	 * query scoped to it would read the survivor's data under the old boundary.
	 * {@see \OCA\OpenRegister\Db\OrganisationMapper::resolveMergeTarget()}
	 * walks this to the survivor, and the tenant-resolution path calls it.
	 *
	 * @var string|null
	 */
	protected ?string $mergedInto = null;

	/**
	 * When this organisation was merged away.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $mergedAt = null;

	/**
	 * Array of child organisation UUIDs (computed, not stored in database)
	 *
	 * This property is populated on-demand via OrganisationMapper::findChildrenChain()
	 * and is used primarily for UI display and administrative purposes.
	 * Children can view parent resources but parents cannot view child resources.
	 *
	 * @var array|null Array of child organisation UUIDs
	 */
	protected ?array $children = null;

	/**
	 * Array of role definitions for this organisation
	 *
	 * Custom roles/groups for role-based access control (RBAC).
	 * This is typically populated from the authorization field or computed on-demand.
	 *
	 * @var array|null Array of role definitions
	 */
	protected ?array $roles = null;

	/**
	 * Linked mail app data.
	 *
	 * @var array|null
	 */
	protected ?array $mail = null;

	/**
	 * Linked contacts app data.
	 *
	 * @var array|null
	 */
	protected ?array $contacts = null;

	/**
	 * Linked notes app data.
	 *
	 * @var array|null
	 */
	protected ?array $notes = null;

	/**
	 * Linked todos app data.
	 *
	 * @var array|null
	 */
	protected ?array $todos = null;

	/**
	 * Linked calendar app data.
	 *
	 * @var array|null
	 */
	protected ?array $calendar = null;

	/**
	 * Linked talk app data.
	 *
	 * @var array|null
	 */
	protected ?array $talk = null;

	/**
	 * Linked deck app data.
	 *
	 * @var array|null
	 */
	protected ?array $deck = null;

	/**
	 * User count for this organisation (computed property, not stored in database)
	 *
	 * @var integer|null Number of users in this organisation
	 */
	public ?int $userCount = null;

	/**
	 * Organisation constructor
	 *
	 * Sets up the entity type mappings for proper database handling.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'slug', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'users', type: 'json');
		$this->addType(fieldName: 'groups', type: 'json');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'active', type: 'boolean');
		$this->addType(fieldName: 'storageQuota', type: 'integer');
		$this->addType(fieldName: 'bandwidthQuota', type: 'integer');
		$this->addType(fieldName: 'requestQuota', type: 'integer');
		$this->addType(fieldName: 'authorization', type: 'json');
		$this->addType(fieldName: 'parent', type: 'string');
		$this->addType(fieldName: 'mail', type: 'json');
		$this->addType(fieldName: 'contacts', type: 'json');
		$this->addType(fieldName: 'notes', type: 'json');
		$this->addType(fieldName: 'todos', type: 'json');
		$this->addType(fieldName: 'calendar', type: 'json');
		$this->addType(fieldName: 'talk', type: 'json');
		$this->addType(fieldName: 'deck', type: 'json');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'environment', type: 'string');
		$this->addType(fieldName: 'provisionedAt', type: 'datetime');
		$this->addType(fieldName: 'suspendedAt', type: 'datetime');
		$this->addType(fieldName: 'deprovisionedAt', type: 'datetime');
		// Identity facet (ADR-022 §3): the statutory identifiers a leaf app
		// used to keep in its own publisher/vendor record.
		$this->addType(fieldName: 'type', type: 'string');
		$this->addType(fieldName: 'summary', type: 'string');
		$this->addType(fieldName: 'oin', type: 'string');
		$this->addType(fieldName: 'tooi', type: 'string');
		$this->addType(fieldName: 'rsin', type: 'string');
		$this->addType(fieldName: 'kvk', type: 'string');
		$this->addType(fieldName: 'pki', type: 'string');
		$this->addType(fieldName: 'image', type: 'string');
		// Relationship facet.
		$this->addType(fieldName: 'registrationStatus', type: 'string');
		$this->addType(fieldName: 'mergedInto', type: 'string');
		$this->addType(fieldName: 'mergedAt', type: 'datetime');
	}//end __construct()

	/**
	 * Add a user to this organisation
	 *
	 * @param string $userId The Nextcloud user ID to add
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function addUser(string $userId): static {
		if ($this->users === null) {
			$this->users = [];
		}

		if (in_array($userId, $this->users) === false) {
			$this->users[] = $userId;
			$this->markFieldUpdated(attribute: 'users');
		}

		return $this;
	}//end addUser()

	/**
	 * Remove a user from this organisation
	 *
	 * @param string $userId The Nextcloud user ID to remove
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function removeUser(string $userId): static {
		if ($this->users === null) {
			return $this;
		}

		$originalCount = count($this->users);
		$this->users = array_values(
			array_filter(
				$this->users,
				function ($id) use ($userId) {
					return $id !== $userId;
				}
			)
		);

		// Only mark as updated if a user was actually removed.
		if (count($this->users) !== $originalCount) {
			$this->markFieldUpdated(attribute: 'users');
		}

		return $this;
	}//end removeUser()

	/**
	 * Check if a user belongs to this organisation
	 *
	 * @param string $userId The Nextcloud user ID to check
	 *
	 * @return bool True if user belongs to this organisation
	 */
	public function hasUser(string $userId): bool {
		return $this->users !== null && in_array($userId, $this->users);
	}//end hasUser()

	/**
	 * Get all users in this organisation
	 *
	 * @return array Array of user IDs
	 */
	public function getUserIds(): array {
		return $this->users ?? [];
	}//end getUserIds()

	/**
	 * Get a specific role by ID or name
	 *
	 * @param string $roleId The role ID or name to retrieve
	 *
	 * @return array|null The role definition or null if not found
	 */
	public function getRole(string $roleId): ?array {
		if ($this->roles === null) {
			return null;
		}

		foreach ($this->roles as $role) {
			$currentId = $role['id'] ?? $role['name'] ?? null;
			if ($currentId === $roleId) {
				return $role;
			}
		}

		return null;
	}//end getRole()

	/**
	 * Get all groups in this organisation
	 *
	 * @return array Array of Nextcloud group IDs
	 */
	public function getGroups(): array {
		return $this->groups ?? [];
	}//end getGroups()

	/**
	 * Set all groups for this organisation
	 *
	 * @param array|null $groups Array of Nextcloud group IDs
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function setGroups(?array $groups): static {
		$this->groups = $groups ?? [];
		$this->markFieldUpdated(attribute: 'groups');
		return $this;
	}//end setGroups()

	/**
	 * Check whether this organisation is active
	 *
	 * @return bool Whether this organisation is active
	 */
	public function isActive(): bool {
		return $this->active ?? true;
	}//end isActive()

	/**
	 * Set whether this organisation is active
	 *
	 * @param bool|null|string $active Whether this should be the active organisation
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function setActive(mixed $active): static {
		// Handle various input types defensively (including empty strings from API).
		// Default to true for organisations.
		$activeValue = true;
		if ($active !== '' && $active !== null) {
			$activeValue = (bool)$active;
		}

		parent::setActive(active: $activeValue);

		$this->markFieldUpdated(attribute: 'active');
		return $this;
	}//end setActive()

	/**
	 * Whether this organisation has been merged into another one
	 *
	 * A merged organisation is NOT a usable tenant: its data now belongs to the
	 * survivor, so anything still scoped to this UUID would be reading across
	 * the boundary. Callers resolving a tenant MUST follow
	 * {@see getMergedInto()} rather than using this row.
	 *
	 * @return bool True when this organisation was merged away
	 */
	public function isMerged(): bool {
		return $this->mergedInto !== null && $this->mergedInto !== '';
	}//end isMerged()

	/**
	 * Get default authorization structure for organisations
	 *
	 * Provides sensible defaults with empty arrays for all permissions
	 * Uses singular entity names for easier authorization checks based on entity type
	 *
	 * @return array[][] Default authorization structure
	 *
	 * @psalm-return array{
	 *     register: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     schema: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     object: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     view: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     agent: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     configuration: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     application: array{
	 *         create: array<never, never>,
	 *         read: array<never, never>,
	 *         update: array<never, never>,
	 *         delete: array<never, never>
	 *     },
	 *     object_publish: array<never, never>,
	 *     agent_use: array<never, never>,
	 *     dashboard_view: array<never, never>,
	 *     llm_use: array<never, never>
	 * }
	 */
	private function getDefaultAuthorization(): array {
		return [
			'register' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'schema' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'object' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'view' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'agent' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'configuration' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'application' => [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			],
			'object_publish' => [],
			'agent_use' => [],
			'dashboard_view' => [],
			'llm_use' => [],
		];
	}//end getDefaultAuthorization()

	/**
	 * Get authorization rules for this organisation
	 *
	 * @return array Authorization rules structure
	 */
	public function getAuthorization(): array {
		return $this->authorization ?? $this->getDefaultAuthorization();
	}//end getAuthorization()

	/**
	 * Set authorization rules for this organisation
	 *
	 * @param array|string|null $authorization Authorization rules structure or JSON string
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function setAuthorization(array|string|null $authorization): static {
		// Handle JSON string from database (type safety).
		if (is_string($authorization) === true) {
			try {
				$decoded = json_decode($authorization, true);
				// Invalid JSON, use default.
				$authorization = null;
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
					$authorization = $decoded;
				}
			} catch (\Exception $e) {
				// If decoding fails, use default.
				$authorization = null;
			}
		}

		$this->authorization = $authorization ?? $this->getDefaultAuthorization();
		$this->markFieldUpdated(attribute: 'authorization');
		return $this;
	}//end setAuthorization()

	/**
	 * Get parent organisation UUID
	 *
	 * @return string|null The parent organisation UUID or null if no parent
	 */
	public function getParent(): ?string {
		return $this->parent;
	}//end getParent()

	/**
	 * Set parent organisation UUID
	 *
	 * @param string|null $parent The parent organisation UUID
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function setParent(?string $parent): static {
		$this->parent = $parent;
		$this->markFieldUpdated(attribute: 'parent');
		return $this;
	}//end setParent()

	/**
	 * Set child organisation UUIDs
	 *
	 * This is used to populate the computed children property for API responses.
	 * Children are not stored in the database, only loaded on demand.
	 *
	 * @param array|null $children Array of child organisation UUIDs
	 *
	 * @return static Returns this organisation for method chaining
	 */
	public function setChildren(?array $children): static {
		$this->children = $children;
		return $this;
	}//end setChildren()

	/**
	 * JSON serialization for API responses
	 *
	 * @return (array|bool|int|null|string)[] Serialized organisation data
	 *
	 * @psalm-return array{
	 *     id: int,
	 *     uuid: null|string,
	 *     slug: null|string,
	 *     name: null|string,
	 *     description: null|string,
	 *     users: array,
	 *     groups: array|null,
	 *     owner: null|string,
	 *     active: bool|null,
	 *     parent: null|string,
	 *     children: array,
	 *     quota: array{
	 *         storage: int|null,
	 *         bandwidth: int|null,
	 *         requests: int|null,
	 *         users: null,
	 *         groups: null
	 *     },
	 *     usage: array{
	 *         storage: 0,
	 *         bandwidth: 0,
	 *         requests: 0,
	 *         users: int<0, max>,
	 *         groups: int<0, max>
	 *     },
	 *     authorization: array,
	 *     created: null|string,
	 *     updated: null|string
	 * }
	 */
	public function jsonSerialize(): array {
		$users = $this->getUserIds();
		$groups = $this->getGroups();
		$provisionedAt = null;
		$suspendedAt = null;
		$deprovisionedAt = null;
		if ($this->provisionedAt instanceof DateTime) {
			$provisionedAt = $this->provisionedAt->format('c');
		}

		if ($this->suspendedAt instanceof DateTime) {
			$suspendedAt = $this->suspendedAt->format('c');
		}

		if ($this->deprovisionedAt instanceof DateTime) {
			$deprovisionedAt = $this->deprovisionedAt->format('c');
		}

		$mergedAt = null;
		if ($this->mergedAt instanceof DateTime) {
			$mergedAt = $this->mergedAt->format('c');
		}

		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'slug' => $this->slug,
			'name' => $this->name,
			'description' => $this->description,
			'users' => $users,
			'groups' => $groups,
			'owner' => $this->owner,
			'active' => $this->isActive(),
			'parent' => $this->parent,
			'children' => $this->children ?? [],
			'quota' => [
				'storage' => $this->storageQuota,
				'bandwidth' => $this->bandwidthQuota,
				'requests' => $this->requestQuota,
				'users' => null,
				// To be set via admin configuration.
				'groups' => null,
				// To be set via admin configuration.
			],
			'usage' => [
				'storage' => 0,
				// To be calculated from actual usage.
				'bandwidth' => 0,
				// To be calculated from actual usage.
				'requests' => 0,
				// To be calculated from actual usage.
				'users' => count($users),
				'groups' => count($groups),
			],
			'authorization' => $this->authorization ?? $this->getDefaultAuthorization(),
			'status' => $this->status ?? 'active',
			'environment' => $this->environment ?? 'production',
			'type' => $this->type ?? 'organisation',
			'summary' => $this->summary,
			'oin' => $this->oin,
			'tooi' => $this->tooi,
			'rsin' => $this->rsin,
			'kvk' => $this->kvk,
			'pki' => $this->pki,
			'image' => $this->image,
			'registrationStatus' => $this->registrationStatus,
			'mergedInto' => $this->mergedInto,
			'mergedAt' => $mergedAt,
			'provisionedAt' => $provisionedAt,
			'suspendedAt' => $suspendedAt,
			'deprovisionedAt' => $deprovisionedAt,
			'created' => $this->getCreatedFormatted(),
			'updated' => $this->getUpdatedFormatted(),
			'_mail' => $this->mail,
			'_contacts' => $this->contacts,
			'_notes' => $this->notes,
			'_todos' => $this->todos,
			'_calendar' => $this->calendar,
			'_talk' => $this->talk,
			'_deck' => $this->deck,
		];
	}//end jsonSerialize()

	/**
	 * String representation of the organisation
	 *
	 * This magic method returns the organisation UUID. If no UUID exists,
	 * it creates a new one, sets it to the organisation, and returns it.
	 * This ensures every organisation has a unique identifier.
	 *
	 * @return string UUID of the organisation
	 */
	public function __toString(): string {
		// Generate new UUID if none exists or is empty.
		if ($this->uuid === null || $this->uuid === '') {
			$this->uuid = Uuid::v4()->toRfc4122();
		}

		return $this->uuid;
	}//end __toString()

	/**
	 * Get created date formatted as ISO 8601 string or null
	 *
	 * @return string|null Formatted date or null
	 */
	private function getCreatedFormatted(): ?string {
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
	private function getUpdatedFormatted(): ?string {
		if ($this->updated !== null) {
			return $this->updated->format('c');
		}

		return null;
	}//end getUpdatedFormatted()
}//end class
