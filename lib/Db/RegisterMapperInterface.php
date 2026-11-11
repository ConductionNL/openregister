<?php

/**
 * RegisterMapperInterface: the public surface of RegisterMapper, for callers to type against.
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

use OCP\AppFramework\Db\Entity;

/**
 * Every public method of RegisterMapper. Callers depend on this interface, not on the class.
 */
interface RegisterMapperInterface {

	/**
	 * Find a register by its ID, with optional extension for statistics
	 *
	 * Includes RBAC and organisation filtering for multi-tenancy.
	 *
	 * @param int|string $id The ID of the register to find
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Register The found register, possibly with stats
	 *
	 * @throws \Exception If RBAC permission check fails
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   Flags control security filtering behavior
	 *
	 * @spec openspec/specs/register-slug-resolution/spec.md
	 */
	public function find(string|int $id, bool $_rbac = true, bool $_multitenancy = true): Register;

	/**
	 * Clear the request-scoped find cache for a specific register
	 *
	 * Used by the runtime-schema-api CRUD path to drop the in-memory
	 * cache entry after a mutation, so the next find() call re-reads
	 * from the database. Clears every cache key that referenced the
	 * given register (by id, uuid, slug) across both RBAC/multi-tenancy
	 * flag combinations.
	 *
	 * @param int $registerId The register ID to drop from the find cache.
	 *
	 * @return void
	 */
	public function clearFindCache(int $registerId): void;

	/**
	 * Finds multiple registers by id
	 *
	 * @param array $ids The ids of the registers
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If a register does not exist
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple registers are found
	 * @throws \OCP\DB\Exception If a database error occurs
	 *
	 * @todo: refactor this into find all
	 *
	 * @return Register[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Register>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findMultiple(array $ids, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Find multiple registers by IDs using a single optimized query
	 *
	 * This method performs a single database query to fetch multiple registers,
	 * register: * significantly improving performance compared to individual queries.
	 *
	 * @param array $ids Array of register IDs to find.
	 *
	 * @return Register&Register[]
	 *
	 * @psalm-return array<Entity&Register>
	 */
	public function findMultipleOptimized(array $ids): array;

	/**
	 * Find all registers, files: with optional extension for statistics
	 *
	 * @param int|null $limit The limit of the results
	 * @param int|null $offset The offset of the results
	 * @param array|null $filters The filters to apply
	 * @param array|null $searchConditions Array of search conditions
	 * @param array|null $searchParams Array of search parameters
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Register[]
	 *
	 * @psalm-return                                list<\OCA\OpenRegister\Db\Register>
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function findAll(
		?int $limit = null, ?int $offset = null, ?array $filters = [], ?array $searchConditions = [], ?array $searchParams = [],
		bool $_rbac = true, bool $_multitenancy = true
	): array;

	/**
	 * Insert a new entity
	 *
	 * Includes RBAC permission check and auto-sets organisation from active session.
	 *
	 * @param Register $entity The entity to insert
	 *
	 * @return Register The inserted entity
	 *
	 * @throws \Exception If RBAC permission check fails
	 * @psalm-suppress LessSpecificImplementedReturnType - Register is more specific than Entity
	 */
	public function insert(Entity $entity): Entity;

	/**
	 * Create a new register from an array of data
	 *
	 * @param array $object The data to create the register from
	 *
	 * @return Register The created register
	 */
	public function createFromArray(array $object): Register;

	/**
	 * Update an entity
	 *
	 * @param Register $entity The entity to update
	 *
	 * @return Register The updated entity
	 *
	 * @psalm-suppress LessSpecificImplementedReturnType - Register is more specific than Entity
	 *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Update an existing register from an array of data
	 *
	 * @param int $id The ID of the register to update
	 * @param array $object The new data for the register
	 *
	 * @return Register The updated register
	 */
	public function updateFromArray(int $id, array $object): Register;

	/**
	 * Delete a register only if no objects are attached
	 *
	 * @param Register $entity The register to delete
	 *
	 * @throws \Exception If objects are still attached to the register
	 *
	 * @return Register The deleted register
	 */
	public function delete(Entity $entity): Register;

	/**
	 * Get all schemas associated with a register
	 *
	 * @param int $registerId The ID of the register
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return Schema[]
	 *
	 * @psalm-return list<\OCA\OpenRegister\Db\Schema>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) Flags control security filtering behavior
	 */
	public function getSchemasByRegisterId(int $registerId, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Retrieves the ID of the first register that includes the given schema ID.
	 *
	 * This method searches the `openregister_registers` table for a register
	 * whose `schemas` field (a string) contains the specified schema ID, register: using
	 * a regular expression for exact word matching. If a match is found, schema: the ID
	 * of the first such register is returned. Otherwise, extend: it returns null.
	 *
	 * @param int $schemaId The ID of the schema to search for.
	 *
	 * @return int|null The ID of the first matching register, files: or null if none found.
	 */
	public function getFirstRegisterWithSchema(int $schemaId): ?int;

	/**
	 * Retrieves the IDs of all registers that include the given schema ID.
	 *
	 * A schema may be referenced by more than one register. Callers that make a
	 * security decision off register-level configuration (e.g. the
	 * inheritFromPublic cascade) must consider every register rather than an
	 * arbitrary first match, so the verdict is deterministic across nodes and
	 * restores. IDs are returned in ascending order for stability.
	 *
	 * @param int $schemaId The ID of the schema to search for.
	 *
	 * @return int[] The IDs of all matching registers (empty when none found).
	 */
	public function getAllRegisterIdsWithSchema(int $schemaId): array;

	/**
	 * Get all register ID to slug mappings
	 *
	 * @return array<string,string> Array mapping register IDs to their slugs
	 */
	public function getIdToSlugMap(): array;

	/**
	 * Get all register slug to ID mappings
	 *
	 * @return array<string,string> Array mapping register slugs to their IDs
	 */
	public function getSlugToIdMap(): array;

	/**
	 * Resolve a bounded set of register slugs to their primary-key ids.
	 *
	 * The register twin of {@see SchemaMapper::findIdsBySlugs()}; see that
	 * docblock for why filtered event subscription must not use
	 * {@see getSlugToIdMap()} on the write path.
	 *
	 * @param array<int,string> $slugs Register slugs to resolve.
	 *
	 * @return array<string,array<int,string>> Lower-cased slug => matching ids.
	 *                                         Empty when $slugs is empty.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md
	 */
	public function findIdsBySlugs(array $slugs): array;

	/**
	 * InsertOrUpdate through the mapper (QBMapper).
	 *
	 * @param Register $entity The entity.
	 *
	 * @return Register The stored entity.
	 */
	public function insertOrUpdate(Entity $entity): Entity;
}//end interface
