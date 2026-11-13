<?php

/**
 * OpenRegister Schema
 *
 * This file contains the class for handling schema related operations
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
use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema\InversedByNormaliser;
use OCA\OpenRegister\Db\Schema\SchemaPropertyDocument;
use JsonSerializable;
use OCA\OpenRegister\Db\Schema\AnnotationVocabulary;
use OCA\OpenRegister\Db\Schema\AuthorizationRulesValidator;
use OCA\OpenRegister\Db\Schema\ConfigurationValidator;
use OCA\OpenRegister\Db\Schema\DeclaredActions;
use OCA\OpenRegister\Kernel\Contracts\Value\GrantQuery;
use OCA\OpenRegister\Db\Schema\PropertyAuthorization;
use OCA\OpenRegister\Db\Schema\SchemaGrantEvaluator;
use OCA\OpenRegister\Db\Schema\VocabularyEntries;
use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandlerInterface;
use OCA\OpenRegister\Service\Schemas\UniqueConstraintEvaluator;
use OCA\OpenRegister\Kernel\Contracts\SchemaInterface;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use OCA\OpenRegister\Db\Schema\NotSuppliedReasons;
use OCA\OpenRegister\Db\Schema\ObjectSourceDeclaration;
use OCA\OpenRegister\Db\Schema\PresentationAnnotations;
use OCA\OpenRegister\Db\Schema\FacetBlockStamp;
use OCA\OpenRegister\Db\Schema\PropertyFacetBlock;
use OCP\IURLGenerator;
use stdClass;
use OCA\OpenRegister\Db\Schema\SchemaFieldTypes;
use OCA\OpenRegister\Db\Schema\TopLevelAnnotationFold;

/**
 * Class Schema
 *
 * Entity class representing a Schema
 *
 * @package OCA\OpenRegister\Db
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getUri()
 * @method void setUri(?string $uri)
 * @method string|null getSlug()
 * @method void setSlug(?string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getSummary()
 * @method void setSummary(?string $summary)
 * @method array|null getRequired()
 * @method void setRequired(array|string|null $required)
 * @method array|null getProperties()
 * @method void setProperties(?array $properties)
 * @method array|null getArchive()
 * @method void setArchive(?array $archive)
 * @method array|null getFacets()
 * @method void setFacets(?array $facets)
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
 * @method array|null getConfiguration()
 * @method void setConfiguration(?array $configuration)
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
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The entity delegates its
 * configuration and authorization rules to small collaborators in Db\Schema, each
 * used by the entity alone; the coupling is to those, not to other components.
 *
 * @psalm-suppress                                PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 */
class Schema extends Entity implements JsonSerializable, SchemaInterface {
	use JsonFieldsTrait;
	use LinkedItemsTrait;

	/**
	 * Unique identifier for the schema
	 *
	 * @var string|null Unique identifier for the schema
	 */
	protected ?string $uuid = null;

	/**
	 * URI of the schema
	 *
	 * @var string|null URI of the schema
	 */
	protected ?string $uri = null;

	/**
	 * Slug of the schema
	 *
	 * @var string|null Slug of the schema
	 */
	protected ?string $slug = null;

	/**
	 * Title of the schema
	 *
	 * @var string|null Title of the schema
	 */
	protected ?string $title = null;

	/**
	 * Description of the schema
	 *
	 * @var string|null Description of the schema
	 */
	protected ?string $description = null;

	/**
	 * Version of the schema
	 *
	 * @var string|null Version of the schema
	 */
	protected ?string $version = null;

	/**
	 * Summary of the schema
	 *
	 * @var string|null Summary of the schema
	 */
	protected ?string $summary = null;

	/**
	 * Required fields of the schema
	 *
	 * @var array|null Required fields of the schema
	 */
	protected ?array $required = [];

	/**
	 * Properties of the schema
	 *
	 * @var array|null Properties of the schema
	 */
	protected ?array $properties = [];

	/**
	 * Archive data of the schema
	 *
	 * @var array|null Archive data of the schema
	 */
	protected ?array $archive = [];

	/**
	 * Pre-computed facet configuration based on schema properties
	 *
	 * **PERFORMANCE OPTIMIZATION**: This field stores pre-analyzed facetable fields
	 * to eliminate runtime schema analysis when _facetable=true is requested.
	 * The facets are automatically generated from schema properties marked with 'facetable': true.
	 *
	 * @var array|null Facet configuration with field types and options
	 */

	/**
	 * Facets configuration for the schema
	 *
	 * @var array|string|null
	 */
	protected $facets = null;

	/**
	 * Source of the schema
	 *
	 * @var string|null Source of the schema
	 */
	protected ?string $source = null;

	/**
	 * Whether hard validation is enabled
	 *
	 * @var boolean Whether hard validation is enabled
	 */
	protected bool $hardValidation = true;

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
	 * Maximum depth of the schema
	 *
	 * @var integer Maximum depth of the schema
	 */
	protected int $maxDepth = 0;

	/**
	 * The Nextcloud user that owns this schema
	 *
	 * @var string|null The Nextcloud user that owns this schema
	 */
	protected ?string $owner = null;

	/**
	 * The application name
	 *
	 * @var string|null The application name
	 */
	protected ?string $application = null;

	/**
	 * Organisation UUID this schema belongs to (for multi-tenancy)
	 *
	 * @var string|null Organisation UUID this schema belongs to
	 */
	protected ?string $organisation = null;

	/**
	 * JSON object describing authorizations
	 *
	 * @var array|null JSON object describing authorizations
	 */
	protected ?array $authorization = [];

	/**
	 * The organisations that may READ this schema as shared master data.
	 *
	 * The `organisation` column is the HOLDER; this list is who else may read
	 * it. A case type and a party are the two the corpus asks for at this
	 * grain: every entity in the samenwerkingsverband reads one definition of
	 * "vergunningaanvraag" rather than keeping a copy that drifts.
	 *
	 * NULL or an empty list means nothing is shared, which is what every row
	 * written before this column existed says.
	 *
	 * @var array|null List of consumer organisation UUIDs
	 *
	 * @spec openspec/changes/several-legal-entities-in-one-instance/specs/saas-multi-tenant/spec.md#requirement-a-register-or-schema-may-be-shared-master-data-across-organisations-req-sle-001
	 */
	protected ?array $sharedWith = null;

	/**
	 * Deletion timestamp
	 *
	 * @var DateTime|null Deletion timestamp
	 */
	protected ?DateTime $deleted = null;

	/**
	 * Configuration of the schema.
	 *
	 * This array can hold various configuration options for the schema.
	 * Use setConfiguration() method to ensure proper validation of configuration values.
	 * See setConfiguration() method documentation for supported options and their validation rules.
	 *
	 * @var array|null
	 * @phpstan-var array<string, mixed>|null
	 * @psalm-var   array<string, mixed>|null
	 */

	/**
	 * Configuration data for the schema
	 *
	 * @var array|string|null
	 */
	protected $configuration = null;

	/**
	 * The icon for the schema from Material Design Icons
	 *
	 * @var string|null The icon reference from https://pictogrammers.com/library/mdi/
	 */
	protected ?string $icon = null;

	/**
	 * Whether this schema is immutable (cannot be changed after creation)
	 *
	 * @var boolean
	 */
	protected bool $immutable = false;

	/**
	 * Whether objects of this schema are append-only (INSERT allowed; UPDATE and DELETE rejected)
	 *
	 * When true, new objects can be created but existing objects cannot be mutated or removed.
	 * This is used for append-only audit logs (e.g. xAPI statements, compliance attestations)
	 * where immutability of past records is a business or legal requirement.
	 *
	 * @var boolean
	 */
	protected bool $appendOnly = false;

	/**
	 * Whether objects of this schema are included in search
	 *
	 * When set to false, objects of this schema are excluded from search,
	 * making them unsearchable through the search functionality but still accessible
	 * through direct API calls.
	 *
	 * @var boolean Whether this schema should be searchable (default: true)
	 */
	protected bool $searchable = true;

	/**
	 * Whether this schema opts in to its own Smart Picker entry
	 *
	 * Gates functionality only for a schema-scoped Smart Picker provider
	 * (`AbstractSchemaReferenceProvider`/`AbstractSchemaSearchProvider`
	 * subclass registered by a consuming app): when `false`, that provider's
	 * `matchReference()`/`resolveReference()`/`search()` are all functionally
	 * inert for this schema. It does NOT remove the provider's entry from
	 * the Smart Picker's "Select provider" list — that list is populated
	 * once at app boot from static class registration, which never consults
	 * this runtime flag. Default `false`: opt-in, unlike `searchable`'s
	 * opt-out default, because exposing a schema as its own picker entry is
	 * a more visible, deliberate choice than being included in generic
	 * search.
	 *
	 * @var boolean Whether this schema opts in to its own Smart Picker entry (default: false)
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	protected bool $smartPickerEnabled = false;

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
	 * Array of schema references that this schema must validate against (all schemas).
	 * Implements JSON Schema 'allOf' for multiple inheritance/composition.
	 * The instance must validate against ALL schemas in the array.
	 * Only additional constraints are allowed (Liskov Substitution Principle).
	 * Metadata (title, description, order) can be overridden.
	 *
	 * @var array|null Array of schema IDs, UUIDs, or slugs
	 */
	protected ?array $allOf = null;

	/**
	 * Array of schema references where instance must validate against exactly one.
	 * Implements JSON Schema 'oneOf' for mutually exclusive options.
	 * The instance must validate against EXACTLY ONE schema in the array.
	 *
	 * @var array|null Array of schema IDs, UUIDs, or slugs
	 */
	protected ?array $oneOf = null;

	/**
	 * Array of schema references where instance must validate against at least one.
	 * Implements JSON Schema 'anyOf' for flexible composition.
	 * The instance must validate against AT LEAST ONE schema in the array.
	 *
	 * @var array|null Array of schema IDs, UUIDs, or slugs
	 */
	protected ?array $anyOf = null;

	/**
	 * Hooks configuration for the schema
	 *
	 * @var array|null Hooks configuration
	 */
	protected ?array $hooks = null;

	/**
	 * A pending edit of this schema's definition, held beside the published one.
	 *
	 * Validation never reads it; publishing applies it through the normal update.
	 * Only SchemasController's draft routes write it, never hydrate().
	 *
	 * @var array|null The draft body, or null when there is none
	 */
	protected ?array $draft = null;

	/**
	 * Constructor for the Schema class
	 *
	 * Sets up field types for all properties, from SchemaFieldTypes::TYPES.
	 */
	public function __construct() {
		foreach (SchemaFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Get the required data
	 *
	 * @return array The required data or empty array if null
	 */
	public function getRequired(): array {
		if ($this->required === null) {
			return [];
		}

		// If it's already an array, return it directly.
		if (is_array($this->required) === true) {
			return $this->required;
		}

		// If it's a JSON string, decode it.
		if (is_string($this->required) === true) {
			$decoded = json_decode($this->required, true);
			if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
				return $decoded;
			}
		}

		// If we get here, something is wrong - return empty array.
		return [];
	}//end getRequired()

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
	public function setRequired(array|string|null $required): void {
		// **DATABASE COMPATIBILITY**: Handle JSON string from database.
		if (is_string($required) === true) {
			try {
				$decoded = json_decode($required, true);
				// Invalid JSON, set to empty array.
				$this->required = [];
				if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) === true) {
					$this->required = $decoded;
				}
			} catch (Exception $e) {
				// If decoding fails, set to empty array.
				$this->required = [];
			}

			$this->markFieldUpdated(attribute: 'required');
			return;
		}

		// Always ensure required is an array, never NULL.
		// This is critical for schema validation to work correctly.
		$this->required = ($required ?? []);
		$this->markFieldUpdated(attribute: 'required');
	}//end setRequired()

	/**
	 * Get the properties data
	 *
	 * @return array The properties data or empty array if null
	 */
	public function getProperties(): array {
		return ($this->properties ?? []);
	}//end getProperties()

	/**
	 * Check if any property in the schema has authorization rules defined.
	 *
	 * This is used to determine if property-level RBAC filtering needs to be applied
	 * during object rendering or validation.
	 *
	 * @return bool True if at least one property has non-empty authorization
	 */
	public function hasPropertyAuthorization(): bool {
		return (new PropertyAuthorization())->hasAny(properties: $this->properties);
	}//end hasPropertyAuthorization()

	/**
	 * Get the authorization rules for a specific property.
	 *
	 * @param string $propertyName The name of the property
	 *
	 * @return array|null The authorization rules or null if none defined
	 */
	public function getPropertyAuthorization(string $propertyName): ?array {
		return (new PropertyAuthorization())->forProperty(properties: $this->properties, propertyName: $propertyName);
	}//end getPropertyAuthorization()

	/**
	 * Get all properties that have authorization rules defined.
	 *
	 * @return array<string, array> Map of property names to their authorization rules
	 */
	public function getPropertiesWithAuthorization(): array {
		return (new PropertyAuthorization())->all(properties: $this->properties);
	}//end getPropertiesWithAuthorization()

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
	public function hasWriteOnlyProperties(): bool {
		if (empty($this->properties) === true) {
			return false;
		}

		foreach ($this->properties as $propertyConfig) {
			if (is_array($propertyConfig) === true
				&& ($propertyConfig['writeOnly'] ?? null) === true
			) {
				return true;
			}
		}

		return false;
	}//end hasWriteOnlyProperties()

	/**
	 * Get the names of all properties declared write-only (`writeOnly: true`).
	 *
	 * @return array<int, string> List of write-only property names
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md#requirement-writeonly-properties-must-never-be-returned-on-any-read
	 */
	public function getWriteOnlyProperties(): array {
		$result = [];

		if (empty($this->properties) === true) {
			return $result;
		}

		foreach ($this->properties as $propertyName => $propertyConfig) {
			if (is_array($propertyConfig) === true
				&& ($propertyConfig['writeOnly'] ?? null) === true
			) {
				$result[] = (string)$propertyName;
			}
		}

		return $result;
	}//end getWriteOnlyProperties()

	/**
	 * The schema-level annotation declaring nested write-only dot-paths.
	 *
	 * `writeOnly: true` is a JSON Schema keyword and can therefore only be
	 * attached to a property the schema actually DECLARES. A secret nested
	 * inside an untyped `object` property (the canonical case: a Source's
	 * `configuration.authentication.client_secret`, a Rule's
	 * `configuration.authentication.keys`) has no declaration to hang it on —
	 * `configuration` is `type: object` with no `properties` — and marking the
	 * whole `configuration` object writeOnly breaks the editors that
	 * legitimately read the rest of it back. This annotation is the missing
	 * declaration surface: it names individual nested paths as write-only
	 * without touching their untyped parent.
	 *
	 * @var string
	 */
	public const WRITEONLY_PATHS_ANNOTATION = 'x-openregister-writeonly-paths';


	/**
	 * The annotation by which a schema opts its objects into archiving.
	 *
	 * Shape: `{"enabled": true}`. Read through
	 * {@see self::isArchivingEnabled()}, which is the single definition of
	 * "does this schema offer archiving" — no caller should re-read the key.
	 *
	 * @var string
	 */
	public const ARCHIVE_ANNOTATION = 'x-openregister-archive';

	/**
	 * The property-level keyword that makes a value immutable once set.
	 *
	 * A property carrying `"immutable": true` accepts its first value and
	 * refuses every later change, whatever the object's state and whoever the
	 * actor is. That is a rule about the property, not about the object: a
	 * vastgesteld besluit keeps its date while the rest of the object is still
	 * open for editing.
	 *
	 * @var string
	 */
	public const IMMUTABLE_PROPERTY_KEYWORD = 'immutable';

	/**
	 * The property-level keyword that makes a list a repeating group.
	 *
	 * A property carrying `"repeatingGroup": true` is a group of fields that
	 * repeats: meerdere gemachtigden, meerdere percelen, meerdere zienswijzen.
	 * The members are the `items.properties` of the list and the count is
	 * bounded by `minItems` and `maxItems`, so the shape stays an ordinary
	 * array of objects. What the keyword adds is the declaration that an
	 * editor may author rows against, and the promise that a refusal names
	 * the row it refused.
	 *
	 * @var string
	 */
	public const REPEATING_GROUP_PROPERTY_KEYWORD = 'repeatingGroup';

	/**
	 * The property-level keyword that says the order of the rows is meaningful.
	 *
	 * Declared on a repeating group. `true` means the stored order is the
	 * authored order and a reader may rely on it. It carries no enforcement of
	 * its own: it tells the editor whether to offer a handle to drag a row.
	 *
	 * @var string
	 */
	public const GROUP_ORDERED_PROPERTY_KEYWORD = 'groupOrdered';

	/**
	 * The property-level keyword naming the member that labels a row.
	 *
	 * Declared on a repeating group, holding the name of one member property.
	 * A row collapsed in a form shows that member's value, so a list of six
	 * gemachtigden reads as six names instead of six copies of the word row.
	 *
	 * @var string
	 */
	public const GROUP_LABEL_PROPERTY_KEYWORD = 'groupLabel';

	/**
	 * The reserved body key that records which properties were not supplied.
	 *
	 * Shape: `{"<property>": "<reason code>"}`, sitting beside `@self` at the
	 * top of the object body. A property named here was left out on purpose,
	 * with a reason from the administered list, which is a different fact from
	 * an empty field. It is stripped before the object meets the validator and
	 * stored with the object, so it reads back with it.
	 *
	 * @var string
	 */
	public const NOT_SUPPLIED_KEY = '@notSupplied';

	/**
	 * The annotation holding the reasons a value may be recorded as not supplied.
	 *
	 * Shape: `{"<code>": "<label>"}`. A schema that administers no reasons
	 * offers no not-supplied state, which is deliberate: "not supplied" without
	 * a reason is the same empty field it replaces. The list lives on the
	 * schema rather than in instance settings because the honest reasons for a
	 * bouwvergunning are not the honest reasons for a personeelsdossier
	 * (ADR-031).
	 *
	 * @var string
	 */
	public const NOT_SUPPLIED_REASONS_ANNOTATION = 'x-openregister-not-supplied-reasons';

	/**
	 * The authorization key that assigns each role name its groups.
	 *
	 * A role is the schema's own vocabulary, and the map says which Nextcloud
	 * groups hold it. It is not an action rule set: nothing is granted by
	 * declaring a role. The notification dispatcher reads this map to turn
	 * `{"kind": "role", "role": "behandelaar"}` into the people to tell, and a
	 * lifecycle transition reads the same map, so the assignment changes in one
	 * place rather than in every rule that names the role.
	 *
	 * @var string
	 */
	public const ROLES_KEY = 'roles';

	/**
	 * The lens annotation: properties that read a referenced record's field live.
	 *
	 * Keyed by the property name the lens renders as, each entry naming the
	 * reference property to look through and the property to read there. A lens
	 * holds the path, never the value, so two records can never disagree about
	 * the same date.
	 *
	 * @var string
	 */
	public const LENS_ANNOTATION = 'x-openregister-lenses';

	/**
	 * The operators an authorization `match` may use (openregister#4089).
	 *
	 * Exactly the set OperatorEvaluator and MagicRbacHandler both evaluate; a
	 * schema naming any other `$` operator in a match is refused at save.
	 *
	 * @var string[]
	 */
	public const MATCH_OPERATORS = ['$eq', '$ne', '$in', '$nin', '$contains', '$exists', '$gt', '$gte', '$lt', '$lte'];

	/**
	 * The list-surface annotation: declared columns and search fields.
	 *
	 * A list page written per object type is a list page that drifts per object
	 * type. The schema declares what to show and what to search; the generic
	 * surface renders it.
	 *
	 * @var string
	 */
	public const LIST_ANNOTATION = 'x-openregister-list';

	/**
	 * The geographic-inheritance annotation: which references carry map features.
	 *
	 * A record may show the point its address holds. Every inherited feature
	 * names the relation it arrived through, and a feature the record holds
	 * itself outranks an inherited one.
	 *
	 * @var string
	 */
	public const GEO_INHERITANCE_ANNOTATION = 'x-openregister-geo-inheritance';

	/**
	 * The property keys whose falsy value is a real one.
	 *
	 * An empty value is dropped by getSchemaObject(), because an empty `title`
	 * says nothing; these carry a value instead, so `false`, `0` and a zero
	 * bound survive. The four numeric ones are the draft-2020-12 keywords, all
	 * declared `number` in PropertyValidatorHandler's table -- not the form's
	 * `exclusiveMin`/`exclusiveMax`, which are booleans meaning "read the bound
	 * as exclusive" and whose `false` really is unset.
	 *
	 * Not the full set of value-carrying keywords. `multipleOf: 0` would be an
	 * invalid schema, and the length and item bounds have no falsy writer:
	 * the property form normalises them through `parseFloat(...) || null`, and
	 * nothing generates one at 0 the way TablesColumnMapper::numberProperty()
	 * generates `minimum: 0`. Add a key when something starts writing one.
	 *
	 * @var array<int, string>
	 */
	public const VALUE_CARRYING_PROPERTY_KEYS = [
		'default',
		'const',
		'minimum',
		'maximum',
		'exclusiveMinimum',
		'exclusiveMaximum',
	];

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
	public function hasWriteOnlyPaths(): bool {
		return empty($this->getWriteOnlyPaths()) === false;
	}//end hasWriteOnlyPaths()

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
	public function getWriteOnlyPaths(): array {
		$configuration = $this->configuration;
		if (is_array($configuration) === false) {
			return [];
		}

		$paths = ($configuration[self::WRITEONLY_PATHS_ANNOTATION] ?? null);
		if (is_array($paths) === false) {
			return [];
		}

		$result = [];
		foreach ($paths as $path) {
			if (is_string($path) === true && $path !== '') {
				$result[] = $path;
			}
		}

		return $result;
	}//end getWriteOnlyPaths()

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
	public function hasEncryptedProperties(): bool {
		if (empty($this->properties) === true) {
			return false;
		}

		foreach ($this->properties as $propertyConfig) {
			if (is_array($propertyConfig) === true
				&& ($propertyConfig['x-openregister-encrypted'] ?? null) === true
			) {
				return true;
			}
		}

		return false;
	}//end hasEncryptedProperties()

	/**
	 * Get the names of all properties declared encrypted (`x-openregister-encrypted: true`).
	 *
	 * @return array<int, string> List of encrypted property names
	 *
	 * @spec openspec/specs/field-level-encryption/spec.md#requirement-flagged-properties-are-encrypted-on-save
	 */
	public function getEncryptedProperties(): array {
		$result = [];

		if (empty($this->properties) === true) {
			return $result;
		}

		foreach ($this->properties as $propertyName => $propertyConfig) {
			if (is_array($propertyConfig) === true
				&& ($propertyConfig['x-openregister-encrypted'] ?? null) === true
			) {
				$result[] = (string)$propertyName;
			}
		}

		return $result;
	}//end getEncryptedProperties()

	/**
	 * Get the archive data
	 *
	 * @return array The archive data or empty array if null
	 */
	public function getArchive(): array {
		return ($this->archive ?? []);
	}//end getArchive()

	/**
	 * Validate the schema properties
	 *
	 * @param PropertyValidatorHandlerInterface $validator The schema property validator
	 *
	 * @throws \Exception If the properties are invalid
	 *
	 * @return true True if the properties are valid
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 */
	public function validateProperties(PropertyValidatorHandlerInterface $validator): bool {
		// Check if properties are set and not empty.
		if (empty($this->properties) === true) {
			return true;
		}

		// Validate and normalize inversedBy properties to ensure they are strings.
		// TODO: Move writeBack, removeAfterWriteBack, and inversedBy
		// from items property to configuration property.
		$this->properties = (new InversedByNormaliser())->normalised(properties: $this->properties);

		return $validator->validateProperties($this->properties);
	}//end validateProperties()

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
	public function validateAuthorization(): bool {
		return (new AuthorizationRulesValidator(declaredActions: $this->declaredActionNames()))->validate(
			authorization: $this->authorization,
			properties: $this->properties
		);
	}//end validateAuthorization()

	/**
	 * The extra action names this schema declares.
	 *
	 * Read from `configuration['x-openregister-action']`, a map of action key →
	 * `{name, description}`. A malformed or absent block contributes nothing, so
	 * the vocabulary falls back to CRUD rather than opening up — failing closed
	 * is the only safe direction for a permission vocabulary.
	 *
	 * @return array<int, string> Declared action keys.
	 *
	 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
	 */
	public function declaredActionNames(): array {
		return (new DeclaredActions())->names(configuration: $this->configuration);
	}//end declaredActionNames()

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
	 * @param GrantQuery $query The group and CRUD action (create, read, update, delete) asked for, the
	 *                         actor (user, user group, active organisation) and the object (owner,
	 *                         data for conditional matches, organisation).
	 *
	 * @return bool True if the group has permission for the action
	 *
	 * @deprecated This is a pre-unification duplicate of the RBAC matcher. The
	 *             canonical evaluators are {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface::hasPermission()}
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
	public function hasPermission(GrantQuery $query): bool {
		return (new SchemaGrantEvaluator())->allows(authorization: $this->authorization, query: $query);
	}//end hasPermission()

	/**
	 * Get all groups that have permission for a specific action
	 *
	 * @param string $action The CRUD action to check
	 *
	 * @return array Array of group IDs that have permission, or empty array if all groups have permission
	 */
	public function getAuthorizedGroups(string $action): array {
		// If no authorization is set, return empty array (meaning all groups).
		if (empty($this->authorization) === true) {
			return [];
		}

		// If action is not specified, return empty array (meaning all groups).
		if (isset($this->authorization[$action]) === false) {
			return [];
		}

		// Return the specific groups that have permission.
		return $this->authorization[$action] ?? [];
	}//end getAuthorizedGroups()

	/**
	 * Hydrate the entity with data from an array
	 *
	 * Sets entity properties based on input array values
	 *
	 * @param array $object The data array to hydrate from
	 * @param PropertyValidatorHandlerInterface $validator Optional validator for properties
	 *
	 * @throws \Exception If property validation fails
	 *
	 * @return static Returns $this for method chaining
	 */
	public function hydrate(array $object, ?PropertyValidatorHandlerInterface $validator = null): static {
		$object = $this->withExactKeysOnly(
			object: $this->foldTopLevelExportable(
				object: $this->foldTopLevelAnnotations(object: self::withHydrateDefaults(object: $object))
			)
		);

		foreach ($object as $key => $value) {
			$this->hydrateField(field: $key, value: $value);
		}

		$this->validateHydrated(object: $object, validator: $validator);

		return $this;
	}//end hydrate()

	/**
	 * Validate the properties and the authorization block the input declared.
	 *
	 * @param array<string, mixed>          $object    The input.
	 * @param PropertyValidatorHandlerInterface|null $validator The property validator.
	 *
	 * @throws \Exception If validation fails.
	 *
	 * @return void
	 */
	private function validateHydrated(array $object, ?PropertyValidatorHandlerInterface $validator): void {
		if ($validator !== null && (($object['properties'] ?? null) !== null)) {
			$this->validateProperties(validator: $validator);
		}

		if (($object['authorization'] ?? null) !== null) {
			$this->validateAuthorization();
		}
	}//end validateHydrated()

	/**
	 * The input with `draft` dropped and the fields every schema hydrates.
	 *
	 * A draft is written by the draft routes only: an import, a PUT body or a
	 * re-imported export carrying `draft` would otherwise set a pending edit
	 * nobody asked for (modelling-schema-draft). `required` always exists so
	 * validation works, and `hardValidation` defaults to true so schemas
	 * validate unless explicitly disabled.
	 *
	 * @param array<string, mixed> $object The input.
	 *
	 * @return array<string, mixed>
	 */
	private static function withHydrateDefaults(array $object): array {
		unset($object['draft']);

		$object['metadata'] = ($object['metadata'] ?? []);
		$object['required'] = ($object['required'] ?? []);
		$object['hardValidation'] = ($object['hardValidation'] ?? true);

		return $object;
	}//end withHydrateDefaults()

	/**
	 * Hydrate one field; `required` must always be an array, never NULL.
	 *
	 * @param string $field The field name.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 */
	private function hydrateField(string $field, mixed $value): void {
		if ($field === 'required') {
			$this->setRequired(required: $value);
			return;
		}

		if ($value === [] && in_array($field, $this->getJsonFields()) === true) {
			$value = null;
		}

		match ($field) {
			'hardValidation' => $this->hydrateHardValidation(value: $value),
			'configuration' => $this->hydrateConfiguration(value: $value),
			'created', 'updated', 'deleted' => $this->hydrateBySetter(field: $field, value: self::dateOrNull(value: $value)),
			default => $this->hydrateBySetter(field: $field, value: $value),
		};
	}//end hydrateField()

	/**
	 * Set `hardValidation` explicitly and mark it updated: the database column
	 * defaults to 0/false, but schemas validate by default.
	 *
	 * @param mixed $value The value.
	 *
	 * @return void
	 */
	private function hydrateHardValidation(mixed $value): void {
		$this->hardValidation = (bool)$value;
		$this->markFieldUpdated(attribute: 'hardValidation');
	}//end hydrateHardValidation()

	/**
	 * Set the configuration through its validation; invalid input becomes null.
	 *
	 * @param mixed $value The configuration, or its JSON text.
	 *
	 * @return void
	 */
	private function hydrateConfiguration(mixed $value): void {
		try {
			$this->setConfiguration(configuration: self::decodedConfiguration(value: $value));
		} catch (\Exception $exception) {
			$this->configuration = null;
			$this->markFieldUpdated(attribute: 'configuration');
		}
	}//end hydrateConfiguration()

	/**
	 * A configuration given as JSON text decoded, null when the text is invalid.
	 *
	 * @param mixed $value The configuration.
	 *
	 * @return mixed
	 */
	private static function decodedConfiguration(mixed $value): mixed {
		if (is_string($value) === false) {
			return $value;
		}

		$decoded = json_decode($value, true);
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}

		return null;
	}//end decodedConfiguration()

	/**
	 * A datetime field's value as a DateTime; anything unusable becomes null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return DateTime|null
	 */
	private static function dateOrNull(mixed $value): ?DateTime {
		if ($value instanceof DateTime) {
			return $value;
		}

		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTime($value);
		} catch (Exception $e) {
			return null;
		}
	}//end dateOrNull()

	/**
	 * Hydrate a field through its setter, ignoring what the setter refuses.
	 *
	 * @param string $field The field name.
	 * @param mixed  $value The value.
	 *
	 * @return void
	 */
	private function hydrateBySetter(string $field, mixed $value): void {
		$method = 'set' . ucfirst($field);

		try {
			$this->$method($value);
		} catch (\Exception $exception) {
			// Silently ignore invalid properties.
		}
	}//end hydrateBySetter()

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
	 */
	public function jsonSerialize(): array {
		return array_merge(
			$this->serialisedIdentity(),
			$this->serialisedContent(),
			$this->serialisedOwnership(),
			$this->serialisedComposition(),
			$this->serialisedLinks()
		);
	}//end jsonSerialize()

	/**
	 * The identity fields of the serialised schema.
	 *
	 * @return array<string, mixed>
	 */
	private function serialisedIdentity(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'uri' => $this->uri,
			'slug' => $this->slug,
			'title' => $this->title,
			'description' => $this->description,
			'version' => $this->version,
			'summary' => $this->summary,
			'icon' => $this->icon,
		];
	}//end serialisedIdentity()

	/**
	 * The content and behaviour fields of the serialised schema.
	 *
	 * @return array<string, mixed>
	 */
	private function serialisedContent(): array {
		return [
			'required' => $this->requiredWithFlaggedProperties(),
			'properties' => ($this->properties ?? []),
			'archive' => $this->archive,
			'source' => $this->source,
			'hardValidation' => $this->hardValidation,
			'immutable' => $this->immutable,
			'appendOnly' => $this->appendOnly,
			'searchable' => $this->searchable,
			'smartPickerEnabled' => $this->smartPickerEnabled,
		];
	}//end serialisedContent()

	/**
	 * The `required` list plus every property flagged `required: true`.
	 *
	 * @return array<int, string>
	 */
	private function requiredWithFlaggedProperties(): array {
		$required = ($this->required ?? []);
		foreach (($this->properties ?? []) as $propertyKey => $property) {
			if (($property['required'] ?? null) === true && in_array($propertyKey, $required) === false) {
				$required[] = $propertyKey;
			}
		}

		return $required;
	}//end requiredWithFlaggedProperties()

	/**
	 * The dates and ownership fields of the serialised schema.
	 *
	 * @return array<string, mixed>
	 */
	private function serialisedOwnership(): array {
		return [
			// @todo: should be refactored to strict.
			'updated' => $this->updated?->format('c'),
			'created' => $this->created?->format('c'),
			'maxDepth' => $this->maxDepth,
			'owner' => $this->owner,
			'application' => $this->application,
			'organisation' => $this->organisation,
			'groups' => $this->groups,
			'authorization' => $this->authorization,
			'sharedWith' => ($this->sharedWith ?? []),
			'deleted' => $this->deleted?->format('c'),
		];
	}//end serialisedOwnership()

	/**
	 * The configuration and composition fields of the serialised schema.
	 *
	 * @return array<string, mixed>
	 */
	private function serialisedComposition(): array {
		return [
			'configuration' => $this->configuration,
			// Mirror of `configuration.exportable`, the one place the flag is
			// stored, served at the top level too because that is where the
			// index page's Export menu reads it (or#4103).
			'exportable' => (($this->configuration['exportable'] ?? false) === true),
			'allOf' => $this->allOf,
			'oneOf' => $this->oneOf,
			'anyOf' => $this->anyOf,
			'facets' => $this->facets,
			'hooks' => $this->hooks,
		];
	}//end serialisedComposition()

	/**
	 * The linked-entity blocks and the draft of the serialised schema.
	 *
	 * @return array<string, mixed>
	 */
	private function serialisedLinks(): array {
		return [
			'_mail' => $this->mail,
			'_contacts' => $this->contacts,
			'_notes' => $this->notes,
			'_todos' => $this->todos,
			'_calendar' => $this->calendar,
			'_talk' => $this->talk,
			'_deck' => $this->deck,
			'draft' => $this->draft,
		];
	}//end serialisedLinks()

	/**
	 * Converts schema to an object representation
	 *
	 * Creates a standard object representation of the schema for API use
	 *
	 * @param IURLGenerator $urlGenerator The URL generator for URLs in the schema
	 *
	 * @return stdClass A standard object representation of the schema
	 */
	public function getSchemaObject(IURLGenerator $urlGenerator): stdClass {
		$schema = new stdClass();
		$schema->title = $this->title;
		$schema->description = $this->description;
		$schema->version = $this->version;
		$schema->type = 'object';
		$schema->required = $this->required;
		$schema->{'$schema'} = 'https://json-schema.org/draft/2020-12/schema';
		$schema->{'$id'} = $urlGenerator->getBaseUrl() . '/apps/openregister/api/v1/schemas/' . $this->uuid;
		$schema->properties = (new SchemaPropertyDocument())->render(properties: ($this->properties ?? []));

		return $schema;
	}//end getSchemaObject()

	/**
	 * Set the slug, ensuring it is always lowercase
	 *
	 * @param string|null $slug The slug to set
	 *
	 * @return void
	 */
	public function setSlug(?string $slug): void {
		// Preserve original case for slug to support camelCase schema names like 'moduleVersie'.
		// Schema slugs should match exactly as defined in the configuration.
		$this->slug = $slug;
		$this->markFieldUpdated(attribute: 'slug');
	}//end setSlug()

	/**
	 * Get the icon for the schema
	 *
	 * @return string|null The icon reference from Material Design Icons
	 */
	public function getIcon(): ?string {
		return $this->icon;
	}//end getIcon()

	/**
	 * Set the icon for the schema
	 *
	 * @param string|null $icon The icon reference from Material Design Icons
	 *
	 * @return void
	 */
	public function setIcon(?string $icon): void {
		$this->icon = $icon;
		$this->markFieldUpdated(attribute: 'icon');
	}//end setIcon()

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
	public function getUpsertKeys(): array {
		$keys = [];
		foreach ((new UniqueConstraintEvaluator())->constraints(configuration: $this->getConfiguration(), includeLegacy: true) as $constraint) {
			if ($constraint['action'] === UniqueConstraintEvaluator::ACTION_REFUSE) {
				$keys[] = $constraint['name'];
			}
		}

		return $keys;
	}//end getUpsertKeys()

	/**
	 * Get the configuration for the schema
	 *
	 * Ensures that configuration is always returned as an array,
	 * automatically decoding JSON strings if necessary.
	 *
	 * @return array|null The configuration array or null if not set
	 */
	public function getConfiguration(): ?array {
		if ($this->configuration === null) {
			return null;
		}

		// If it's already an array, return it directly.
		if (is_array($this->configuration) === true) {
			return $this->configuration;
		}

		// If it's a JSON string, decode it.
		if (is_string($this->configuration) === true) {
			$decoded = json_decode($this->configuration, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				return $decoded;
			}
		}

		// If we get here, something is wrong - return null.
		return null;
	}//end getConfiguration()

	/**
	 * Get the calendar provider configuration from the schema configuration
	 *
	 * Extracts the calendarProvider section from the configuration JSON.
	 * Returns null if not present or if enabled is false.
	 *
	 * @return array|null The calendar provider config array, or null if disabled/absent
	 */
	public function getCalendarProviderConfig(): ?array {
		$configuration = $this->getConfiguration();

		if ($configuration === null) {
			return null;
		}

		$calendarConfig = $configuration['calendarProvider'] ?? null;

		if ($calendarConfig === null || is_array($calendarConfig) === false) {
			return null;
		}

		if (empty($calendarConfig['enabled']) === true) {
			return null;
		}

		return $calendarConfig;
	}//end getCalendarProviderConfig()

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
	public function getObjectSource(): ?array {
		return (new ObjectSourceDeclaration())->declared(configuration: $this->getConfiguration());
	}//end getObjectSource()

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
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getLenses(): array {
		return (new PresentationAnnotations())->lenses(configuration: $this->getConfiguration());
	}//end getLenses()

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
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getListPresentation(): array {
		return (new PresentationAnnotations())->listPresentation(configuration: $this->getConfiguration());
	}//end getListPresentation()

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
	 * @spec openspec/specs/linked-entity-types/spec.md
	 */
	public function getGeoInheritance(): array {
		return (new PresentationAnnotations())->geoInheritance(configuration: $this->getConfiguration());
	}//end getGeoInheritance()

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
	public function isContextChatIndexingEnabled(): bool {
		$configuration = $this->getConfiguration();

		if ($configuration === null) {
			return false;
		}

		return ($configuration['x-openregister-contextchat'] ?? false) === true;
	}//end isContextChatIndexingEnabled()

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
	 * - 'objectSlugField': (string) A dot-notation path or template for the field an
	 *   object's slug is built from. Example: 'kenmerk'. The reserved value
	 *   '@schema.name' builds the slug from the schema name and the object's name
	 *   ('objectNameField') instead: '<schema slug>-<name>'. A slug is unique within
	 *   the register and schema and does not change once set (#4037).
	 * - 'objectSlugSuffix': (bool) Append a UTC date-time suffix to a slug that is
	 *   already taken instead of saving the object without a slug. Default false.
	 * - 'allowFiles': (bool) Whether this schema allows file attachments
	 * - 'orphanCleanup': (bool) Soft-delete a child the parent owns through an inverse
	 *   relation when the parent stops referencing it. Default false: the child stays.
	 * - 'allowedTags': (array) Array of allowed file tags/types for file filtering
	 * - 'autoShare': (bool) Create a Nextcloud share for every file uploaded to
	 *   a file property of this schema, unless the property sets its own.
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
	public function setConfiguration($configuration): void {
		$validated = null;
		if ($configuration !== null) {
			$validated = $this->validatedConfiguration(configuration: $configuration);
		}

		$this->configuration = $validated;
		$this->markFieldUpdated(attribute: 'configuration');
	}//end setConfiguration()

	/**
	 * Parse and validate a configuration input; null when it does not parse or nothing in it is kept.
	 *
	 * A validator that throws leaves the stored configuration as it was: the caller assigns only after this returns.
	 *
	 * @param mixed $configuration The configuration array or JSON string.
	 *
	 * @throws \InvalidArgumentException If a key that must fail the whole save is invalid
	 *
	 * @return array|null The validated configuration.
	 */
	private function validatedConfiguration(mixed $configuration): ?array {
		$parsed = $this->parseConfigurationInput(configuration: $configuration);
		if ($parsed === null) {
			return null;
		}

		$validated = $this->validatedArray(configuration: $parsed);
		if (empty($validated) === true) {
			return null;
		}

		return $validated;
	}//end validatedConfiguration()

	/**
	 * Validate a parsed configuration, recording the keys it drops.
	 *
	 * @param array $configuration The parsed configuration.
	 *
	 * @throws \InvalidArgumentException If a key that must fail the whole save is invalid
	 *
	 * @return array The keys that passed.
	 */
	private function validatedArray(array $configuration): array {
		$validator = new ConfigurationValidator(properties: $this->properties);
		try {
			return $validator->validate(configuration: $configuration);
		} finally {
			// Recorded even when the save fails, as they were when this ran inline.
			array_push($this->droppedKeys, ...$validator->droppedKeys());
		}
	}//end validatedArray()

	/**
	 * Parse configuration input into an array
	 *
	 * @param mixed $configuration Configuration input
	 *
	 * @return array|null Parsed array or null if invalid
	 */
	private function parseConfigurationInput(mixed $configuration): ?array {
		if (is_array($configuration) === true) {
			return $configuration;
		}

		if (is_string($configuration) === true) {
			$decoded = json_decode($configuration, true);
			if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
				return $decoded;
			}
		}

		return null;
	}//end parseConfigurationInput()

	/**
	 * Fold top-level annotation blocks into the `configuration` array.
	 *
	 * @param array<string, mixed> $object The hydrate input.
	 *
	 * @return array<string, mixed> The input with its annotation blocks folded.
	 */
	private function foldTopLevelAnnotations(array $object): array {
		return (new TopLevelAnnotationFold())->folded(object: $object);
	}//end foldTopLevelAnnotations()

	/**
	 * Drop every key that is not the exact name of a property.
	 *
	 * The setter call below resolves `Authorization` to setAuthorization() too,
	 * and every check on a schema body reads the exact key, so another spelling
	 * would be stored unchecked.
	 *
	 * @param array<int|string, mixed> $object The hydrate input.
	 *
	 * @return array<string, mixed> The input with only exact property names as keys.
	 */
	private function withExactKeysOnly(array $object): array {
		return array_filter(
			$object,
			fn (int|string $key): bool => is_string($key) === true && property_exists($this, $key) === true,
			ARRAY_FILTER_USE_KEY
		);
	}//end withExactKeysOnly()

	/**
	 * Fold a top-level `exportable` into `configuration.exportable`.
	 *
	 * The entity has no `exportable` field, so a top-level flag used to fall
	 * through to a `setExportable()` that does not exist and be swallowed by
	 * hydrate()'s silent catch (or#4103). It is folded into the configuration
	 * instead, the same way `x-schema-org` is: an explicit
	 * `configuration.exportable` wins. A top-level `false` with no
	 * configuration value is not written, because absent already means not
	 * exportable and the serialised schema carries the mirror on every read,
	 * so a read-and-save round trip would otherwise add the key to every
	 * schema. When the payload carries no configuration the stored one is
	 * the base, so a partial write of the flag keeps the rest.
	 *
	 * @param array $object The hydrate payload.
	 *
	 * @return array The payload without a top-level `exportable`.
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	private function foldTopLevelExportable(array $object): array {
		if (array_key_exists('exportable', $object) === false) {
			return $object;
		}

		$exportable = $object['exportable'];
		unset($object['exportable']);

		$config = ($object['configuration'] ?? $this->configuration ?? []);
		if (is_string($config) === true) {
			$config = json_decode($config, true);
		}

		if (is_array($config) === false || array_key_exists('exportable', $config) === true || $exportable === false) {
			return $object;
		}

		$config['exportable'] = $exportable;
		$object['configuration'] = $config;

		return $object;
	}//end foldTopLevelExportable()

	/**
	 * Dropped `x-openregister-*` annotation keys collected during the most
	 * recent `ConfigurationValidator::validate()` pass (R07). SchemaMapper reads
	 * this after `setConfiguration()` and emits a logger->warning()
	 * for each entry so operators see a signal without us having to
	 * inject a logger into the entity itself.
	 *
	 * @var array<int, string>
	 */
	private array $droppedKeys = [];

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
	public function consumeDroppedAnnotationKeys(): array {
		$dropped = $this->droppedKeys;
		$this->droppedKeys = [];
		return $dropped;
	}//end consumeDroppedAnnotationKeys()

	/**
	 * Declared `x-openregister-*` annotation keys.
	 *
	 * Keys outside this set are dropped at save time so a typo
	 * (e.g. `x-openregister-lifecycl` instead of `…-lifecycle`) is caught
	 * early. PUBLIC because the import path compares only the keys that can
	 * persist. The list and the reason for every entry live in
	 * {@see AnnotationVocabulary::KEYS}; this is the same list.
	 *
	 * @var array<int, string>
	 */
	public const ANNOTATION_VOCABULARY = AnnotationVocabulary::KEYS;

	/**
	 * The roles a person can hold on an object of this schema, [] when the schema declares none.
	 *
	 * @return array<int, array{key: string, label: string, description?: string}> The vocabulary.
	 *
	 * @spec openspec/changes/people-on-objects/specs/people-on-objects/spec.md#requirement-a-schema-declares-the-roles-its-objects-carry
	 */
	public function getLinkRoles(): array {
		return (new VocabularyEntries())->configuredVocabulary(configuration: $this->configuration, config: 'linkRoles');
	}//end getLinkRoles()

	/**
	 * The kinds of party an object of this schema accepts, [] when it declares none.
	 *
	 * A schema that declares none keeps its reference properties and behaves
	 * as it did before the party model: the picker offers everything and the
	 * validator refuses nothing.
	 *
	 * @return array<int, array{key: string, label: string, description?: string, roles?: array<int, string>}> The vocabulary.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-holds-a-typed-role-on-an-object-for-a-period-req-prm-001
	 */
	public function getPartyKinds(): array {
		return (new VocabularyEntries())->configuredVocabulary(configuration: $this->configuration, config: 'partyKinds');
	}//end getPartyKinds()

	/**
	 * The `x-openregister-party` annotation, [] when the schema is not a party schema.
	 *
	 * @return array<string, mixed> The annotation as stored.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 */
	public function getPartyAnnotation(): array {
		$configured = $this->configuration['x-openregister-party'] ?? null;
		if (is_array($configured) === false) {
			return [];
		}

		return $configured;
	}//end getPartyAnnotation()

	/**
	 * Get the linked types from the schema configuration
	 *
	 * Returns the array of Nextcloud entity types this schema can link to.
	 * Defaults to empty array if not configured.
	 *
	 * @return array The linked types array
	 */
	public function getLinkedTypes(): array {
		$configuration = $this->getConfiguration();

		if ($configuration === null) {
			return [];
		}

		return $configuration['linkedTypes'] ?? [];
	}//end getLinkedTypes()

	/**
	 * Check whether this schema's objects are included in search
	 *
	 * @return bool True if schema objects are included in search
	 */
	public function isSearchable(): bool {
		return $this->searchable;
	}//end isSearchable()

	/**
	 * Set whether this schema's objects are included in search
	 *
	 * @param bool $searchable Whether schema objects are included in search
	 *
	 * @return void
	 */
	public function setSearchable(bool $searchable): void {
		$this->searchable = $searchable;
		$this->markFieldUpdated(attribute: 'searchable');
	}//end setSearchable()

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
	public function isSmartPickerEnabled(): bool {
		return $this->smartPickerEnabled;
	}//end isSmartPickerEnabled()

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
	public function setSmartPickerEnabled(bool $smartPickerEnabled): void {
		$this->smartPickerEnabled = $smartPickerEnabled;
		$this->markFieldUpdated(attribute: 'smartPickerEnabled');
	}//end setSmartPickerEnabled()

	/**
	 * Check whether objects of this schema are append-only.
	 *
	 * When true, INSERT is permitted but UPDATE and DELETE are rejected with
	 * HTTP 405 and error code SCHEMA_APPEND_ONLY.
	 *
	 * @return bool True if the schema is append-only
	 */
	public function isAppendOnly(): bool {
		return $this->appendOnly;
	}//end isAppendOnly()

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
	public function hasArchivalAnnotation(): bool {
		$configuration = ($this->getConfiguration() ?? []);

		return is_array($configuration['x-openregister-archival'] ?? null);
	}//end hasArchivalAnnotation()

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
	public function isArchivingEnabled(): bool {
		$configuration = ($this->getConfiguration() ?? []);
		$annotation = ($configuration[self::ARCHIVE_ANNOTATION] ?? null);

		if (is_array($annotation) === false) {
			return false;
		}

		return ($annotation['enabled'] ?? false) === true;
	}//end isArchivingEnabled()

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
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function notSuppliedReasons(): array {
		return (new NotSuppliedReasons())->reasons(configuration: $this->getConfiguration());
	}//end notSuppliedReasons()

	/**
	 * String representation of the schema
	 *
	 * This magic method is required for proper entity handling in Nextcloud
	 * when the framework needs to convert the object to a string.
	 *
	 * @return string String representation of the schema
	 */
	public function __toString(): string {
		// Return the schema slug if available, otherwise return a descriptive string.
		if ($this->slug !== null && $this->slug !== '') {
			return $this->slug;
		}

		// Fallback to title if slug is not available.
		if ($this->title !== null && $this->title !== '') {
			return $this->title;
		}

		// Final fallback with ID.
		return 'Schema #' . ($this->id ?? 'unknown');
	}//end __toString()

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
	 *
	 * @phpstan-return array<string, mixed>|null
	 * @psalm-return   array<string, mixed>|null
	 */
	public function getFacets(): ?array {
		if ($this->facets === null) {
			return null;
		}

		// If it's a JSON string, decode it.
		if (is_string($this->facets) === true) {
			$decoded = json_decode($this->facets, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				return $decoded;
			}

			return null;
		}

		// Otherwise, it's already an array.
		return $this->facets;
	}//end getFacets()

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
	public function setFacets(array|string|null $facets): void {
		// **DATABASE COMPATIBILITY**: Handle JSON string from database.
		if (is_string($facets) === true) {
			try {
				$this->facets = json_decode($facets, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					// Invalid JSON, set to null.
					$this->facets = null;
				}
			} catch (Exception $e) {
				$this->facets = null;
			}

			$this->markFieldUpdated(attribute: 'facets');

			return;
		}

		$this->facets = $facets;
		$this->markFieldUpdated(attribute: 'facets');
	}//end setFacets()

	/**
	 * Regenerate facets from current schema properties
	 *
	 * @deprecated This method is no longer needed since facets are now computed at runtime
	 *             from property-level `facetable: true` settings. The system automatically
	 *             reads facetable properties when processing facet requests.
	 *             This method is kept for backward compatibility only.
	 *
	 * @return void
	 */
	public function regenerateFacetsFromProperties(): void {
		$this->setFacets(
			facets: (new PropertyFacetBlock())->builtFor(
				properties: $this->getProperties(),
				stamp: new FacetBlockStamp(version: $this->getVersion(), generatedAt: time())
			)
		);
	}//end regenerateFacetsFromProperties()

	/**
	 * Get the array of schema references that this schema must validate against (allOf)
	 *
	 * The instance must validate against ALL schemas in the array.
	 * This implements JSON Schema 'allOf' for multiple inheritance/composition.
	 *
	 * @return array|null Array of schema IDs, UUIDs, or slugs
	 */
	public function getAllOf(): ?array {
		return $this->allOf;
	}//end getAllOf()

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
	public function setAllOf(?array $allOf): void {
		$this->allOf = $allOf;
		$this->markFieldUpdated(attribute: 'allOf');
	}//end setAllOf()

	/**
	 * Get the array of schema references where instance must validate against exactly one (oneOf)
	 *
	 * The instance must validate against EXACTLY ONE schema in the array.
	 * This implements JSON Schema 'oneOf' for mutually exclusive options.
	 *
	 * @return array|null Array of schema IDs, UUIDs, or slugs
	 */
	public function getOneOf(): ?array {
		return $this->oneOf;
	}//end getOneOf()

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
	public function setOneOf(?array $oneOf): void {
		$this->oneOf = $oneOf;
		$this->markFieldUpdated(attribute: 'oneOf');
	}//end setOneOf()

	/**
	 * Get the array of schema references where instance must validate against at least one (anyOf)
	 *
	 * The instance must validate against AT LEAST ONE schema in the array.
	 * This implements JSON Schema 'anyOf' for flexible composition.
	 *
	 * @return array|null Array of schema IDs, UUIDs, or slugs
	 */
	public function getAnyOf(): ?array {
		return $this->anyOf;
	}//end getAnyOf()

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
	public function setAnyOf(?array $anyOf): void {
		$this->anyOf = $anyOf;
		$this->markFieldUpdated(attribute: 'anyOf');
	}//end setAnyOf()

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
	public function isManagedByConfiguration(array $configurations): bool {
		if (empty($configurations) === true || $this->id === null) {
			return false;
		}

		foreach ($configurations as $configuration) {
			$schemas = $configuration->getSchemas();
			if (in_array($this->id, $schemas ?? [], true) === true) {
				return true;
			}
		}

		return false;
	}//end isManagedByConfiguration()

	/**
	 * Get the configuration that manages this schema
	 *
	 * Returns the first configuration that has this schema's ID in its schemas array.
	 * Returns null if the schema is not managed by any configuration.
	 *
	 * @param array<Configuration> $configurations Array of Configuration entities to check against
	 *
	 * @return Configuration|null The configuration managing this schema, or null
	 *
	 * @phpstan-param array<Configuration> $configurations
	 * @psalm-param   array<Configuration> $configurations
	 */
	public function getManagedByConfiguration(array $configurations): ?Configuration {
		if (empty($configurations) === true || $this->id === null) {
			return null;
		}

		foreach ($configurations as $configuration) {
			$schemas = $configuration->getSchemas();
			if (in_array($this->id, $schemas ?? [], true) === true) {
				return $configuration;
			}
		}

		return null;
	}//end getManagedByConfiguration()
}//end class
