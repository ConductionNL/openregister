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
use OCA\OpenRegister\Db\Organisation\OrganisationFieldTypes;
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
 * @method int|null getUserQuota()
 * @method void setUserQuota(?int $userQuota)
 * @method int|null getObjectQuota()
 * @method void setObjectQuota(?int $objectQuota)
 * @method string|null getObjectQuotaSchema()
 * @method void setObjectQuotaSchema(?string $objectQuotaSchema)
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
 * @method DateTime|null getRetainedAt()
 * @method void setRetainedAt(?DateTime $retainedAt)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method bool|null getIsLocalTenant()
 * @method void setIsLocalTenant(?bool $isLocalTenant)
 * @method string|null getRemoteInstanceUrl()
 * @method void setRemoteInstanceUrl(?string $remoteInstanceUrl)
 * @method string|null getContactEmail()
 * @method void setContactEmail(?string $contactEmail)
 * @method string|null getDefaultPermissionLevel()
 * @method void setDefaultPermissionLevel(?string $defaultPermissionLevel)
 * @method int|null getQualityScore()
 * @method void setQualityScore(?int $qualityScore)
 * @method string|null getQualityStatus()
 * @method void setQualityStatus(?string $qualityStatus)
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
 * @method string|null getLegalName()
 * @method void setLegalName(?string $legalName)
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
 * @SuppressWarnings(PHPMD.LongVariable)
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) One mapped column costs a
 * property, an accessor pair, a type registration and a serialised key, so the
 * entity grows with the table. Splitting it would split one row's mapping.
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Organisation extends Entity implements JsonSerializable {
	use IsoTimestampsTrait;
	use AuthorizationRulesTrait;

	/**
	 * Resources whose default authorization is a create/read/update/delete block.
	 *
	 * @var list<string>
	 */
	private const CRUD_RESOURCES = [
		'register',
		'schema',
		'object',
		'view',
		'agent',
		'configuration',
		'application',
	];

	/**
	 * Rights whose default authorization is a single grant list.
	 *
	 * Only grant_manage is enforced, unlike the four before it: see
	 * Service\Rbac\OrganisationSpecialRights.
	 *
	 * @var list<string>
	 */
	private const SPECIAL_RIGHTS = [
		'object_publish',
		'agent_use',
		'dashboard_view',
		'llm_use',
		'grant_manage',
	];

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
	 * How many members the organisation may have; NULL = no limit.
	 *
	 * @var integer|null User quota
	 */
	protected ?int $userQuota = null;

	/**
	 * How many live objects the organisation may hold, of the schema
	 * `objectQuotaSchema` names (every schema when it names none); NULL = no limit.
	 *
	 * @var integer|null Object quota
	 */
	protected ?int $objectQuota = null;

	/**
	 * The slug of the schema the object quota counts, such as `case`; NULL counts every schema.
	 *
	 * @var string|null Object quota schema slug
	 */
	protected ?string $objectQuotaSchema = null;

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
	 *   "llm_use": [],
	 *   "grant_manage": []
	 * }
	 *
	 * @var array|null Authorization rules as JSON structure
	 */
	protected ?array $authorization = null;

	/**
	 * Tenant lifecycle status
	 *
	 * Valid values: provisioning, active, suspended, deprovisioning, archived,
	 * retained
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
	 * Timestamp when the organisation entered the retained state
	 *
	 * The start of its retention period. Never read by the purge job, which
	 * measures from `deprovisionedAt` only.
	 *
	 * @var DateTime|null Retention start timestamp
	 */
	protected ?DateTime $retainedAt = null;

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
	 * Whether this organisation is a tenant OF THIS INSTALLATION.
	 *
	 * 🔴 THIS ONE IS AN AUTHORIZATION AND LIFECYCLE INPUT, unlike `type` above,
	 * which is deliberately not. It answers a question `type` cannot: a row may
	 * be a perfectly real organisation — a ketenpartner, a supplier, a
	 * municipality we exchange cases with — and still not be a tenant here.
	 *
	 * It exists because the tenant BACKGROUND JOBS select on `status` alone,
	 * and one of them (TenantPurgeJob) permanently DELETES what it selects.
	 * Without this flag an archived counterparty is indistinguishable from an
	 * archived tenant, and is deleted with it. Every tenant enumeration filters
	 * on this; see TenantJobsScopeTest.
	 *
	 * Defaults to TRUE so every existing row keeps its present meaning.
	 *
	 * @var boolean|null True for a tenant of this installation.
	 */
	protected ?bool $isLocalTenant = true;

	/**
	 * Base URL of the OpenRegister instance this organisation is a tenant of.
	 *
	 * Set only on a counterparty — an organisation that is a tenant somewhere
	 * ELSE and interacts with us across the federation. It is the same value
	 * `FederatedShare.remoteInstanceUrl` carries, which is what lets a share
	 * and the organisation it is with be resolved to each other.
	 *
	 * Empty for a local tenant, which is the ordinary case.
	 *
	 * @var string|null The peer instance base URL.
	 */
	protected ?string $remoteInstanceUrl = null;

	/**
	 * Where to reach this organisation about the work.
	 *
	 * The organisation's own address, as opposed to any individual user account
	 * inside it. A chain partner is corresponded with as a body.
	 *
	 * @var string|null The contact address.
	 */
	protected ?string $contactEmail = null;

	/**
	 * The access level a share with this organisation starts at.
	 *
	 * 🔴 A DEFAULT, never an authorization decision. ADR-002 Rule 1 keeps the
	 * organisation UUID as the only tenant key, and nothing may read this to
	 * decide whether an actor MAY act, only to decide what a new share is
	 * proposed at. The same trap `type` was kept out of.
	 *
	 * The name is 22 characters and has to be: Entity maps a property to its
	 * column BY NAME, resolving a setter to lcfirst(substr($method, 3)) and
	 * looking that up, so this must be the camelCase of
	 * `default_permission_level`. Shortening it to satisfy PHPMD.LongVariable
	 * would stop the mapping silently rather than failing, which is why the
	 * class-level suppression exists.
	 *
	 * @var string|null The proposed starting level.
	 */
	protected ?string $defaultPermissionLevel = null;

	/**
	 * Chain-partner quality score, as scored by the installation working with them.
	 *
	 * Null rather than zero when unscored: a zero reads as "scored badly", which
	 * is a different claim from "never assessed".
	 *
	 * @var integer|null The score.
	 */
	protected ?int $qualityScore = null;

	/**
	 * The qualitative reading of {@see $qualityScore}.
	 *
	 * @var string|null The status.
	 */
	protected ?string $qualityStatus = null;

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
	 * The name the organisation is registered under (statutaire naam).
	 *
	 * Distinct from `name`, what every list shows. Never a copy of it: a reader
	 * needing something to print falls back to `name` at the point of use.
	 * Identity only, never a key; `kvk`, `rsin` and `oin` are the identifiers.
	 *
	 * @var string|null The registered legal name.
	 */
	protected ?string $legalName = null;

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
	 * walks this to the survivor.
	 *
	 * NOT YET WIRED: the live tenant-resolution path does not call the resolver
	 * yet, so a merged organisation still resolves as itself. Doing so changes
	 * which rows every scoped query returns and is held for its own change --
	 * see openspec/changes/consolidate-organisation-on-or/tasks.md task 3.3.
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
		foreach (OrganisationFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
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

		// Assign DIRECTLY, as Application::setActive() does. This called
		// parent::setActive(active: ...) — a NAMED arg into Entity::__call(),
		// which reads $args[0], so the value never arrived and setActive(false)
		// left the organisation ACTIVE. Nothing could be deactivated.
		$this->active = $activeValue;

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
	 *     llm_use: array<never, never>,
	 *     grant_manage: array<never, never>
	 * }
	 */
	private function getDefaultAuthorization(): array {
		$authorization = [];
		foreach (self::CRUD_RESOURCES as $resource) {
			$authorization[$resource] = [
				'create' => [],
				'read' => [],
				'update' => [],
				'delete' => [],
			];
		}

		foreach (self::SPECIAL_RIGHTS as $right) {
			$authorization[$right] = [];
		}

		return $authorization;
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
	 *         users: int|null,
	 *         objects: int|null,
	 *         objectsSchema: string|null,
	 *         groups: null
	 *     },
	 *     usage: array{
	 *         storage: null,
	 *         bandwidth: null,
	 *         requests: null,
	 *         objects: null,
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

		return array_merge(
			$this->identityJson(users: $users, groups: $groups),
			[
				'quota' => $this->quotaJson(),
				'usage' => $this->usageJson(users: $users, groups: $groups),
			],
			$this->tenancyJson(),
			$this->registryJson(),
			$this->lifecycleJson(),
			$this->workspaceJson()
		);
	}//end jsonSerialize()

	/**
	 * The usage block of the serialised organisation.
	 *
	 * Storage, bandwidth and requests are measured by OrganisationUsageService;
	 * the entity cannot measure them, so they are null here rather than a 0 that
	 * reads as "nothing used".
	 *
	 * @param array $users  The member user ids.
	 * @param array $groups The member groups.
	 *
	 * @return array<string, int|null> The usage block.
	 */
	private function usageJson(array $users, array $groups): array {
		return [
			'storage' => null,
			'bandwidth' => null,
			'requests' => null,
			'objects' => null,
			'users' => count($users),
			'groups' => count($groups),
		];
	}//end usageJson()

	/**
	 * The identity and membership part of the serialised organisation.
	 *
	 * @param array $users  The member user ids.
	 * @param array $groups The member groups.
	 *
	 * @return array<string, mixed> Keys id to children, in output order.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function identityJson(array $users, array $groups): array {
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
		];
	}//end identityJson()

	/**
	 * The quota part of the serialised organisation.
	 *
	 * @return array<string, mixed> The configured quotas; groups is set via admin configuration.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function quotaJson(): array {
		return [
			'storage' => $this->storageQuota,
			'bandwidth' => $this->bandwidthQuota,
			'requests' => $this->requestQuota,
			'users' => $this->userQuota,
			'objects' => $this->objectQuota,
			'objectsSchema' => $this->objectQuotaSchema,
			'groups' => null,
		];
	}//end quotaJson()

	/**
	 * The tenancy part of the serialised organisation.
	 *
	 * @return array<string, mixed> Keys authorization to defaultPermissionLevel, in output order.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function tenancyJson(): array {
		return [
			'authorization' => $this->authorization ?? $this->getDefaultAuthorization(),
			'status' => $this->status ?? 'active',
			'environment' => $this->environment ?? 'production',
			'type' => $this->type ?? 'organisation',
			'isLocalTenant' => ($this->isLocalTenant ?? true),
			'remoteInstanceUrl' => $this->remoteInstanceUrl,
			'contactEmail' => $this->contactEmail,
			'defaultPermissionLevel' => $this->defaultPermissionLevel,
		];
	}//end tenancyJson()

	/**
	 * The registry part of the serialised organisation: quality and legal identifiers.
	 *
	 * @return array<string, mixed> Keys qualityScore to mergedInto, in output order.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function registryJson(): array {
		return [
			'qualityScore' => $this->qualityScore,
			'qualityStatus' => $this->qualityStatus,
			'summary' => $this->summary,
			'oin' => $this->oin,
			'tooi' => $this->tooi,
			'rsin' => $this->rsin,
			'kvk' => $this->kvk,
			'legalName' => $this->legalName,
			'pki' => $this->pki,
			'image' => $this->image,
			'registrationStatus' => $this->registrationStatus,
			'mergedInto' => $this->mergedInto,
		];
	}//end registryJson()

	/**
	 * The lifecycle dates of the serialised organisation, as ISO 8601.
	 *
	 * @return array<string, string|null> Keys mergedAt to updated, in output order.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function lifecycleJson(): array {
		return [
			'mergedAt' => $this->mergedAt?->format('c'),
			'provisionedAt' => $this->provisionedAt?->format('c'),
			'suspendedAt' => $this->suspendedAt?->format('c'),
			'deprovisionedAt' => $this->deprovisionedAt?->format('c'),
			'retainedAt' => $this->retainedAt?->format('c'),
			'created' => $this->getCreatedFormatted(),
			'updated' => $this->getUpdatedFormatted(),
		];
	}//end lifecycleJson()

	/**
	 * The workspace links of the serialised organisation.
	 *
	 * @return array<string, array|null> The linked mail, contacts, notes, todos, calendar, talk and deck.
	 *
	 * @spec openspec/specs/saas-multi-tenant/spec.md
	 */
	private function workspaceJson(): array {
		return [
			'_mail' => $this->mail,
			'_contacts' => $this->contacts,
			'_notes' => $this->notes,
			'_todos' => $this->todos,
			'_calendar' => $this->calendar,
			'_talk' => $this->talk,
			'_deck' => $this->deck,
		];
	}//end workspaceJson()

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
}//end class
