<?php

/**
 * SchemaMapperInterface: the public surface of SchemaMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Exception\ValidationException;
use OCP\AppFramework\Db\Entity;

/**
 * Every public method of SchemaMapper. Callers depend on this interface, not on the class.
 */
interface SchemaMapperInterface {

	/**
	 * Finds a schema by id, with optional extension for statistics
	 *
	 * This method automatically resolves schema extensions. If the schema has
	 * an 'extend' property set, it will load the parent schema and merge its
	 * properties with the current schema, providing the complete resolved schema.
	 *
	 * @param int|string $id The id of the schema
	 * @param array $_extend Optional array of extensions (e.g., ['@self.stats'])
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *                            Set to false to bypass organization filter
	 *                            (e.g., when expanding schemas for registers)
	 *
	 * @return Schema The schema, possibly with stats and resolved extensions
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 * @SuppressWarnings(PHPMD.StaticAccess)          Schema::fromRow is a standard entity factory pattern
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function find(string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema;

	/**
	 * Resolve a schema by slug, scoped to a single owning application.
	 *
	 * The plain {@see find()} matches a schema by `LOWER(slug)` GLOBALLY across
	 * every application on the instance and returns the FIRST row it fetches.
	 * On a shared instance where ~20 apps live in one OpenRegister, that lets a
	 * generic slug (e.g. `conversation`, `order`, `task`) from app B silently
	 * bind to — or, on import, OVERWRITE — app A's schema that happens to share
	 * the lower(slug). This scoped lookup is the import-side fix: an app only
	 * ever matches (and therefore updates) a schema IT owns, so importing a
	 * colliding slug creates the app's OWN schema instead of clobbering a
	 * foreign one.
	 *
	 * @param string $slug The schema slug (matched case-insensitively).
	 * @param string $application The owning application id (exact match).
	 *
	 * @return Schema|null The application-owned schema, or null when the app
	 *                     does not (yet) own a schema with this slug.
	 */
	public function findByApplicationAndSlug(string $slug, string $application): ?Schema;

	/**
	 * EVERY schema an application owns under a slug, not just the first.
	 *
	 * `findByApplicationAndSlug()` caps at one row, which is correct where the
	 * pair is treated as unique. It is not unique in practice: the import unions
	 * schema ids and never removes one, so a descriptor edit that renames or
	 * retires a schema leaves the old row behind and a later import can add a
	 * second under the same pair.
	 *
	 * That is exactly the situation `openregister:schemas:prune-retired` exists
	 * to clean up, and reading one row per run meant it removed one of two and
	 * reported success. Measured on a live instance: opencatalogi owned two
	 * `document` rows (ids 39 and 40), the command pruned 40, said
	 * `Pruned=1, skipped=0`, and left 39 answering every lookup.
	 *
	 * @param string $slug        The schema slug, matched case-insensitively.
	 * @param string $application The owning application id.
	 *
	 * @return array<int, Schema> Every matching schema, oldest id first.
	 *
	 * @spec openspec/specs/schema-import/spec.md#requirement-a-schema-retired-from-a-descriptor-must-be-removable-from-the-instance
	 */
	public function findAllByApplicationAndSlug(string $slug, string $application): array;

	/**
	 * Resolve a schema by slug, scoped to a set of schema ids.
	 *
	 * This is the runtime-resolution half of the cross-app slug-collision fix.
	 * When an object operation carries a register context, a schema slug must
	 * resolve to the schema THAT REGISTER references — not to whichever
	 * same-slug row {@see find()} happens to fetch first. Callers pass the
	 * register's own `schemas` id list; the slug is matched only among those.
	 *
	 * @param string $slug The schema slug (matched case-insensitively).
	 * @param int[] $schemaIds The candidate schema ids (a register's schemas list).
	 *
	 * @return Schema|null The matching schema within the id set, or null when
	 *                     none of the register's schemas carry this slug.
	 */
	public function findBySlugInIds(string $slug, array $schemaIds): ?Schema;

	/**
	 * Resolve a schema identifier (numeric id, uuid, or slug) within a set of schema ids.
	 *
	 * The register-scoped counterpart of {@see find()}: it matches the SAME
	 * identifier forms with the SAME case-insensitive slug semantics and the SAME
	 * tie-break ordering, but only among the given ids — a register's `schemas`
	 * list. {@see findBySlugInIds()} scopes slugs only; this method exists so the
	 * boundary a caller names holds for EVERY identifier form: a numeric id or a
	 * uuid the register does not carry must not resolve merely because a schema
	 * with that id exists elsewhere on the instance.
	 *
	 * @param string|int $id        The identifier — numeric id, uuid, or slug (slug matched case-insensitively).
	 * @param array      $schemaIds The candidate schema ids (a register's schemas list).
	 *
	 * @return Schema|null The matching schema within the id set, or null when none matches.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function findInIds(string|int $id, array $schemaIds): ?Schema;

	/**
	 * Count how many schemas on the instance carry a slug.
	 *
	 * Used only to build the message of {@see SchemaNotInRegisterException}. A
	 * register-scoped miss reads as "your slug is wrong" unless the caller is told
	 * how many same-slug schemas exist — and when that number is nine, as measured
	 * for `anonymizationLink` on 2026-08-16, "your slug is wrong" is the one
	 * conclusion that is certainly false.
	 *
	 * Deliberately unscoped by organisation and application: the point is to report
	 * the size of the ambiguity the caller just escaped, not to resolve anything.
	 *
	 * @param string $slug The schema slug (matched case-insensitively).
	 *
	 * @return int The number of schemas carrying this slug.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function countBySlug(string $slug): int;

	/**
	 * Clear the request-scoped find cache for a specific schema
	 *
	 * Used by the runtime-schema-api CRUD path to drop the in-memory
	 * cache entry after a mutation, so the next find() call re-reads
	 * from the database. Clears every cache key that referenced the
	 * given schema (by id, uuid, slug) across both RBAC/multi-tenancy
	 * flag combinations.
	 *
	 * @param int $schemaId The schema ID to drop from the find cache.
	 *
	 * @return void
	 */
	public function clearFindCache(int $schemaId): void;

	/**
	 * Finds multiple schemas by id
	 *
	 * @param array $ids The ids of the schemas
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If a schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple schemas are found
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @todo: refactor this into find all
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Find multiple schemas by IDs using a single optimized query
	 *
	 * This method performs a single database query to fetch multiple schemas,
	 * register: * significantly improving performance compared to individual queries.
	 *
	 * @param array $ids Array of schema IDs to find
	 *
	 * @return Schema&Schema[]
	 *
	 * @psalm-return array<Entity&Schema>
	 */
	public function findMultipleOptimized(array $ids): array;

	/**
	 * Finds schemas by slug
	 *
	 * Searches for schemas matching the given slug with optional
	 * multi-tenancy and RBAC filtering.
	 *
	 * @param string $slug The slug to search for
	 * @param ListQuery $query The page: its limit and offset
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Schema[] Array of matching schemas
	 *
	 * @psalm-return list<Schema>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findBySlug(string $slug, ListQuery $query, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Finds all schemas, files: with optional extension for statistics
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions The search conditions to apply
	 * @param array|null $searchParams The search parameters to apply
	 * @param array $_extend Optional array of extensions (e.g., ['@self.stats'])
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Schema[]
	 *
	 * @throws \Exception If user doesn't have read permission
	 *
	 * @psalm-return                                  list<\OCA\OpenRegister\Db\Schema>
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 */
	public function findAll(
		?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [],
		?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true
	): array;

	/**
	 * Inserts a schema entity into the database
	 *
	 * @param Schema $entity The entity to insert
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \Exception If user doesn't have create permission
	 *
	 * @return Schema The inserted entity
	 *
	 * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Creates a schema from an array
	 *
	 * This method handles schema extension by extracting only the delta
	 * (differences from parent schema) before saving when the schema extends another.
	 *
	 * @param array $object The object to create
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \Exception If property validation fails
	 *
	 * @return Schema The created schema
	 */
	public function createFromArray(array $object): Schema;

	/**
	 * Updates a schema entity in the database
	 *
	 * This method handles schema extension by extracting only the delta
	 * (differences from parent schema) before saving when the schema extends another.
	 *
	 * @param Schema $entity The entity to update
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the entity does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple entities are found
	 * @throws \Exception If user doesn't have update permission or access to this organisation
	 *
	 * @return Schema The updated entity
	 *
	 * @psalm-suppress LessSpecificImplementedReturnType - Schema is more specific than Entity
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-schema-save-that-changes-nothing-publishes-nothing
	 */
	public function update(Entity $entity): Entity;

	/**
	 * How many SchemaUpdatedEvents this request dispatched for one schema.
	 *
	 * A caller that saves a schema and needs to know whether the save reached
	 * the listeners (the configuration import, which must otherwise run the
	 * installers itself) reads this before and after its writes.
	 *
	 * @param int $schemaId The schema id.
	 *
	 * @return int The number of update events dispatched for it so far.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-a-schema-save-that-changes-nothing-publishes-nothing
	 */
	public function updateEventCount(int $schemaId): int;

	/**
	 * From now on a notification url action that cannot be linked to only warns on save.
	 *
	 * @return bool Whether it already did.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function warnOnUnlinkableNotificationActions(): bool;

	/**
	 * From now on a notification url action that cannot be linked to refuses the save, as it does by default.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function refuseUnlinkableNotificationActions(): void;

	/**
	 * Updates a schema from an array
	 *
	 * @param int $id The id of the schema to update
	 * @param array $object The object to update
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the schema does not exist
	 * @throws \Exception If property validation fails
	 *
	 * @return Schema The updated schema
	 */
	public function updateFromArray(int $id, array $object): Schema;

	/**
	 * Delete a schema
	 *
	 * **DELETE SAFETY (runtime-schema-api)**: refuses to delete a schema that still
	 * holds objects, unless the caller explicitly asks to orphan them ($force).
	 *
	 * This guard is enforced HERE, at the mapper, rather than only in
	 * SchemasController::destroy(), because the mapper is the choke point every
	 * deletion caller shares — including the AI/LLM-invokable SchemasToolProvider
	 * and SchemaTool surfaces, which have no controller in front of them.
	 *
	 * @param Schema $entity The schema entity to delete
	 * @param bool $force Bypass the object-count guard and deliberately orphan the
	 *                    objects. Only the `?force=true` disposition of
	 *                    `DELETE /api/schemas/{id}` may pass true. The cascade
	 *                    disposition needs no bypass: it deletes the rows first, so
	 *                    the guard naturally counts 0.
	 *
	 * @throws \OCP\DB\Exception If a database error occurs
	 * @throws ValidationException If objects are still attached and $force is false
	 * @throws \Exception If user doesn't have delete permission or access to this organisation
	 *
	 * @return Schema The deleted schema
	 *
	 * @psalm-suppress PossiblyUnusedReturnValue
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The force flag is the API-level disposition, mirrored from the endpoint.
	 *
	 * @spec openspec/changes/schema-delete-cascade/specs/runtime-schema-api/spec.md
	 */
	public function delete(Entity $entity, bool $force = false): Schema;

	/**
	 * Get the number of registers associated with each schema
	 *
	 * This method returns an associative array where the key is the schema ID
	 * and the value is the number of registers that reference that schema.
	 *
	 * @phpstan-return array<int,int>  Associative array of schema ID => register count
	 *
	 * @psalm-return array<int, int>
	 * @return int[]
	 */
	public function getRegisterCountPerSchema(): array;

	/**
	 * Get all schema ID to slug mappings
	 *
	 * @return array<string,string> Array mapping schema IDs to their slugs
	 */
	public function getIdToSlugMap(): array;

	/**
	 * Get all schema slug to ID mappings
	 *
	 * @return array<string,string> Array mapping schema slugs to their IDs
	 */
	public function getSlugToIdMap(): array;

	/**
	 * Resolve a bounded set of schema slugs to their primary-key ids.
	 *
	 * Deliberately NOT `getSlugToIdMap()`: that materialises every schema row
	 * on the instance (1931 on the reference instance) to answer a question
	 * about a handful of slugs. Filtered event subscription runs this on the
	 * object write path, so it must be bounded by the caller's declaration
	 * rather than by the size of the instance.
	 *
	 * A slug is not unique across apps — the same `decision` slug can exist in
	 * two registers — so each slug maps to a LIST of ids and the caller narrows
	 * further (typically by register). Matching is case-insensitive, mirroring
	 * {@see find()}; keys come back lower-cased.
	 *
	 * @param array<int,string> $slugs Schema slugs to resolve.
	 *
	 * @return array<string,array<int,string>> Lower-cased slug => matching ids.
	 *                                         Empty when $slugs is empty.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function findIdsBySlugs(array $slugs): array;

	/**
	 * Find schemas that have properties referencing the given schema
	 *
	 * This method searches through all schemas to find ones that have properties
	 * with $ref pointing to the target schema, indicating a relationship.
	 *
	 * @param Schema|int|string $schema The target schema to find references to
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If the target schema does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple target schemas are found
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 *
	 * @spec openspec/specs/rbac-scopes/spec.md
	 */
	public function getRelated(Schema|int|string $schema): array;

	/**
	 * Recursively check if properties contain a reference to the target schema
	 *
	 * This method searches through properties recursively to find $ref values
	 * that match the target schema's ID, files: UUID, rbac: or slug.
	 *
	 * @param array  $properties The properties array to search through
	 * @param Schema $target     The schema whose id, uuid or slug a reference names
	 *
	 * @return bool True if a reference to the target schema is found
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Recursive reference checking requires many conditions
	 */
	public function hasReferenceToSchema(array $properties, Schema $target): bool;

	/**
	 * Get property source metadata for a schema
	 *
	 * Returns metadata about each property indicating whether it's native (defined in this schema)
	 * or inherited (from a parent schema via allOf). For inherited properties, shows the source schema.
	 *
	 * @param Schema $schema The schema to analyze
	 *
	 * @return array<string, array<string, string|null>> Property metadata keyed by property name
	 *
	 * @psalm-return array<string,
	 *     array{source: 'native'|'inherited', inheritedFrom: string|null}>
	 */
	public function getPropertySourceMetadata(Schema $schema): array;

	/**
	 * Find schemas that compose with a given schema
	 *
	 * Returns an array of schema UUIDs for schemas that reference the given schema
	 * in their allOf, oneOf, or anyOf composition patterns.
	 *
	 * @param Schema|int|string $schema The schema, or its ID, UUID or slug to look it up by.
	 *
	 * @return array Array of schema UUIDs that compose with this schema
	 *
	 * @psalm-return list{0?: mixed,...}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
	 * @SuppressWarnings(PHPMD.NPathComplexity)      Schema composition search requires many conditional checks
	 */
	public function findExtendedBy(Schema|int|string $schema): array;

	/**
	 * Find all schema extension relationships in a single query
	 *
	 * Scans all schemas for allOf/oneOf/anyOf references and builds a reverse map
	 * of which schemas extend which. Replaces N individual findExtendedBy() calls
	 * with 1 query.
	 *
	 * @return array<int, string[]> Map of targetSchemaId => [extendingSchemaUuid, ...]
	 */
	public function findAllExtendedBy(): array;

	/**
	 * Return the IDs of all schemas whose `searchable` flag is false.
	 *
	 * Used by the unified search provider to exclude opted-out schemas from
	 * Nextcloud unified search inside the query (rather than post-filtering a
	 * result page, which would leak counts and break pagination). The lookup
	 * is intentionally RBAC/tenant-agnostic: it only answers "which schemas
	 * declared themselves non-searchable", the access filtering happens in the
	 * object search itself.
	 *
	 * @return int[] List of schema IDs with `searchable = false`.
	 *
	 * @psalm-return   list<int>
	 * @phpstan-return array<int, int>
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function findNonSearchableIds(): array;

	/**
	 * Return the IDs of all schemas whose `searchable` flag is true (default).
	 *
	 * The unified search provider passes this allow-list as the `@self.schema`
	 * IN-filter so that only searchable schemas contribute results, applied
	 * inside the query.
	 *
	 * @return int[] List of schema IDs with `searchable = true`.
	 *
	 * @psalm-return   list<int>
	 * @phpstan-return array<int, int>
	 *
	 * @spec openspec/specs/unified-search-provider/spec.md
	 */
	public function findSearchableIds(): array;

	/**
	 * Return the IDs of all schemas whose `smartPickerEnabled` flag is true.
	 *
	 * Consulted by a schema-scoped `AbstractSchemaReferenceProvider`/
	 * `AbstractSchemaSearchProvider` instance to gate its own functionality
	 * per-instance (a schema-scoped provider only ever concerns one schema),
	 * mirroring `findSearchableIds()`'s pattern for the unified search
	 * provider's opt-out. Unlike `searchable`, no consumer currently needs
	 * the inverse "disabled ids" list.
	 *
	 * @return int[] List of schema IDs with `smartPickerEnabled = true`.
	 *
	 * @psalm-return   list<int>
	 * @phpstan-return array<int, int>
	 *
	 * @spec openspec/changes/schema-scoped-smart-picker/design.md#d2a
	 */
	public function findSmartPickerEnabledIds(): array;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param Schema $entity The entity.
	 *
	 * @return Schema The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
