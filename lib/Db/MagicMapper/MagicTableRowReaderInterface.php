<?php

/**
 * MagicTableRowReaderInterface: Reads and counts the rows of one register and schema table.
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use RuntimeException;

/**
 * Reads and counts the rows of one register and schema table.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicTableRowReaderInterface {
	/**
	 * Find object in register+schema table by identifier (ID, UUID, slug, or URI).
	 *
	 * This method provides ObjectEntity compatibility for the public find() method.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param bool $_rbac Whether to apply RBAC.
	 * @param bool $_multitenancy Whether to apply multi-tenancy.
	 * @param bool $includeDeleted Whether to include soft-deleted objects.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 *
	 * @return ObjectEntity The found object.
	 */
	public function findInRegisterSchemaTable(
		string|int $identifier, Register $register, Schema $schema, bool $_rbac = true, bool $_multitenancy = true,
		bool $includeDeleted = false
	): ObjectEntity;

	/**
	 * Find all objects in register+schema table with filtering and pagination.
	 *
	 * @param Register $register The register context.
	 * @param Schema $schema The schema context.
	 * @param int|null $limit Maximum number of results.
	 * @param int|null $offset Offset for pagination.
	 * @param array|null $filters Filters to apply.
	 * @param array $sort Sort order.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findAllInRegisterSchemaTable(
		Register $register, Schema $schema, ?int $limit = null, ?int $offset = null, ?array $filters = null, array $sort = []
	): array;

	/**
	 * Find objects by a list of uuids within ONE register+schema table.
	 *
	 * The batched counterpart of {@see findInRegisterSchemaTable()}: one indexed
	 * `IN` query answers "which of these uuids already exist here" for a whole
	 * batch, where the single-object method answers it one round trip at a time.
	 *
	 * Bulk save needs exactly this question — it has to classify every row of a
	 * batch as create or update before it writes any of them — and asking it per
	 * row made the create/update probe the dominant cost of a bulk write, and
	 * O(N) queries in the one code path whose entire purpose is to avoid them.
	 *
	 * Deliberately bounded to the given register+schema. The unbounded
	 * equivalent ({@see findMultipleAcrossAllMagicTables()}) unions every magic
	 * table on the instance, which answers a DIFFERENT question — "does this
	 * uuid exist anywhere" — and is the wrong one when the caller is deciding
	 * how to write into one specific table.
	 *
	 * No access-control filters are applied: this is an existence probe for
	 * callers that then run their own per-row RBAC check against the returned
	 * entity's owner, exactly as the per-row lookup it replaces did with
	 * `_rbac: false, _multitenancy: false`.
	 *
	 * @param Register $register The register whose table to search.
	 * @param Schema $schema The schema whose table to search.
	 * @param array<int, string> $uuids The uuids to look for.
	 * @param bool $includeDeleted Whether to include soft-deleted rows.
	 *
	 * @return array<string, ObjectEntity> Found entities keyed by uuid; missing uuids are simply absent.
	 */
	public function findObjectsByUuidsInRegisterSchema(Register $register, Schema $schema, array $uuids, bool $includeDeleted = false): array;

	/**
	 * Convert database row to ObjectEntity.
	 *
	 * This method is public to allow bulk handlers to convert rows for event dispatching.
	 * Delegates to MagicStatisticsHandler.
	 *
	 * @param array $row Database row
	 * @param Register $_register Register context
	 * @param Schema $_schema Schema context
	 *
	 * @return ObjectEntity|null Converted entity or null on failure
	 */
	public function convertRowToObjectEntity(array $row, Register $_register, Schema $_schema): ?ObjectEntity;

	/**
	 * Search objects in register+schema-specific table
	 *
	 * @param array $query Search query parameters
	 * @param Register $register The register context for table selection
	 * @param Schema $schema The schema for table selection
	 *
	 * @throws Exception If search operation fails
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function searchObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): array;

	/**
	 * Count objects in a register+schema specific table.
	 *
	 * This method counts objects from a dedicated table based on register+schema combination.
	 * It supports basic filtering and returns only the count for better performance.
	 * A count that cannot be made answers 0 (logged); a caller that acts on the
	 * number uses countObjectsOrFail() instead.
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 */
	public function countObjectsInRegisterSchemaTable(array $query, Register $register, Schema $schema): int;

	/**
	 * Count objects in a register+schema table, throwing when the count cannot be made.
	 *
	 * The same count as countObjectsInRegisterSchemaTable(), for a caller that
	 * acts on the number (a quota): a failed query or an answer that is not a
	 * number throws instead of reading as 0, so a broken count can never pass
	 * for an empty table (live pass O10).
	 *
	 * @param array    $query    Search parameters for filtering (excluding pagination).
	 * @param Register $register The register context for table selection.
	 * @param Schema   $schema   The schema for table selection.
	 *
	 * @return int Count of matching objects.
	 *
	 * @throws RuntimeException When the count query does not answer with a number.
	 * @throws Exception        When the count query fails.
	 *
	 * @spec openspec/changes/object-quota-per-organisation/specs/tenant-quotas/spec.md
	 */
	public function countObjectsOrFail(array $query, Register $register, Schema $schema): int;

	/**
	 * Get the list of filter properties that were ignored during the last search.
	 *
	 * These are properties that were requested as filters but don't exist in the schema.
	 * Useful for providing feedback to API clients about invalid filter parameters.
	 *
	 * @return array<string> List of ignored filter property names
	 */
	public function getIgnoredFilters(): array;
}//end interface
