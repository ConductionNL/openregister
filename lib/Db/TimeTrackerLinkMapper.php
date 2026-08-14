<?php

/**
 * Mapper for time-tracker link entities.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 * @link    https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class TimeTrackerLinkMapper
 *
 * @template-extends QBMapper<TimeTrackerLink>
 */
class TimeTrackerLinkMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'openregister_timetracker_links',
			entityClass: TimeTrackerLink::class
		);
	}//end __construct()

	/**
	 * Find time-tracker links by object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return TimeTrackerLink[] Array of time-tracker links.
	 */
	public function findByObjectUuid(string $objectUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->orderBy('linked_at', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findByObjectUuid()

	/**
	 * Find a specific time-tracker link by object UUID + entry composite.
	 *
	 * The composite (entry_type + client_id + task_id + time_id) matches
	 * the unique index. Null id components are matched with `IS NULL`.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $entryType The entry kind (`client`|`task`|`time`).
	 * @param string|null $clientId The client uuid (or null).
	 * @param string|null $taskId The task uuid (or null).
	 * @param string|null $timeId The time-entry uuid (or null).
	 *
	 * @return TimeTrackerLink|null The link or null if not found.
	 */
	public function findByObjectAndEntry(
		string $objectUuid,
		string $entryType,
		?string $clientId,
		?string $taskId,
		?string $timeId,
	): ?TimeTrackerLink {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere($qb->expr()->eq('entry_type', $qb->createNamedParameter($entryType)));

		$this->applyIdComponent(qb: $qb, column: 'client_id', value: $clientId);
		$this->applyIdComponent(qb: $qb, column: 'task_id', value: $taskId);
		$this->applyIdComponent(qb: $qb, column: 'time_id', value: $timeId);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndEntry()

	/**
	 * Find a time-tracker link for an object by its upstream entry id,
	 * regardless of which id column carries it.
	 *
	 * The Tier-2 unlink path passes a single opaque `entryId`; this
	 * matches it against any of the three id columns for the object.
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $entryId The upstream entry id (client/task/time uuid).
	 *
	 * @return TimeTrackerLink|null The link or null if not found.
	 */
	public function findByObjectAndEntryId(string $objectUuid, string $entryId): ?TimeTrackerLink {
		$qb = $this->db->getQueryBuilder();
		$param = $qb->createNamedParameter($entryId);
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('client_id', $param),
					$qb->expr()->eq('task_id', $param),
					$qb->expr()->eq('time_id', $param)
				)
			)
			->setMaxResults(1);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndEntryId()

	/**
	 * Delete all time-tracker links for an object UUID.
	 *
	 * @param string $objectUuid The object UUID.
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectUuid(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteByObjectUuid()

	/**
	 * Delete a time-tracker link by object UUID + upstream entry id
	 * (Tier-2 unlink path).
	 *
	 * Matches the entry id against any of the three id columns. Returns
	 * the number of rows actually deleted so callers can distinguish
	 * "no such link" (0) from "ok" (>=1).
	 *
	 * @param string $objectUuid The object UUID.
	 * @param string $entryId The upstream entry id (client/task/time uuid).
	 *
	 * @return int Number of deleted rows.
	 */
	public function deleteByObjectAndEntryId(string $objectUuid, string $entryId): int {
		$qb = $this->db->getQueryBuilder();
		$param = $qb->createNamedParameter($entryId);
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->eq('client_id', $param),
					$qb->expr()->eq('task_id', $param),
					$qb->expr()->eq('time_id', $param)
				)
			);

		return $qb->executeStatement();
	}//end deleteByObjectAndEntryId()

	/**
	 * Return every link row, optionally scoped to an object uuid.
	 *
	 * Used by the `openregister:time:reconcile` command to walk every
	 * link and re-fetch upstream NC TimeManager metadata (duration,
	 * billable, started_at, name) so the denormalised fields match the
	 * authoritative source after schema/data drift.
	 *
	 * @param string|null $objectUuid Optional object uuid scope. Null returns every row.
	 *
	 * @return TimeTrackerLink[]
	 *
	 * @spec openspec/specs/integration-time-tracker/spec.md
	 */
	public function findAll(?string $objectUuid = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'ASC');

		if ($objectUuid !== null) {
			$qb->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));
		}

		return $this->findEntities(query: $qb);
	}//end findAll()

	/**
	 * Apply an id-component constraint to the query builder, using
	 * `IS NULL` when the component is absent so the composite-unique
	 * match is exact.
	 *
	 * @param IQueryBuilder $qb The query builder.
	 * @param string $column The id column name.
	 * @param string|null $value The id value, or null.
	 *
	 * @return void
	 */
	private function applyIdComponent(IQueryBuilder $qb, string $column, ?string $value): void {
		if ($value === null) {
			$qb->andWhere($qb->expr()->isNull($column));
			return;
		}

		$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
	}//end applyIdComponent()
}//end class
