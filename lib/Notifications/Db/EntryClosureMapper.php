<?php

/**
 * The closures of objects to new timeline entries and notes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Notifications\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * One row per closed object, keyed by its uuid.
 *
 * @extends QBMapper<EntryClosure>
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
 */
class EntryClosureMapper extends QBMapper {

	/**
	 * The table.
	 *
	 * @var string
	 */
	public const TABLE = 'openregister_entry_closures';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: EntryClosure::class);
	}//end __construct()

	/**
	 * The closure of an object, or null when it is open.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return EntryClosure|null The closure.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function findForObject(string $objectUuid): ?EntryClosure {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$rows = $this->findEntities(query: $qb);
		if ($rows === []) {
			return null;
		}

		return $rows[0];
	}//end findForObject()

	/**
	 * Reopen an object: remove its closure.
	 *
	 * @param string $objectUuid The object uuid.
	 *
	 * @return int The rows removed.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md#requirement-a-record-closes-to-new-entries-and-an-entry-can-be-withdrawn-or-locked-req-oas-006
	 */
	public function deleteForObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}//end deleteForObject()
}//end class
