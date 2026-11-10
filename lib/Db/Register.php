<?php

/**
 * OpenRegister Register
 *
 * This file contains the class for handling register related operations
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
use OCA\OpenRegister\Db\Register\ExactKeyHydration;
use OCA\OpenRegister\Db\Register\MagicMappingConfig;
use OCA\OpenRegister\Db\Register\MagicMappingOptions;
use OCA\OpenRegister\Db\Register\RegisterFieldTypes;
use OCA\OpenRegister\Db\Register\RegisterJson;
use OCA\OpenRegister\Db\Register\RegisterLanguages;
use OCA\OpenRegister\Db\Register\RegisterSchemaIds;
use OCA\OpenRegister\Kernel\Contracts\RegisterInterface;
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
 * @method array|null getSharedWith()
 * @method void setSharedWith(?array $sharedWith)
 * @method array|null getGroups()
 * @method void setGroups(?array $groups)
 * @method DateTime|null getDeleted()
 * @method void setDeleted(?DateTime $deleted)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method array|null getLanguages()
 * @method void setLanguages(?array $languages)
 * @method array|null getConfiguration()
 * @method void setConfiguration(array|string|null $configuration)
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
class Register extends Entity implements JsonSerializable, RegisterInterface {
	use JsonFieldsTrait;
	use ManagedByConfigurationTrait;
	use LinkedItemsTrait;

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
	 * The organisations that may READ this register as shared master data.
	 *
	 * The `organisation` column above is the HOLDER. This list is who else may
	 * read it, declared by the holder and by nobody else — a consumer cannot
	 * add itself, because a consumer cannot write this row at all
	 * (SharedMasterDataWriteException).
	 *
	 * NULL or an empty list is the overwhelming majority and means exactly what
	 * it did before this column existed: nothing is shared.
	 *
	 * @var array|null List of consumer organisation UUIDs
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	protected ?array $sharedWith = null;

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
	 * @var array<string, array<string>>|null
	 */
	protected ?array $groups = [];

	/**
	 * Deletion timestamp
	 *
	 * @var DateTime|null Deletion timestamp
	 */
	protected ?DateTime $deleted = null;

	/**
	 * Register classification, sourced from `x-openregister.type` on import.
	 *
	 * Free-form short string. Standard values are "mock" (demo/seed data
	 * registers shipped in `lib/Settings/`) and "production" (real data).
	 * Consuming apps filter by this via `GET /api/registers?filters[type]=mock`
	 * to keep mock content out of production deployments.
	 *
	 * @var string|null Register type (e.g. "mock", "production")
	 */
	protected ?string $type = null;

	/**
	 * Available languages for this register.
	 *
	 * JSON array of BCP 47 language codes (e.g., ["nl", "en"]).
	 * The first language in the array is the default (required) language.
	 * Used for translatable schema properties to determine which languages
	 * are available for translation and which is the fallback.
	 *
	 * @var array|null Available language codes
	 */
	protected ?array $languages = null;

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
	public function __construct() {
		foreach (RegisterFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Get the schemas data
	 *
	 * @return array The schemas data or empty array if null
	 */
	public function getSchemas(): array {
		return ($this->schemas ?? []);
	}//end getSchemas()

	/**
	 * Set the schemas data
	 *
	 * @param array|string $schemas Array of schema IDs or JSON string
	 *
	 * @return static Returns self for method chaining
	 */
	public function setSchemas($schemas): static {
		// phpcs:disable CustomSniffs.Functions.NamedParameters
		// Reason: the "setSchemas" function in parent is a call to the magic method which does not take named parameter.
		parent::setSchemas((new RegisterSchemaIds())->fromInput(schemas: $schemas));
		// phpcs:enable CustomSniffs.Functions.NamedParameters

		return $this;
	}//end setSchemas()

	/**
	 * Record that a schema belongs to this register.
	 *
	 * The `schemas` list is a BOUNDARY since register-scoped slug resolution
	 * landed: a slug it does not carry is refused with
	 * {@see \OCA\OpenRegister\Exception\SchemaNotInRegisterException}. A boundary
	 * that nothing maintains is worse than none — a write addressed by numeric id
	 * lands while the matching slug read 404s.
	 *
	 * Additive and idempotent. Existing entries are preserved VERBATIM: a
	 * normalising rewrite would silently drop a non-numeric legacy entry, and that
	 * is data loss rather than cleanup. Repair of an existing list belongs to
	 * `occ openregister:registers:relink-schemas`.
	 *
	 * Mutates the entity only; the caller persists it, so the register's own RBAC
	 * and cross-tenant checks stay on the mapper where they already are.
	 *
	 * @param int $schemaId The schema id to record.
	 *
	 * @return bool True when the list gained the id, false when it already had it.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function addSchemaId(int $schemaId): bool {
		$existing = $this->getSchemas();
		if ((new RegisterSchemaIds())->contains(schemas: $existing, schemaId: $schemaId) === true) {
			return false;
		}

		$existing[] = $schemaId;
		$this->setSchemas(schemas: array_values($existing));

		return true;
	}//end addSchemaId()

	/**
	 * Hydrate the entity with data from an array
	 *
	 * Sets entity properties based on input array values; see ExactKeyHydration.
	 *
	 * @param array $object The data array to hydrate from
	 *
	 * @return static Returns $this for method chaining
	 */
	public function hydrate(array $object): static {
		(new ExactKeyHydration(register: $this))->apply(object: $object);

		return $this;
	}//end hydrate()

	/**
	 * Convert entity to JSON serializable array
	 *
	 * Prepares the entity data for JSON serialization; see RegisterJson.
	 *
	 * @return array<string, mixed> Array of serializable entity data
	 */
	public function jsonSerialize(): array {
		return (new RegisterJson())->serialise(fields: get_object_vars($this));
	}//end jsonSerialize()

	/**
	 * String representation of the register
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string: the title,
	 * else the slug, else a descriptive string with the id.
	 *
	 * @return string String representation of the register
	 */
	public function __toString(): string {
		if ($this->title !== null && $this->title !== '') {
			return $this->title;
		}

		if ($this->slug !== null && $this->slug !== '') {
			return $this->slug;
		}

		return 'Register #' . ($this->id ?? 'unknown');
	}//end __toString()

	/**
	 * The ids a configuration lists as its registers, see ManagedByConfigurationTrait.
	 *
	 * @param Configuration $configuration The configuration.
	 *
	 * @return array|null
	 */
	protected function configurationMembers(Configuration $configuration): ?array {
		return $configuration->getRegisters();
	}//end configurationMembers()

	/**
	 * Get the default language for this register: the first configured, else 'nl'.
	 *
	 * @return string The default language code (BCP 47)
	 */
	public function getDefaultLanguage(): string {
		return (new RegisterLanguages())->defaultOf(languages: $this->languages);
	}//end getDefaultLanguage()

	/**
	 * Check if a language is available for this register; none configured means only 'nl'.
	 *
	 * @param string $language The language code to check
	 *
	 * @return bool True if the language is available
	 */
	public function hasLanguage(string $language): bool {
		return (new RegisterLanguages())->has(languages: $this->languages, language: $language);
	}//end hasLanguage()

	/**
	 * Get configuration settings.
	 *
	 * @return array Configuration settings or empty array if null.
	 */
	public function getConfiguration(): array {
		return (new JsonArrayColumn())->asArray(value: $this->configuration);
	}//end getConfiguration()

	/**
	 * Set configuration settings.
	 *
	 * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration.
	 * The database stores configuration as JSON strings, but we want to work with arrays in PHP.
	 * A string that is not JSON for an array is stored as null.
	 *
	 * @param array|string|null $configuration Configuration settings (array or JSON string).
	 *
	 * @return void
	 */
	public function setConfiguration(array|string|null $configuration): void {
		if (is_string($configuration) === true) {
			$configuration = (new JsonArrayColumn())->decoded(json: $configuration);
		}

		$this->configuration = $configuration;
		$this->markFieldUpdated(attribute: 'configuration');
	}//end setConfiguration()

	/**
	 * Check if magic mapping is enabled for a specific schema in this register.
	 *
	 * This is the SINGLE SOURCE OF TRUTH for magic mapping checks.
	 * All other classes should delegate to this method. Formats: see MagicMappingConfig.
	 *
	 * @param int         $schemaId   The schema ID to check.
	 * @param string|null $schemaSlug Optional schema slug to also check in configuration.
	 *
	 * @return bool True if magic mapping is enabled for this schema.
	 */
	public function isMagicMappingEnabledForSchema(int $schemaId, ?string $schemaSlug = null): bool {
		return $this->magicMapping()->isEnabled(schemaId: $schemaId, schemaSlug: $schemaSlug);
	}//end isMagicMappingEnabledForSchema()

	/**
	 * Check if auto-create table is enabled for a specific schema in this register.
	 *
	 * Supports both configuration formats (new and legacy).
	 * Note: Legacy format doesn't have per-schema autoCreateTable, so defaults to true if magicMapping is enabled.
	 *
	 * @param int         $schemaId   The schema ID to check.
	 * @param string|null $schemaSlug Optional schema slug to also check in configuration.
	 *
	 * @return bool True if auto-create table is enabled for this schema.
	 */
	public function isAutoCreateTableEnabledForSchema(int $schemaId, ?string $schemaSlug = null): bool {
		return $this->magicMapping()->isAutoCreateTable(schemaId: $schemaId, schemaSlug: $schemaSlug);
	}//end isAutoCreateTableEnabledForSchema()

	/**
	 * Enable magic mapping for a specific schema in this register.
	 *
	 * @param int                 $schemaId The schema ID.
	 * @param MagicMappingOptions $options  Whether to auto-create the table (default: true) and an optional
	 *                                      comment describing why magic mapping is enabled.
	 *
	 * @return static Returns self for method chaining.
	 */
	public function enableMagicMappingForSchema(int $schemaId, MagicMappingOptions $options = new MagicMappingOptions()): static {
		$mapping = $this->magicMapping();
		$entry = $mapping->entry(autoCreateTable: $options->autoCreateTable, comment: $options->comment);
		$this->setConfiguration(configuration: $mapping->with(schemaId: $schemaId, entry: $entry));

		return $this;
	}//end enableMagicMappingForSchema()

	/**
	 * Disable magic mapping for a specific schema in this register.
	 *
	 * @param int $schemaId The schema ID.
	 *
	 * @return static Returns self for method chaining.
	 */
	public function disableMagicMappingForSchema(int $schemaId): static {
		$config = $this->magicMapping()->withDisabled(schemaId: $schemaId);
		if ($config !== null) {
			$this->setConfiguration(configuration: $config);
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
	public function getSchemasWithMagicMapping(): array {
		return $this->magicMapping()->enabledSchemaIds();
	}//end getSchemasWithMagicMapping()

	/**
	 * The magic mapping view of the current configuration.
	 *
	 * @return MagicMappingConfig
	 */
	private function magicMapping(): MagicMappingConfig {
		return new MagicMappingConfig(config: $this->getConfiguration());
	}//end magicMapping()
}//end class
