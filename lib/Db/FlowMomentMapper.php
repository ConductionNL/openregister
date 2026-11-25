<?php

/**
 * Reads and writes the due-moment index of calendar flow triggers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper of `openregister_flow_moments`.
 *
 * @template-extends QBMapper<FlowMoment>
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowMomentMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_moments', entityClass: FlowMoment::class);
	}//end __construct()

	/**
	 * The moment of one trigger for one record, if indexed.
	 *
	 * @param string $flowUuid   The flow.
	 * @param string $nodeId     The calendar trigger node.
	 * @param string $objectUuid The record.
	 *
	 * @return FlowMoment|null
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function findMoment(string $flowUuid, string $nodeId, string $objectUuid): ?FlowMoment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('flow_uuid', $qb->createNamedParameter($flowUuid)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId)))
			->andWhere($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)))
			->setMaxResults(1);

		$found = $this->findEntities(query: $qb);

		return ($found[0] ?? null);
	}//end findMoment()

	/**
	 * The moments that have come due and have not fired, earliest first.
	 *
	 * @param DateTime $now   The clock.
	 * @param int      $limit At most this many.
	 *
	 * @return list<FlowMoment>
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function findDue(DateTime $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('fired_at'))
			->andWhere($qb->expr()->lte('due_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('due_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDue()

	/**
	 * Claim a waiting moment for firing: marks it fired unless another tick already did.
	 *
	 * @param FlowMoment $moment The moment.
	 * @param DateTime   $now    The clock.
	 * @param bool       $skip   Whether it is skipped rather than fired.
	 *
	 * @return bool True when this call claimed it.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function claim(FlowMoment $moment, DateTime $now, bool $skip=false): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('fired_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->set('skipped', $qb->createNamedParameter($skip, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($moment->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('fired_at'));

		return $qb->executeStatement() === 1;
	}//end claim()

	/**
	 * Record the run a claimed moment queued.
	 *
	 * @param FlowMoment $moment  The moment.
	 * @param string     $runUuid The run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function recordRun(FlowMoment $moment, string $runUuid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('run_uuid', $qb->createNamedParameter($runUuid))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($moment->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}//end recordRun()

	/**
	 * Remove every moment of one record, for every trigger.
	 *
	 * @param string $objectUuid The record.
	 *
	 * @return int The rows removed.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/specs/flow-engine/spec.md#scenario-a-cleared-date-or-a-deleted-record-cancels-the-run
	 */
	public function deleteForObject(string $objectUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('object_uuid', $qb->createNamedParameter($objectUuid)));

		return $qb->executeStatement();
	}//end deleteForObject()
}//end class
