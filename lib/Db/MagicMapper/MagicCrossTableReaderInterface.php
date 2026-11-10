<?php

/**
 * MagicCrossTableReaderInterface: Reads and counts objects across every magic table at once.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
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

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Reads and counts objects across every magic table at once.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicCrossTableReaderInterface {
	/**
	 * Find an object across all magic tables without knowing register/schema upfront.
	 *
	 * This method searches all existing magic tables for an object by its identifier.
	 * It's useful for operations like lock/unlock where the caller doesn't know
	 * which storage backend contains the object.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerIdScope Restrict the scan to one register's tables.
	 *                                  A caller that named a register has already
	 *                                  said where the object lives; widening the
	 *                                  search past it is never a correct answer,
	 *                                  and it is what makes this method cost
	 *                                  seconds. Null keeps the historic
	 *                                  everything-everywhere behaviour.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 *                                                                                   The found object with its register and schema context.
	 *
	 * @throws DoesNotExistException If object not found in any magic table.
	 *
	 * @spec openspec/changes/object-write-sub-500ms/specs/object-write-performance/spec.md
	 */
	public function findAcrossAllMagicTables(
		string|int $identifier, bool $includeDeleted = false, bool $_rbac = true, bool $_multitenancy = true, ?int $registerIdScope = null
	): array;

	/**
	 * Find multiple objects by UUIDs across ALL magic tables.
	 *
	 * This method efficiently searches all magic tables for multiple UUIDs in batch.
	 * It's optimized for performance by searching all UUIDs in each table with a single query.
	 *
	 * @param array    $uuids           Array of UUIDs to search for.
	 * @param bool     $includeDeleted  Whether to include soft-deleted objects.
	 * @param int|null $registerIdScope Only look in this register's tables (null: every table).
	 *
	 * @return ObjectEntity[] Array of found objects (may be fewer than requested if some not found).
	 */
	public function findMultipleAcrossAllMagicTables(array $uuids, bool $includeDeleted = false, ?int $registerIdScope = null): array;

	/**
	 * Find objects by uuid across all magic tables, each with every stored column.
	 *
	 * @param array $uuids          The uuids to search for.
	 * @param bool  $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @return ObjectEntity[] The found objects (may be fewer than requested).
	 *
	 * @spec openspec/specs/magic-table-reads/spec.md
	 */
	public function findStoredAcrossAllMagicTables(array $uuids, bool $includeDeleted = false): array;

	/**
	 * Find all soft-deleted objects across ALL magic tables.
	 *
	 * Objects live in per-register/schema magic tables, so there is no single
	 * table to query for `/api/deleted`. This scans every magic table for rows
	 * whose `_deleted` marker is set and returns them newest-first. Replaces the
	 * broken register/schema-less `searchObjectsPaginated()` path which always
	 * fell through to an empty result.
	 *
	 * Each table is narrowed IN THE QUERY to the rows the caller may read, with
	 * the same organisation and RBAC filters the object list applies
	 * ({@see MagicSearchHandler::applyAccessControlToQuery()}), so a trashed
	 * object is never shown to someone who could not read it before it was
	 * deleted, and the total the count answers matches the pages this returns
	 * (openregister#4078). A table whose schema cannot be resolved is skipped:
	 * whether the caller may read it cannot be answered, and the answer is no.
	 *
	 * @param int|null $limit Maximum rows to return.
	 * @param int|null $offset Rows to skip (pagination).
	 * @param bool $_rbac Apply the schema's read rules for the caller (false only for an admin or system caller).
	 * @param bool $_multitenancy Apply the organisation boundary for the caller.
	 *
	 * @return ObjectEntity[] Soft-deleted objects across all magic tables.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function findDeletedAcrossAllMagicTables(?int $limit = null, ?int $offset = null, bool $_rbac = true, bool $_multitenancy = true): array;

	/**
	 * Count all soft-deleted objects across ALL magic tables.
	 *
	 * Narrowed per table exactly as {@see findDeletedAcrossAllMagicTables()}
	 * is, so the total never counts a row the listing would not return.
	 *
	 * @param bool $_rbac Apply the schema's read rules for the caller (false only for an admin or system caller).
	 * @param bool $_multitenancy Apply the organisation boundary for the caller.
	 *
	 * @return int Total soft-deleted object count.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function countDeletedAcrossAllMagicTables(bool $_rbac = true, bool $_multitenancy = true): int;

	/**
	 * Find all objects across ALL magic tables that have the given UUID in their relations.
	 *
	 * This method searches across all magic tables to find objects that reference the given UUID.
	 * Relations are stored as JSON objects like {"fieldName": "uuid", ...}.
	 *
	 * @param string $uuid The UUID to search for in relations.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 *
	 * @return ObjectEntity[] Array of found ObjectEntity objects.
	 */
	public function findByRelationAcrossAllMagicTables(string $uuid, bool $includeDeleted = false): array;

	/**
	 * The file ids of the object folders that belong to one organisation.
	 *
	 * Scans every magic table for live rows whose `_organisation` is the given
	 * uuid and returns their distinct numeric `_folder` values. Not narrowed to
	 * a caller: it measures the organisation, and the caller decides who may
	 * see the measurement.
	 *
	 * @param string $organisationUuid The organisation uuid.
	 *
	 * @return list<int> The folder file ids.
	 *
	 * @throws RuntimeException When a table could not be read: a sum over the tables that could be read would
	 *                           be too low and look measured, so the whole measurement fails (and is logged).
	 *
	 * @spec openspec/specs/organisation-usage-measured/spec.md
	 */
	public function findFolderIdsByOrganisation(string $organisationUuid): array;

	/**
	 * Count an organisation's live objects across the magic tables, of the
	 * given schemas only when ids are given.
	 *
	 * A table that cannot be read fails the count after the scan, naming the
	 * tables, so a caller never shows a figure that is too low.
	 *
	 * @param string          $organisationUuid The organisation.
	 * @param array<int, int> $schemaIds        The schemas to count; empty counts every schema.
	 *
	 * @return int The number of live objects.
	 *
	 * @throws RuntimeException When a table could not be read.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function countObjectsByOrganisation(string $organisationUuid, array $schemaIds=[]): int;

	/**
	 * Search across multiple register+schema tables simultaneously.
	 *
	 * This method performs a cross-table search by:
	 * 1. Querying each register+schema table separately with fuzzy search.
	 * 2. Combining results using array merge (since we can't easily UNION with different schemas).
	 * 3. Sorting by relevance score globally.
	 *
	 * @param array $query Search parameters including _search term.
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return array Array of ObjectEntity objects from all tables, sorted by relevance.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-cross-table-searches-honour-the-recent-lens-like-one-schema
	 */
	public function searchAcrossMultipleTables(array $query, array $registerSchemaPairs): array;

	/**
	 * Count every row a cross-table search matches, before paging.
	 *
	 * Follows the search's own path, so the total and the rows agree on
	 * every filter: the UNION path is counted with one `SUM` over `COUNT(*)`
	 * arms built from the same WHERE as its search arms (one statement per
	 * UNION_ARM_BATCH_SIZE tables), the sequential path by summing the
	 * single-table count it pages with.
	 *
	 * @param array $query               Search parameters (`_limit` and `_offset` are ignored).
	 * @param array $registerSchemaPairs Array of ['register' => Register, 'schema' => Schema] pairs.
	 *
	 * @return int The number of matching rows across all tables.
	 *
	 * @spec openspec/specs/objects-crud/spec.md#requirement-limit-supports-an-explicit-unlimited-value
	 */
	public function countAcrossMultipleTables(array $query, array $registerSchemaPairs): int;
}//end interface
