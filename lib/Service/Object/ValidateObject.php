<?php

/**
 * OpenRegister ValidateObject Handler
 *
 * Handler class responsible for validating objects against their schemas.
 * This handler provides methods for:
 * - JSON Schema validation of objects
 * - Custom validation rule processing
 * - Schema resolution and caching
 * - Validation error handling and formatting
 * - Support for external schema references
 * - Format validation (e.g., BSN numbers)
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Handler
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
 */

namespace OCA\OpenRegister\Service\Object;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\ObjectHandling;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Formats\FormatFactory;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use Opis\Uri\Uri;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * Handler class for validating objects in the OpenRegister application.
 *
 * This handler is responsible for validating objects against their schemas,
 * including custom validation rules and error handling.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Objects
 * @author    Conduction b.v. <info@conduction.nl>
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/OpenCatalogi/OpenRegister
 * @version   GIT: <git_id>
 * @copyright 2024 Conduction b.v.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Validation requires comprehensive rule handling
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complex JSON Schema validation logic
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Validation requires multiple format and schema dependencies
 * @SuppressWarnings(PHPMD.TooManyMethods)           Validation requires per-type and per-format validator methods
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class ValidateObject implements ValidateObjectInterface {
	/**
	 * Default validation error message.
	 *
	 * @var string
	 */
	public const VALIDATION_ERROR_MESSAGE = 'Invalid object';

	/**
	 * What a related-object reference matches: a UUID (optionally prefixed, or without dashes) or a numeric id.
	 *
	 * @var string
	 */
	private const UUID_REFERENCE_PATTERN = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';

	/**
	 * OpenRegister keywords that are not JSON Schema and are removed before validation.
	 *
	 * @var array<int, string>
	 */
	private const METADATA_KEYWORDS = [
		'cascadeDelete',
		'objectConfiguration',
		'inversedBy',
		'mappedBy',
		'targetEntity',
		'fetch',
		'indexBy',
		'orphanRemoval',
		'joinColumns',
		'inverseJoinColumns',
		'joinTable',
		'uniqueConstraints',
		'indexes',
		'options',
		'computed',
		'calculation',
	];

	/**
	 * Custom OpenRegister types and the JSON Schema type they validate as. File references are
	 * integer file ids, data URIs or null; the others are stored as strings.
	 *
	 * @var array<string, string|array<int, string>>
	 */
	private const CUSTOM_TYPE_MAP = [
		'file' => ['integer', 'string', 'null'],
		'datetime' => 'string',
		'date' => 'string',
		'time' => 'string',
		'uuid' => 'string',
		'url' => 'string',
		'email' => 'string',
		'phone' => 'string',
		'color' => 'string',
		'recurrence' => 'string',
	];

	/**
	 * Request-scoped memoized validator instance.
	 *
	 * Building an Opis Validator and registering the custom formats and the
	 * http protocol resolver on every validateObject() call is pure overhead:
	 * the configuration is identical for every validation in a request. The
	 * shared instance also lets the Opis schema loader reuse `$ref` documents
	 * it already resolved (each of which costs a SchemaMapper::find round
	 * trip through resolveSchema()) instead of re-resolving them per call.
	 *
	 * @var Validator|null
	 */
	private ?Validator $validator = null;

	/**
	 * Account ids a caller declared it writes before the object, accepted by
	 * the `user` format for the length of one validateObjectWithPlannedUsers() call.
	 *
	 * @var array<int, string>
	 */
	private array $plannedUsers = [];

	/**
	 * Request-scoped cache of fully prepared validation schemas.
	 *
	 * Keyed by `{schemaId}:{schemaVersion}` (mirroring SchemaMapper's
	 * findCache pattern — a version bump produces a new key, so stale
	 * entries are never served). Each entry holds the schema object after
	 * the complete per-schema preparation pipeline (reference/circular-ref
	 * transformation, metadata cleaning, computed-property stripping from
	 * `required`, null-type widening) plus the derived computed-property
	 * and required-field lists, so validating N objects against the same
	 * schema runs the pipeline once instead of N times.
	 *
	 * @var array<string, array{schemaObject: object, computed: list<string>, required: array}>
	 */
	private array $preparedSchemaCache = [];

	/**
	 * Enforce JSON-Schema `readOnly: true` on the UPDATE write path.
	 *
	 * For each top-level property declared `readOnly: true` on the schema:
	 *  - If the incoming payload omits the property → OK (no violation).
	 *  - If the incoming payload includes the property AND its value differs
	 *    from the previously-stored value → reject with a structured error.
	 *  - If the value matches the previously-stored value → OK (no-op write).
	 *
	 * CREATE is intentionally NOT covered: there's no prior value to violate,
	 * and the canonical use of `readOnly` is "server-stamped on create,
	 * immutable afterwards." For CREATE-time immutability use `const` or a
	 * `default: …` with `defaultBehavior: 'always'` (both already enforced by
	 * SaveObject::setDefaultValues).
	 *
	 * Wave-12 Fix 1. Audited gap at `/tmp/wave11-or-engine-primitives.md`
	 * Section A: prior to this method, `readOnly: true` was pure metadata —
	 * `SchemaMapper.php:2303-2304` flagged it as a "freely overridable
	 * metadataField," with no write-path enforcement anywhere.
	 *
	 * Returns the list of violations, empty when the update is compliant. Callers
	 * MUST throw `ValidationException` (or return an equivalent 422 response) when
	 * this method returns a non-empty list.
	 *
	 * @param array $incomingObject The candidate object data (top-level keys
	 *                              are property names).
	 * @param array $existingObject The previously-stored object data (same
	 *                              shape). Pass the empty array `[]` to
	 *                              opt out (CREATE / no existing record).
	 * @param Schema $schema The schema whose `readOnly` declarations
	 *                       drive enforcement.
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 */
	public function validateReadOnlyConstraints(
		array $incomingObject,
		array $existingObject,
		Schema $schema,
	): array {
		if ($existingObject === []) {
			return [];
		}

		return (new WriteConstraintCheck(incoming: $incomingObject, stored: $existingObject))
			->readOnlyViolations(names: self::flaggedProperties(schema: $schema, keyword: 'readOnly'));
	}//end validateReadOnlyConstraints()

	/**
	 * Enforce `immutable: true` on the UPDATE write path.
	 *
	 * ⚠️ NOT a synonym for `readOnly`, and the difference is the whole point.
	 * `readOnly` refuses any value that differs from what is stored, including
	 * the FIRST one, so a property that was never filled in can never be filled
	 * in. `immutable` means "once set": while the stored value is absent or
	 * null the property is freely writable, and from the first real value
	 * onwards every change is refused.
	 *
	 * That is what a vastgesteld besluit and a final document actually ask for.
	 * The rule holds whatever the object's state and whoever the actor is, so
	 * it fires on an open object as readily as on a frozen one — which is why
	 * it lives on the property rather than on the state.
	 *
	 * Returns the list of violations, empty when the update is compliant.
	 * Callers MUST refuse the write when this returns a non-empty list.
	 *
	 * @param array $incomingObject The candidate object data.
	 * @param array $existingObject The previously-stored object data. Pass `[]`
	 *                              to opt out (CREATE / no existing record).
	 * @param Schema $schema The schema whose declarations drive enforcement.
	 *
	 * @return array<int, array{property: string, attempted: mixed, stored: mixed, message: string}>
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function validateImmutableConstraints(
		array $incomingObject,
		array $existingObject,
		Schema $schema,
	): array {
		if ($existingObject === []) {
			return [];
		}

		return (new WriteConstraintCheck(incoming: $incomingObject, stored: $existingObject))
			->immutableViolations(names: self::flaggedProperties(schema: $schema, keyword: Schema::IMMUTABLE_PROPERTY_KEYWORD));
	}//end validateImmutableConstraints()

	/**
	 * The names of the top-level properties that set a keyword to true.
	 *
	 * Nested declarations are deliberately ignored: the write constraints apply to top-level properties.
	 *
	 * @param Schema $schema  The schema.
	 * @param string $keyword The keyword.
	 *
	 * @return list<string> The property names.
	 */
	private static function flaggedProperties(Schema $schema, string $keyword): array {
		$flagged = [];
		foreach (self::declaredProperties(schema: $schema) as $name => $definition) {
			if (is_array($definition) === true && ($definition[$keyword] ?? false) === true) {
				$flagged[] = (string)$name;
			}
		}

		return $flagged;
	}//end flaggedProperties()

	/**
	 * The schema's property definitions, or an empty list when it declares none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array The property definitions.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	private static function declaredProperties(Schema $schema): array {
		$properties = $schema->getProperties();
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end validateImmutableConstraints()

	/**
	 * Configuration service.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $config;

	/**
	 * Object mapper.
	 *
	 * @var MagicObjectSearchInterface
	 */
	private MagicObjectSearchInterface $objectMapper;

	/**
	 * Schema mapper.
	 *
	 * @var SchemaMapperInterface
	 */
	private SchemaMapperInterface $schemaMapper;

	/**
	 * URL generator.
	 *
	 * @var IURLGenerator
	 */
	private IURLGenerator $urlGenerator;

	/**
	 * Logger for logging operations.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * Backend consulted by the `user` string format.
	 *
	 * @var IUserManager
	 */
	private IUserManager $userManager;

	/**
	 * Reads and applies the recorded-incompleteness record.
	 *
	 * Nullable with a null default so the unit tests that build this class
	 * without it keep working. The handler has no dependencies of its own, so
	 * the fallback in notSupplied() constructs the real thing rather than
	 * degrading to a no-op: there is no configuration that can make recorded
	 * incompleteness silently stop being enforced.
	 *
	 * @var NotSuppliedHandler|null
	 */
	private ?NotSuppliedHandler $notSuppliedHandler;

	/**
	 * Constructor for ValidateObject
	 *
	 * @param ValidationSchemaSource  $schemas            Loads the schemas and resolves their references.
	 * @param ValidationLookups       $lookups            Looks existing objects and users up.
	 * @param LoggerInterface         $logger             Logger for logging operations.
	 * @param NotSuppliedHandler|null $notSuppliedHandler Reads and applies the recorded-incompleteness record.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		ValidationSchemaSource $schemas,
		ValidationLookups $lookups,
		LoggerInterface $logger,
		?NotSuppliedHandler $notSuppliedHandler = null,
	) {
		$this->config = $schemas->config;
		$this->schemaMapper = $schemas->schemaMapper;
		$this->urlGenerator = $schemas->urlGenerator;
		$this->objectMapper = $lookups->objectMapper;
		$this->userManager = $lookups->userManager;
		$this->logger = $logger;
		$this->notSuppliedHandler = $notSuppliedHandler;
	}//end __construct()

	/**
	 * The not-supplied handler, constructed on first use.
	 *
	 * @return NotSuppliedHandler The handler.
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	private function notSupplied(): NotSuppliedHandler {
		if ($this->notSuppliedHandler === null) {
			$this->notSuppliedHandler = new NotSuppliedHandler();
		}

		return $this->notSuppliedHandler;
	}//end notSupplied()

	/**
	 * Transforms OpenRegister-specific object configurations before validation.
	 *
	 * This method handles the difference between:
	 * - Related objects: Should expect UUID strings, not full objects
	 * - Nested objects: Should expect full object structures
	 *
	 * This prevents circular reference issues and ensures proper validation
	 * according to OpenRegister's object handling logic.
	 *
	 * @param object $schemaObject The schema object to transform
	 *
	 * @return object The transformed schema object
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformOpenRegisterObjectConfigurations(object $schemaObject): object {
		if (isset($schemaObject->properties) === false) {
			return $schemaObject;
		}

		foreach ($schemaObject->properties as $propertyName => $propertySchema) {
			// Suppress unused variable warning for $propertyName - only processing schemas.
			unset($propertyName);
			$this->transformPropertyForOpenRegister(propertySchema: $propertySchema);
		}

		return $schemaObject;
	}//end transformOpenRegisterObjectConfigurations()

	/**
	 * Transforms a single property based on OpenRegister object configuration.
	 *
	 * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items property to configuration property
	 *
	 * @param object $propertySchema The property schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformPropertyForOpenRegister(object $propertySchema): void {
		// 🔴 First, drop any `$ref` that CANNOT be a JSON Schema reference.
		//
		// OpenRegister stores a `$ref` as a relation marker, and every branch
		// below already removes it before the schema reaches Opis — but only for
		// the shapes those branches recognise. A `$ref` that is an int, a null,
		// an array or an empty string is not one of them, survives, and makes
		// Opis throw `$ref must be a non-empty string` from
		// RefKeywordParser::parse().
		//
		// That exception fires LAZILY, when the offending property is present in
		// the written data, so it names neither the property nor the schema and
		// reads like a broken register rather than a stored schema defect. It is
		// also unrecoverable for the caller: no payload shape fixes a schema.
		//
		// Registers imported before openregister#2321 already carry exactly this
		// (an int `$ref` grafted onto an array property by ImportHandler), so
		// this normalisation is what heals them without a migration. A VALID
		// reference — a non-empty string — is untouched here and handled by the
		// branches below exactly as before.
		$this->dropUnusableRef(schema: $propertySchema);

		$this->applyInversedByRelation(propertySchema: $propertySchema);
		$this->transformArrayItemsForOpenRegister(propertySchema: $propertySchema);

		// Handle direct object properties.
		if (($propertySchema->type ?? null) !== null && $propertySchema->type === 'object') {
			$this->transformObjectPropertyForOpenRegister(objectSchema: $propertySchema);
		}

		$this->stripRelationRefs(propertySchema: $propertySchema);

		// Recursively transform nested properties.
		$this->transformNestedPropertiesForOpenRegister(schema: $propertySchema);
	}//end transformPropertyForOpenRegister()

	/**
	 * Let an inversedBy relation property accept a UUID or a nested object; pre-validation cascading transforms it.
	 *
	 * An array accepts either per item; an object also accepts null, and loses its own shape.
	 *
	 * TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
	 *
	 * @param object $propertySchema The property schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyInversedByRelation(object $propertySchema): void {
		if (($propertySchema->inversedBy ?? null) === null || $propertySchema->inversedBy === '') {
			return;
		}

		$type = ($propertySchema->type ?? null);
		if ($type === 'array') {
			// For inversedBy array properties, allow objects or UUIDs.
			$propertySchema->items = (object)['oneOf' => [self::uuidReference(), self::separatelyCreatedObject()]];
			return;
		}

		if ($type === 'object') {
			// For inversedBy object properties, allow objects, UUIDs, or null.
			$propertySchema->oneOf = $this->inversedByObjectAlternatives();
			unset($propertySchema->type, $propertySchema->pattern, $propertySchema->properties, $propertySchema->required, $propertySchema->{'$ref'});
		}
	}//end applyInversedByRelation()

	/**
	 * The schema of a UUID string that references a related object.
	 *
	 * @return object The schema.
	 */
	private static function uuidReference(): object {
		return (object)['type' => 'string', 'pattern' => self::UUID_REFERENCE_PATTERN, 'description' => 'UUID reference to a related object'];
	}//end uuidReference()

	/**
	 * The schema of a nested object the save creates on its own.
	 *
	 * @return object The schema.
	 */
	private static function separatelyCreatedObject(): object {
		return (object)['type' => 'object', 'description' => 'Nested object that will be created separately'];
	}//end separatelyCreatedObject()

	/**
	 * What an inversedBy object property accepts: null (managed by the other side), a UUID, or a nested object.
	 *
	 *
	 * @return array<int, object> The oneOf alternatives.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inversedByObjectAlternatives(): array {
		return [
			(object)['type' => 'null', 'description' => 'No related object (inversedBy - managed by other side)'],
			self::uuidReference(),
			self::separatelyCreatedObject(),
		];
	}//end inversedByObjectAlternatives()

	/**
	 * Rewrite the items of an array property: relation items become UUID strings, object items are transformed.
	 *
	 * @param object $propertySchema The property schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformArrayItemsForOpenRegister(object $propertySchema): void {
		if (($propertySchema->type ?? null) !== 'array' || ($propertySchema->items ?? null) === null) {
			return;
		}

		// `items` may decode as an associative array rather than a stdClass depending on the
		// schema source; normalise it to an object so the $ref-strip below runs. Without this,
		// an array-items $ref (e.g. `installedOn.items {"$ref":"Agent"}`) is left intact and
		// Opis JSON Schema fails with "Unresolved reference: schema:///Agent#" — unlike a scalar
		// string $ref, which is stripped by a separate branch that needs no object cast.
		if (is_array($propertySchema->items) === true) {
			$propertySchema->items = (object)$propertySchema->items;
		}

		if (is_object($propertySchema->items) === true) {
			$this->transformArrayItemSchema(itemsSchema: $propertySchema->items);
		}
	}//end transformArrayItemsForOpenRegister()

	/**
	 * Rewrite one array's item schema: an inversedBy or `$ref` item becomes a UUID string, an object item is transformed.
	 *
	 * @param object $itemsSchema The item schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformArrayItemSchema(object $itemsSchema): void {
		// Same normalisation as the property itself — see dropUnusableRef().
		$this->dropUnusableRef(schema: $itemsSchema);

		// TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
		if (($itemsSchema->inversedBy ?? null) !== null) {
			self::asUuidItem(itemsSchema: $itemsSchema, description: 'UUID reference to a related object (inversedBy - should be empty)');
			return;
		}

		// Array items that $ref another schema are relation references stored as UUID
		// strings (e.g. assignment.briefingMaterialIds, item-bank.itemIds declared as
		// items {"$ref":"<slug>","format":"uuid"}). OpenRegister uses the $ref only for
		// relation tracking; Opis JSON Schema would otherwise try to resolve the bare
		// slug as a URI and fail with "Unresolved reference: schema:///<slug>#". This
		// mirrors how single string $ref props and array self-references are handled.
		if (($itemsSchema->{'$ref'} ?? null) !== null) {
			self::asUuidItem(itemsSchema: $itemsSchema, description: 'UUID reference to a related object');
			return;
		}

		if (($itemsSchema->type ?? null) === 'object') {
			$this->transformObjectItem(itemsSchema: $itemsSchema);
		}
	}//end transformArrayItemSchema()

	/**
	 * Turn array items into opaque UUID strings for validation.
	 *
	 * @param object $itemsSchema The items schema.
	 * @param string $description What the UUID stands for.
	 *
	 * @return void
	 */
	private static function asUuidItem(object $itemsSchema, string $description): void {
		$itemsSchema->type        = 'string';
		$itemsSchema->pattern     = self::UUID_REFERENCE_PATTERN;
		$itemsSchema->description = $description;
		unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});
	}//end asUuidItem()

	/**
	 * Transform object items and the item object's own properties.
	 *
	 * Without the second step a `$ref` on a property of an object inside `items`
	 * (learniq ReportCard.subjectGrades[].curriculumPlanId, Lesson.blocks[],
	 * HourPlan.lines[]) reached Opis untouched, and every write that carried the
	 * array failed with `Unresolved reference: schema:///CurriculumPlan#`
	 * (openregister#2179). Each such property gets exactly the treatment a
	 * top-level property gets.
	 *
	 * @param object $itemsSchema The items schema.
	 *
	 * @return void
	 */
	private function transformObjectItem(object $itemsSchema): void {
		$this->transformObjectPropertyForOpenRegister(objectSchema: $itemsSchema);
		$this->transformNestedPropertiesForOpenRegister(schema: $itemsSchema);
	}//end transformObjectItem()

	/**
	 * Strip a relation `$ref`, at any depth and on any type, so Opis does not try to resolve it as a URI.
	 *
	 * OpenRegister uses `$ref` to record a relation (e.g. for onDelete), not for validation. A property that
	 * keeps one fails every write with `Unresolved reference: schema:///<slug>#`, which names neither the
	 * property nor the schema.
	 *
	 * @param object $propertySchema The property schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function stripRelationRefs(object $propertySchema): void {
		$reference = ($propertySchema->{'$ref'} ?? null);
		if ($reference === null) {
			return;
		}

		if (($propertySchema->type ?? null) === 'string' || self::isRelationMarker(reference: $reference) === true) {
			unset($propertySchema->{'$ref'});
		}
	}//end stripRelationRefs()

	/**
	 * Whether a `$ref` names a schema: a slug or `#/components/schemas/<slug>`, neither of which Opis can resolve.
	 *
	 * Any other fragment or URI could be a genuine JSON Schema reference and stays.
	 *
	 * @param mixed $reference The `$ref` value
	 *
	 * @return bool
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function isRelationMarker(mixed $reference): bool {
		if (is_string($reference) === false) {
			return false;
		}

		return str_starts_with($reference, '#/components/schemas/') === true
			|| (str_contains($reference, '#') === false && str_contains($reference, '/') === false);
	}//end isRelationMarker()

	/**
	 * Apply transformPropertyForOpenRegister() to every property of an object schema.
	 *
	 * Used for a nested object property and for the object inside an array's
	 * `items`, so a relation `$ref` at any depth is treated the way a top-level
	 * one is.
	 *
	 * @param object $schema The object (or items) schema whose properties to transform in place.
	 *
	 * @return void
	 */
	private function transformNestedPropertiesForOpenRegister(object $schema): void {
		// `properties` and each property may decode as associative arrays for some
		// schema sources; cast them and WRITE THE CAST BACK, or the transform runs
		// on a copy and the original keeps its `$ref`.
		if (is_array(($schema->properties ?? null)) === true) {
			$schema->properties = (object)$schema->properties;
		}

		if (is_object(($schema->properties ?? null)) === false) {
			return;
		}

		foreach (array_keys(get_object_vars($schema->properties)) as $name) {
			$this->transformNestedProperty(properties: $schema->properties, name: $name);
		}
	}//end transformNestedPropertiesForOpenRegister()

	/**
	 * Transform one nested property, writing an array shape back as an object.
	 *
	 * @param object     $properties The `properties` object.
	 * @param int|string $name       The property's name.
	 *
	 * @return void
	 */
	private function transformNestedProperty(object $properties, int|string $name): void {
		if (is_array($properties->{$name}) === true) {
			$properties->{$name} = (object)$properties->{$name};
		}

		if (is_object($properties->{$name}) === true) {
			$this->transformPropertyForOpenRegister(propertySchema: $properties->{$name});
		}
	}//end transformNestedProperty()

	/**
	 * Remove a `$ref` that cannot be a JSON Schema reference.
	 *
	 * A JSON Schema `$ref` MUST be a non-empty string; Opis enforces that in
	 * `RefKeywordParser::parse()` and throws `$ref must be a non-empty string`
	 * for anything else. OpenRegister only ever uses `$ref` as a relation
	 * marker, never for validation-time resolution, so removing an unusable one
	 * loses nothing and turns an opaque 500 back into a normal validation pass.
	 *
	 * Valid refs (non-empty strings) are deliberately left alone — the
	 * surrounding transform branches own those.
	 *
	 * @param object $schema The property or items schema to normalise in place.
	 *
	 * @return void
	 */
	private function dropUnusableRef(object $schema): void {
		if (property_exists($schema, '$ref') === false) {
			return;
		}

		$ref = $schema->{'$ref'};
		if (is_string($ref) === true && $ref !== '') {
			return;
		}

		unset($schema->{'$ref'});

	}//end dropUnusableRef()

	/**
	 * Transforms object properties based on OpenRegister object configuration.
	 *
	 * @param object $objectSchema The object schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformObjectPropertyForOpenRegister(object $objectSchema): void {
		$handling = $this->extractObjectConfigurationHandling(propertySchema: $objectSchema);

		// For related objects, expect UUID strings instead of full objects. Other handling leaves it as-is.
		if ($handling === 'related-schema' || $handling === 'related-object') {
			$this->transformToUuidProperty(objectSchema: $objectSchema);
		}
	}//end transformObjectPropertyForOpenRegister()

	/**
	 * Transforms an object property to expect UUID strings for related objects.
	 *
	 * @param object $objectSchema The object schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformToUuidProperty(object $objectSchema): void {
		// If this property has inversedBy, it should support both objects and UUID strings.
		if (($objectSchema->inversedBy ?? null) === null) {
			unset($objectSchema->properties, $objectSchema->required, $objectSchema->{'$ref'});
			$objectSchema->type        = 'string';
			$objectSchema->pattern     = self::UUID_REFERENCE_PATTERN;
			$objectSchema->description = 'UUID reference to a related object';
			return;
		}

		$objectAlternative = self::fullObjectAlternative(objectSchema: $objectSchema);

		// Clear the current object and set up the union type.
		$objectSchema->type = null;
		unset($objectSchema->properties, $objectSchema->required, $objectSchema->{'$ref'});
		$objectSchema->oneOf       = [$objectAlternative, self::uuidReference()];
		$objectSchema->description = 'Related object (can be full object or UUID reference)';
	}//end transformToUuidProperty()

	/**
	 * The full-object side of a related object's union, with the original structure.
	 *
	 * @param object $objectSchema The property as declared.
	 *
	 * @return object The object schema.
	 */
	private static function fullObjectAlternative(object $objectSchema): object {
		$alternative = (object)['type' => 'object'];
		foreach (['properties', 'required'] as $key) {
			if (empty($objectSchema->{$key}) === false) {
				$alternative->{$key} = $objectSchema->{$key};
			}
		}

		$ref = ($objectSchema->{'$ref'} ?? null);
		if ($ref !== null && $ref !== '') {
			$alternative->{'$ref'} = $ref;
		}

		return $alternative;
	}//end fullObjectAlternative()

	/**
	 * Extracts the objectConfiguration handling value from a property schema.
	 *
	 * Checks for objectConfiguration in multiple locations:
	 * - Directly on the property schema
	 * - Inside items (for array-like structures)
	 * - Inside items.oneOf (for polymorphic references)
	 *
	 * @param object $propertySchema The property schema to check
	 *
	 * @return string|null The handling value or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extractObjectConfigurationHandling(object $propertySchema): ?string {
		return ($this->handlingOf(holder: $propertySchema)
			?? $this->itemsHandling(propertySchema: $propertySchema)
			?? $this->extractHandlingFromOneOfItems(oneOf: ($propertySchema->oneOf ?? null)));
	}//end extractObjectConfigurationHandling()

	/**
	 * The handling in a schema's own objectConfiguration.
	 *
	 * @param mixed $holder An object or array that may carry an objectConfiguration.
	 *
	 * @return string|null The handling, if any.
	 */
	private function handlingOf(mixed $holder): ?string {
		return $this->getMixedValue(data: $this->getMixedValue(data: $holder, key: 'objectConfiguration'), key: 'handling');
	}//end handlingOf()

	/**
	 * The handling inside `items`: its own, then inside its oneOf for polymorphic references.
	 *
	 * Items can be either an object (stdClass) or an array depending on how the schema was processed.
	 *
	 * @param object $propertySchema The property schema.
	 *
	 * @return string|null The handling, if any.
	 */
	private function itemsHandling(object $propertySchema): ?string {
		if (isset($propertySchema->items) === false) {
			return null;
		}

		$items = $propertySchema->items;

		return ($this->handlingOf(holder: $items)
			?? $this->extractHandlingFromOneOfItems(oneOf: $this->getMixedValue(data: $items, key: 'oneOf')));
	}//end itemsHandling()

	/**
	 * Extracts the handling value from a oneOf array of schema items.
	 *
	 * Iterates through oneOf items looking for objectConfiguration with a handling value.
	 * Used to find handling in polymorphic schema references.
	 *
	 * @param mixed $oneOf The oneOf array or object to search (null-safe)
	 *
	 * @return string|null The handling value or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function extractHandlingFromOneOfItems($oneOf): ?string {
		if (self::isArrayOrObject(value: $oneOf) === false) {
			return null;
		}

		foreach ($oneOf as $oneOfItem) {
			$handling = $this->handlingOf(holder: $oneOfItem);
			if ($handling !== null) {
				return $handling;
			}
		}

		return null;
	}//end extractHandlingFromOneOfItems()

	/**
	 * Whether a value is an array or an object.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool True for an array or an object
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function isArrayOrObject(mixed $value): bool {
		return is_array($value) === true || is_object($value) === true;
	}//end isArrayOrObject()

	/**
	 * Gets a value from either an array or object by key.
	 *
	 * Consolidates array/object access into a single helper that works
	 * with both data formats used in schema configurations.
	 *
	 * @param mixed $data The data structure (array or object)
	 * @param string $key The key to retrieve
	 *
	 * @return mixed The value or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getMixedValue($data, string $key) {
		if (is_array($data) === true && isset($data[$key]) === true) {
			return $data[$key];
		}

		if (is_object($data) === true && isset($data->$key) === true) {
			return $data->$key;
		}

		return null;
	}//end getMixedValue()

	/**
	 * Transforms a schema for validation: self-references, OpenRegister object configurations and `$id`.
	 *
	 * 1. A property that references the current schema becomes a UUID reference (see transformSelfReference())
	 * 2. OpenRegister-specific object configurations are transformed
	 * 3. `$id` is removed, to prevent duplicate schema ID errors
	 *
	 * @param object $schemaObject      The schema object to transform
	 * @param string $currentSchemaSlug The current schema slug to detect self-references
	 *
	 * @return object The transformed schema
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformSchemaForValidation(object $schemaObject, string $currentSchemaSlug): object {
		if (isset($schemaObject->properties) === false) {
			return $schemaObject;
		}

		foreach ((array) $schemaObject->properties as $propertySchema) {
			if ($this->isSelfReference(propertySchema: $propertySchema, schemaSlug: $currentSchemaSlug) === true) {
				$this->transformSelfReference(propertySchema: $propertySchema, schemaSlug: $currentSchemaSlug);
			}
		}

		$schemaObject = $this->transformOpenRegisterObjectConfigurations(schemaObject: $schemaObject);

		if (($schemaObject->{'$id'} ?? null) !== null) {
			unset($schemaObject->{'$id'});
		}

		return $schemaObject;
	}//end transformSchemaForValidation()

	/**
	 * Rewrite a property that references the current schema, so validation does not recurse into it.
	 *
	 * A related-object or related-schema property becomes a UUID reference: with inversedBy it also
	 * accepts null or a nested object, and it loses its own shape. An array whose items reference the
	 * current schema becomes an array of UUID references. Either way the `$ref` is removed.
	 *
	 * @param object $propertySchema The self-referencing property schema, changed in place
	 * @param string $schemaSlug     The current schema slug
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) ObjectHandling::relates is a stateless enum-style helper
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformSelfReference(object $propertySchema, string $schemaSlug): void {
		$handling = $this->getMixedValue(data: ($propertySchema->objectConfiguration ?? null), key: 'handling');
		if (ObjectHandling::relates($handling) === true) {
			$this->transformSelfRelatedObject(propertySchema: $propertySchema);
		} elseif (($propertySchema->type ?? null) === 'array'
			&& is_object($propertySchema->items ?? null) === true
			&& $this->isSelfReference(propertySchema: $propertySchema->items, schemaSlug: $schemaSlug) === true
		) {
			$propertySchema->items = (object)[
				'type' => 'string',
				'pattern' => self::UUID_REFERENCE_PATTERN,
				'description' => 'UUID reference to a related object (self-reference)',
			];
		}

		unset($propertySchema->{'$ref'});
	}//end transformSelfReference()

	/**
	 * Turn a self-referencing related object into a UUID reference; with inversedBy, also null or a nested object.
	 *
	 * @param object $propertySchema The property schema, changed in place
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformSelfRelatedObject(object $propertySchema): void {
		unset($propertySchema->properties, $propertySchema->required);
		if (($propertySchema->inversedBy ?? null) !== null) {
			$propertySchema->oneOf = $this->inversedByObjectAlternatives();
			unset($propertySchema->type, $propertySchema->pattern);
			return;
		}

		$propertySchema->type = 'string';
		$propertySchema->pattern = self::UUID_REFERENCE_PATTERN;
		$propertySchema->description = 'UUID reference to a related object (self-reference)';
	}//end transformSelfRelatedObject()

	/**
	 * Cleans a schema object by removing all Nextcloud-specific metadata properties.
	 * This ensures the schema is valid JSON Schema before validation.
	 *
	 * @param object $schemaObject The schema object to clean
	 * @param bool $_isArrayItems Whether this is cleaning array items (more aggressive cleaning)
	 *
	 * @return object The cleaned schema object
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag needed to handle array items differently
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cleanSchemaForValidation(object $schemaObject, bool $_isArrayItems = false): object {
		$cleanedSchema = json_decode(json_encode($schemaObject));
		$this->stripMetadataKeywords(schema: $cleanedSchema);
		$this->cleanNestedSchemas(schema: $cleanedSchema);

		return $cleanedSchema;
	}//end cleanSchemaForValidation()

	/**
	 * Cleans a property schema by removing metadata and handling special cases.
	 *
	 * @param mixed $propertySchema The property schema to clean
	 * @param bool $isArrayItems Whether this is cleaning array items (more aggressive)
	 *
	 * @return mixed The cleaned property schema
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Boolean flag needed to handle array items differently
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cleanPropertyForValidation($propertySchema, bool $isArrayItems = false) {
		if (is_object($propertySchema) === false) {
			return $propertySchema;
		}

		$cleanedProperty = json_decode(json_encode($propertySchema));
		$this->stripMetadataKeywords(schema: $cleanedProperty);
		$cleanedProperty = $this->transformCustomTypeToJsonSchemaType(propertySchema: $cleanedProperty);
		if ($isArrayItems === true) {
			return $this->transformArrayItemsForValidation(itemsSchema: $cleanedProperty);
		}

		$this->cleanNestedSchemas(schema: $cleanedProperty);

		return $this->fixMisplacedArrayConstraints(propertySchema: $cleanedProperty);
	}//end cleanPropertyForValidation()

	/**
	 * Remove the OpenRegister metadata keywords that carry a value from a schema.
	 *
	 * @param object $schema The schema, changed in place.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function stripMetadataKeywords(object $schema): void {
		foreach (self::METADATA_KEYWORDS as $keyword) {
			if (($schema->$keyword ?? null) !== null) {
				unset($schema->$keyword);
			}
		}
	}//end stripMetadataKeywords()

	/**
	 * Clean the nested properties of a schema as properties and its items as array items.
	 *
	 * @param object $schema The schema, changed in place.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function cleanNestedSchemas(object $schema): void {
		if (($schema->properties ?? null) !== null) {
			foreach ($schema->properties as $propertyName => $propertySchema) {
				$schema->properties->$propertyName = $this->cleanPropertyForValidation(
					propertySchema: $propertySchema,
					isArrayItems: false
				);
			}
		}

		if (($schema->items ?? null) !== null) {
			$schema->items = $this->cleanPropertyForValidation(propertySchema: $schema->items, isArrayItems: true);
		}
	}//end cleanPropertyForValidation()

	/**
	 * Fixes misplaced enum and oneOf constraints on array-type properties.
	 *
	 * JSON Schema requires enum and oneOf to be on the items level for arrays,
	 * not on the array property itself. This method moves them to the correct location.
	 *
	 * @param object $propertySchema The property schema to fix
	 *
	 * @return object The property schema with constraints moved to items level
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function fixMisplacedArrayConstraints(object $propertySchema): object {
		if (($propertySchema->type ?? null) !== 'array') {
			return $propertySchema;
		}

		self::moveEnumIntoItems(propertySchema: $propertySchema);
		self::moveOneOfIntoItems(propertySchema: $propertySchema);

		return $propertySchema;
	}//end fixMisplacedArrayConstraints()

	/**
	 * Move a non-empty enum written on an array onto its items (items default to strings).
	 *
	 * @param object $propertySchema The array property schema (changed in place).
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function moveEnumIntoItems(object $propertySchema): void {
		$enum = ($propertySchema->enum ?? null);
		if (is_array($enum) === true && empty($enum) === false) {
			$propertySchema->items ??= (object) ['type' => 'string'];
			$propertySchema->items->enum ??= $enum;
			unset($propertySchema->enum);
		}
	}//end moveEnumIntoItems()

	/**
	 * Move a non-empty oneOf written on an array onto its items.
	 *
	 * @param object $propertySchema The array property schema (changed in place).
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function moveOneOfIntoItems(object $propertySchema): void {
		$oneOf = ($propertySchema->oneOf ?? null);
		if (self::isArrayOrObject(value: $oneOf) === true && empty((array) $oneOf) === false) {
			$propertySchema->items ??= new stdClass();
			$propertySchema->items->oneOf ??= $oneOf;
			unset($propertySchema->oneOf);
		}
	}//end moveOneOfIntoItems()

	/**
	 * Transforms custom OpenRegister types to valid JSON Schema types.
	 *
	 * JSON Schema only allows: string, number, integer, boolean, array, object, null.
	 * OpenRegister uses custom types like "file" which need to be converted.
	 *
	 * @param object $propertySchema The property schema to transform
	 *
	 * @return object The transformed property schema with valid JSON Schema types
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformCustomTypeToJsonSchemaType(object $propertySchema): object {
		$type = ($propertySchema->type ?? null);
		if (is_string($type) === true && isset(self::CUSTOM_TYPE_MAP[$type]) === true) {
			$propertySchema->type = self::CUSTOM_TYPE_MAP[$type];
		}

		if (is_array($type) === true) {
			$propertySchema->type = array_map(static fn ($t) => self::CUSTOM_TYPE_MAP[$t] ?? $t, $type);
		}

		return $propertySchema;
	}//end transformCustomTypeToJsonSchemaType()

	/**
	 * Transforms array items for validation by converting object items to appropriate types.
	 *
	 * @param object $itemsSchema The array items schema to transform
	 *
	 * @return object The transformed items schema
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformArrayItemsForValidation(object $itemsSchema): object {

		// If items don't have a type or aren't objects, return as-is.
		if (isset($itemsSchema->type) === false || $itemsSchema->type !== 'object') {
			return $itemsSchema;
		}

		$handling = self::objectConfigurationHandling(itemsSchema: $itemsSchema);
		$hasRef = (($itemsSchema->{'$ref'} ?? null) !== null);

		// Relation markers are the only signal that these items describe a reference to
		// another object rather than an inline value object: explicit objectConfiguration
		// handling, or a $ref pointing at another schema.
		// Inline value-object arrays (e.g. [{register, label}]) declare their own properties
		// and carry no relation markers at all. They are not references to other objects, so
		// they must be left untouched - replacing their properties with a bare {id} stub while
		// an authored `additionalProperties: false` survives the clean would reject every one
		// of the schema's own sub-properties. See or#290.
		if (self::isInlineValueObject(handling: $handling, hasRef: $hasRef, itemsSchema: $itemsSchema) === true) {
			return $itemsSchema;
		}

		if (self::usesUuidStrings(handling: $handling, hasRef: $hasRef) === true) {
			return self::asRelationReferenceItems(itemsSchema: $itemsSchema);
		}

		return self::asNestedObjectItems(itemsSchema: $itemsSchema);
	}//end transformArrayItemsForValidation()

	/**
	 * Whether object items validate as UUID references rather than as simple objects.
	 *
	 * UUID strings: related-object handling, $ref references, or unknown handling types.
	 * Simple object: nested-object handling, or no configuration and no $ref.
	 *
	 * @param mixed $handling The objectConfiguration handling, or null.
	 * @param bool  $hasRef   Whether the items carry a $ref.
	 *
	 * @return bool True for UUID references
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function usesUuidStrings(mixed $handling, bool $hasRef): bool {
		if ($handling !== null) {
			return $handling !== 'nested-object';
		}

		return $hasRef;
	}//end usesUuidStrings()

	/**
	 * Whether object items are an inline value object: own properties and no relation marker.
	 *
	 * @param mixed  $handling    The objectConfiguration handling, or null.
	 * @param bool   $hasRef      Whether the items carry a $ref.
	 * @param object $itemsSchema The items schema.
	 *
	 * @return bool True for an inline value object
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function isInlineValueObject(mixed $handling, bool $hasRef, object $itemsSchema): bool {
		return $handling === null && $hasRef === false && isset($itemsSchema->properties) === true;
	}//end isInlineValueObject()

	/**
	 * Turn array items into a simple nested object with an `id`, without its `$ref` so it cannot recurse.
	 *
	 * @param object $itemsSchema The array items schema.
	 *
	 * @return object The transformed items schema.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function asNestedObjectItems(object $itemsSchema): object {
		unset($itemsSchema->{'$ref'});
		$itemsSchema->type        = 'object';
		$itemsSchema->description = 'Nested object';
		$itemsSchema->properties  = (object) [
			'id' => (object) ['type' => 'string', 'description' => 'Object identifier'],
		];

		return $itemsSchema;
	}//end asNestedObjectItems()

	/**
	 * Turn array items into a reference to a related object: a UUID string or an object with an `id`.
	 *
	 * @param object $itemsSchema The array items schema.
	 *
	 * @return object The transformed items schema.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function asRelationReferenceItems(object $itemsSchema): object {
		// Transform to accept both UUID strings and objects with id field.
		// Remove all object-specific properties.
		unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});

		// UUID pattern for string validation.
		$uuidPattern = self::UUID_REFERENCE_PATTERN;

		// Accept either a UUID string or an object with an id field.
		// This allows flexibility in how related objects are submitted.
		unset($itemsSchema->type);
		$itemsSchema->oneOf = [
			(object)[
				'type' => 'string',
				'pattern' => $uuidPattern,
				'description' => 'UUID reference to a related object',
			],
			(object)[
				'type' => 'object',
				'description' => 'Object with id field referencing a related object',
				'properties' => (object)[
					'id' => (object)[
						'type' => 'string',
						'pattern' => $uuidPattern,
					],
				],
				'required' => ['id'],
				'additionalProperties' => true,
			],
		];
		$itemsSchema->description = 'UUID reference or object with id field';
		return $itemsSchema;
	}//end asRelationReferenceItems()

	/**
	 * The `handling` of an items schema's objectConfiguration, given as an array or an object.
	 *
	 * @param object $itemsSchema The array items schema.
	 *
	 * @return mixed The handling, or null when none is configured.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function objectConfigurationHandling(object $itemsSchema): mixed {
		$config = $itemsSchema->objectConfiguration ?? null;
		if (is_array($config) === true && isset($config['handling']) === true) {
			return $config['handling'];
		}

		if (is_object($config) === true && isset($config->handling) === true) {
			return $config->handling;
		}

		return null;
	}//end objectConfigurationHandling()

	/**
	 * Checks if a property schema is a self-reference to the given schema slug.
	 *
	 * @param object $propertySchema The property schema to check
	 * @param string $schemaSlug The schema slug to check against
	 *
	 * @return bool True if this is a self-reference
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function isSelfReference(object $propertySchema, string $schemaSlug): bool {
		// Check for $ref in the property.
		if (($propertySchema->{'$ref'} ?? null) === null) {
			return false;
		}

		// Handle both string and object formats for $ref.
		$refId = self::refIdOf(ref: $propertySchema->{'$ref'});

		// Extract schema slug from reference path.
		if (is_string($refId) === true && str_contains($refId, '#/components/schemas/') === true) {
			// Remove query parameters if present.
			$cleanRefId = $this->removeQueryParameters(reference: $refId);
			$referencedSlug = substr($cleanRefId, strrpos($cleanRefId, '/') + 1);
			return $referencedSlug === $schemaSlug;
		}

		return false;
	}//end isSelfReference()

	/**
	 * The id of a $ref: its own value, or the `id` of an object or array form.
	 *
	 * @param mixed $ref The $ref value.
	 *
	 * @return mixed The reference id
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	private static function refIdOf(mixed $ref): mixed {
		if (is_object($ref) === true && (($ref->id ?? null) !== null)) {
			return $ref->id;
		}

		if (is_array($ref) === true && (($ref['id'] ?? null) !== null)) {
			return $ref['id'];
		}

		return $ref;
	}//end refIdOf()

	/**
	 * Validates an object against a schema, letting a `user` format accept the given account ids.
	 *
	 * For a caller that writes those accounts before the object, such as an
	 * example set's check. Every other account id is still asked of the user
	 * manager, and the ids are accepted for this one call only.
	 *
	 * @param array                     $object       The object to validate.
	 * @param Schema|int|null           $schema       The schema or schema ID to validate against.
	 * @param object                    $schemaObject A custom schema object for validation.
	 * @param array<int, string>        $plannedUsers Account ids to accept although they do not exist yet.
	 *
	 * @return ValidationResult The result of the validation.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-check-validates-every-object-against-its-real-schema-without-writing-req-exs-004
	 */
	public function validateObjectWithPlannedUsers(
		array $object,
		Schema|int|null $schema = null,
		object $schemaObject = new stdClass(),
		array $plannedUsers = [],
	): ValidationResult {
		$this->plannedUsers = array_values(array_filter($plannedUsers, 'is_string'));
		try {
			return $this->validateObject(object: $object, schema: $schema, schemaObject: $schemaObject);
		} finally {
			$this->plannedUsers = [];
		}
	}//end validateObjectWithPlannedUsers()

	/**
	 * Validates an object against a schema.
	 *
	 * @param array $object The object to validate.
	 * @param Schema|int|null $schema The schema or schema ID to validate against.
	 * @param object $schemaObject A custom schema object for validation.
	 * @param int $_depth The depth level for validation (unused).
	 * @param array<string, string> $notSupplied Properties recorded as not supplied, mapped to their reason codes.
	 *
	 * @return ValidationResult The result of the validation.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function validateObject(
		array $object,
		Schema|int|string|null $schema = null,
		object $schemaObject = new stdClass(),
		int $_depth = 0,
		array $notSupplied = [],
	): ValidationResult {
		$schema = $this->schemaEntity(schema: $schema);
		$target = $this->validationTarget(schema: $schema, given: ['schemaObject' => $schemaObject, 'notSupplied' => $notSupplied]);

		$this->checkFieldRules(object: $object, target: ['schema' => $schema, 'schemaObject' => $target['schemaObject']]);

		// The per-schema preparation pipeline (transform, clean, computed strip,
		// null-type widening) is memoized per schemaId:version, so bulk
		// validation of N objects against one schema prepares it once.
		return $this->validatePrepared(
			object: $object,
			prepared: $this->preparedSchema(schemaObject: $target['schemaObject'], target: ['schema' => $schema, 'cacheKey' => $target['cacheKey']])
		);
	}//end validateObject()

	/**
	 * Resolve a schema id to its entity once, so unique-field validation and prepared-schema caching work with the entity.
	 *
	 * SchemaMapper::find() memoizes lookups in its own findCache.
	 *
	 * @param Schema|int|string|null $schema The schema or its id.
	 *
	 * @return Schema|null The entity.
	 */
	private function schemaEntity(Schema|int|string|null $schema): ?Schema {
		if ($schema === null || $schema instanceof Schema) {
			return $schema;
		}

		return $this->schemaMapper->find($schema);
	}//end schemaEntity()

	/**
	 * The checks Opis does not make: unique fields and the extended field types.
	 *
	 * Extended field types (color, recurrence) are read from the original,
	 * un-transformed schema so the declared `type`/`format` annotations are intact.
	 *
	 * @param array                                         $object The object data.
	 * @param array{schema: ?Schema, schemaObject: object} $target What it is validated against.
	 *
	 * @return void
	 *
	 * @throws CustomValidationException When a rule is broken.
	 */
	private function checkFieldRules(array $object, array $target): void {
		if ($target['schema'] instanceof Schema) {
			$this->validateUniqueFields(object: $object, schema: $target['schema']);
		}

		(new ExtendedFieldCheck(object: $object))->assertValid(schemaObject: $target['schemaObject']);
	}//end checkFieldRules()

	/**
	 * Validate the object against a prepared schema.
	 *
	 * @param array                                                        $object   The object data.
	 * @param array{schemaObject: object, computed: array, required: array} $prepared The prepared schema.
	 *
	 * @return ValidationResult The result.
	 */
	private function validatePrepared(array $object, array $prepared): ValidationResult {
		$schemaObject = $prepared['schemaObject'];

		// Without properties there is nothing to validate: validate against an
		// empty schema object to get a valid ValidationResult.
		if (isset($schemaObject->properties) === false || empty($schemaObject->properties) === true) {
			return $this->getValidator()->validate(json_decode(json_encode($object)), new stdClass());
		}

		$values = $this->significantValues(object: $object, prepared: $prepared);

		return $this->getValidator()->validate(json_decode(json_encode($values)), $schemaObject);
	}//end validatePrepared()

	/**
	 * The object data that goes to the validator.
	 *
	 * Computed fields are system-generated and are not validated against user input.
	 * Only truly empty values without validation significance are removed: empty
	 * strings stay for required fields so they fail with a proper error message.
	 *
	 * @param array                                                        $object   The object data.
	 * @param array{schemaObject: object, computed: array, required: array} $prepared The prepared schema.
	 *
	 * @return array The values to validate.
	 */
	private function significantValues(array $object, array $prepared): array {
		// @todo This should be done earlier.
		unset($object['extend'], $object['filters']);

		foreach ($prepared['computed'] as $computedProperty) {
			unset($object[$computedProperty]);
		}

		$context = ['required' => $prepared['required'], 'schemaObject' => $prepared['schemaObject']];

		return array_filter(
			$object,
			fn ($value, $key): bool => $this->isSignificantForValidation(entry: ['key' => $key, 'value' => $value], context: $context),
			ARRAY_FILTER_USE_BOTH
		);
	}//end significantValues()

	/**
	 * The schema object to validate against, and the key its preparation is cached under.
	 *
	 * Without a caller-supplied schema object the schema entity's own is used,
	 * and cached per schemaId:version. Properties recorded as not supplied are
	 * excused per object, so such a call is never cached: a cached schema object
	 * with one object's excusals baked in would excuse them for every other
	 * object validated against that schema in the same request.
	 *
	 * @param Schema|null                                                    $schema The schema entity, if any.
	 * @param array{schemaObject: object, notSupplied: array<string, string>} $given  The caller's schema object and excusals.
	 *
	 * @return array{schemaObject: object, cacheKey: string|null}
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	private function validationTarget(?Schema $schema, array $given): array {
		$schemaObject = $given['schemaObject'];
		if ($schema === null) {
			return ['schemaObject' => $schemaObject, 'cacheKey' => null];
		}

		// Use == because === will never be true when comparing stdClass-instances.
		// Phpcs:ignore Squiz.Operators.ComparisonOperatorUsage.NotAllowed
		$useDefaultSchema = ($schemaObject == new stdClass());
		if ($useDefaultSchema === true) {
			$schemaObject = $schema->getSchemaObject($this->urlGenerator);
		}

		if ($given['notSupplied'] !== []) {
			return ['schemaObject' => $this->notSupplied()->excuse(schemaObject: $schemaObject, declared: $given['notSupplied']), 'cacheKey' => null];
		}

		return ['schemaObject' => $schemaObject, 'cacheKey' => self::preparedCacheKey(schema: $schema, cacheable: $useDefaultSchema)];
	}//end validationTarget()

	/**
	 * The key a prepared schema is cached under; only the schema's own object is cached.
	 *
	 * @param Schema $schema    The schema.
	 * @param bool   $cacheable Whether the validation uses the schema's own object.
	 *
	 * @return string|null `{schemaId}:{schemaVersion}`, or null.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag says which object is validated against.
	 */
	private static function preparedCacheKey(Schema $schema, bool $cacheable): ?string {
		if ($cacheable === false) {
			return null;
		}

		return ((string)$schema->getId()).':'.((string)$schema->getVersion());
	}//end preparedCacheKey()

	/**
	 * The prepared schema for validation, memoized per schemaId:version.
	 *
	 * A null cache key (a caller-supplied schema object, or excused properties)
	 * prepares afresh and caches nothing.
	 *
	 * @param object                                         $schemaObject The schema object to prepare.
	 * @param array{schema: Schema|null, cacheKey: string|null} $target       The schema entity and the cache key.
	 *
	 * @return array{schemaObject: object, computed: array, required: array} The prepared schema.
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	private function preparedSchema(object $schemaObject, array $target): array {
		$cacheKey = $target['cacheKey'];
		if ($cacheKey !== null && isset($this->preparedSchemaCache[$cacheKey]) === true) {
			return $this->preparedSchemaCache[$cacheKey];
		}

		$prepared = $this->prepareSchemaForValidation(schemaObject: $schemaObject, schema: $target['schema']);
		if ($cacheKey !== null) {
			$this->preparedSchemaCache[$cacheKey] = $prepared;
		}

		return $prepared;
	}//end preparedSchema()

	/**
	 * Whether a value is kept for validation.
	 *
	 * Required fields are always kept, so an empty one fails. Otherwise a null
	 * is dropped from an enum that does not list null, an empty array is
	 * dropped unless the property constrains its items, and an empty string is
	 * dropped. Everything else (null, 0, false) is kept.
	 *
	 * @param array{key: string|int, value: mixed}             $entry   The property and its value.
	 * @param array{required: array, schemaObject: object} $context The required fields and the prepared schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	private function isSignificantForValidation(array $entry, array $context): bool {
		if (in_array($entry['key'], $context['required']) === true) {
			return true;
		}

		$value          = $entry['value'];
		$propertySchema = ($context['schemaObject']->properties->{$entry['key']} ?? null);

		// Strict: loosely, null == "", so an enum listing "" (stored as null)
		// would read as allowing null, keep the null, and fail the string type.
		if ($value === null && is_array($propertySchema->enum ?? null) === true) {
			return in_array(null, $propertySchema->enum, true) === true;
		}

		if ($value === []) {
			return $this->constrainsItems(propertySchema: $propertySchema);
		}

		return $value !== '';
	}//end isSignificantForValidation()

	/**
	 * Whether a property schema puts minItems above 0, maxItems, or uniqueItems on its array.
	 *
	 * @param object|null $propertySchema The property schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/object-validation/spec.md
	 */
	private function constrainsItems(?object $propertySchema): bool {
		return ($propertySchema->minItems ?? 0) > 0
			|| isset($propertySchema->maxItems) === true
			|| ($propertySchema->uniqueItems ?? false) === true;
	}//end constrainsItems()

	/**
	 * Run the complete per-schema preparation pipeline for validation.
	 *
	 * Applies, in order: circular-reference/OpenRegister-config transformation,
	 * Nextcloud metadata cleaning, empty-`required` removal, computed-property
	 * stripping from `required`, and null-type widening for non-required
	 * fields. The output depends only on the schema (never on the object being
	 * validated), which is what makes it cacheable per schemaId:version.
	 *
	 * Returns the prepared schema object plus the derived computed-property and
	 * required-field lists.
	 *
	 * @param object $schemaObject The raw schema object to prepare
	 * @param Schema|null $schema The schema entity (for slug-based circular reference detection)
	 *
	 * @return array{schemaObject: object, computed: list<string>, required: array}
	 */
	private function prepareSchemaForValidation(object $schemaObject, ?Schema $schema): array {
		// Transform schema for validation (handles circular references, OpenRegister configs, and schema resolution).
		$schemaObject = $this->transformSchemaForValidation(schemaObject: $schemaObject, currentSchemaSlug: self::slugOf(schema: $schema));

		// Collect computed property names BEFORE cleaning: the cleaning step
		// strips the per-property `computed` marker.
		$computedProperties = $this->collectComputedProperties(schemaObject: $schemaObject);

		// Clean the schema by removing all Nextcloud-specific metadata properties.
		$schemaObject = $this->cleanSchemaForValidation(schemaObject: $schemaObject);

		// A nested property marked `nullable: true` accepts null, as an
		// optional top-level property already does (widened further down).
		$this->allowNullForNestedNullable(schemaObject: $schemaObject);

		$requiredFields = $this->settledRequired(schemaObject: $schemaObject, computed: $computedProperties);
		$this->allowNullForOptionalProperties(schemaObject: $schemaObject, requiredFields: $requiredFields);
		$this->acceptTranslationMaps(schemaObject: $schemaObject);

		return ['schemaObject' => $schemaObject, 'computed' => $computedProperties, 'required' => $requiredFields];
	}//end prepareSchemaForValidation()

	/**
	 * The slug for circular reference detection; an entity without a slug is a TypeError.
	 *
	 * @param Schema|null $schema The schema.
	 *
	 * @return string The slug, empty without a schema.
	 */
	private static function slugOf(?Schema $schema): string {
		if ($schema === null) {
			return '';
		}

		return $schema->getSlug();
	}//end slugOf()

	/**
	 * The required list without computed properties; an empty list is dropped from the schema.
	 *
	 * @param object       $schemaObject The cleaned schema.
	 * @param list<string> $computed     The computed property names.
	 *
	 * @return mixed The required fields.
	 */
	private function settledRequired(object $schemaObject, array $computed): mixed {
		if (($schemaObject->required ?? null) !== null && empty($schemaObject->required) === true) {
			unset($schemaObject->required);
		}

		$this->dropComputedFromRequired(schemaObject: $schemaObject, computed: $computed);

		return ($schemaObject->required ?? []);
	}//end settledRequired()

	/**
	 * The names of the properties the system derives: a Twig `computed` marker or a JSON-AST `calculation`.
	 *
	 * A value the client sent for either is dropped before validation, and neither can be required from user input.
	 *
	 * @param object $schemaObject The schema object, before cleaning
	 *
	 * @return list<int|string> The computed property names
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function collectComputedProperties(object $schemaObject): array {
		$computedProperties = [];
		foreach (($schemaObject->properties ?? []) as $propName => $propSchema) {
			if (is_object($propSchema) === false) {
				continue;
			}

			if (($propSchema->computed ?? null) !== null || ($propSchema->calculation ?? null) !== null) {
				$computedProperties[] = $propName;
			}
		}

		return $computedProperties;
	}//end collectComputedProperties()

	/**
	 * Strip computed properties from the required list: they are system-generated.
	 *
	 * @param object $schemaObject The cleaned schema object, changed in place
	 * @param array  $computed     The computed property names
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function dropComputedFromRequired(object $schemaObject, array $computed): void {
		if (is_array($schemaObject->required ?? null) === false) {
			return;
		}

		foreach ($computed as $propName) {
			$reqKey = array_search($propName, $schemaObject->required, true);
			if ($reqKey !== false) {
				unset($schemaObject->required[$reqKey]);
				$schemaObject->required = array_values($schemaObject->required);
			}
		}
	}//end dropComputedFromRequired()

	/**
	 * Let every optional top-level property accept null, except an enum that does not list null.
	 *
	 * An enum field is either set to one of its values or omitted. A required field accepts null
	 * only when its schema says so.
	 *
	 * @param object $schemaObject   The cleaned schema object, changed in place
	 * @param mixed  $requiredFields The required property names
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function allowNullForOptionalProperties(object $schemaObject, mixed $requiredFields): void {
		if (is_object($schemaObject->properties ?? null) === false) {
			return;
		}

		foreach ($schemaObject->properties as $propertyName => $propertySchema) {
			if (in_array($propertyName, $requiredFields) === true || $this->enumExcludesNull(propertySchema: $propertySchema) === true) {
				continue;
			}

			$this->addNullType(propertySchema: $propertySchema);
		}
	}//end allowNullForOptionalProperties()

	/**
	 * Whether a property is an enum that does not list null.
	 *
	 * @param mixed $propertySchema The property schema
	 *
	 * @return bool True when the property has an enum without null
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function enumExcludesNull(mixed $propertySchema): bool {
		$enum = ($propertySchema->enum ?? null);
		return is_array($enum) === true && in_array(null, $enum, true) === false;
	}//end enumExcludesNull()

	/**
	 * Add `null` to a property's type, whether it is a single type or a list.
	 *
	 * @param mixed $propertySchema The property schema, changed in place
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function addNullType(mixed $propertySchema): void {
		$type = ($propertySchema->type ?? null);
		if (is_string($type) === true) {
			$propertySchema->type = [$type, 'null'];
			return;
		}

		if (is_array($type) === true && in_array('null', $type, true) === false) {
			$propertySchema->type[] = 'null';
		}
	}//end addNullType()

	/**
	 * Let a translatable property accept its scalar value or a language-keyed object of such values.
	 *
	 * A language-keyed write body like {"nl": "Welkom", "en": "Welcome"} must pass validation before
	 * TranslationHandler::normalizeTranslationsForSave() splits it into per-language translation rows.
	 * Every language value is still checked against the scalar schema, through additionalProperties.
	 *
	 * @param object $schemaObject The cleaned schema object, changed in place
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function acceptTranslationMaps(object $schemaObject): void {
		if (is_object($schemaObject->properties ?? null) === false) {
			return;
		}

		foreach ($schemaObject->properties as $propertyName => $propertySchema) {
			if (is_object($propertySchema) === true && ($propertySchema->translatable ?? null) === true) {
				$schemaObject->properties->$propertyName = self::translationUnion(propertySchema: $propertySchema);
			}
		}
	}//end acceptTranslationMaps()

	/**
	 * A union of the scalar value and a map of those values by language.
	 *
	 * @param object $propertySchema The translatable property.
	 *
	 * @return object The union: the property minus the custom marker, or an object of them.
	 */
	private static function translationUnion(object $propertySchema): object {
		$valueSchema = json_decode(json_encode($propertySchema));
		unset($valueSchema->translatable);

		$map = (object)['type' => 'object', 'additionalProperties' => json_decode(json_encode($valueSchema))];

		return (object)['anyOf' => [$valueSchema, $map]];
	}//end translationUnion()

	/**
	 * Let every NESTED property marked `nullable: true` accept `null`.
	 *
	 * The top level widens a non-required property with `"null"` in
	 * prepareSchemaForValidation(). Nothing did the same one level down, so a
	 * property inside an object or inside an array's `items` that the schema
	 * declares `nullable: true` refused `null`, and every write carrying one
	 * failed (learniq LearnerProfile.beeldmateriaalConsent, whose purposes are
	 * null while consent is undecided; ReportCard.subjectGrades[].courseId).
	 * Opis implements JSON Schema, where `nullable` is not a keyword, so the
	 * marker has to become a type the validator reads.
	 *
	 * The top-level properties themselves are left to the existing widening:
	 * only what sits beneath them is walked, at any depth.
	 *
	 * @param object $schemaObject The cleaned schema object, changed in place.
	 *
	 * @return void
	 *
	 * @spec exclude bug fix: nested nullable parity with the top-level null widening
	 */
	private function allowNullForNestedNullable(object $schemaObject): void {
		if (is_object($schemaObject->properties ?? null) === false) {
			return;
		}

		foreach (get_object_vars($schemaObject->properties) as $propertySchema) {
			if (is_object($propertySchema) === true) {
				$this->allowNullBeneath(schema: $propertySchema);
			}
		}
	}//end allowNullForNestedNullable()

	/**
	 * Widen every nullable schema below the given one, recursively.
	 *
	 * Walks `properties`, `items` (a single schema or a tuple), and the
	 * `oneOf` / `anyOf` / `allOf` branches.
	 *
	 * @param object $schema The schema whose descendants to widen in place.
	 *
	 * @return void
	 *
	 * @spec exclude bug fix: nested nullable parity with the top-level null widening
	 */
	private function allowNullBeneath(object $schema): void {
		foreach (self::childSchemas(schema: $schema) as $child) {
			if (is_object($child) === false) {
				continue;
			}

			if (($child->nullable ?? null) === true) {
				$this->widenToAcceptNull(schema: $child);
			}

			$this->allowNullBeneath(schema: $child);
		}
	}//end allowNullBeneath()

	/**
	 * The schemas directly beneath a schema: its properties, items and union branches.
	 *
	 * @param object $schema The schema.
	 *
	 * @return array<int|string, mixed> The children.
	 */
	private static function childSchemas(object $schema): array {
		$children = [];
		if (is_object($schema->properties ?? null) === true) {
			$children = array_values(get_object_vars($schema->properties));
		}

		$items = ($schema->items ?? null);
		if (is_object($items) === true) {
			$items = [$items];
		}

		$lists = [$items, ($schema->oneOf ?? null), ($schema->anyOf ?? null), ($schema->allOf ?? null)];

		return array_merge($children, ...array_filter($lists, 'is_array'));
	}//end childSchemas()

	/**
	 * Make one schema accept `null` alongside what it already accepts.
	 *
	 * @param object $schema The schema to widen in place.
	 *
	 * @return void
	 *
	 * @spec exclude bug fix: nested nullable parity with the top-level null widening
	 */
	private function widenToAcceptNull(object $schema): void {
		$type = ($schema->type ?? null);
		if ($type !== 'null') {
			$this->addNullType(propertySchema: $schema);
		}

		// An enum is checked on its own, so null has to be one of its values.
		if ($this->enumExcludesNull(propertySchema: $schema) === true) {
			$schema->enum[] = null;
		}

		if ($type === null) {
			self::addNullBranch(schema: $schema);
		}
	}//end widenToAcceptNull()

	/**
	 * Give a typeless union (a related object rewritten to oneOf) a null branch.
	 *
	 * Unless it has one: a second would make a oneOf match twice.
	 *
	 * @param object $schema The schema.
	 *
	 * @return void
	 */
	private static function addNullBranch(object $schema): void {
		$keyword = 'anyOf';
		if (is_array($schema->oneOf ?? null) === true) {
			$keyword = 'oneOf';
		}

		if (is_array($schema->{$keyword} ?? null) === false) {
			return;
		}

		if (self::hasNullBranch(branches: $schema->{$keyword}) === false) {
			$schema->{$keyword}[] = (object)['type' => 'null'];
		}
	}//end addNullBranch()

	/**
	 * Whether a union already accepts null.
	 *
	 * @param array $branches The union's branches.
	 *
	 * @return bool True when one branch is the null type.
	 */
	private static function hasNullBranch(array $branches): bool {
		foreach ($branches as $branch) {
			if (is_object($branch) === true && ($branch->type ?? null) === 'null') {
				return true;
			}
		}

		return false;
	}//end hasNullBranch()

	/**
	 * Get the request-scoped memoized validator.
	 *
	 * Builds the Opis Validator once per service instance: max-error limit,
	 * custom format validators (bsn, semver, ISO 8601 date-time) and the
	 * http protocol resolver are registered a single time instead of on
	 * every validateObject() call. The shared loader also caches resolved
	 * `$ref` schema documents across calls.
	 *
	 * @return Validator The configured validator instance
	 */
	private function getValidator(): Validator {
		if ($this->validator !== null) {
			return $this->validator;
		}

		$validator = new Validator();
		$validator->setParser((new RefLoopGuardParser(keyword: '$ref'))->schemaParser());
		$validator->setMaxErrors(100);

		$formats = $validator->parser()->getFormatResolver();
		foreach ($this->customFormats() as $format => $resolver) {
			$formats->register('string', $format, $resolver);
		}

		$validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);

		$this->validator = $validator;

		return $validator;
	}//end getValidator()

	/**
	 * The custom string formats.
	 *
	 * `date-time` accepts ISO 8601 input (optional seconds/timezone), overriding the
	 * opis built-in format whose regex mandates seconds. Storage still normalises to
	 * a DATETIME column and reads emit RFC 3339.
	 *
	 * @return array<string, object> The resolvers by format.
	 */
	private function customFormats(): array {
		return FormatFactory::customStringFormats(
			userManager: $this->userManager,
			planned: fn (string $uid): bool => in_array($uid, $this->plannedUsers, true)
		);
	}//end customFormats()

	/**
	 * Resolves a schema from a given URI.
	 *
	 * @param Uri $uri The URI pointing to the schema.
	 *
	 * @return string The schema content in JSON format.
	 *
	 * @throws GuzzleException If there is an error during schema fetching.
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uri::fromParts is standard GuzzleHttp\Psr7 pattern
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function resolveSchema(Uri $uri): string {
		$local = ($this->urlGenerator->getBaseUrl() === $uri->scheme().'://'.$uri->host());
		$path  = ($uri->path() ?? '');

		if ($local === true && str_contains($path, '/api/schemas') === true) {
			$exploded = explode('/', $path);
			$schema   = $this->schemaMapper->find(end($exploded));

			return json_encode($schema->getSchemaObject($this->urlGenerator));
		}

		if ($local === true && str_contains($path, '/api/files/schema') === true) {
			return self::fileSchema();
		}

		return $this->externalSchema(uri: $uri);
	}//end resolveSchema()

	/**
	 * A basic file schema object.
	 *
	 * TODO: Implement proper file schema resolution.
	 *
	 * @return string The JSON.
	 */
	private static function fileSchema(): string {
		$properties = ['id' => 'integer', 'name' => 'string', 'path' => 'string', 'mimetype' => 'string', 'size' => 'integer'];

		return json_encode(
			(object)['type' => 'object', 'properties' => (object)array_map(static fn (string $type): object => (object)['type' => $type], $properties)]
		);
	}//end fileSchema()

	/**
	 * The schema at an external URI, when external schemas are allowed.
	 *
	 * @param Uri $uri The URI.
	 *
	 * @return string The schema, empty when not allowed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Uri::fromParts is standard GuzzleHttp\Psr7 pattern
	 */
	private function externalSchema(Uri $uri): string {
		if ($this->config->getValueBool('openregister', 'allowExternalSchemas') !== true) {
			return '';
		}

		$client = new Client();

		return $client->get(\GuzzleHttp\Psr7\Uri::fromParts($uri->components()))->getBody()->getContents();
	}//end externalSchema()

	/**
	 * Removes query parameters from a reference string.
	 *
	 * @param string $reference The reference string that may contain query parameters
	 *
	 * @return string The reference string without query parameters
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function removeQueryParameters(string $reference): string {
		// Remove query parameters if present (e.g., "schema?key=value" -> "schema").
		if (str_contains($reference, '?') === true) {
			return substr($reference, 0, strpos($reference, '?'));
		}

		return $reference;
	}//end removeQueryParameters()

	/**
	 * Generates a meaningful error message from a validation result.
	 *
	 * This method creates clear, user-friendly error messages instead of using
	 * the generic Opis error message like "The required properties ({missing}) are missing".
	 *
	 * @param ValidationResult $result The validation result from Opis JsonSchema.
	 *
	 * @return string A meaningful error message.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function generateErrorMessage(ValidationResult $result): string {
		return (new ValidationErrorMessages())->forResult(result: $result);
	}//end generateErrorMessage()

	/**
	 * Handles validation exceptions by formatting them into a JSON response.
	 *
	 * @param ValidationException|CustomValidationException $exception The validation exception.
	 *
	 * @return JSONResponse JSON error response with validation errors and 400 status code.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function handleValidationException(ValidationException|CustomValidationException $exception): JSONResponse {
		if ($exception instanceof ValidationException === false) {
			return self::validationFailedResponse(errors: array_values($exception->getErrors()));
		}

		$property = null;
		if (method_exists($exception, 'getProperty') === true) {
			$property = $exception->getProperty();
		}

		return self::validationFailedResponse(
			errors: [['property' => $property, 'message' => $exception->getMessage(), 'errors' => self::formattedErrors(exception: $exception)]]
		);
	}//end handleValidationException()

	/**
	 * The formatted Opis errors of an exception.
	 *
	 * `getErrors()` is null for a ValidationException thrown with a message alone (readOnly
	 * enforcement is one); formatting null is a TypeError and would turn this 400 into a 500.
	 *
	 * @param ValidationException $exception The exception.
	 *
	 * @return array The formatted errors.
	 */
	private static function formattedErrors(ValidationException $exception): array {
		$validationError = $exception->getErrors();
		if ($validationError === null) {
			return [];
		}

		return (new ErrorFormatter())->format($validationError);
	}//end formattedErrors()

	/**
	 * The 400 response for a failed validation.
	 *
	 * @param array $errors The error entries.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function validationFailedResponse(array $errors): JSONResponse {
		return new JSONResponse(
			data: ['status' => 'error', 'message' => 'Validation failed', 'errors' => $errors],
			statusCode: 400
		);
	}//end handleValidationException()

	/**
	 * Check of the value of a parameter, or a combination of parameters, is unique
	 *
	 * @param array $object The object to check
	 * @param Schema $schema The schema of the object
	 *
	 * @return void
	 * @throws CustomValidationException
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function validateUniqueFields(array $object, Schema $schema): void {
		$uniqueFields = ($schema->getConfiguration()['unique'] ?? null);
		if (empty($uniqueFields) === true) {
			return;
		}

		$filters = self::uniqueFilters(object: $object, uniqueFields: $uniqueFields);
		if ($this->objectMapper->countAll(_filters: $filters, schema: $schema) !== 0) {
			throw self::notUniqueException(object: $object, uniqueFields: $uniqueFields);
		}
	}//end validateUniqueFields()

	/**
	 * The filters that find objects sharing the object's unique field values.
	 *
	 * @param array $object       The object.
	 * @param mixed $uniqueFields The configured unique field, or list of fields.
	 *
	 * @return array The filters, keyed by field.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function uniqueFilters(array $object, mixed $uniqueFields): array {
		if (is_string($uniqueFields) === true) {
			return [$uniqueFields => $object[$uniqueFields]];
		}

		$filters = [];
		if (is_array($uniqueFields) === true) {
			foreach ($uniqueFields as $field) {
				$filters[$field] = $object[$field];
			}
		}

		return $filters;
	}//end uniqueFilters()

	/**
	 * The exception naming the unique fields and the values that are already taken.
	 *
	 * @param array $object       The object.
	 * @param mixed $uniqueFields The configured unique field, or list of fields.
	 *
	 * @return CustomValidationException The exception.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function notUniqueException(array $object, mixed $uniqueFields): CustomValidationException {
		$fields      = (array) $uniqueFields;
		$fieldNames  = implode(', ', $fields);
		$fieldValues = implode(', ', array_map(static fn ($field) => $field . '=' . ($object[$field] ?? 'null'), $fields));
		$errorName   = (string) ($fields[array_key_first($fields)] ?? 'uniqueField');

		return new CustomValidationException(
			message: "Fields are not unique: {$fieldNames} (values: {$fieldValues})",
			errors: [$errorName => "The identifying fields ({$fieldNames}) are not unique. Found duplicate values: {$fieldValues}"]
		);
	}//end validateUniqueFields()
}//end class
