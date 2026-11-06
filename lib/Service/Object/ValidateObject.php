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
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectHandling;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Formats\Iso8601DateTimeFormat;
use OCA\OpenRegister\Formats\CronFormat;
use OCA\OpenRegister\Formats\SemVerFormat;
use OCA\OpenRegister\Formats\UserFormat;
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
	 * Messages that name the property, one argument and the value: the argument key, its
	 * default, and the sprintf template.
	 *
	 * @var array<string, array{0: string, 1: int|string, 2: string}>
	 */
	private const SIMPLE_ERROR_MESSAGES = [
		'format'  => [
			'format',
			'unknown',
			"Property '%s' should match format '%s' but '%s' does not. Please provide a value in the correct format.",
		],
		'minimum' => ['min', 0, "Property '%s' should be at least %s, but is %s. Please provide a larger number."],
		'maximum' => ['max', 0, "Property '%s' should be at most %s, but is %s. Please provide a smaller number."],
		'pattern' => [
			'pattern',
			'unknown',
			"Property '%s' should match pattern '%s' but '%s' does not. Please provide a value that matches the required pattern.",
		],
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
	 * Constructor for ValidateObject
	 *
	 * @param IAppConfig $config Configuration service.
	 * @param MagicMapperInterface $objectMapper Object mapper.
	 * @param SchemaMapperInterface $schemaMapper Schema mapper.
	 * @param IURLGenerator $urlGenerator URL generator.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param IUserManager $userManager Backend consulted by the `user` string format.
	 * @param NotSuppliedHandler|null $notSuppliedHandler Reads and applies the recorded-incompleteness record.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		private IAppConfig $config,
		private MagicMapperInterface $objectMapper,
		private SchemaMapperInterface $schemaMapper,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private IUserManager $userManager,
		// Nullable with a null default so the unit tests that build this class
		// positionally keep working. The handler has no dependencies of its
		// own, so the fallback below constructs the real thing rather than
		// degrading to a no-op: there is no configuration that can make
		// recorded incompleteness silently stop being enforced.
		private ?NotSuppliedHandler $notSuppliedHandler = null,
	) {
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
	 * Pre-processes a schema object to resolve all schema references.
	 *
	 * This method recursively walks through the schema object and replaces
	 * any "#/components/schemas/[slug]" references with the actual schema definitions.
	 * This ensures the validation library can work with fully resolved schemas.
	 *
	 * @param object $schemaObject The schema object to process
	 * @param array $visited Array to track visited schemas to prevent infinite loops
	 * @param bool $_skipUuidTransformed Whether to skip UUID transformation (unused)
	 *
	 * @return object The processed schema object with resolved references
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Schema reference resolution requires multiple type checks
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)  Boolean flag needed for backward compatibility
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function preprocessSchemaReferences(
		object $schemaObject,
		array $visited = [],
		bool $_skipUuidTransformed = false,
	): object {
		// Clone the schema object to avoid modifying the original.
		$processedSchema = json_decode(json_encode($schemaObject));

		// Recursively process all properties.
		if (($processedSchema->properties ?? null) !== null) {
			foreach ($processedSchema->properties as $propertyName => $propertySchema) {
				// Skip processing if this property has been transformed to a UUID type by OpenRegister logic.
				// This prevents circular references for related-object properties.
				$isStringType = ($propertySchema->type ?? null) !== null
					&& $propertySchema->type === 'string';
				$hasUuidPattern = ($propertySchema->pattern ?? null) !== null
					&& str_contains($propertySchema->pattern, 'uuid') === true;
				if ($isStringType === true && $hasUuidPattern === true) {
					continue;
				}

				$processedSchema->properties->$propertyName = $this->resolveSchemaProperty(
					propertySchema: $propertySchema,
					visited: $visited
				);
			}
		}

		// Process array items if present.
		if (($processedSchema->items ?? null) !== null) {
			// Skip processing if array items have been transformed to UUID type by OpenRegister logic.
			$isStringType = ($processedSchema->items->type ?? null) !== null
				&& $processedSchema->items->type === 'string';
			$hasUuidPattern = ($processedSchema->items->pattern ?? null) !== null
				&& str_contains($processedSchema->items->pattern, 'uuid') === true;
			$isAlreadyTransformed = $isStringType && $hasUuidPattern;

			if ($isAlreadyTransformed === false) {
				$processedSchema->items = $this->resolveSchemaProperty(
					propertySchema: $processedSchema->items,
					visited: $visited
				);
			}
		}

		return $processedSchema;
	}//end preprocessSchemaReferences()

	/**
	 * Resolves schema references in a property definition.
	 *
	 * @param object $propertySchema The property schema to resolve
	 * @param array $visited Array to track visited schemas to prevent infinite loops
	 *
	 * @return object The resolved property schema
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Complex reference resolution with multiple format handlers
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Multiple reference types and nested schema scenarios
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function resolveSchemaProperty(object $propertySchema, array $visited = []): object {
		$schemaSlug = $this->componentSchemaSlug(propertySchema: $propertySchema);
		if ($schemaSlug !== null) {
			// Prevent infinite loops.
			if (in_array($schemaSlug, $visited) === true) {
				return $propertySchema;
			}

			$referencedSchema = $this->findSchemaBySlug(slug: $schemaSlug);
			if ($referencedSchema !== null) {
				$resolvedSchema = $this->preprocessSchemaReferences(
					schemaObject: $referencedSchema->getSchemaObject($this->urlGenerator),
					visited: array_merge($visited, [$schemaSlug])
				);
				return self::mergeResolvedReference(propertySchema: $propertySchema, resolvedSchema: $resolvedSchema);
			}
		}

		// Handle array items with $ref.
		if (($propertySchema->items ?? null) !== null && (($propertySchema->items->{'$ref'} ?? null) !== null) === true) {
			$propertySchema->items = $this->resolveSchemaProperty(propertySchema: $propertySchema->items, visited: $visited);
		}

		// Recursively process nested properties.
		if (($propertySchema->properties ?? null) !== null) {
			foreach ($propertySchema->properties ?? [] as $nestedPropertyName => $nestedPropertySchema) {
				$propertySchema->properties->$nestedPropertyName = $this->resolveSchemaProperty(
					propertySchema: $nestedPropertySchema,
					visited: $visited
				);
			}
		}

		return $propertySchema;
	}//end resolveSchemaProperty()

	/**
	 * The slug of the `#/components/schemas/` schema a property's `$ref` points at.
	 *
	 * The `$ref` may be a string, or an object or array carrying an `id`; query parameters are dropped.
	 *
	 * @param object $propertySchema The property schema.
	 *
	 * @return string|null The schema slug, or null when the property has no such reference.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function componentSchemaSlug(object $propertySchema): ?string {
		$reference = ($propertySchema->{'$ref'} ?? null);
		if (is_object($reference) === true && (($reference->id ?? null) !== null)) {
			$reference = $reference->id;
		} elseif (is_array($reference) === true && (($reference['id'] ?? null) !== null)) {
			$reference = $reference['id'];
		}

		if (is_string($reference) === false || str_contains($reference, '#/components/schemas/') === false) {
			return null;
		}

		$cleanReference = $this->removeQueryParameters(reference: $reference);
		return substr($cleanReference, strrpos($cleanReference, '/') + 1);
	}//end componentSchemaSlug()

	/**
	 * A property schema with its `$ref` replaced by the resolved schema.
	 *
	 * An object property accepts the full object or a UUID string; its other keywords except `type`
	 * stay. Any other property becomes the resolved schema carrying its other keywords.
	 *
	 * @param object $propertySchema The property schema.
	 * @param object $resolvedSchema The resolved referenced schema.
	 *
	 * @return object The merged schema.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private static function mergeResolvedReference(object $propertySchema, object $resolvedSchema): object {
		if (($propertySchema->type ?? null) !== null && $propertySchema->type === 'object') {
			$unionSchema = new stdClass();
			$unionSchema->oneOf = [
				$resolvedSchema,
				(object)[
					'type' => 'string',
					'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
				],
			];

			foreach ($propertySchema as $key => $value) {
				if ($key !== '$ref' && $key !== 'type') {
					$unionSchema->$key = $value;
				}
			}

			return $unionSchema;
		}

		foreach ($propertySchema as $key => $value) {
			if ($key !== '$ref') {
				$resolvedSchema->$key = $value;
			}
		}

		return $resolvedSchema;
	}//end mergeResolvedReference()

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

		// UUID pattern for related object references.
		$uuidPat = self::UUID_REFERENCE_PATTERN;

		$this->applyInversedByRelation(propertySchema: $propertySchema, uuidPattern: $uuidPat);
		$this->transformArrayItemsForOpenRegister(propertySchema: $propertySchema, uuidPattern: $uuidPat);

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
	 * @param string $uuidPattern    The pattern a related-object reference matches
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function applyInversedByRelation(object $propertySchema, string $uuidPattern): void {
		if (($propertySchema->inversedBy ?? null) === null || $propertySchema->inversedBy === '') {
			return;
		}

		$type = ($propertySchema->type ?? null);
		if ($type === 'array') {
			// For inversedBy array properties, allow objects or UUIDs.
			$propertySchema->items = (object)[
				'oneOf' => [
					(object)[
						'type' => 'string',
						'pattern' => $uuidPattern,
						'description' => 'UUID reference to a related object',
					],
					(object)[
						'type' => 'object',
						'description' => 'Nested object that will be created separately',
					],
				],
			];
			return;
		}

		if ($type === 'object') {
			// For inversedBy object properties, allow objects, UUIDs, or null.
			$propertySchema->oneOf = $this->inversedByObjectAlternatives(uuidPattern: $uuidPattern);
			unset(
				$propertySchema->type,
				$propertySchema->pattern,
				$propertySchema->properties,
				$propertySchema->required,
				$propertySchema->{'$ref'}
			);
		}
	}//end applyInversedByRelation()

	/**
	 * What an inversedBy object property accepts: null (managed by the other side), a UUID, or a nested object.
	 *
	 * @param string $uuidPattern The pattern a related-object reference matches
	 *
	 * @return array<int, object> The oneOf alternatives.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function inversedByObjectAlternatives(string $uuidPattern): array {
		return [
			(object)[
				'type' => 'null',
				'description' => 'No related object (inversedBy - managed by other side)',
			],
			(object)[
				'type' => 'string',
				'pattern' => $uuidPattern,
				'description' => 'UUID reference to a related object',
			],
			(object)[
				'type' => 'object',
				'description' => 'Nested object that will be created separately',
			],
		];
	}//end inversedByObjectAlternatives()

	/**
	 * Rewrite the items of an array property: relation items become UUID strings, object items are transformed.
	 *
	 * @param object $propertySchema The property schema to transform
	 * @param string $uuidPattern    The pattern a related-object reference matches
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformArrayItemsForOpenRegister(object $propertySchema, string $uuidPattern): void {
		$isArrayType = ($propertySchema->type ?? null) !== null
			&& $propertySchema->type === 'array';
		$hasItems = ($propertySchema->items ?? null) !== null;
		// `items` may decode as an associative array rather than a stdClass depending on the
		// schema source; normalise it to an object so the $ref-strip below runs. Without this,
		// an array-items $ref (e.g. `installedOn.items {"$ref":"Agent"}`) is left intact and
		// Opis JSON Schema fails with "Unresolved reference: schema:///Agent#" — unlike a scalar
		// string $ref, which is stripped by a separate branch that needs no object cast.
		if ($isArrayType === true && $hasItems === true && is_array($propertySchema->items) === true) {
			$propertySchema->items = (object)$propertySchema->items;
		}

		if ($isArrayType === true && $hasItems === true && is_object($propertySchema->items) === true) {
			$this->transformArrayItemSchema(itemsSchema: $propertySchema->items, uuidPattern: $uuidPattern);
		}
	}//end transformArrayItemsForOpenRegister()

	/**
	 * Rewrite one array's item schema: an inversedBy or `$ref` item becomes a UUID string, an object item is transformed.
	 *
	 * @param object $itemsSchema The item schema to transform
	 * @param string $uuidPattern The pattern a related-object reference matches
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformArrayItemSchema(object $itemsSchema, string $uuidPattern): void {
		// Same normalisation as the property itself — see dropUnusableRef().
		$this->dropUnusableRef(schema: $itemsSchema);

		// Handle inversedBy relationships for array items.
		// TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
		if (($itemsSchema->inversedBy ?? null) !== null) {
			// For inversedBy array items, transform to UUID string validation.
			$itemsSchema->type = 'string';
			$itemsSchema->pattern = $uuidPattern;
			$itemsSchema->description = 'UUID reference to a related object (inversedBy - should be empty)';
			unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});
		} elseif (($itemsSchema->{'$ref'} ?? null) !== null) {
			// Array items that $ref another schema are relation references
			// stored as UUID strings (e.g. assignment.briefingMaterialIds,
			// item-bank.itemIds declared as items {"$ref":"<slug>","format":
			// "uuid"}). OpenRegister uses the $ref only for relation tracking;
			// Opis JSON Schema would otherwise try to resolve the bare slug as
			// a URI and fail with "Unresolved reference: schema:///<slug>#".
			// Treat the items as opaque UUID strings for validation — this
			// mirrors how single string $ref props (below) and array
			// self-references (transformSchemaForValidation Step 1) are handled.
			$itemsSchema->type = 'string';
			$itemsSchema->pattern = $uuidPattern;
			$itemsSchema->description = 'UUID reference to a related object';
			unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});
		} elseif (isset($itemsSchema->type) === true && $itemsSchema->type === 'object') {
			$this->transformObjectPropertyForOpenRegister(objectSchema: $itemsSchema);

			// 🔴 AND THE ITEM OBJECT'S OWN PROPERTIES. Without this a `$ref` on a
			// property of an object inside `items` (learniq
			// ReportCard.subjectGrades[].curriculumPlanId, Lesson.blocks[],
			// HourPlan.lines[]) reached Opis untouched, and every write that
			// carried the array failed with `Unresolved reference:
			// schema:///CurriculumPlan#` (openregister#2179). Each such
			// property gets exactly the treatment a top-level property gets.
			$this->transformNestedPropertiesForOpenRegister(schema: $itemsSchema);
		}//end if
	}//end transformArrayItemSchema()

	/**
	 * Strip a relation `$ref` Opis would try to resolve as a URI: from a string property, and from an untyped one.
	 *
	 * @param object $propertySchema The property schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function stripRelationRefs(object $propertySchema): void {
		// Strip $ref from string-type properties referencing other schemas.
		// OpenRegister uses $ref to denote schema relationships, but Opis JSON Schema
		// interprets $ref as a JSON Schema reference and tries to resolve it as a URI.
		// The $ref is only needed by OpenRegister for relation tracking (e.g. onDelete),
		// not for validation.
		if (($propertySchema->type ?? null) !== null
			&& $propertySchema->type === 'string'
			&& ($propertySchema->{'$ref'} ?? null) !== null
		) {
			unset($propertySchema->{'$ref'});
		}

		// 🔴 AND FROM A PROPERTY THAT DECLARES NO TYPE AT ALL, which is the one
		// shape every branch above misses. `{"$ref": "besluit"}` on its own is
		// not an array, not an object and not a string, so the slug survived
		// into Opis and every object write of that schema failed with
		// `Unresolved reference: schema:///besluit#` — a message that names
		// neither the property nor the schema. The schema itself saved with a
		// 200, so the author read a success and then could not store anything.
		// Measured 2026-09-19 on tests/e2e/ci/link-exposure.spec.ts, whose
		// disclosure assertion never ran for this reason.
		//
		// A ref that could genuinely be a JSON Schema reference — a fragment
		// or a URI — is left alone: this app writes slugs, and dropping
		// something that resolves would change validation rather than restore
		// it.
		$bareRef = ($propertySchema->{'$ref'} ?? null);
		if (($propertySchema->type ?? null) === null
			&& is_string($bareRef) === true
			&& $bareRef !== ''
			&& str_contains($bareRef, '#') === false
			&& str_contains($bareRef, '/') === false
		) {
			unset($propertySchema->{'$ref'});
		}
	}//end stripRelationRefs()

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
		if (($schema->properties ?? null) === null) {
			return;
		}

		// `properties` and each property may decode as associative arrays for some
		// schema sources; cast them and WRITE THE CAST BACK, or the transform runs
		// on a copy and the original keeps its `$ref`.
		if (is_array($schema->properties) === true) {
			$schema->properties = (object)$schema->properties;
		}

		if (is_object($schema->properties) === false) {
			return;
		}

		foreach (get_object_vars($schema->properties) as $nestedPropertyName => $nestedPropertySchema) {
			if (is_array($nestedPropertySchema) === true) {
				$nestedPropertySchema = (object)$nestedPropertySchema;
				$schema->properties->{$nestedPropertyName} = $nestedPropertySchema;
			}

			if (is_object($nestedPropertySchema) === true) {
				$this->transformPropertyForOpenRegister(propertySchema: $nestedPropertySchema);
			}
		}
	}//end transformNestedPropertiesForOpenRegister()

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
		// Check if this has objectConfiguration (can be array or object).
		// Also check inside items.oneOf for polymorphic references.
		$handling = $this->extractObjectConfigurationHandling(propertySchema: $objectSchema);

		if ($handling === null) {
			return;
		}

		switch ($handling) {
			case 'related-schema':
			case 'related-object':
				// For related objects, expect UUID strings instead of full objects.
				$this->transformToUuidProperty(objectSchema: $objectSchema);
				break;

			case 'nested-object':
				// For nested objects, keep the full object structure but remove circular refs.
				$this->transformToNestedObjectProperty(objectSchema: $objectSchema);
				break;

			default:
				// For other handling types, leave as-is.
				break;
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
		// UUID pattern for related object references.
		$uuidPat = self::UUID_REFERENCE_PATTERN;

		// If this property has inversedBy, it should support both objects and UUID strings.
		if (($objectSchema->inversedBy ?? null) === null) {
			// Original behavior for non-inversedBy properties.
			// Remove object-specific properties.
			unset($objectSchema->properties, $objectSchema->required);

			// Set to string type with UUID pattern.
			$objectSchema->type = 'string';
			$objectSchema->pattern = $uuidPat;
			$objectSchema->description = 'UUID reference to a related object';

			// Remove $ref to prevent circular references.
			unset($objectSchema->{'$ref'});
			return;
		}

		// Create a union type that allows both full objects and UUID strings.
		$originalProperties = $objectSchema->properties ?? null;
		$originalRequired = $objectSchema->required ?? null;
		$originalRef = $objectSchema->{'$ref'} ?? null;

		// Create the object schema (preserve original structure).
		$objectTypeSchema = (object)[
			'type' => 'object',
		];

		if ($originalProperties !== null && empty($originalProperties) === false) {
			$objectTypeSchema->properties = $originalProperties;
		}

		if ($originalRequired !== null && empty($originalRequired) === false) {
			$objectTypeSchema->required = $originalRequired;
		}

		if ($originalRef !== null && $originalRef !== '') {
			$objectTypeSchema->{'$ref'} = $originalRef;
		}

		// Create the UUID string schema.
		$uuidTypeSchema = (object)[
			'type' => 'string',
			'pattern' => $uuidPat,
			'description' => 'UUID reference to a related object',
		];

		// Clear the current object and set up union type.
		$objectSchema->type = null;
		unset($objectSchema->properties, $objectSchema->required, $objectSchema->{'$ref'});

		// Create union type.
		$objectSchema->oneOf = [
			$objectTypeSchema,
			$uuidTypeSchema,
		];

		$objectSchema->description = 'Related object (can be full object or UUID reference)';
		// End if.
	}//end transformToUuidProperty()

	/**
	 * Transforms an object property for nested objects, removing circular references.
	 *
	 * @param object $objectSchema The object schema to transform
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformToNestedObjectProperty(object $objectSchema): void {
		// For nested objects, we need to resolve the $ref but prevent circular references.
		if (($objectSchema->{'$ref'} ?? null) !== null) {
			$ref = $objectSchema->{'$ref'};

			// Handle both string and object formats for $ref.
			$reference = $ref;
			if (is_object($ref) === true && (($ref->id ?? null) !== null)) {
				$reference = $ref->id;
			} elseif (is_array($ref) === true && (($ref['id'] ?? null) !== null)) {
				$reference = $ref['id'];
			}

			// If this is a self-reference (circular), convert to a simple object type.
			if (is_string($reference) === true && str_contains($reference, '/components/schemas/') === true) {
				// Remove query parameters if present.
				$cleanReference = $this->removeQueryParameters(reference: $reference);
				$schemaSlug = substr($cleanReference, strrpos($cleanReference, '/') + 1);

				// For self-references, create a generic object structure to prevent circular validation.
				// Create a temporary object for isSelfReference check.
				$tempSchema = (object)['$ref' => $schemaSlug];
				if ($this->isSelfReference(propertySchema: $tempSchema, schemaSlug: $schemaSlug) === true) {
					$objectSchema->type = 'object';
					$objectSchema->description = 'Nested object (self-reference prevented)';
					unset($objectSchema->{'$ref'});

					// Add basic properties that most objects should have.
					$objectSchema->properties = (object)[
						'id' => (object)[
							'type' => 'string',
							'description' => 'Object identifier',
						],
					];
				}
			}//end if
		}//end if
	}//end transformToNestedObjectProperty()

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
		// Check directly on the property schema.
		if (isset($propertySchema->objectConfiguration) === true) {
			$handling = $this->getMixedValue(data: $propertySchema->objectConfiguration, key: 'handling');
			if ($handling !== null) {
				return $handling;
			}
		}

		// Check inside items (for properties with items structure).
		// Items can be either an object (stdClass) or an array depending on how the schema was processed.
		if (isset($propertySchema->items) === true) {
			$items = $propertySchema->items;

			// Check if items has objectConfiguration directly.
			$itemsConfig = $this->getMixedValue(data: $items, key: 'objectConfiguration');
			if ($itemsConfig !== null) {
				$handling = $this->getMixedValue(data: $itemsConfig, key: 'handling');
				if ($handling !== null) {
					return $handling;
				}
			}

			// Check inside items.oneOf (for polymorphic references).
			$oneOf = $this->getMixedValue(data: $items, key: 'oneOf');
			$handling = $this->extractHandlingFromOneOfItems(oneOf: $oneOf);
			if ($handling !== null) {
				return $handling;
			}
		}//end if

		// Check inside oneOf directly on the property (alternative structure).
		$handling = $this->extractHandlingFromOneOfItems(oneOf: ($propertySchema->oneOf ?? null));
		if ($handling !== null) {
			return $handling;
		}

		return null;
	}//end extractObjectConfigurationHandling()

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
		if ($oneOf === null || (is_array($oneOf) === false && is_object($oneOf) === false)) {
			return null;
		}

		foreach ($oneOf as $oneOfItem) {
			$oneOfConfig = $this->getMixedValue(data: $oneOfItem, key: 'objectConfiguration');
			if ($oneOfConfig !== null) {
				$handling = $this->getMixedValue(data: $oneOfConfig, key: 'handling');
				if ($handling !== null) {
					return $handling;
				}
			}
		}

		return null;
	}//end extractHandlingFromOneOfItems()

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
			$propertySchema->oneOf = $this->inversedByObjectAlternatives(uuidPattern: self::UUID_REFERENCE_PATTERN);
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

		$enum = ($propertySchema->enum ?? null);
		if (is_array($enum) === true && empty($enum) === false) {
			$propertySchema->items ??= (object) ['type' => 'string'];
			$propertySchema->items->enum ??= $enum;
			unset($propertySchema->enum);
		}

		$oneOf = ($propertySchema->oneOf ?? null);
		if ((is_array($oneOf) === true || is_object($oneOf) === true) && empty((array) $oneOf) === false) {
			$propertySchema->items ??= new stdClass();
			$propertySchema->items->oneOf ??= $oneOf;
			unset($propertySchema->oneOf);
		}

		return $propertySchema;
	}//end fixMisplacedArrayConstraints()

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
		if ($handling === null && $hasRef === false && isset($itemsSchema->properties) === true) {
			return $itemsSchema;
		}

		// UUID strings: related-object handling, $ref references, or unknown handling types.
		// Simple object: nested-object handling or no configuration and no $ref.
		$useUuidStrings = $hasRef;
		if ($handling !== null) {
			$useUuidStrings = ($handling !== 'nested-object');
		}

		if ($useUuidStrings === true) {
			return self::asRelationReferenceItems(itemsSchema: $itemsSchema);
		}

		return self::asNestedObjectItems(itemsSchema: $itemsSchema);
	}//end transformArrayItemsForValidation()

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
		if (($propertySchema->{'$ref'} ?? null) !== null) {
			$ref = $propertySchema->{'$ref'};

			// Handle both string and object formats for $ref.
			$refId = $ref;
			if (is_object($ref) === true && (($ref->id ?? null) !== null)) {
				$refId = $ref->id;
			} elseif (is_array($ref) === true && (($ref['id'] ?? null) !== null)) {
				$refId = $ref['id'];
			}

			// Extract schema slug from reference path.
			if (is_string($refId) === true && str_contains($refId, '#/components/schemas/') === true) {
				// Remove query parameters if present.
				$cleanRefId = $this->removeQueryParameters(reference: $refId);
				$referencedSlug = substr($cleanRefId, strrpos($cleanRefId, '/') + 1);
				return $referencedSlug === $schemaSlug;
			}
		}

		return false;
	}//end isSelfReference()

	/**
	 * Finds a schema by slug (case-insensitive).
	 *
	 * @param string $slug The schema slug to find
	 *
	 * @return Schema|null The found schema or null if not found
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function findSchemaBySlug(string $slug): ?Schema {
		try {
			// Try direct slug match first using the find method which supports slug lookups.
			// find() throws when there is no match; the catch below is the
			// "not found" path, so no null test is needed here.
			return $this->schemaMapper->find($slug);
		} catch (Exception $e) {
			// Continue with case-insensitive search.
		}

		// Try case-insensitive search.
		try {
			$schemas = $this->schemaMapper->findAll();
			foreach ($schemas as $schema) {
				if (strcasecmp($schema->getSlug(), $slug) === 0) {
					return $schema;
				}
			}
		} catch (Exception $e) {
			// Failed to fetch schemas, returning null.
			$this->logger->debug(
				message: '[ValidateObject] Failed to find schema by slug',
				context: ['file' => __FILE__, 'line' => __LINE__, 'slug' => $slug, 'exception' => $e->getMessage()]
			);
		}

		return null;
	}//end findSchemaBySlug()

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
		$schemaObject = $this->transformSchemaForValidation(schemaObject: $schemaObject, currentSchemaSlug: ($schema?->getSlug() ?? ''));

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
		return [
			'bsn'       => new BsnFormat(),
			'semver'    => new SemVerFormat(),
			'cron'      => new CronFormat(),
			'user'      => new UserFormat(userManager: $this->userManager, planned: fn (string $uid): bool => in_array($uid, $this->plannedUsers, true)),
			'date-time' => new Iso8601DateTimeFormat(),
		];
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
		if ($result->isValid() === true) {
			return 'Validation passed';
		}

		// Get the primary validation error.
		$error = $result->error();

		return $this->formatValidationError(error: $error);
	}//end generateErrorMessage()

	/**
	 * Formats a validation error into a user-friendly message.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error The validation error.
	 *
	 * @return string A formatted error message.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function formatValidationError(\Opis\JsonSchema\Errors\ValidationError $error): string {
		$keyword      = $error->keyword();
		$propertyPath = $this->errorPropertyPath(error: $error);

		if (isset(self::SIMPLE_ERROR_MESSAGES[$keyword]) === true) {
			return $this->simpleErrorMessage(error: $error, propertyPath: $propertyPath);
		}

		return match ($keyword) {
			'required' => $this->requiredErrorMessage(args: $error->args()),
			'type' => $this->typeErrorMessage(error: $error, propertyPath: $propertyPath),
			'minItems', 'maxItems' => $this->itemCountErrorMessage(error: $error, propertyPath: $propertyPath),
			'minLength', 'maxLength' => $this->lengthErrorMessage(error: $error, propertyPath: $propertyPath),
			'enum' => $this->enumErrorMessage(error: $error, propertyPath: $propertyPath),
			default => $this->otherErrorMessage(error: $error, propertyPath: $propertyPath),
		};
	}//end formatValidationError()

	/**
	 * The dotted path of the failing value, or `root`.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error The validation error.
	 *
	 * @return string
	 */
	private function errorPropertyPath(\Opis\JsonSchema\Errors\ValidationError $error): string {
		$dataPath = $error->data()->fullPath();
		if (empty($dataPath) === true) {
			return 'root';
		}

		return implode('.', $dataPath);
	}//end errorPropertyPath()

	/**
	 * The message for a keyword in {@see self::SIMPLE_ERROR_MESSAGES}.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The validation error.
	 * @param string                                  $propertyPath The failing property.
	 *
	 * @return string
	 */
	private function simpleErrorMessage(\Opis\JsonSchema\Errors\ValidationError $error, string $propertyPath): string {
		[$argName, $default, $template] = self::SIMPLE_ERROR_MESSAGES[$error->keyword()];

		return sprintf($template, $propertyPath, ($error->args()[$argName] ?? $default), $error->data()->value());
	}//end simpleErrorMessage()

	/**
	 * The message for a missing required property or properties.
	 *
	 * @param array $args The error arguments.
	 *
	 * @return string
	 */
	private function requiredErrorMessage(array $args): string {
		$missing = $args['missing'] ?? [];
		if (is_array($missing) === false || count($missing) === 0) {
			return 'Required property is missing';
		}

		if (count($missing) === 1) {
			$hint = 'Please provide a value for this property or set it to null if allowed.';
			return "The required property ({$missing[0]}) is missing. {$hint}";
		}

		$missingList = implode(', ', $missing);
		return "The required properties ({$missingList}) are missing. Please provide values for these properties.";
	}//end requiredErrorMessage()

	/**
	 * The message for a value of the wrong type, with specific guidance for an empty value.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The validation error.
	 * @param string                                  $propertyPath The failing property.
	 *
	 * @return string
	 */
	private function typeErrorMessage(\Opis\JsonSchema\Errors\ValidationError $error, string $propertyPath): string {
		$value        = $error->data()->value();
		$expectedType = $error->args()['expected'] ?? 'unknown';
		$actualType   = $this->getValueType(value: $value);

		// A type list such as ["string", "null"].
		if (is_array($expectedType) === true) {
			$expectedType = implode(' or ', $expectedType);
		}

		$emptyHint = $this->emptyValueTypeMessage(expectedType: $expectedType, value: $value);
		if ($emptyHint !== null) {
			return "Property '{$propertyPath}' {$emptyHint}";
		}

		$hint = 'Please provide a value of the correct type.';
		return "Property '{$propertyPath}' should be type '{$expectedType}' but is '{$actualType}'. {$hint}";
	}//end typeErrorMessage()

	/**
	 * The rest of a type message for an empty object, array or string, or null for any other value.
	 *
	 * @param mixed $expectedType The expected type.
	 * @param mixed $value        The failing value.
	 *
	 * @return string|null
	 */
	private function emptyValueTypeMessage(mixed $expectedType, mixed $value): ?string {
		if ($expectedType === 'string' && $value === '') {
			$hint1 = 'For non-required string properties, set this to null to clear the field.';
			$hint2 = 'For required string properties, provide a valid string value.';
			return "expects non-empty string but got empty string. {$hint1} {$hint2}";
		}

		if (is_array($value) === false || empty($value) === false) {
			return null;
		}

		if ($expectedType === 'object') {
			$hint1 = 'For non-required object properties, set this to null to clear the field.';
			$hint2 = 'For required object properties, provide a valid object with the necessary properties.';
			return "expects object but got empty ({}). {$hint1} {$hint2}";
		}

		if ($expectedType === 'array') {
			$hint = 'This likely has a minItems constraint. Please provide at least one item.';
			return "expects non-empty array but got empty array ([]). {$hint}";
		}

		return null;
	}//end emptyValueTypeMessage()

	/**
	 * The message for an array with too few or too many items.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The validation error.
	 * @param string                                  $propertyPath The failing property.
	 *
	 * @return string
	 */
	private function itemCountErrorMessage(\Opis\JsonSchema\Errors\ValidationError $error, string $propertyPath): string {
		$value       = $error->data()->value();
		$actualItems = 0;
		if (is_array($value) === true) {
			$actualItems = count($value);
		}

		if ($error->keyword() === 'minItems') {
			$minItems = $error->args()['min'] ?? 0;
			$hint     = 'Please add more items to the array or set to null if the property is not required.';
			return "Property '{$propertyPath}' requires at least {$minItems} items, has {$actualItems}. {$hint}";
		}

		$maxItems = $error->args()['max'] ?? 0;
		$hint     = 'Please remove some items from the array.';
		return "Property '{$propertyPath}' allows at most {$maxItems} items, has {$actualItems}. {$hint}";
	}//end itemCountErrorMessage()

	/**
	 * The message for a string that is too short or too long.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The validation error.
	 * @param string                                  $propertyPath The failing property.
	 *
	 * @return string
	 */
	private function lengthErrorMessage(\Opis\JsonSchema\Errors\ValidationError $error, string $propertyPath): string {
		$value        = $error->data()->value();
		$actualLength = 0;
		if (is_string($value) === true) {
			$actualLength = strlen($value);
		}

		if ($error->keyword() === 'maxLength') {
			$maxLength = $error->args()['max'] ?? 0;
			$hint      = 'Please provide a shorter string value.';
			return "Property '{$propertyPath}' allows at most {$maxLength} chars, has {$actualLength}. {$hint}";
		}

		$minLength = $error->args()['min'] ?? 0;
		if ($actualLength === 0) {
			$hint = 'Please provide a non-empty string value.';
			return "Property '{$propertyPath}' requires at least {$minLength} characters, but is empty. {$hint}";
		}

		$hint = 'Please provide a longer string value.';
		return "Property '{$propertyPath}' requires at least {$minLength} chars, has {$actualLength}. {$hint}";
	}//end lengthErrorMessage()

	/**
	 * The message for a value outside an enum, listing the allowed values when they are known.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The validation error.
	 * @param string                                  $propertyPath The failing property.
	 *
	 * @return string
	 */
	private function enumErrorMessage(\Opis\JsonSchema\Errors\ValidationError $error, string $propertyPath): string {
		$value         = $error->data()->value();
		$allowedValues = $error->args()['values'] ?? [];
		if ($allowedValues === []) {
			$allowedValues = $this->enumValuesFromSchema(error: $error);
		}

		if (is_array($allowedValues) === true && $allowedValues !== []) {
			$valuesList = implode(
				', ',
				array_map(
					function ($v) {
						return "'{$v}'";
					},
					$allowedValues
				)
			);
			$msg = "Property '{$propertyPath}' should be one of: {$valuesList}, ";
			return $msg . "but is '{$value}'. Please choose one of the allowed values.";
		}

		$msg = "Property '{$propertyPath}' has an invalid value '{$value}'. ";
		return $msg . 'Please provide one of the allowed values.';
	}//end enumErrorMessage()

	/**
	 * The enum values the failing schema declares, or an empty list.
	 *
	 * Opis passes no arguments for `enum` (`EnumKeyword::validate()` reports the error
	 * without a fourth argument), so the allowed values have to come from the schema.
	 * Without them the message would list nothing, which reads as "no value is valid".
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error The validation error.
	 *
	 * @return array
	 */
	private function enumValuesFromSchema(\Opis\JsonSchema\Errors\ValidationError $error): array {
		$schemaData = $error->schema()->info()->data();
		if (is_object($schemaData) === true
			&& isset($schemaData->enum) === true
			&& is_array($schemaData->enum) === true
		) {
			return $schemaData->enum;
		}

		return [];
	}//end enumValuesFromSchema()

	/**
	 * The message for any other keyword: the first sub-error's message when there is one.
	 *
	 * @param \Opis\JsonSchema\Errors\ValidationError $error        The validation error.
	 * @param string                                  $propertyPath The failing property.
	 *
	 * @return string
	 */
	private function otherErrorMessage(\Opis\JsonSchema\Errors\ValidationError $error, string $propertyPath): string {
		$subErrors = $error->subErrors();
		if (empty($subErrors) === false) {
			return $this->formatValidationError(error: $subErrors[0]);
		}

		$msg = "Property '{$propertyPath}' failed validation for rule '{$error->keyword()}'. ";
		return $msg . 'Please check the property value and schema requirements.';
	}//end otherErrorMessage()

	/**
	 * Gets a human-readable type name for a value.
	 *
	 * @param mixed $value The value to get the type for.
	 *
	 * @return string The type name.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function getValueType($value): string {
		if ($value === null) {
			return 'null';
		}

		if (is_bool($value) === true) {
			return 'boolean';
		}

		if (is_int($value) === true) {
			return 'integer';
		}

		if (is_float($value) === true) {
			return 'number';
		}

		if (is_string($value) === true) {
			return 'string';
		}

		if (is_array($value) === true) {
			return 'array';
		}

		if (is_object($value) === true) {
			return 'object';
		}

		return 'unknown';
	}//end getValueType()

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
