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
 * @method list<TimeTrackerLink> findByObjectUuid(string $objectUuid) FindLinksByObjectUuidTrait, typed for this mapper.
 */
class TimeTrackerLinkMapper extends QBMapper implements TimeTrackerLinkMapperInterface {
	use FindLinksByObjectUuidTrait;
	use DeleteLinksByObjectUuidTrait;
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

		$this->applyIdComponents(
			qb: $qb,
			components: ['client_id' => $clientId, 'task_id' => $taskId, 'time_id' => $timeId]
		);

		try {
			return $this->findEntity(query: $qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}//end findByObjectAndEntry()

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
	 * Narrow to each id component, using `IS NULL` for an absent one so the
	 * composite-unique match is exact.
	 *
	 * @param IQueryBuilder               $qb         The query being built.
	 * @param array<string, string|null> $components The id columns and their values, in order.
	 *
	 * @return void
	 */
	private function applyIdComponents(IQueryBuilder $qb, array $components): void {
		foreach ($components as $column => $value) {
			if ($value === null) {
				$qb->andWhere($qb->expr()->isNull($column));
				continue;
			}

			$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
		}
	}//end applyIdComponents()
}//end class
