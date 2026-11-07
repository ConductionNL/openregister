<?php

/**
 * SaveObjectInterface: the public surface of SaveObject, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\DuplicateBlockedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Exception\ObjectExistsException;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Rules\ExpressionDefaultException;
use OCP\IUser;

/**
 * Every public method of SaveObject. Callers depend on this interface, not on the class.
 */
interface SaveObjectInterface {

	/**
	 * Get sub-objects created during cascade operations.
	 *
	 * Returns an array of sub-objects indexed by their UUID, suitable for
	 * inclusion in the parent object's @self.objects property.
	 *
	 * @return array<string, array> Sub-objects indexed by UUID
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function getCreatedSubObjects(): array;

	/**
	 * Clear the created sub-objects cache.
	 *
	 * Should be called before processing a new parent object to ensure
	 * sub-objects from previous operations are not included.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clearCreatedSubObjects(): void;

	/**
	 * Clear all request-scoped caches.
	 *
	 * Should be called at the start of a new top-level save operation to ensure
	 * caches from previous operations don't interfere. This clears:
	 * - Created sub-objects cache
	 * - Schema entity cache
	 * - Register entity cache
	 * - Schema reference cache
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function clearAllCaches(): void;

	/**
	 * Track a created sub-object for inclusion in @self.objects.
	 *
	 * This method is called by CascadingHandler when creating related objects
	 * during pre-validation cascading.
	 *
	 * @param string $uuid The UUID of the created sub-object
	 * @param array $objectData The serialized object data
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function trackCreatedSubObject(string $uuid, array $objectData): void;

	/**
	 * Scans an object for relations (UUIDs and URLs) and returns them in dot notation
	 *
	 * This method now also checks schema properties for relation types:
	 * - Properties with type 'text' and format 'uuid', 'uri', or 'url'
	 * - Properties with type 'object' that contain string values (always treated as relations)
	 * - Properties with type 'array' of objects that contain string values
	 *
	 * @param array $data The object data to scan
	 * @param string $prefix The current prefix for dot notation (used in recursion)
	 * @param Schema|null $schema The schema to check property definitions against
	 *
	 * @return array Array of relations with dot notation paths as keys and UUIDs/URLs as values
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function scanForRelations(array $data, string $prefix = '', ?Schema $schema = null): array;

	/**
	 * Hydrates object metadata fields based on schema configuration.
	 *
	 * This method uses the schema configuration to set metadata fields on the object entity
	 * based on the object data. It supports:
	 * - Simple field mapping using dot notation paths (e.g., 'contact.email', 'title')
	 * - Twig-like concatenation for combining multiple fields (e.g., '{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}')
	 * - All metadata fields: name, description, summary, image, slug
	 *
	 * Schema configuration example:
	 * ```json
	 * {
	 *   "objectNameField": "{{ voornaam }} {{ tussenvoegsel }} {{ achternaam }}",
	 *   "objectDescriptionField": "beschrijving",
	 *   "objectSummaryField": "beschrijvingKort",
	 *   "objectImageField": "afbeelding",
	 *   "objectSlugField": "naam"
	 * }
	 * ```
	 *
	 * This method is public to support both individual saves and bulk save operations.
	 * During bulk imports, it's called from SaveObjects for each object to ensure consistent
	 * metadata extraction across all import paths.
	 *
	 * @param ObjectEntity $entity The entity to hydrate
	 * @param Schema $schema The schema containing the configuration
	 *
	 * @see website/docs/developers/import-flow.md for complete import flow documentation
	 * @see website/docs/core/schema.md for schema configuration details
	 *
	 * @return void
	 *
	 * @psalm-return   void
	 * @phpstan-return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  Complex metadata extraction from multiple sources
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Multiple field types and formats require branching
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) Comprehensive metadata hydration logic
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function hydrateObjectMetadata(ObjectEntity $entity, Schema $schema): void;

	/**
	 * Applies defaults with defaultBehavior: "always" BEFORE validation.
	 *
	 * This method is called from ObjectService before validation to ensure that
	 * computed/derived properties with defaultBehavior: "always" are set before
	 * validation runs. This allows properties like "dienstType" to be automatically
	 * populated from "type" even when the payload contains an invalid value.
	 *
	 * @param Schema $schema The schema containing property definitions.
	 * @param array $data The object data to transform.
	 *
	 * @return array The transformed data with "always" defaults applied.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Default value resolution requires template + type branching
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function applyAlwaysDefaults(Schema $schema, array $data): array;

	/**
	 * Evaluate the schema's expression defaults for an object being CREATED.
	 *
	 * Called from ObjectService beside {@see SaveObject::applyAlwaysDefaults()} and
	 * for the same reason: a derived value has to exist before validation, or
	 * a property that is both required and derived can never be created.
	 *
	 * CREATE ONLY. A default is what a value starts as. Re-deriving it on every
	 * update would silently overwrite what a caseworker typed, which is a
	 * calculation and is a different annotation.
	 *
	 * The resolver is built here from this handler's own session rather than
	 * injected, because adding a constructor parameter to this class would
	 * change the positional signature ten unit tests construct it by. It holds
	 * no state between calls, so building it per create costs an object.
	 *
	 * @param Schema $schema The schema being written against.
	 * @param array<string, mixed> $data The object being created.
	 *
	 * @return array<string, mixed> The object with the derived defaults written onto it.
	 *
	 * @throws ExpressionDefaultException When an expression cannot be evaluated.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function applyExpressionDefaults(Schema $schema, array $data): array;

	/**
	 * Auto-seed the lifecycle field from the parent on the CREATE path.
	 *
	 * When the schema declares an object-form `x-openregister-lifecycle.initial`
	 * (`{ from, field }`) and the lifecycle field is absent/null/empty, this
	 * resolves the parent reference from `data[initial.from]`, loads the parent
	 * through the standard read path (RBAC + multitenancy apply), and sets the
	 * lifecycle field to the parent's `initial.field` value. It runs BEFORE
	 * schema validation so a `required` lifecycle `$ref` field passes on a
	 * seeded create. Called from ObjectService only on the create path — never
	 * on update.
	 *
	 * Rules (design: `initial` object-form + auto-seed on create):
	 * - Empty-field-only: an explicitly provided value is NEVER overwritten.
	 * - Fail-soft no-op (debug log) when the parent ref is empty, the parent
	 *   cannot be loaded, or the parent's `initial.field` value is empty.
	 * - The legacy literal-string `initial` form is NOT auto-seeded (no static
	 *   behaviour change).
	 * - Dispatches no `ObjectTransitionedEvent` — this is initialisation.
	 *
	 * @param Schema $schema The schema whose annotation drives the seed.
	 * @param array $data The incoming object data (create payload).
	 *
	 * @return array The data, with the lifecycle field seeded when applicable.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Each guard is a distinct fail-soft no-op branch required by the spec.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function seedLifecycleFieldOnCreate(Schema $schema, array $data): array;

	/**
	 * Apply property default values to a data array (for use in bulk save paths).
	 *
	 * This is the public counterpart to setDefaultValues() that can work without
	 * an ObjectEntity. It applies defaults based on defaultBehavior settings and
	 * renders templates using MetadataHydrationHandler's processTwigLikeTemplate().
	 *
	 * @param Schema $schema The schema containing property definitions.
	 * @param array $data The object data to apply defaults to.
	 *
	 * @return array The data with defaults applied.
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function applyPropertyDefaults(Schema $schema, array $data): array;

	/**
	 * Saves an object.
	 *
	 * @param Register|int|string|null $register The register containing the object.
	 * @param Schema|int|string $schema The schema to validate against.
	 * @param array $data The object data to save.
	 * @param string|null $uuid The UUID of the object to update (if updating).
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 * @param bool $persist Whether to persist the object to database (default: true).
	 * @param bool $silent Whether to skip audit trail creation and events (default: false).
	 * @param bool $_validation Whether to validate the object (default: true).
	 * @param array|null $uploadedFiles Uploaded files array (optional).
	 * @param IUser|null $currentUser Explicit acting user for `@self.folder` access checks; falls back to the session user when null.
	 * @param bool $failIfExists Insert-only: throw ObjectExistsException rather than update when taken (default: false).
	 * @param bool $_unowned Stamp the system identity even when a session exists.
	 * @param bool $_dedupOverride Save through a blocking duplicate match, when the caller is entitled to.
	 * @param string|null $_owner An explicit owner for a new object, such as a draft's scope value (decision 195).
	 *
	 * @return ObjectEntity The saved object entity.
	 *
	 * @throws Exception If there is an error during save.
	 * @throws ObjectExistsException When $failIfExists is true and the identifier is already taken.
	 * @throws DuplicateBlockedException When the schema declares `onCreate: "block"`, the candidate
	 *         strongly matches a stored object, and the caller may not override.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Required for flexible save options
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)    Boolean flags needed for flexible save behavior
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function saveObject(
		Register|int|string|null $register, Schema|int|string $schema, array $data, ?string $uuid = null,
		bool $_rbac = true, bool $_multitenancy = true, bool $persist = true, bool $silent = false, bool $_validation = true,
		?array $uploadedFiles = null, ?IUser $currentUser = null, bool $failIfExists = false, bool $_unowned = false,
		bool $_dedupOverride = false, ?string $_owner = null
	): ObjectEntity;

	/**
	 * Record the permission mode of a save whose cascades are about to run.
	 *
	 * Paired with leaveCascadeScope() in a finally. A nested object created
	 * inside the scope is checked for create rights unless an enclosing save
	 * runs with RBAC off.
	 *
	 * @param bool $_rbac Whether the enclosing save applies RBAC.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC flag follows the save API
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function enterCascadeScope(bool $_rbac): void;

	/**
	 * Close the scope opened by the matching enterCascadeScope().
	 *
	 * @return void
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function leaveCascadeScope(): void;

	/**
	 * Refuse a nested object the caller may not create.
	 *
	 * A cascade creates every nested inline object as a new row of the
	 * property's target schema, so the caller needs create rights there,
	 * just as for a direct create. Skipped only when an enclosing save runs
	 * with RBAC off. With no enclosing save the check runs.
	 *
	 * @param Schema      $schema   The schema the nested object is created in.
	 * @param string|null $register The register it is created in, when known.
	 *
	 * @return void
	 *
	 * @throws NotAuthorizedException When the caller may not create objects in the schema.
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function assertMayCascadeCreate(Schema $schema, ?string $register = null): void;

	/**
	 * The slug handler this save pipeline uses, so the bulk path shares one instance.
	 *
	 * @return ObjectSlugHandler The handler.
	 *
	 * @spec openspec/changes/object-slugs-configurable-and-unique/specs/object-lifecycle/spec.md#requirement-bulk-save-must-apply-the-same-slug-rules-as-a-single-save
	 */
	public function getSlugHandler(): ObjectSlugHandler;

	/**
	 * Clear the request-scoped reference-existence cache.
	 *
	 * Provided for long-running CLI processes (e.g. background jobs that
	 * iterate over hundreds of objects) that should not let cached
	 * verdicts leak across logical request boundaries.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function clearReferenceValidationCache(): void;

	/**
	 * Streaming bulk-upsert primitive — closes 2c on the
	 * `reference-existence-validation` change.
	 *
	 * Iterates `$rows` one-at-a-time through the standard
	 * `saveObject()` write path (NOT through MagicMapper's
	 * ultraFastBulkSave) so the request-scoped reference-validation
	 * cache is engaged for every row. The cache reduces per-row
	 * reference checks to O(1) lookup against the in-memory map for
	 * the second-and-subsequent occurrences of any given target UUID,
	 * eliminating the N×M database round-trips that the unstreamed
	 * bulk path incurs.
	 *
	 * The returned `BatchOperationStatus` aggregates per-row
	 * outcomes (created/updated/unchanged/failed) plus reference-
	 * cache hit/miss counters, suitable for surfacing as a job
	 * dashboard entry, a streaming response body, or telemetry.
	 *
	 * Failure isolation: per-row exceptions are caught and recorded
	 * on the status; the batch continues. Callers that need
	 * fail-fast semantics can iterate the `failed` array on the
	 * returned status and abort early.
	 *
	 * Out of scope (this is the prerequisite, not the feature):
	 * async dispatch of the streaming loop. Async post-save
	 * re-validation is sequenced after this primitive lands and is
	 * tracked separately.
	 *
	 * @param Register|null $register Register context for the batch.
	 * @param Schema|int|string|null $schema Schema context for the batch.
	 * @param iterable $rows Input rows (each is the same
	 *                       shape `saveObject` accepts).
	 * @param BatchOperationStatus|null $status Optional pre-existing status
	 *                                          (for callers that want to
	 *                                          accumulate across multiple
	 *                                          calls).
	 *
	 * @return BatchOperationStatus
	 *
	 * @spec openspec/archive/retrofit-annotate-openregister-2026-04-23/tasks.md
	 */
	public function saveObjectsStreaming(
		Register|int|string|null $register, Schema|int|string|null $schema, iterable $rows, ?BatchOperationStatus $status = null
	): BatchOperationStatus;

	/**
	 * Updates an existing object.
	 *
	 * @param Register|int|string $register The register containing the object.
	 * @param Schema|int|string $schema The schema to validate against.
	 * @param array $data The updated object data.
	 * @param ObjectEntity $existingObject The existing object to update.
	 * @param bool $silent Whether to skip audit trail creation and events (default: false).
	 * @param ObjectEntity|null $oldObject The original object before changes (optional).
	 *
	 * @return ObjectEntity The updated object entity.
	 *
	 * @throws Exception If there is an error during update.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Silent flag needed for audit trail control
	 *
	 * @spec openspec/archive/retrofit-object-lifecycle-2026-04-28/tasks.md
	 */
	public function updateObject(
		Register|int|string $register, Schema|int|string $schema, array $data, ObjectEntity $existingObject,
		bool $silent = false, ?ObjectEntity $oldObject = null
	): ObjectEntity;
}//end interface
