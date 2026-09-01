<?php

/**
 * Persistence for {@see FlowTimer}: the two bounded range scans of the sweep,
 * the subject and run reads that cancellation needs, and the conditional
 * terminal claim.
 *
 * The sweep reads are `WHERE state = 'armed' AND fire_at <= :now` (expiries)
 * and `WHERE state = 'armed' AND next_rung_at <= :now` (rungs), each ordered
 * and LIMITed, served by `or_flowtimer_due_idx` and `or_flowtimer_rung_idx`.
 * Every row read is a row acted on — never a page of open rows filtered in
 * PHP afterwards, which is the measured failure mode this store replaces
 * (design D-8).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Mapper
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for `openregister_flow_timers`.
 *
 * @template-extends QBMapper<FlowTimer>
 */
class FlowTimerMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_timers', entityClass: FlowTimer::class);

	}//end __construct()

	/**
	 * Insert, stamping `created`.
	 *
	 * @param Entity $entity The timer to insert.
	 *
	 * @return FlowTimer The inserted timer, with its id.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function insert(Entity $entity): FlowTimer {
		if ($entity instanceof FlowTimer === false) {
			throw new InvalidArgumentException('FlowTimerMapper persists FlowTimer entities only.');
		}

		if ($entity->getCreated() === null) {
			$entity->setCreated(new DateTime());
		}

		return parent::insert(entity: $entity);
	}//end insert()

	/**
	 * Update, stamping `updated`.
	 *
	 * @param Entity $entity The timer to update.
	 *
	 * @return FlowTimer The updated timer.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function update(Entity $entity): FlowTimer {
		if ($entity instanceof FlowTimer === false) {
			throw new InvalidArgumentException('FlowTimerMapper persists FlowTimer entities only.');
		}

		$entity->setUpdated(new DateTime());

		return parent::update(entity: $entity);
	}//end update()

	/**
	 * Find a timer by its public uuid.
	 *
	 * @param string $uuid The timer uuid.
	 *
	 * @return FlowTimer The timer.
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such timer exists.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findByUuid(string $uuid): FlowTimer {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * The expiry range scan: armed ENFORCING-PURPOSE timers whose fire moment
	 * has passed, oldest first, bounded.
	 *
	 * `purpose = expiry` is part of the predicate because a `due` timer stays
	 * ARMED past its fire moment by design — that is what makes it overdue on
	 * read — and must not be re-selected every pass.
	 *
	 * @param DateTimeInterface $now The sweep instant.
	 * @param int $limit The batch limit.
	 *
	 * @return array<int, FlowTimer> The due expiry timers.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function findDueExpiries(DateTimeInterface $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(FlowTimer::STATE_ARMED)))
			->andWhere($qb->expr()->eq('purpose', $qb->createNamedParameter(FlowTimer::PURPOSE_EXPIRY)))
			->andWhere($qb->expr()->isNotNull('fire_at'))
			->andWhere($qb->expr()->lte('fire_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('fire_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDueExpiries()

	/**
	 * The rung range scan: armed timers whose next unfired rung is due, bounded.
	 *
	 * @param DateTimeInterface $now The sweep instant.
	 * @param int $limit The batch limit.
	 *
	 * @return array<int, FlowTimer> The timers with a due rung.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function findDueRungs(DateTimeInterface $now, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(FlowTimer::STATE_ARMED)))
			->andWhere($qb->expr()->isNotNull('next_rung_at'))
			->andWhere($qb->expr()->lte('next_rung_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)))
			->orderBy('next_rung_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findDueRungs()

	/**
	 * Every timer bound to a subject, optionally restricted to a state set.
	 *
	 * @param string $subjectType The subject type.
	 * @param string $subjectUuid The subject uuid.
	 * @param array<int, string> $states Restrict to these states; empty means all.
	 *
	 * @return array<int, FlowTimer> The subject's timers, oldest first.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findBySubject(string $subjectType, string $subjectUuid, array $states = []): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('subject_type', $qb->createNamedParameter($subjectType)))
			->andWhere($qb->expr()->eq('subject_uuid', $qb->createNamedParameter($subjectUuid)))
			->orderBy('id', 'ASC');

		if ($states !== []) {
			$qb->andWhere($qb->expr()->in('state', $qb->createNamedParameter($states, IQueryBuilder::PARAM_STR_ARRAY)));
		}

		return $this->findEntities(query: $qb);
	}//end findBySubject()

	/**
	 * The OPEN timers a run terminality reaches: bound to the run as subject,
	 * or carrying it as provenance.
	 *
	 * @param string $runUuid The run uuid.
	 *
	 * @return array<int, FlowTimer> The open timers.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findOpenByRun(string $runUuid): array {
		$qb = $this->db->getQueryBuilder();
		$runParam = $qb->createNamedParameter($runUuid);
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('run_uuid', $runParam),
					$qb->expr()->andX(
						$qb->expr()->eq('subject_type', $qb->createNamedParameter('run')),
						$qb->expr()->eq('subject_uuid', $runParam)
					)
				)
			)
			->andWhere(
				$qb->expr()->in(
					'state',
					$qb->createNamedParameter([FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED], IQueryBuilder::PARAM_STR_ARRAY)
				)
			)
			->orderBy('id', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findOpenByRun()

	/**
	 * The successors of a timer: rows that supersede it, newest first.
	 *
	 * @param string $uuid The superseded timer's uuid.
	 *
	 * @return array<int, FlowTimer> The successors.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-deadlines-anchor-is-stored-so-a-moved-anchor-re-arms-the-timer
	 */
	public function findSuccessors(string $uuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('supersedes_uuid', $qb->createNamedParameter($uuid)))
			->orderBy('id', 'DESC');

		return $this->findEntities(query: $qb);
	}//end findSuccessors()

	/**
	 * Every timer in a state, paged by id, for the invariant check.
	 *
	 * @param string $state The state.
	 * @param int $afterId Return rows with an id above this.
	 * @param int $limit The page size.
	 *
	 * @return array<int, FlowTimer> The page.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function findByStatePaged(string $state, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findByStatePaged()

	/**
	 * CLAIM the terminal fire of a timer: `SET state = 'fired' WHERE uuid = ?
	 * AND state = 'armed'`. Zero affected rows means another pass owns it, so
	 * the outcome is applied at most once (design D-8).
	 *
	 * @param string $uuid The timer uuid.
	 * @param DateTimeInterface $firedAt The claim instant.
	 *
	 * @return boolean True when this caller won the claim.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-the-sweep-is-bounded-to-due-work-by-index-not-by-a-page-of-candidates
	 */
	public function claimFired(string $uuid, DateTimeInterface $firedAt): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(FlowTimer::STATE_FIRED))
			->set('fired_at', $qb->createNamedParameter($firedAt, IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->set('updated', $qb->createNamedParameter(new DateTime(), IQueryBuilder::PARAM_DATETIME_MUTABLE))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)))
			->andWhere($qb->expr()->eq('state', $qb->createNamedParameter(FlowTimer::STATE_ARMED)));

		return $qb->executeStatement() === 1;
	}//end claimFired()
}//end class
