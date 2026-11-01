<?php
/**
 * SchemaInterface: what callers outside lib/Db need of a Schema row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use OCP\IURLGenerator;
use stdClass;

/**
 * A schema: the definition of one object type, with its properties, rules and annotations.
 *
 * Implemented by \OCA\OpenRegister\Db\Schema. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 * hydrate() and validateProperties() stay on the entity (they take a
 * Service validator), and so does getManagedByConfiguration() (it returns a
 * Db\Configuration): a Kernel contract names no Service or Db class.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getUri()
 * @method void setUri(?string $uri)
 * @method string|null getSlug()
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method void setProperties(?array $properties)
 * @method void setArchive(?array $archive)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method bool getHardValidation()
 * @method void setHardValidation(bool $hardValidation)
 * @method bool getAppendOnly()
 * @method void setAppendOnly(bool $appendOnly)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method int getMaxDepth()
 * @method void setMaxDepth(int $maxDepth)
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
 * @method DateTime|null getDeleted()
 * @method void setDeleted(?DateTime $deleted)
 * @method array|null getHooks()
 * @method void setHooks(?array $hooks)
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
 * @method array|null getDraft()
 * @method void setDraft(?array $draft)
 */
interface SchemaInterface extends JsonSerializable {

	/**
	 * Get the required data
	 *
	 * @return array The required data or empty array if null
	 */
	public function getRequired(): array;

	/**
	 * Set the required data
	 *
	 * Always ensures required is an array, never NULL.
	 * This prevents database errors during schema validation.
	 *
	 * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration.
	 * The database stores required as JSON strings, but we want to work with arrays in PHP.
	 *
	 * @param array|string|null $required The required field names (array or JSON string)
	 *
	 * @return void
	 */
	public function setRequired(array|string|null $required): void;

	/**
	 * Get the properties data
	 *
	 * @return array The properties data or empty array if null
	 */
	public function getProperties(): array;

	/**
	 * Check if any property in the schema has authorization rules defined.
	 *
	 * This is used to determine if property-level RBAC filtering needs to be applied
	 * during object rendering or validation.
	 *
	 * @return bool True if at least one property has non-empty authorization
	 */
	public function hasPropertyAuthorization(): bool;

	/**
	 * Get the authorization rules for a specific property.
	 *
	 * @param string $propertyName The name of the property
	 *
	 * @return array|null The authorization rules or null if none defined
	 */
	public function getPropertyAuthorization(string $propertyName): ?array;

	/**
	 * Get all properties that have authorization rules defined.
	 *
	 * @return array<string, array> Map of property names to their authorization rules
	 */
	public function getPropertiesWithAuthorization(): array;

	/**
	 * Check if any property in the schema is declared write-only.
	 *
	 * A property carrying the JSON Schema / OpenAPI keyword `writeOnly: true`
	 * is a write-only secret: it may be written but is NEVER returned on read,
	 * for anyone (including admin). This is used to decide whether the render
	 * path must apply write-only stripping (see PropertyRbacHandler).
	 *
	 * @return bool True if at least one property has writeOnly === true
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 */
	public function hasWriteOnlyProperties(): bool;

	/**
	 * Get the names of all properties declared write-only (`writeOnly: true`).
	 *
	 * @return array<int, string> List of write-only property names
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 */
	public function getWriteOnlyProperties(): array;

	/**
	 * Whether the schema declares any nested write-only dot-paths.
	 *
	 * Companion to hasWriteOnlyProperties(): that one answers "does a declared
	 * property carry `writeOnly: true`", this one answers "does the schema
	 * declare a nested path as write-only". The render path must strip when
	 * EITHER is true, so every gate checks both.
	 *
	 * @return bool True when at least one nested write-only path is declared.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	public function hasWriteOnlyPaths(): bool;

	/**
	 * Get the nested write-only dot-paths declared by this schema.
	 *
	 * Paths are dot-separated and rooted at a declared top-level property,
	 * e.g. `configuration.authentication.client_secret`. A path denotes the
	 * value at that location AND everything beneath it, so declaring
	 * `configuration.authentication` strips the whole sub-tree.
	 *
	 * @return array<int, string> Declared write-only dot-paths (possibly empty).
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-nested-writeonly-paths-must-never-be-returned-on-any-read
	 */
	public function getWriteOnlyPaths(): array;

	/**
	 * Check if any property in the schema is declared encrypted at rest.
	 *
	 * A property carrying `x-openregister-encrypted: true` (the OpenRegister
	 * vendor-extension convention, sibling to `x-openregister-relations` etc.)
	 * is encrypted at rest via {@see \OCA\OpenRegister\Service\FieldEncryptionHandler}
	 * on save and decrypted for authorized reads on render. Used to decide
	 * whether the save/render path must apply the encryption/decryption step,
	 * and whether the magic-table column builder must skip the property.
	 *
	 * @return bool True if at least one property has x-openregister-encrypted === true
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function hasEncryptedProperties(): bool;

	/**
	 * Get the names of all properties declared encrypted (`x-openregister-encrypted: true`).
	 *
	 * @return array<int, string> List of encrypted property names
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function getEncryptedProperties(): array;

	/**
	 * Get the archive data
	 *
	 * @return array The archive data or empty array if null
	 */
	public function getArchive(): array;

	/**
	 * Get JSON fields from the entity
	 *
	 * Returns all fields that are of type 'json'
	 *
	 * @return string[] List of JSON field names
	 *
	 * @psalm-return list<string>
	 */
	public function getJsonFields(): array;

	/**
	 * Validate the authorization structure for RBAC
	 *
	 * Validates that the authorization array follows the correct structure:
	 * - Keys are either CRUD actions (create, read, update, delete) or
	 *   reserved cascade flags (e.g. inheritFromPublic)
	 * - Action values must be arrays of group IDs (strings); reserved flag
	 *   values must be booleans
	 * - Group IDs must be non-empty strings
	 *
	 * Also validates property-level authorization if any properties have authorization defined.
	 *
	 * @throws \InvalidArgumentException If the authorization structure is invalid
	 *
	 * @return true True if the authorization structure is valid
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function validateAuthorization(): bool;

	/**
	 * Check if a user group has permission for a specific CRUD action
	 *
	 * Rules:
	 * - If no authorization is set, all groups have all permissions
	 * - If authorization is set but action is not specified, all groups have permission for that action
	 * - The 'admin' group always has all permissions
	 * - Object owner always has all permissions for their specific objects
	 *
	 * TODO: Extend this method to support property-level permission checks
	 * Add optional $propertyName parameter to check property-specific authorization.
	 * When $propertyName is provided, check the property's authorization array first,
	 * then fall back to schema-level authorization if no property-level authorization exists.
	 *
	 * @param string $groupId The group ID to check
	 * @param string $action The CRUD action (create, read, update, delete)
	 * @param string $userId Optional user ID for owner check
	 * @param string $userGroup Optional user group for admin check
	 * @param string $objectOwner Optional object owner for ownership check
	 * @param array $objectData Optional object data for conditional match evaluation
	 * @param string $objectOrganisation Optional object organisation UUID (@self.organisation)
	 * @param string $activeOrganisation Optional user's active organisation UUID for $organisation variable
	 *
	 * @return bool True if the group has permission for the action
	 *
	 * @deprecated This is a pre-unification duplicate of the RBAC matcher. The
	 *             canonical evaluators are {@see \OCA\OpenRegister\Service\Object\PermissionHandler::hasPermission()}
	 *             (PHP-side) and {@see \OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler::applyRbacFilters()}
	 *             (SQL-side), both of which delegate conditional match evaluation
	 *             to {@see \OCA\OpenRegister\Service\ConditionMatcher}. This method
	 *             has no production callers (only test coverage via
	 *             BasicCrudTest/RbacTest/RbacComprehensiveTest) and is retained
	 *             solely to avoid churning the test suite in the #1336 unification
	 *             change. Scheduled for removal in a follow-up cleanup; do not
	 *             add new callers.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Conditional authorization rules require multiple checks
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Match condition evaluation creates multiple paths
	 */
	public function hasPermission(
		string $groupId,
		string $action,
		?string $userId = null,
		?string $userGroup = null,
		?string $objectOwner = null,
		?array $objectData = null,
		?string $objectOrganisation = null,
		?string $activeOrganisation = null,
	): bool;

	/**
	 * Get all groups that have permission for a specific action
	 *
	 * @param string $action The CRUD action to check
	 *
	 * @return array Array of group IDs that have permission, or empty array if all groups have permission
	 */
	public function getAuthorizedGroups(string $action): array;

	/**
	 * Serializes the schema to an array
	 *
	 * Converts entity data to a JSON serializable array
	 *
	 * @return ((mixed|string[])[]|bool|int|null|string)[] The serialized schema data
	 *
	 * @psalm-return array{id: int, uuid: null|string, uri: null|string,
	 *     slug: null|string, title: null|string, description: null|string,
	 *     version: null|string, summary: null|string, icon: null|string,
	 *     required: array, properties: array, archive: array|null,
	 *     source: null|string, hardValidation: bool, immutable: bool, appendOnly: bool,
	 *     searchable: bool, smartPickerEnabled: bool, updated: null|string, created: null|string,
	 *     maxDepth: int, owner: null|string, application: null|string,
	 *     organisation: null|string,
	 *     groups: array<string, list<string>>|null, authorization: array|null,
	 *     deleted: null|string, configuration: array|null|string,
	 *     allOf: array|null, oneOf: array|null, anyOf: array|null}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function jsonSerialize(): array;

	/**
	 * Converts schema to an object representation
	 *
	 * Creates a standard object representation of the schema for API use
	 *
	 * @param IURLGenerator $urlGenerator The URL generator for URLs in the schema
	 *
	 * @return stdClass A standard object representation of the schema
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function getSchemaObject(IURLGenerator $urlGenerator): stdClass;

	/**
	 * Set the slug, ensuring it is always lowercase
	 *
	 * @param string|null $slug The slug to set
	 *
	 * @return void
	 */
	public function setSlug(?string $slug): void;

	/**
	 * Get the icon for the schema
	 *
	 * @return string|null The icon reference from Material Design Icons
	 */
	public function getIcon(): ?string;

	/**
	 * Set the icon for the schema
	 *
	 * @param string|null $icon The icon reference from Material Design Icons
	 *
	 * @return void
	 */
	public function setIcon(?string $icon): void;

	/**
	 * The names an upsert may use as its key.
	 *
	 * These are the schema's uniqueness constraints with action `refuse`,
	 * the legacy `unique` key included and named by its properties joined
	 * with `+`. A `report` constraint allows duplicates on purpose, so it
	 * never identifies one record.
	 *
	 * @return array<int, string> The constraint names, in declaration order.
	 *
	 * @spec openspec/changes/api-upsert-on-a-declared-key/specs/objects-crud/spec.md
	 */
	public function getUpsertKeys(): array;

	/**
	 * Get the configuration for the schema
	 *
	 * Ensures that configuration is always returned as an array,
	 * automatically decoding JSON strings if necessary.
	 *
	 * @return array|null The configuration array or null if not set
	 */
	public function getConfiguration(): ?array;

	/**
	 * Get the calendar provider configuration from the schema configuration
	 *
	 * Extracts the calendarProvider section from the configuration JSON.
	 * Returns null if not present or if enabled is false.
	 *
	 * @return array|null The calendar provider config array, or null if disabled/absent
	 */
	public function getCalendarProviderConfig(): ?array;

	/**
	 * Get the object-source declaration from the schema configuration.
	 *
	 * Reads the `x-openregister-object-source` annotation block (folded into the
	 * configuration column at save time). When present and valid, the schema's
	 * objects are served by the named ObjectSourceProvider instead of the magic
	 * table (see GetObject). Returns null when absent or when `provider` is not a
	 * non-empty string.
	 *
	 * A schema declaring `x-openregister-view: {view: <id or uuid>}` and no object
	 * source is served by the read-only `view` provider.
	 *
	 * @return array{provider: string, readOnly?: bool, config?: array}|null
	 *                                                                       The parsed object-source declaration, or null when absent/invalid.
	 *
	 * @spec openspec/changes/object-source-providers/tasks.md#task-2.2
	 * @spec openspec/changes/modelling-query-backed-type/specs/saved-search-views/spec.md#requirement-req-qtype-001-a-saved-view-can-back-a-read-only-record-type
	 */
	public function getObjectSource(): ?array;

	/**
	 * Get the lens declarations from the schema configuration.
	 *
	 * A lens is a property that shows a referenced record's own field, live: it
	 * holds the path, never the value. Each entry is keyed by the property name
	 * it renders as and declares `through` (a reference property on this schema)
	 * and `property` (the property to read through it, dot paths allowed). An
	 * entry missing either key is dropped rather than half-applied.
	 *
	 * @return array<string, array{through: string, property: string, label?: string}>
	 *                                                                                The declared lenses, keyed by property name.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function getLenses(): array;

	/**
	 * Get the list-surface declaration from the schema configuration.
	 *
	 * A schema may say which columns a list shows and which fields it searches
	 * on, so the generic surface renders any object type without a list page of
	 * its own. A schema declaring neither keeps whatever the surface defaults
	 * to today.
	 *
	 * @return array The declared columns and search fields, each possibly empty.
	 *
	 * @psalm-return array{columns: array<int, array{property: string, label?: string}>, searchFields: array<int, string>}
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function getListPresentation(): array;

	/**
	 * Get the geographic-inheritance declaration from the schema configuration.
	 *
	 * A record may collect map features from the objects and parties it points
	 * at. Each entry names the reference property to follow; the collector
	 * stamps that name onto every feature it brings back, so a pin on a map can
	 * always say where it came from.
	 *
	 * @return array<int, array{through: string, label?: string}> The reference properties to collect from.
	 *
	 * @spec openspec/changes/objects-as-the-hinge-between-cases/specs/linked-entity-types/spec.md
	 */
	public function getGeoInheritance(): array;

	/**
	 * Check whether this schema's objects are opted into Context Chat indexing.
	 *
	 * Reads the `x-openregister-contextchat` annotation (default OFF). Follows
	 * the same `x-openregister-*` boolean-flag convention as other schema
	 * annotations — objects of this schema are only ever submitted to
	 * `OCP\ContextChat` when this returns true AND the object independently
	 * satisfies the publication predicate (see ContextChatSubmissionListener).
	 *
	 * @return bool True when the schema opted in to Context Chat indexing.
	 *
	 * @spec openspec/specs/context-chat-provider/spec.md
	 */
	public function isContextChatIndexingEnabled(): bool;

	/**
	 * Set the configuration for the schema with validation
	 *
	 * Validates and sets the configuration array for the schema.
	 *
	 * Supported configuration options:
	 * - 'objectNameField': (string) A dot-notation path to the field within an object's data
	 *   that should be used as its name. Example: 'person.firstName'
	 * - 'objectDescriptionField': (string) A dot-notation path to the field for the object's description.
	 *   Example: 'case.summary'
	 * - 'objectSummaryField': (string) A dot-notation path to the field for the object's summary.
	 *   Example: 'article.abstract'
	 * - 'objectImageField': (string) A dot-notation path to the field for the object's image.
	 *   Example: 'profile.avatar' (should contain base64 encoded image data)
	 * - 'allowFiles': (bool) Whether this schema allows file attachments
	 * - 'allowedTags': (array) Array of allowed file tags/types for file filtering
	 * - 'defaultAutoShare': (bool) Default value for the "Automatically publish"
	 *   toggle on the attachment upload dialog. true seeds the toggle on; absent
	 *   or false keeps it off. Users can always override per upload.
	 *   See: ConductionNL/opencatalogi#577
	 * - 'mailObjectTemplate': (array) Field map used by the Mail-sidebar
	 *   "create from email" action. Keys are schema property names, string
	 *   values may contain {{subject}}/{{sender}}/{{senderName}}/{{date}}/
	 *   {{date30}}/{{datetime}}/{{preview}}/{{messageId}}/{{mailRef}}
	 *   placeholders; non-string values pass through verbatim. Only schemas
	 *   declaring this template get a create-from-email button.
	 *
	 * @param array|string|null $configuration The configuration array/string to validate and set
	 *
	 * @throws \InvalidArgumentException If configuration contains invalid values
	 *
	 * @return void
	 */
	public function setConfiguration($configuration): void;

	/**
	 * Return + reset the list of dropped annotation keys.
	 *
	 * Returns the keys collected since the last call (or instance
	 * construction) and then clears the internal buffer so a single
	 * dropped key isn't logged twice across the cleanObject() →
	 * insert/update path.
	 *
	 * @return array<int, string>
	 */
	public function consumeDroppedAnnotationKeys(): array;

	/**
	 * The roles a person can hold on an object of this schema, [] when the schema declares none.
	 *
	 * @return array<int, array{key: string, label: string, description?: string}> The vocabulary.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function getLinkRoles(): array;

	/**
	 * The kinds of party an object of this schema accepts, [] when it declares none.
	 *
	 * A schema that declares none keeps its reference properties and behaves
	 * as it did before the party model: the picker offers everything and the
	 * validator refuses nothing.
	 *
	 * @return array<int, array{key: string, label: string, description?: string, roles?: array<int, string>}> The vocabulary.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function getPartyKinds(): array;

	/**
	 * The `x-openregister-party` annotation, [] when the schema is not a party schema.
	 *
	 * @return array<string, mixed> The annotation as stored.
	 *
	 * @spec openspec/changes/party-roles-beyond-the-requester/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function getPartyAnnotation(): array;

	/**
	 * Get the linked types from the schema configuration
	 *
	 * Returns the array of Nextcloud entity types this schema can link to.
	 * Defaults to empty array if not configured.
	 *
	 * @return array The linked types array
	 */
	public function getLinkedTypes(): array;

	/**
	 * Check whether this schema should be searchable in SOLR
	 *
	 * @return bool True if schema objects should be indexed in SOLR
	 */
	public function isSearchable(): bool;

	/**
	 * Set whether this schema should be searchable in SOLR
	 *
	 * @param bool $searchable Whether schema objects should be indexed in SOLR
	 *
	 * @return void
	 */
	public function setSearchable(bool $searchable): void;

	/**
	 * Check whether this schema opts in to its own Smart Picker entry
	 *
	 * Named `is...()` rather than `get...()` (unlike this column's own
	 * `@spec` prose in the schema-scoped-smart-picker change, which used
	 * `getSmartPickerEnabled()`) to match this class's REAL existing
	 * boolean-getter convention (`isSearchable()`, not `getSearchable()`)
	 * and PHPMD's `BooleanGetMethodName` rule, which a real declared
	 * `get...()` boolean getter — unlike a magic `@method` one — triggers.
	 *
	 * @return bool True if a schema-scoped Smart Picker provider for this
	 *              schema should be functionally active
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	public function isSmartPickerEnabled(): bool;

	/**
	 * Set whether this schema opts in to its own Smart Picker entry
	 *
	 * @param bool $smartPickerEnabled Whether a schema-scoped Smart Picker
	 *                                 provider for this schema should be
	 *                                 functionally active
	 *
	 * @return void
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	public function setSmartPickerEnabled(bool $smartPickerEnabled): void;

	/**
	 * Check whether objects of this schema are append-only.
	 *
	 * When true, INSERT is permitted but UPDATE and DELETE are rejected with
	 * HTTP 405 and error code SCHEMA_APPEND_ONLY.
	 *
	 * @return bool True if the schema is append-only
	 */
	public function isAppendOnly(): bool;

	/**
	 * Check whether this schema declares `x-openregister-archival`.
	 *
	 * An archival schema holds legally retained records: user-driven deletes are
	 * rejected with HTTP 403 and error code SCHEMA_ARCHIVAL_IMMUTABLE, and rows
	 * leave only through {@see \OCA\OpenRegister\BackgroundJob\ArchivalRetentionTask}
	 * when they pass their retention, or through the administrative
	 * `openregister:objects:purge --force` CLI path.
	 *
	 * This is the single definition of "is archival". Every gate MUST ask here
	 * rather than re-reading the annotation, so a second delete route cannot
	 * quietly disagree with the first.
	 *
	 * @return bool True if the schema declares the archival annotation.
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
	 */
	public function hasArchivalAnnotation(): bool;

	/**
	 * Whether this schema offers archiving.
	 *
	 * ⚠️ NOT the same question as {@see self::hasArchivalAnnotation()}, and the
	 * two annotations are not spellings of each other.
	 * `x-openregister-archival` declares a legally retained schema whose rows a
	 * user may not delete at all. `x-openregister-archive` declares that this
	 * schema's objects have a finished state and may be taken out of the
	 * working views by hand. A schema can carry either, both, or neither.
	 *
	 * The rule is declared once on the schema rather than decided by each app
	 * that renders a button (ADR-031), so a schema with no finished state never
	 * grows an action nobody uses.
	 *
	 * @return bool True when the schema declares `x-openregister-archive` with
	 *              `enabled: true`.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function isArchivingEnabled(): bool;

	/**
	 * The reasons this schema accepts for a value that was not supplied.
	 *
	 * Returns a map of code to label, empty when the schema administers none.
	 * The single definition of "which reasons may this schema use": no caller
	 * re-reads the annotation, for the same reason nothing re-reads the
	 * archiving one.
	 *
	 * @return array<string, string> Reason codes mapped to their labels.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/runtime-schema-api/spec.md
	 */
	public function notSuppliedReasons(): array;

	/**
	 * String representation of the schema
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string.
	 *
	 * @return string String representation of the schema
	 */
	public function __toString(): string;

	/**
	 * Get the pre-computed facet configuration
	 *
	 * @deprecated Since runtime facet computation was implemented, this method is no longer
	 *             used for faceting. Facets are now computed at runtime from property-level
	 *             `facetable: true` settings. This method is kept for backward compatibility
	 *             but the `facets` column can be considered deprecated.
	 *             Use schema properties with `facetable: true` instead.
	 *
	 * @return array|null The facet configuration or null if not computed
	 */
	public function getFacets(): ?array;

	/**
	 * Set the facet configuration
	 *
	 * **TYPE SAFETY**: Handle both array and JSON string inputs for database hydration.
	 * The database stores facets as JSON strings, but we want to work with arrays in PHP.
	 *
	 * @param array|string|null $facets The facet configuration array or JSON string.
	 *
	 * @return void
	 *
	 * @deprecated Since runtime facet computation was implemented, this method is no longer
	 *             needed. Facets are now computed at runtime from property-level `facetable: true`
	 *             settings. Set `facetable: true` on individual properties instead.
	 */
	public function setFacets(array|string|null $facets): void;

	/**
	 * Regenerate facets from current schema properties
	 *
	 * @deprecated This method is no longer needed since facets are now computed at runtime
	 *             from property-level `facetable: true` settings. The system automatically
	 *             reads facetable properties when processing facet requests.
	 *             This method is kept for backward compatibility only.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 */
	public function regenerateFacetsFromProperties(): void;

	/**
	 * Get the array of schema references that this schema must validate against (allOf)
	 *
	 * The instance must validate against ALL schemas in the array.
	 * This implements JSON Schema 'allOf' for multiple inheritance/composition.
	 *
	 * @return array|null Array of schema IDs, UUIDs, or slugs
	 */
	public function getAllOf(): ?array;

	/**
	 * Set the array of schema references that this schema must validate against (allOf)
	 *
	 * The instance must validate against ALL schemas in the array.
	 * Only additional constraints are allowed (Liskov Substitution Principle).
	 * Metadata (title, description, order) can be overridden.
	 *
	 * @param array|null $allOf Array of schema IDs, UUIDs, or slugs
	 *
	 * @return void
	 */
	public function setAllOf(?array $allOf): void;

	/**
	 * Get the array of schema references where instance must validate against exactly one (oneOf)
	 *
	 * The instance must validate against EXACTLY ONE schema in the array.
	 * This implements JSON Schema 'oneOf' for mutually exclusive options.
	 *
	 * @return array|null Array of schema IDs, UUIDs, or slugs
	 */
	public function getOneOf(): ?array;

	/**
	 * Set the array of schema references where instance must validate against exactly one (oneOf)
	 *
	 * The instance must validate against EXACTLY ONE schema in the array.
	 * This implements JSON Schema 'oneOf' for mutually exclusive options.
	 *
	 * @param array|null $oneOf Array of schema IDs, UUIDs, or slugs
	 *
	 * @return void
	 */
	public function setOneOf(?array $oneOf): void;

	/**
	 * Get the array of schema references where instance must validate against at least one (anyOf)
	 *
	 * The instance must validate against AT LEAST ONE schema in the array.
	 * This implements JSON Schema 'anyOf' for flexible composition.
	 *
	 * @return array|null Array of schema IDs, UUIDs, or slugs
	 */
	public function getAnyOf(): ?array;

	/**
	 * Set the array of schema references where instance must validate against at least one (anyOf)
	 *
	 * The instance must validate against AT LEAST ONE schema in the array.
	 * This implements JSON Schema 'anyOf' for flexible composition.
	 *
	 * @param array|null $anyOf Array of schema IDs, UUIDs, or slugs
	 *
	 * @return void
	 */
	public function setAnyOf(?array $anyOf): void;

	/**
	 * Check if this schema is managed by any configuration
	 *
	 * This method checks if the schema's ID is present in the schemas array
	 * of any provided configuration entities.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return bool True if this schema is managed by at least one configuration
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 */
	public function isManagedByConfiguration(array $configurations): bool;
}//end interface
