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
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectHandling;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCA\OpenRegister\Exception\ValidationException;
use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Formats\ExtendedFieldTypeValidator;
use OCA\OpenRegister\Formats\Iso8601DateTimeFormat;
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
class ValidateObject {
	/**
	 * Default validation error message.
	 *
	 * @var string
	 */
	public const VALIDATION_ERROR_MESSAGE = 'Invalid object';

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
	 * Walk the schema's property definitions (raw `$schema->getProperties()`
	 * shape: associative array) and find every property whose definition sets
	 * `readOnly: true` at the top level.
	 *
	 * Wave-12 Fix 1 helper. We deliberately ignore nested readOnly inside
	 * object/array sub-schemas — the wave-11 audit (Section A) flagged
	 * top-level enforcement only; nested cases can be added by the same
	 * mechanism in a follow-up once the leaf-app contract is stabilised.
	 *
	 * @param array $properties Raw schema properties (associative array; key = property name).
	 *
	 * @return array<int, string> List of property names whose schema sets `readOnly: true`.
	 */
	private function collectReadOnlyPropertyNames(array $properties): array {
		$readOnly = [];
		foreach ($properties as $name => $definition) {
			if (is_array($definition) === false) {
				continue;
			}

			$isReadOnly = ($definition['readOnly'] ?? false);
			if ($isReadOnly === true) {
				$readOnly[] = (string)$name;
			}
		}

		return $readOnly;
	}//end collectReadOnlyPropertyNames()

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
		// No existing object → CREATE path, readOnly is not enforced.
		if ($existingObject === []) {
			return [];
		}

		$properties = $schema->getProperties();
		if (is_array($properties) === false || $properties === []) {
			return [];
		}

		$readOnlyNames = $this->collectReadOnlyPropertyNames(properties: $properties);
		if ($readOnlyNames === []) {
			return [];
		}

		$violations = [];
		foreach ($readOnlyNames as $name) {
			// Omitted from the payload → not a mutation → OK.
			if (array_key_exists($name, $incomingObject) === false) {
				continue;
			}

			$attempted = $incomingObject[$name];
			$stored = $existingObject[$name] ?? null;

			// Compare with PHP-equality (===) so type-coercion doesn't mask a
			// mutation: `42` (int) vs `"42"` (string) IS a mutation. Authors
			// who care about looser equality should use `default` instead.
			if ($attempted === $stored) {
				continue;
			}

			$violations[] = [
				'property' => $name,
				'attempted' => $attempted,
				'stored' => $stored,
				'message' => sprintf(
					"Property '%s' is declared readOnly and cannot be modified after creation.",
					$name
				),
			];
		}//end foreach

		return $violations;
	}//end validateReadOnlyConstraints()

	/**
	 * Constructor for ValidateObject
	 *
	 * @param IAppConfig $config Configuration service.
	 * @param MagicMapper $objectMapper Object mapper.
	 * @param SchemaMapper $schemaMapper Schema mapper.
	 * @param IURLGenerator $urlGenerator URL generator.
	 * @param LoggerInterface $logger Logger for logging operations.
	 * @param IUserManager $userManager Backend consulted by the `user` string format.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function __construct(
		private IAppConfig $config,
		private MagicMapper $objectMapper,
		private SchemaMapper $schemaMapper,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
		private IUserManager $userManager,
	) {
	}//end __construct()

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
		// Handle $ref references.
		if (($propertySchema->{'$ref'} ?? null) !== null) {
			$reference = $propertySchema->{'$ref'};

			// Handle both string and object formats for $ref.
			if (is_object($reference) === true && (($reference->id ?? null) !== null)) {
				$reference = $reference->id;
			} elseif (is_array($reference) === true && (($reference['id'] ?? null) !== null)) {
				$reference = $reference['id'];
			}

			// Check if this is a schema reference we should resolve.
			if (is_string($reference) === true && str_contains($reference, '#/components/schemas/') === true) {
				// Remove query parameters if present.
				$cleanReference = $this->removeQueryParameters(reference: $reference);
				$schemaSlug = substr($cleanReference, strrpos($cleanReference, '/') + 1);

				// Prevent infinite loops.
				if (in_array($schemaSlug, $visited) === true) {
					return $propertySchema;
				}

				// Try to resolve the schema.
				$referencedSchema = $this->findSchemaBySlug(slug: $schemaSlug);
				if ($referencedSchema !== null) {
					// Get the referenced schema object and recursively process it.
					$refSchemaObj = $referencedSchema->getSchemaObject($this->urlGenerator);

					$newVisited = array_merge($visited, [$schemaSlug]);
					$resolvedSchema = $this->preprocessSchemaReferences(
						schemaObject: $refSchemaObj,
						visited: $newVisited
					);

					// For object properties, we need to handle both nested objects and UUID references.
					if (($propertySchema->type ?? null) !== null && $propertySchema->type === 'object') {
						// Create a union type that allows both the full object and a UUID string.
						$unionSchema = new stdClass();
						$unionSchema->oneOf = [
							$resolvedSchema,
							// Full object.
							(object)[
								// UUID string.
								'type' => 'string',
								'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$',
							],
						];

						// Copy any other properties from the original schema.
						foreach ($propertySchema as $key => $value) {
							if ($key !== '$ref' && $key !== 'type') {
								$unionSchema->$key = $value;
							}
						}

						return $unionSchema;
					}//end if

					// For non-object properties, just return the resolved schema.
					// But preserve any additional properties from the original.
					foreach ($propertySchema as $key => $value) {
						if ($key !== '$ref') {
							$resolvedSchema->$key = $value;
						}
					}

					return $resolvedSchema;
				}//end if
			}//end if
		}//end if

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Multiple OpenRegister configuration scenarios
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Various property transformation paths
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
		$uuidPat = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';

		// Handle inversedBy relationships for validation.
		// TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
		if (($propertySchema->inversedBy ?? null) !== null && $propertySchema->inversedBy !== '') {
			// Check if this is an array property.
			$isArrayType = ($propertySchema->type ?? null) !== null
				&& $propertySchema->type === 'array';
			if ($isArrayType === true) {
				// For inversedBy array properties, allow objects or UUIDs
				// (pre-validation cascading will handle transformation).
				$propertySchema->items = (object)[
					'oneOf' => [
						(object)[
							'type' => 'string',
							'pattern' => $uuidPat,
							'description' => 'UUID reference to a related object',
						],
						(object)[
							'type' => 'object',
							'description' => 'Nested object that will be created separately',
						],
					],
				];
			} elseif (($propertySchema->type ?? null) !== null
				&& $propertySchema->type === 'object'
			) {
				// For inversedBy object properties, allow objects, UUIDs, or null
				// (pre-validation cascading will handle transformation).
				$propertySchema->oneOf = [
					(object)[
						'type' => 'null',
						'description' => 'No related object (inversedBy - managed by other side)',
					],
					(object)[
						'type' => 'string',
						'pattern' => $uuidPat,
						'description' => 'UUID reference to a related object',
					],
					(object)[
						'type' => 'object',
						'description' => 'Nested object that will be created separately',
					],
				];
				unset(
					$propertySchema->type,
					$propertySchema->pattern,
					$propertySchema->properties,
					$propertySchema->required,
					$propertySchema->{'$ref'}
				);
			}//end if
		}//end if

		// Handle array properties with object items (inlined from transformArrayItemsForOpenRegister).
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
			$itemsSchema = $propertySchema->items;

			// Same normalisation as the property itself — see dropUnusableRef().
			$this->dropUnusableRef(schema: $itemsSchema);

			// Handle inversedBy relationships for array items.
			// TODO: Move writeBack, removeAfterWriteBack, and inversedBy from items to config.
			if (($itemsSchema->inversedBy ?? null) !== null) {
				// For inversedBy array items, transform to UUID string validation.
				$itemsSchema->type = 'string';
				$itemsSchema->pattern = $uuidPat;
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
				$itemsSchema->pattern = $uuidPat;
				$itemsSchema->description = 'UUID reference to a related object';
				unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});
			} elseif (isset($itemsSchema->type) === true && $itemsSchema->type === 'object') {
				$this->transformObjectPropertyForOpenRegister(objectSchema: $itemsSchema);
			}//end if
		}//end if

		// Handle direct object properties.
		if (($propertySchema->type ?? null) !== null && $propertySchema->type === 'object') {
			$this->transformObjectPropertyForOpenRegister(objectSchema: $propertySchema);
		}

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

		// Recursively transform nested properties.
		if (($propertySchema->properties ?? null) !== null) {
			foreach ($propertySchema->properties ?? [] as $nestedPropertyName => $nestedPropertySchema) {
				// Suppress unused variable warning for $nestedPropertyName - only processing schemas.
				unset($nestedPropertyName);
				// Ensure nested property schema is an object (deeply nested JSON may decode as array).
				if (is_array($nestedPropertySchema) === true) {
					$nestedPropertySchema = (object)$nestedPropertySchema;
				}

				$this->transformPropertyForOpenRegister(propertySchema: $nestedPropertySchema);
			}
		}
	}//end transformPropertyForOpenRegister()

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
		$uuidPat = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';

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
	 * Transforms schema for validation by handling circular references, OpenRegister configurations, and schema resolution.
	 *
	 * This function combines all schema transformation steps into a single method:
	 * 1. Detects and transforms circular references (self-references)
	 * 2. Transforms OpenRegister-specific object configurations
	 * 3. Resolves schema references
	 *
	 * @param object $schemaObject The schema object to transform
	 * @param array $object The object data to transform
	 * @param string $currentSchemaSlug The current schema slug to detect self-references
	 *
	 * @return (array|object)[] Array containing [transformedSchema, transformedObject]
	 *
	 * @psalm-return list{object, array}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex schema transformation with multiple scenarios
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive schema transformation logic
	 * @SuppressWarnings(PHPMD.StaticAccess)          ObjectHandling::relates is a stateless enum-style helper
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function transformSchemaForValidation(object $schemaObject, array $object, string $currentSchemaSlug): array {

		if (isset($schemaObject->properties) === false) {
			return [$schemaObject, $object];
		}

		$propertiesArray = (array)$schemaObject->properties;
		// Step 1: Handle circular references.
		foreach ($propertiesArray as $propertyName => $propertySchema) {
			// Suppress unused variable warning for $propertyName - only processing schemas.
			unset($propertyName);
			// Check if this property has a $ref that references the current schema.
			if ($this->isSelfReference(propertySchema: $propertySchema, schemaSlug: $currentSchemaSlug) === true) {
				// Check if this is a related-object with objectConfiguration.
				// Handle both array and object formats for objectConfiguration.
				$config = $propertySchema->objectConfiguration ?? null;
				$handling = null;
				if (is_array($config) === true && isset($config['handling']) === true) {
					$handling = $config['handling'];
				} elseif (is_object($config) === true && isset($config->handling) === true) {
					$handling = $config->handling;
				}

				// UUID pattern for related object references.
				$uuidPat = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';

				if ($config !== null && ObjectHandling::relates($handling) === true) {
					// Handle inversedBy relationships for single objects.
					if (($propertySchema->inversedBy ?? null) !== null) {
						// For inversedBy properties, allow objects, UUIDs, or null
						// (pre-validation cascading will handle transformation).
						$propertySchema->oneOf = [
							(object)[
								'type' => 'null',
								'description' => 'No related object (inversedBy - managed by other side)',
							],
							(object)[
								'type' => 'string',
								'pattern' => $uuidPat,
								'description' => 'UUID reference to a related object',
							],
							(object)[
								'type' => 'object',
								'description' => 'Nested object that will be created separately',
							],
						];
						unset($propertySchema->type, $propertySchema->pattern);
					}//end if

					if (($propertySchema->inversedBy ?? null) === null) {
						// For non-inversedBy properties, expect string UUID.
						// Support prefixed UUIDs, UUIDs without dashes,
						// and numeric IDs.
						$uuidPattern = $uuidPat;
						$propertySchema->type = 'string';
						$propertySchema->pattern = $uuidPattern;
						$desc = 'UUID reference to a related object (self-reference)';
						$propertySchema->description = $desc;
					}//end if

					unset($propertySchema->properties, $propertySchema->required, $propertySchema->{'$ref'});
				} elseif (($propertySchema->type ?? null) !== null
					&& $propertySchema->type === 'array'
					&& (($propertySchema->items ?? null) !== null) === true
					&& is_object($propertySchema->items) === true
					&& $this->isSelfReference(
						propertySchema: $propertySchema->items,
						schemaSlug: $currentSchemaSlug
					) === true
				) {
					// Check if array items are self-referencing.
					$propertySchema->type = 'array';

					// Handle inversedBy relationships differently for validation.
					if (($propertySchema->items->inversedBy ?? null) !== null) {
						// For inversedBy properties, allow objects or UUIDs
						// (pre-validation cascading will handle transformation).
						$propertySchema->type = 'array';
						$propertySchema->items = (object)[
							'oneOf' => [
								(object)[
									'type' => 'string',
									'pattern' => $uuidPat,
									'description' => 'UUID reference to a related object',
								],
								(object)[
									'type' => 'object',
									'description' => 'Nested object that will be created separately',
								],
							],
						];
					}//end if

					if (($propertySchema->items->inversedBy ?? null) === null) {
						// For non-inversedBy properties, expect array of UUIDs.
						$propertySchema->items = (object)[
							'type' => 'string',
							'pattern' => $uuidPat,
							'description' => 'UUID reference to a related object (self-reference)',
						];
					}//end if

					unset($propertySchema->{'$ref'});

					// Ensure items has a valid schema after transformation.
					if (isset($propertySchema->items->type) === false && isset($propertySchema->items->oneOf) === false) {
						$propertySchema->items->type = 'string';
					}
				}//end if

				// Remove the $ref to prevent circular validation issues.
				unset($propertySchema->{'$ref'});
			}//end if
		}//end foreach

		// Step 2: Transform OpenRegister-specific object configurations.
		$schemaObject = $this->transformOpenRegisterObjectConfigurations(schemaObject: $schemaObject);

		// Step 3: Remove $id property to prevent duplicate schema ID errors.
		if (($schemaObject->{'$id'} ?? null) !== null) {
			unset($schemaObject->{'$id'});
		}

		// Step 4: Pre-process the schema to resolve all schema references (but skip UUID-transformed properties).
		// Temporarily disable schema resolution to see if that's causing the duplicate schema ID issue.
		// $schemaObject = $this->preprocessSchemaReferences($schemaObject, [], true);.
		return [$schemaObject, $object];
	}//end transformSchemaForValidation()

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

		// Clone the schema object to avoid modifying the original.
		$cleanedSchema = json_decode(json_encode($schemaObject));

		// Remove Nextcloud-specific metadata properties.
		$metadataProperties = [
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
		];

		foreach ($metadataProperties as $property) {
			if (($cleanedSchema->$property ?? null) !== null) {
				unset($cleanedSchema->$property);
			}
		}

		// Handle properties recursively.
		if (($cleanedSchema->properties ?? null) !== null) {
			foreach ($cleanedSchema->properties as $propertyName => $propertySchema) {
				$cleanedSchema->properties->$propertyName = $this->cleanPropertyForValidation(
					propertySchema: $propertySchema,
					isArrayItems: false
				);
			}
		}

		// Handle array items - this is where the distinction matters.
		if (($cleanedSchema->items ?? null) !== null) {
			$cleanedSchema->items = $this->cleanPropertyForValidation(
				propertySchema: $cleanedSchema->items,
				isArrayItems: true
			);
		}

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
		// Handle non-object properties.
		if (is_object($propertySchema) === false) {
			return $propertySchema;
		}

		// Clone to avoid modifying original.
		$cleanedProperty = json_decode(json_encode($propertySchema));

		// Remove Nextcloud-specific metadata properties.
		$metadataProperties = [
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
		];

		foreach ($metadataProperties as $property) {
			if (($cleanedProperty->$property ?? null) !== null) {
				unset($cleanedProperty->$property);
			}
		}

		// Transform custom OpenRegister types to valid JSON Schema types.
		// JSON Schema only allows: string, number, integer, boolean, array, object, null.
		$cleanedProperty = $this->transformCustomTypeToJsonSchemaType(propertySchema: $cleanedProperty);

		// Special handling for array items - more aggressive transformation.
		if ($isArrayItems === true) {
			return $this->transformArrayItemsForValidation(itemsSchema: $cleanedProperty);
		}

		// Handle nested properties recursively.
		if (($cleanedProperty->properties ?? null) !== null) {
			foreach ($cleanedProperty->properties as $nestedPropertyName => $nestedPropertySchema) {
				$cleanedProperty->properties->$nestedPropertyName = $this->cleanPropertyForValidation(
					propertySchema: $nestedPropertySchema,
					isArrayItems: false
				);
			}
		}

		// Handle nested array items.
		if (($cleanedProperty->items ?? null) !== null) {
			$cleanedProperty->items = $this->cleanPropertyForValidation(
				propertySchema: $cleanedProperty->items,
				isArrayItems: true
			);
		}

		// Fix misplaced enum and oneOf on array types by moving them to items level.
		$cleanedProperty = $this->fixMisplacedArrayConstraints(propertySchema: $cleanedProperty);

		return $cleanedProperty;
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

		// Fix misplaced enum: move from array level to items level.
		if (($propertySchema->enum ?? null) !== null
			&& is_array($propertySchema->enum) === true
			&& empty($propertySchema->enum) === false
		) {
			// Ensure items object exists.
			if (($propertySchema->items ?? null) === null) {
				$propertySchema->items = new stdClass();
				$propertySchema->items->type = 'string';
			}

			// Move enum to items (only if items doesn't already have an enum).
			if (($propertySchema->items->enum ?? null) === null) {
				$propertySchema->items->enum = $propertySchema->enum;
			}

			// Remove enum from array level.
			unset($propertySchema->enum);
		}

		// Fix misplaced oneOf: move from array level to items level.
		if (($propertySchema->oneOf ?? null) !== null
			&& (is_array($propertySchema->oneOf) === true || is_object($propertySchema->oneOf) === true)
		) {
			$oneOfArray = $propertySchema->oneOf;
			if (is_object($propertySchema->oneOf) === true) {
				$oneOfArray = get_object_vars($propertySchema->oneOf);
			}

			if (empty($oneOfArray) === false) {
				// Ensure items object exists.
				if (($propertySchema->items ?? null) === null) {
					$propertySchema->items = new stdClass();
				}

				// Move oneOf to items (only if items doesn't already have oneOf).
				if (($propertySchema->items->oneOf ?? null) === null) {
					$propertySchema->items->oneOf = $propertySchema->oneOf;
				}

				// Remove oneOf from array level.
				unset($propertySchema->oneOf);
			}
		}//end if

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
		// Map of custom OpenRegister types to their JSON Schema equivalents.
		$customTypeMap = [
			'file' => ['integer', 'string', 'null'],
			// File references are stored as integer file IDs, string data URIs, or null.
			'datetime' => 'string',
			// Datetime values are stored as ISO 8601 strings.
			'date' => 'string',
			// Date values are stored as strings.
			'time' => 'string',
			// Time values are stored as strings.
			'uuid' => 'string',
			// UUIDs are strings.
			'url' => 'string',
			// URLs are strings.
			'email' => 'string',
			// Emails are strings.
			'phone' => 'string',
			// Phone numbers are strings.
			'color' => 'string',
			// Colour values (hex/rgba/oklch) are stored as literal strings.
			'recurrence' => 'string',
			// RFC 5545 RRULE recurrence patterns are stored as literal strings.
		];

		// Check if type is set and needs transformation.
		if (isset($propertySchema->type) === false) {
			return $propertySchema;
		}

		$type = $propertySchema->type;

		// Handle single type as string.
		if (is_string($type) === true && isset($customTypeMap[$type]) === true) {
			$propertySchema->type = $customTypeMap[$type];
		}

		// Handle type as array (e.g., ["file", "null"]).
		if (is_array($type) === true) {
			$propertySchema->type = array_map(
				function ($t) use ($customTypeMap) {
					return $customTypeMap[$t] ?? $t;
				},
				$type
			);
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

		// Check if this has objectConfiguration to determine handling.
		// Handle both array and object formats for objectConfiguration.
		$config = $itemsSchema->objectConfiguration ?? null;
		$handling = null;
		if (is_array($config) === true && isset($config['handling']) === true) {
			$handling = $config['handling'];
		} elseif (is_object($config) === true && isset($config->handling) === true) {
			$handling = $config->handling;
		}

		// Relation markers are the only signal that these items describe a reference to
		// another object rather than an inline value object: explicit objectConfiguration
		// handling, or a $ref pointing at another schema.
		$hasRelationMarkers = ($config !== null && $handling !== null) || (($itemsSchema->{'$ref'} ?? null) !== null);

		// Inline value-object arrays (e.g. [{register, label}]) declare their own properties
		// and carry no relation markers at all. They are not references to other objects, so
		// they must be left untouched - replacing their properties with a bare {id} stub while
		// an authored `additionalProperties: false` survives the clean would reject every one
		// of the schema's own sub-properties. See or#290.
		if ($hasRelationMarkers === false && isset($itemsSchema->properties) === true) {
			return $itemsSchema;
		}

		// Determine whether to use UUID strings or simple object structure.
		// UUID strings: related-object handling, $ref references, or unknown handling types.
		// Simple object: nested-object handling or no configuration and no $ref.
		$useUuidStrings = false;
		if ($config !== null && $handling !== null) {
			$useUuidStrings = ($handling !== 'nested-object');
		} elseif (($itemsSchema->{'$ref'} ?? null) !== null) {
			$useUuidStrings = true;
		}

		if ($useUuidStrings === true) {
			// Transform to accept both UUID strings and objects with id field.
			// Remove all object-specific properties.
			unset($itemsSchema->properties, $itemsSchema->required, $itemsSchema->{'$ref'});

			// UUID pattern for string validation.
			$uuidPattern = '^([a-z]+-)?([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{32}|[0-9]+)$';

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
		}//end if

		// Transform to a simple object structure for nested objects.
		// Remove $ref to prevent circular references.
		unset($itemsSchema->{'$ref'});

		// Create a simple object structure.
		$itemsSchema->type = 'object';
		$itemsSchema->description = 'Nested object';

		// Add basic properties that most objects should have.
		$itemsSchema->properties = (object)[
			'id' => (object)[
				'type' => 'string',
				'description' => 'Object identifier',
			],
		];

		return $itemsSchema;
	}//end transformArrayItemsForValidation()

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
			$schema = $this->schemaMapper->find($slug);
			if ($schema !== null) {
				return $schema;
			}
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
	 * Validates an object against a schema.
	 *
	 * @param array $object The object to validate.
	 * @param Schema|int|null $schema The schema or schema ID to validate against.
	 * @param object $schemaObject A custom schema object for validation.
	 * @param int $_depth The depth level for validation (unused).
	 *
	 * @return ValidationResult The result of the validation.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Comprehensive validation with many edge case handlers
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple validation scenarios and schema types
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Complete validation logic requires extensive handling
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function validateObject(
		array $object,
		Schema|int|string|null $schema = null,
		object $schemaObject = new stdClass(),
		int $_depth = 0,
	): ValidationResult {

		// Resolve a schema id to its entity once so downstream steps (unique-field
		// validation, prepared-schema caching) always work with the entity.
		// SchemaMapper::find() memoizes lookups in its own findCache.
		if ($schema !== null && ($schema instanceof Schema) === false) {
			$schema = $this->schemaMapper->find($schema);
		}

		// Use == because === will never be true when comparing stdClass-instances.
		// Phpcs:ignore Squiz.Operators.ComparisonOperatorUsage.NotAllowed
		$useDefaultSchema = ($schemaObject == new stdClass());
		if ($useDefaultSchema === true && $schema instanceof Schema) {
			$schemaObject = $schema->getSchemaObject($this->urlGenerator);
		}

		if ($schema instanceof Schema) {
			$this->validateUniqueFields(object: $object, schema: $schema);
		}

		// Validate extended field types (color, recurrence) against the original,
		// un-transformed schema so the declared `type`/`format` annotations are intact.
		$this->validateExtendedFieldTypes(object: $object, schemaObject: $schemaObject);

		// Run the per-schema preparation pipeline (transform, clean, computed strip,
		// null-type widening) — memoized per schemaId:version so bulk validation of
		// N objects against one schema prepares it once instead of N times. A custom
		// caller-supplied $schemaObject bypasses the cache (no stable cache key).
		$cacheKey = null;
		$prepared = null;
		if ($useDefaultSchema === true && $schema instanceof Schema) {
			$cacheKey = ((string)$schema->getId()) . ':' . ((string)$schema->getVersion());
			$prepared = ($this->preparedSchemaCache[$cacheKey] ?? null);
		}

		if ($prepared === null) {
			$prepared = $this->prepareSchemaForValidation(schemaObject: $schemaObject, schema: $schema);
			if ($cacheKey !== null) {
				$this->preparedSchemaCache[$cacheKey] = $prepared;
			}
		}

		$schemaObject = $prepared['schemaObject'];

		// If there are no properties, we don't need to validate.
		// Skip validation ONLY if properties are NOT set OR if properties are empty.
		if (isset($schemaObject->properties) === false || empty($schemaObject->properties) === true) {
			// Validate against an empty schema object to get a valid ValidationResult.
			return $this->getValidator()->validate(json_decode(json_encode($object)), new stdClass());
		}

		// @todo This should be done earlier.
		unset($object['extend'], $object['filters']);

		// Remove computed properties from input data.
		// Computed fields are system-generated and should not be validated against user input.
		foreach ($prepared['computed'] as $computedProperty) {
			unset($object[$computedProperty]);
		}

		// Remove only truly empty values that have no validation significance.
		// Keep empty strings for required fields so they can fail validation with proper error messages.
		$requiredFields = $prepared['required'];
		$object = array_filter(
			$object,
			function ($value, $key) use ($requiredFields, $schemaObject) {
				// Always keep required fields, even if they're empty strings (they should fail validation).
				if (in_array($key, $requiredFields) === true) {
					return true;
				}

				// Check if this is an enum field.
				$propertySchema = $schemaObject->properties->$key ?? null;
				if (($propertySchema !== null) === true
					&& (($propertySchema->enum ?? null) !== null) === true
					&& is_array($propertySchema->enum) === true
				) {
					// For enum fields, only keep null if it's explicitly allowed in the enum.
					if ($value === null && in_array(null, $propertySchema->enum) === false) {
						return false;
						// Remove null values for enum fields that don't allow null.
					}
				}

				// For non-required fields, filter out empty arrays ONLY if they have no validation constraints.
				// Keep empty arrays if they have minItems, maxItems, or other array validation rules.
				if (is_array($value) === true && empty($value) === true) {
					// Check if this field has array validation constraints.
					if (($propertySchema !== null) === true) {
						$hasMinItems = isset($propertySchema->minItems) && $propertySchema->minItems > 0;
						$hasMaxItems = isset($propertySchema->maxItems);
						$hasUniqueItems = isset($propertySchema->uniqueItems) && $propertySchema->uniqueItems === true;

						// Keep empty arrays if they have validation constraints (should fail validation).
						if ($hasMinItems === true || $hasMaxItems === true || $hasUniqueItems === true) {
							return true;
						}
					}

					return false;
					// Remove empty arrays for non-required fields without validation constraints.
				}

				if ($value === '') {
					return false;
					// Remove empty strings for non-required fields.
				}

				// Keep everything else (including null, 0, false, etc.).
				return true;
			},
			ARRAY_FILTER_USE_BOTH
		);

		return $this->getValidator()->validate(json_decode(json_encode($object)), $schemaObject);
	}//end validateObject()

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
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential schema mutations with per-property branching
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function prepareSchemaForValidation(object $schemaObject, ?Schema $schema): array {
		// Get the current schema slug for circular reference detection.
		$currentSchemaSlug = '';
		if ($schema instanceof Schema) {
			$currentSchemaSlug = $schema->getSlug();
		}

		// Transform schema for validation (handles circular references, OpenRegister configs, and schema resolution).
		[$schemaObject] = $this->transformSchemaForValidation(
			schemaObject: $schemaObject,
			object: [],
			currentSchemaSlug: $currentSchemaSlug
		);

		// Collect computed property names BEFORE cleaning — the cleaning step
		// strips the per-property `computed` marker, which previously made
		// computed-property removal unreachable (the old code inspected the
		// already-cleaned schema and never found a `computed` key).
		$computedProperties = [];
		if (($schemaObject->properties ?? null) !== null) {
			foreach ($schemaObject->properties as $propName => $propSchema) {
				if (is_object($propSchema) === true && ($propSchema->computed ?? null) !== null) {
					$computedProperties[] = $propName;
				}
			}
		}

		// Clean the schema by removing all Nextcloud-specific metadata properties.
		$schemaObject = $this->cleanSchemaForValidation(schemaObject: $schemaObject);

		// If schemaObject required is empty unset it.
		if (($schemaObject->required ?? null) !== null && empty($schemaObject->required) === true) {
			unset($schemaObject->required);
		}

		// Strip computed properties from the required list — computed fields
		// are system-generated and can't be required from user input.
		foreach ($computedProperties as $propName) {
			if (is_array($schemaObject->required ?? null) === true) {
				$reqKey = array_search($propName, $schemaObject->required, true);
				if ($reqKey !== false) {
					unset($schemaObject->required[$reqKey]);
					$schemaObject->required = array_values($schemaObject->required);
				}
			}
		}

		$requiredFields = ($schemaObject->required ?? []);

		/*
		 * Modify schema to allow null values for non-required fields.
		 * This ensures that null values are valid for optional fields.
		 * @psalm-suppress NoValue
		 */

		if (property_exists($schemaObject, 'properties') === true) {
			$properties = $schemaObject->properties;

			// Handle properties object.
			if (isset($properties) === true && is_object($properties) === true) {
				foreach ($properties as $propertyName => $propertySchema) {
					// Skip required fields - they should not allow null unless explicitly defined.
					if (in_array($propertyName, $requiredFields) === true) {
						continue;
					}

					// Special handling for enum fields - only allow null if not explicitly defined in enum.
					if (($propertySchema->enum ?? null) !== null && is_array($propertySchema->enum) === true) {
						// If enum doesn't include null, don't add it automatically.
						// Enum fields should be either set to a valid enum value or omitted entirely.
						if (in_array(null, $propertySchema->enum, true) === false) {
							continue;
						}
					}

					// For non-required fields, allow null values by modifying the type.
					if (($propertySchema->type ?? null) !== null && is_string($propertySchema->type) === true) {
						// Convert single type to array with null support.
						$propertySchema->type = [$propertySchema->type, 'null'];
					} elseif (($propertySchema->type ?? null) !== null && is_array($propertySchema->type) === true) {
						// Add null to existing type array if not already present.
						if (in_array('null', $propertySchema->type, true) === false) {
							$propertySchema->type[] = 'null';
						}
					}
				}//end foreach
			}//end if
		}//end if

		// Translatable properties accept EITHER the scalar value (source-language
		// / legacy write, or the X-Translation-Target-Language wrap path) OR a
		// language-keyed object like {"nl": "Welkom", "en": "Welcome"}. The scalar
		// branch is validated by the original (null-widened) property schema; the
		// language-map branch validates every inner language value against that
		// same scalar schema via additionalProperties. Without this widening a
		// language-keyed write body is rejected by plain type validation (object
		// vs string) BEFORE TranslationHandler::normalizeTranslationsForSave() can
		// split it into per-language translation rows — which broke the entire
		// i18n write path (see i18n-api-language-negotiation / i18n-source-of-truth).
		if (property_exists($schemaObject, 'properties') === true
			&& is_object($schemaObject->properties) === true
		) {
			foreach ($schemaObject->properties as $propertyName => $propertySchema) {
				if (is_object($propertySchema) === false) {
					continue;
				}

				if (($propertySchema->translatable ?? null) !== true) {
					continue;
				}

				// Scalar branch: the property schema minus the custom marker.
				$valueSchema = json_decode(json_encode($propertySchema));
				unset($valueSchema->translatable);

				// Language-map branch: an object whose every value matches the
				// scalar schema (so language values are still type-checked).
				$languageObjectSchema = (object)[
					'type' => 'object',
					'additionalProperties' => json_decode(json_encode($valueSchema)),
				];

				$schemaObject->properties->$propertyName = (object)[
					'anyOf' => [
						$valueSchema,
						$languageObjectSchema,
					],
				];
			}//end foreach
		}//end if

		return [
			'schemaObject' => $schemaObject,
			'computed' => $computedProperties,
			'required' => $requiredFields,
		];
	}//end prepareSchemaForValidation()

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

		// Register custom format validators using our helper method that supports named parameters.
		$this->registerCustomFormat(validator: $validator, type: 'string', format: 'bsn', resolver: new BsnFormat());
		$this->registerCustomFormat(validator: $validator, type: 'string', format: 'semver', resolver: new SemVerFormat());
		$this->registerCustomFormat(
			validator: $validator,
			type: 'string',
			format: 'user',
			resolver: new UserFormat(userManager: $this->userManager)
		);

		// Accept ISO 8601 date-time input (optional seconds/timezone), overriding the
		// opis built-in date-time format whose regex mandates seconds. Storage still
		// normalises to a DATETIME column and reads emit RFC 3339.
		$this->registerCustomFormat(validator: $validator, type: 'string', format: 'date-time', resolver: new Iso8601DateTimeFormat());

		$validator->loader()->resolver()->registerProtocol('http', [$this, 'resolveSchema']);

		$this->validator = $validator;

		return $validator;
	}//end getValidator()

	/**
	 * Register a custom format validator with named parameters support
	 *
	 * This helper method wraps the Opis\JsonSchema FormatResolver::register() method
	 * to support named parameters, maintaining consistency with our codebase style.
	 *
	 * @param Validator $validator The validator instance
	 * @param string $type The data type (e.g., 'string', 'number')
	 * @param string $format The format name (e.g., 'bsn', 'semver')
	 * @param object $resolver The format resolver instance
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function registerCustomFormat(Validator $validator, string $type, string $format, object $resolver): void {
		// The underlying library doesn't support named parameters, so we convert them here.
		$validator->parser()->getFormatResolver()->register($type, $format, $resolver);
	}//end registerCustomFormat()

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
		// Local schema resolution.
		if ($this->urlGenerator->getBaseUrl() === $uri->scheme() . '://' . $uri->host()
			&& str_contains($uri->path() ?? '', '/api/schemas') === true
		) {
			$exploded = explode('/', $uri->path() ?? '');
			$schema = $this->schemaMapper->find(end($exploded));

			return json_encode($schema->getSchemaObject($this->urlGenerator));
		}

		// File schema resolution.
		if ($this->urlGenerator->getBaseUrl() === $uri->scheme() . '://' . $uri->host()
			&& str_contains($uri->path(), '/api/files/schema') === true
		) {
			// Return a basic file schema object.
			// TODO: Implement proper file schema resolution.
			$fileSchema = (object)[
				'type' => 'object',
				'properties' => (object)[
					'id' => (object)['type' => 'integer'],
					'name' => (object)['type' => 'string'],
					'path' => (object)['type' => 'string'],
					'mimetype' => (object)['type' => 'string'],
					'size' => (object)['type' => 'integer'],
				],
			];
			return json_encode($fileSchema);
		}

		// External schema resolution.
		if ($this->config->getValueBool('openregister', 'allowExternalSchemas') === true) {
			$client = new Client();
			$result = $client->get(\GuzzleHttp\Psr7\Uri::fromParts($uri->components()));

			return $result->getBody()->getContents();
		}

		return '';
	}//end resolveSchema()

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
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Many validation error types require specific formatting
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive error formatting for all validation types
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	private function formatValidationError(\Opis\JsonSchema\Errors\ValidationError $error): string {
		$keyword = $error->keyword();
		$dataPath = $error->data()->fullPath();
		$value = $error->data()->value();
		$args = $error->args();

		// Build property path for better identification.
		$propertyPath = implode('.', $dataPath);
		if (empty($dataPath) === true) {
			$propertyPath = 'root';
		}

		switch ($keyword) {
			case 'required':
				$missing = $args['missing'] ?? [];
				if (is_array($missing) === true && count($missing) > 0) {
					if (count($missing) === 1) {
						$property = $missing[0];
						$hint = 'Please provide a value for this property or set it to null if allowed.';
						return "The required property ({$property}) is missing. {$hint}";
					}

					$missingList = implode(', ', $missing);
					$msg = "The required properties ({$missingList}) are missing. ";
					return $msg . 'Please provide values for these properties.';
				}
				return 'Required property is missing';
			case 'type':
				$expectedType = $args['expected'] ?? 'unknown';
				$actualType = $this->getValueType(value: $value);

				// Handle array type definitions (e.g., ["array"] or ["string", "null"]).
				if (is_array($expectedType) === true) {
					$expectedType = implode(' or ', $expectedType);
				}

				// Provide specific guidance for empty values.
				if ($expectedType === 'object' && (is_array($value) === true && empty($value) === true)) {
					$hint1 = 'For non-required object properties, set this to null to clear the field.';
					$hint2 = 'For required object properties, provide a valid object with the necessary properties.';
					return "Property '{$propertyPath}' expects object but got empty ({}). {$hint1} {$hint2}";
				}

				if ($expectedType === 'array' && (is_array($value) === true && empty($value) === true)) {
					$hint = 'This likely has a minItems constraint. Please provide at least one item.';
					return "Property '{$propertyPath}' expects non-empty array but got empty array ([]). {$hint}";
				}

				if ($expectedType === 'string' && $value === '') {
					$hint1 = 'For non-required string properties, set this to null to clear the field.';
					$hint2 = 'For required string properties, provide a valid string value.';
					return "Property '{$propertyPath}' expects non-empty string but got empty string. {$hint1} {$hint2}";
				}

				$hint = 'Please provide a value of the correct type.';
				return "Property '{$propertyPath}' should be type '{$expectedType}' but is '{$actualType}'. {$hint}";
			case 'minItems':
				$minItems = $args['min'] ?? 0;
				$actualItems = 0;
				if (is_array($value) === true) {
					$actualItems = count($value);
				}

				$hint = 'Please add more items to the array or set to null if the property is not required.';
				return "Property '{$propertyPath}' requires at least {$minItems} items, has {$actualItems}. {$hint}";
			case 'maxItems':
				$maxItems = $args['max'] ?? 0;
				$actualItems = 0;
				if (is_array($value) === true) {
					$actualItems = count($value);
				}

				$hint = 'Please remove some items from the array.';
				return "Property '{$propertyPath}' allows at most {$maxItems} items, has {$actualItems}. {$hint}";
			case 'format':
				$format = $args['format'] ?? 'unknown';
				$hint = 'Please provide a value in the correct format.';
				return "Property '{$propertyPath}' should match format '{$format}' but '{$value}' does not. {$hint}";
			case 'minLength':
				$minLength = $args['min'] ?? 0;
				$actualLength = 0;
				if (is_string($value) === true) {
					$actualLength = strlen($value);
				}

				if ($actualLength === 0) {
					$hint = 'Please provide a non-empty string value.';
					return "Property '{$propertyPath}' requires at least {$minLength} characters, but is empty. {$hint}";
				}

				$hint = 'Please provide a longer string value.';
				return "Property '{$propertyPath}' requires at least {$minLength} chars, has {$actualLength}. {$hint}";
			case 'maxLength':
				$maxLength = $args['max'] ?? 0;
				$actualLength = 0;
				if (is_string($value) === true) {
					$actualLength = strlen($value);
				}

				$hint = 'Please provide a shorter string value.';
				return "Property '{$propertyPath}' allows at most {$maxLength} chars, has {$actualLength}. {$hint}";
			case 'minimum':
				$minimum = $args['min'] ?? 0;
				$msg = "Property '{$propertyPath}' should be at least {$minimum}, ";
				return $msg . "but is {$value}. Please provide a larger number.";
			case 'maximum':
				$maximum = $args['max'] ?? 0;
				$msg = "Property '{$propertyPath}' should be at most {$maximum}, ";
				return $msg . "but is {$value}. Please provide a smaller number.";
			case 'enum':
				$allowedValues = $args['values'] ?? [];

				// ⚠️ OPIS PASSES NO ARGS FOR `enum`, so `$args['values']` is ALWAYS
				// empty and this message rendered as:
				//
				//   Property 'ticketType' should be one of: , but is 'contactmoment'.
				//   Please choose one of the allowed values.
				//
				// — an empty allowed-list, i.e. the one fact the reader needs is the
				// one it omits. `EnumKeyword::validate()` calls
				// `$this->error($schema, $context, 'enum', 'The data should match one
				// item from enum')` with no fourth argument, so there is nothing to
				// read; the values have to come from the SCHEMA instead.
				//
				// Measured 2026-08-17: an agent told "should be one of: , but is
				// 'sent'" concluded the enum was empty and that NO value could be
				// valid — a reasonable reading of that sentence, and wrong. It then
				// worked around a constraint that was correctly rejecting its input.
				// A self-correcting caller needs the list; so does a human.
				if ($allowedValues === []) {
					$schemaData = $error->schema()->info()->data();
					if (is_object($schemaData) === true
						&& isset($schemaData->enum) === true
						&& is_array($schemaData->enum) === true
					) {
						$allowedValues = $schemaData->enum;
					}
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
			case 'pattern':
				$pattern = $args['pattern'] ?? 'unknown';
				$hint = 'Please provide a value that matches the required pattern.';
				return "Property '{$propertyPath}' should match pattern '{$pattern}' but '{$value}' does not. {$hint}";
			default:
				// Check for sub-errors to provide more specific messages.
				$subErrors = $error->subErrors();
				if (empty($subErrors) === false) {
					return $this->formatValidationError(error: $subErrors[0]);
				}

				$msg = "Property '{$propertyPath}' failed validation for rule '{$keyword}'. ";
				return $msg . 'Please check the property value and schema requirements.';
		}//end switch
	}//end formatValidationError()

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
		$errors = [];
		if ($exception instanceof ValidationException === false) {
			foreach ($exception->getErrors() as $error) {
				$errors[] = $error;
			}

			return new JSONResponse(
				data: [
					'status' => 'error',
					'message' => 'Validation failed',
					'errors' => $errors,
				],
				statusCode: 400
			);
		}

		// The exception message should already be meaningful thanks to generateErrorMessage().
		$property = null;
		if (method_exists($exception, 'getProperty') === true) {
			$property = $exception->getProperty();
		}

		$errors[] = [
			'property' => $property,
			'message' => $exception->getMessage(),
			'errors' => (new ErrorFormatter())->format($exception->getErrors()),
		];

		return new JSONResponse(
			data: [
				'status' => 'error',
				'message' => 'Validation failed',
				'errors' => $errors,
			],
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
		$config = $schema->getConfiguration();
		$uniqueFields = $config['unique'] ?? null;

		// BUGFIX: Early return if no unique fields are configured.
		if (empty($uniqueFields) === true) {
			return;
		}

		$filters = [];
		if (is_array($uniqueFields) === true) {
			foreach ($uniqueFields as $field) {
				$filters[$field] = $object[$field];
			}
		} elseif (is_string($uniqueFields) === true) {
			$filters[$uniqueFields] = $object[$uniqueFields];
		}

		$count = $this->objectMapper->countAll(_filters: $filters, schema: $schema);

		if ($count !== 0) {
			// IMPROVED ERROR MESSAGE: Show which field(s) caused the uniqueness violation.
			$fieldNames = $uniqueFields;
			if (is_array($uniqueFields) === true) {
				$fieldNames = implode(', ', $uniqueFields);
			}

			if (is_array($uniqueFields) === true) {
				$fieldValues = implode(
					', ',
					array_map(
						function ($field) use ($object) {
							return $field . '=' . ($object[$field] ?? 'null');
						},
						$uniqueFields
					)
				);
				$errorName = (string)(array_shift($uniqueFields) ?? 'uniqueField');
			}

			if (is_array($uniqueFields) === false) {
				// Scalar field name only — guarding the concat here avoids an
				// "Array to string conversion" when $uniqueFields is an array.
				$fieldValues = $uniqueFields . '=' . ($object[$uniqueFields] ?? 'null');
				$errorName = (string)$uniqueFields;
			}

			$errMsg = "The identifying fields ({$fieldNames}) are not unique. ";
			$errMsg .= "Found duplicate values: {$fieldValues}";
			throw new CustomValidationException(
				message: "Fields are not unique: {$fieldNames} (values: {$fieldValues})",
				errors: [
					$errorName => $errMsg,
				]
			);
		}//end if
	}//end validateUniqueFields()

	/**
	 * Validate extended field-type values (`color`, `recurrence`).
	 *
	 * The base Opis validator only understands JSON-Schema primitives, so the
	 * extended types are mapped to `string` for structural validation. This hook
	 * performs the per-type value validation that the spec mandates and emits the
	 * exact 422 messages required by REQ-EFT-003 / REQ-EFT-005. It walks the
	 * schema's declared properties, and for each `color`/`recurrence` property
	 * present in the submitted object validates the value via the shared
	 * ExtendedFieldTypeValidator. `null` values are skipped (handled by the
	 * required/optional logic elsewhere).
	 *
	 * @param array $object The submitted object data.
	 * @param object $schemaObject The original, un-transformed schema object.
	 *
	 * @return void
	 *
	 * @throws CustomValidationException When a color/recurrence value is invalid.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Per-type dispatch over schema properties
	 *
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 * @spec openspec/changes/add-openregister-discovered-capabilities/specs/extended-field-types/spec.md
	 */
	private function validateExtendedFieldTypes(array $object, object $schemaObject): void {
		$properties = ($schemaObject->properties ?? null);
		if (is_object($properties) === false && is_array($properties) === false) {
			return;
		}

		$validator = new ExtendedFieldTypeValidator();

		foreach ($properties as $propertyName => $propertySchema) {
			// Property not present in the submitted object - nothing to validate.
			if (array_key_exists($propertyName, $object) === false) {
				continue;
			}

			$value = $object[$propertyName];
			if ($value === null) {
				continue;
			}

			// Read the declared type and format from the (possibly object/array) schema.
			$type = null;
			$format = null;
			if (is_object($propertySchema) === true) {
				$type = ($propertySchema->type ?? null);
				$format = ($propertySchema->format ?? null);
			} elseif (is_array($propertySchema) === true) {
				$type = ($propertySchema['type'] ?? null);
				$format = ($propertySchema['format'] ?? null);
			}

			$error = null;
			if ($type === 'color') {
				$error = $validator->validateColor(value: $value, format: $format, propertyName: (string)$propertyName);
			} elseif ($type === 'recurrence') {
				$error = $validator->validateRecurrence(value: $value, propertyName: (string)$propertyName);
			}

			if ($error !== null) {
				throw new CustomValidationException(
					message: $error,
					errors: [(string)$propertyName => $error]
				);
			}
		}//end foreach
	}//end validateExtendedFieldTypes()
}//end class
