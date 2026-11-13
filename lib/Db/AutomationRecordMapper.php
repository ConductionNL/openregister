<?php

/**
 * OpenRegister AutomationRecordMapper
 *
 * Stores and reads automation run records.
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
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use DateTimeZone;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * The `openregister_automation_records` table (D-1).
 *
 * @extends QBMapper<AutomationRecord>
 *
 * @spec openspec/changes/automation-run-records/design.md
 */
class AutomationRecordMapper extends QBMapper {

	public const TABLE = 'openregister_automation_records';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: self::TABLE, entityClass: AutomationRecord::class);
	}//end __construct()

	/**
	 * The records matching a filter, newest first.
	 *
	 * @param AutomationRecordFilter $filter The filter and page.
	 *
	 * @return array<int, AutomationRecord> The records.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	public function findFiltered(AutomationRecordFilter $filter): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		foreach (['kind' => $filter->kind, 'schema_id' => $filter->schema, 'object_uuid' => $filter->object] as $column => $value) {
			if ($value !== '') {
				$qb->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($value)));
			}
		}

		if ($filter->keyPrefix !== '') {
			$qb->andWhere($qb->expr()->like('automation_key', $qb->createNamedParameter($this->db->escapeLikeParameter($filter->keyPrefix) . '%')));
		}

		if ($filter->since !== null) {
			$qb->andWhere($qb->expr()->gte('recorded_at', $qb->createNamedParameter(self::utc(moment: $filter->since))));
		}

		if ($filter->until !== null) {
			$qb->andWhere($qb->expr()->lte('recorded_at', $qb->createNamedParameter(self::utc(moment: $filter->until))));
		}

		$qb->orderBy('recorded_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults($filter->limit)
			->setFirstResult($filter->offset);

		return $this->findEntities(query: $qb);
	}//end findFiltered()

	/**
	 * Delete every record written before a moment.
	 *
	 * @param DateTime $cutoff The moment (UTC).
	 *
	 * @return int The number of records deleted.
	 *
	 * @spec openspec/changes/automation-run-records/design.md
	 */
	public function deleteOlderThan(DateTime $cutoff): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('recorded_at', $qb->createNamedParameter(self::utc(moment: $cutoff))));

		return $qb->executeStatement();
	}//end deleteOlderThan()

	/**
	 * A moment as the UTC text the `recorded_at` column is compared in.
	 *
	 * @param DateTime $moment The moment.
	 *
	 * @return string The UTC text.
	 */
	private static function utc(DateTime $moment): string {
		return (clone $moment)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
	}//end utc()
}//end class
