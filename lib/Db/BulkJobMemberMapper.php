<?php

/**
 * Mapper for BulkJobMember entities (the per-object outcome side table).
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
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Class BulkJobMemberMapper
 *
 * @template-extends QBMapper<BulkJobMember>
 */
class BulkJobMemberMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_bulk_job_members', entityClass: BulkJobMember::class);

	}//end __construct()

	/**
	 * Find the members of a job, optionally filtered by outcome.
	 *
	 * @param int $jobId The job id.
	 * @param string|null $outcome Optional outcome filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return BulkJobMember[] The members.
	 */
	public function findByJob(int $jobId, ?string $outcome = null, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		if ($outcome !== null) {
			$qb->andWhere($qb->expr()->eq('outcome', $qb->createNamedParameter($outcome)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findByJob()

	/**
	 * Find the next batch of members a commit still has to walk.
	 *
	 * Members that were actually WRITTEN are excluded, which is what makes a
	 * retry idempotent: a job that failed at member 300 of 400 resumes at 301
	 * rather than acting on the first 300 a second time (D-5). The test is
	 * `applied_at`, never the outcome column: a member the preview reports as
	 * "would apply" has not been written yet, and excluding it here would
	 * make the commit skip exactly the members it exists to act on.
	 *
	 * @param int $jobId The job id.
	 * @param int $afterId Only members with a higher id.
	 * @param int $limit The batch size.
	 *
	 * @return BulkJobMember[] The members still to walk.
	 */
	public function findPendingBatch(int $jobId, int $afterId, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($afterId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('applied_at'))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findPendingBatch()

	/**
	 * How many members the commit has walked so far.
	 *
	 * Members are walked in id order and the job's cursor holds the last one,
	 * so the count of members at or below it is the honest position: it does
	 * not move when a preview outcome changes, and it restarts when a retry
	 * resets the cursor, which is what that run actually did.
	 *
	 * @param int $jobId The job id.
	 * @param int $cursor The job's cursor.
	 *
	 * @return int The number of members walked.
	 */
	public function countWalked(int $jobId, int $cursor): int {
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('id'), 'member_count')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('id', $qb->createNamedParameter($cursor, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		if ($row === false) {
			return 0;
		}

		return (int)$row['member_count'];
	}//end countWalked()

	/**
	 * Count the members of a job per outcome.
	 *
	 * @param int $jobId The job id.
	 *
	 * @return array<string, int> Outcome name to count.
	 */
	public function countByOutcome(int $jobId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('outcome')
			->selectAlias($qb->func()->count('id'), 'member_count')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)))
			->groupBy('outcome');

		$result = $qb->executeQuery();
		$counts = [];

		foreach ($result->fetchAll() as $row) {
			$counts[(string)$row['outcome']] = (int)$row['member_count'];
		}

		$result->closeCursor();

		return $counts;
	}//end countByOutcome()

	/**
	 * The uuids already held by a job, for the commit-time delta.
	 *
	 * @param int $jobId The job id.
	 *
	 * @return array<int, string> The member uuids.
	 */
	public function findUuidsByJob(int $jobId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_uuid')
			->from($this->getTableName())
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$uuids = [];

		foreach ($result->fetchAll() as $row) {
			$uuids[] = (string)$row['object_uuid'];
		}

		$result->closeCursor();

		return $uuids;
	}//end findUuidsByJob()

	/**
	 * Create a member from an array of values.
	 *
	 * @param array<string, mixed> $data The member values.
	 *
	 * @return BulkJobMember The persisted member.
	 */
	public function createFromArray(array $data): BulkJobMember {
		$member = new BulkJobMember();
		$member->hydrate($data);

		$now = new DateTime();
		if ($member->getCreated() === null) {
			$member->setCreated($now);
		}

		$member->setUpdated($now);

		return $this->insert(entity: $member);
	}//end createFromArray()

	/**
	 * Persist a member's outcome, refreshing the updated timestamp.
	 *
	 * @param BulkJobMember $member The member to persist.
	 *
	 * @return BulkJobMember The updated member.
	 */
	public function save(BulkJobMember $member): BulkJobMember {
		$member->setUpdated(new DateTime());

		return $this->update(entity: $member);
	}//end save()

	/**
	 * Delete every member of a job.
	 *
	 * @param int $jobId The job id.
	 *
	 * @return int The number of deleted rows.
	 */
	public function deleteByJob(int $jobId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('job_id', $qb->createNamedParameter($jobId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}//end deleteByJob()
}//end class
