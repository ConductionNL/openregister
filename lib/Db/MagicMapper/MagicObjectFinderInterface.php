<?php

/**
 * MagicObjectFinderInterface: Finds objects by identifier, in one table or across every source.
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

/**
 * Finds objects by identifier, in one table or across every source.
 *
 * One role of MagicMapperInterface: a caller that needs only this role types against it.
 */
interface MagicObjectFinderInterface {
	/**
	 * Find an object entity by identifier (ID, UUID, slug, or URI).
	 *
	 * When register+schema context is available, searches the specific magic table.
	 * Otherwise searches across all magic tables.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param Register|null $register Optional register to filter by.
	 * @param Schema|null $schema Optional schema to filter by.
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks (default: true).
	 * @param bool $_multitenancy Whether to apply multitenancy filtering (default: true).
	 *
	 * @return ObjectEntity The found object.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found.
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple objects found.
	 */
	public function find(
		string|int $identifier, ?Register $register = null, ?Schema $schema = null, bool $includeDeleted = false, bool $_rbac = true,
		bool $_multitenancy = true
	): ObjectEntity;

	/**
	 * Find an object across all storage sources (all magic tables).
	 *
	 * Searches all magic tables to find an object by its identifier without
	 * requiring register/schema context.
	 *
	 * @param string|int $identifier Object identifier (ID, UUID, slug, or URI).
	 * @param bool $includeDeleted Whether to include deleted objects.
	 * @param bool $_rbac Whether to apply RBAC checks.
	 * @param bool $_multitenancy Whether to apply multitenancy filtering.
	 * @param int|null $registerIdScope Restrict the scan to one register's tables.
	 *
	 * @return array{object: ObjectEntity, register: Register|null, schema: Schema|null}
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If object not found in any source.
	 */
	public function findAcrossAllSources(
		string|int $identifier, bool $includeDeleted = false,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_rbac = true,
		// phpcs:ignore Squiz.NamingConventions.ValidVariableName.MemberNotCamelCaps -- named arg convention.
		bool $_multitenancy = true,
		?int $registerIdScope = null
	): array;

	/**
	 * Find all ObjectEntities of one register+schema table with filtering, pagination, ids, search and relation narrowing.
	 *
	 * @param MagicListRequest $request The table, the listing (limit unbounded for all rows) and the ids and relation to narrow to.
	 *
	 * @return ObjectEntity[]
	 *
	 * @throws \OCP\DB\Exception If a database error occurs.
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findAllListed(MagicListRequest $request): array;

	/**
	 * Find multiple objects by their IDs or UUIDs.
	 *
	 * @param array $ids Array of IDs or UUIDs.
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findMultiple(array $ids): array;

	/**
	 * Find all objects for a given schema.
	 *
	 * Searches across all magic tables that belong to the given schema.
	 *
	 * PERF-13: the optional $limit/$offset/$filter arguments are pushed down
	 * into SQL (via findAllInRegisterSchemaTable) so callers that only need a
	 * bounded/filtered slice no longer pull every row of the schema into PHP.
	 * They are optional and default to the previous unbounded full-table read,
	 * so existing callers are unaffected.
	 *
	 * @param int $schemaId Schema ID.
	 * @param int|null $limit Optional maximum number of rows (pushed to SQL).
	 * @param int|null $offset Optional rows to skip for pagination (pushed to SQL).
	 * @param array|null $filter Optional filter criteria (pushed to SQL).
	 *
	 * @return ObjectEntity[]
	 *
	 * @psalm-return list<ObjectEntity>
	 */
	public function findBySchema(int $schemaId, ?int $limit = null, ?int $offset = null, ?array $filter = null): array;
}//end interface
