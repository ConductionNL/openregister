<?php

/**
 * RegisterMapperInterface: reads and writes register rows.
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

use OCP\AppFramework\Db\Entity;

/**
 * Finds, creates, updates and deletes registers, and answers which schemas a
 * register holds. Implemented by Db\RegisterMapper.
 *
 * @template TRegister of RegisterInterface
 * @template TSchema of SchemaInterface
 *
 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)   The mapper's flags are part of its signatures.
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
	 * @return TRegister The found register, possibly with stats
	 *
	 * @throws \Exception If RBAC permission check fails
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function find(
		string|int $id,
		bool $_rbac = true,
		bool $_multitenancy = true,
	);

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
	 * @return TRegister[]
	 *
	 * @psalm-return                                list<TRegister>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function findAll(
		?int $limit = null,
		?int $offset = null,
		?array $filters = [],
		?array $searchConditions = [],
		?array $searchParams = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;

	/**
	 * Update an entity
	 *
	 * @param Entity $entity The entity to update
	 *
	 * @return Entity&TRegister The updated entity
  *
	 * @spec openspec/specs/activity-provider/spec.md#requirement-a-register-save-that-changes-nothing-publishes-nothing
	 */
	public function update(Entity $entity): Entity;

	/**
	 * Get all schemas associated with a register
	 *
	 * @param int $registerId The ID of the register
	 * @param bool $_rbac Whether to apply RBAC permission checks (default: true)
	 * @param bool $_multitenancy Whether to apply multi-tenancy filtering (default: true)
	 *
	 * @return TSchema[]
	 *
	 * @psalm-return list<TSchema>
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getSchemasByRegisterId(
		int $registerId,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array;

	/**
	 * Create a new register from an array of data
	 *
	 * @param array $object The data to create the register from
	 *
	 * @return TRegister The created register
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function createFromArray(array $object);

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
	 *
	 * @spec openspec/specs/objects-crud/spec.md
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
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getAllRegisterIdsWithSchema(int $schemaId): array;

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
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function clearFindCache(int $registerId): void;

	/**
	 * Delete a register only if no objects are attached
	 *
	 * @param Entity&RegisterInterface $entity The register to delete
	 *
	 * @throws \Exception If objects are still attached to the register
	 *
	 * @return TRegister The deleted register
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function delete(Entity $entity);

	/**
	 * Update an existing register from an array of data
	 *
	 * @param int $id The ID of the register to update
	 * @param array $object The new data for the register
	 *
	 * @return TRegister The updated register
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function updateFromArray(int $id, array $object);

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
	 * Get all register slug to ID mappings
	 *
	 * @return array<string,string> Array mapping register slugs to their IDs
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getSlugToIdMap(): array;

	/**
	 * Get all register ID to slug mappings
	 *
	 * @return array<string,string> Array mapping register IDs to their slugs
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public function getIdToSlugMap(): array;
}//end interface
