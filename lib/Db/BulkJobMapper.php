<?php

/**
 * Mapper for BulkJob entities.
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Symfony\Component\Uid\Uuid;

/**
 * Class BulkJobMapper
 *
 * @template-extends QBMapper<BulkJob>
 */
class BulkJobMapper extends QBMapper {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_bulk_jobs', entityClass: BulkJob::class);

	}//end __construct()

	/**
	 * Find a job by its numeric id.
	 *
	 * @param int $id The job id.
	 *
	 * @return BulkJob The job.
	 *
	 * @throws DoesNotExistException When no such job exists.
	 */
	public function find(int $id): BulkJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $this->findEntity(query: $qb);
	}//end find()

	/**
	 * Find a job by its uuid.
	 *
	 * @param string $uuid The job uuid.
	 *
	 * @return BulkJob The job.
	 *
	 * @throws DoesNotExistException When no such job exists.
	 */
	public function findByUuid(string $uuid): BulkJob {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

		return $this->findEntity(query: $qb);
	}//end findByUuid()

	/**
	 * List the jobs of one actor, newest first.
	 *
	 * @param string $startedBy The actor's uid.
	 * @param string|null $state Optional state filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return BulkJob[] The actor's jobs.
	 */
	public function findByActor(string $startedBy, ?string $state = null, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('started_by', $qb->createNamedParameter($startedBy)))
			->orderBy('id', 'DESC');

		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findByActor()

	/**
	 * List every job, newest first. For the administrator's console.
	 *
	 * @param string|null $state Optional state filter.
	 * @param int|null $limit Optional page size.
	 * @param int|null $offset Optional page offset.
	 *
	 * @return BulkJob[] The jobs.
	 */
	public function findAllJobs(?string $state = null, ?int $limit = null, ?int $offset = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('id', 'DESC');

		if ($state !== null) {
			$qb->andWhere($qb->expr()->eq('state', $qb->createNamedParameter($state)));
		}

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}

		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities(query: $qb);
	}//end findAllJobs()

	/**
	 * Create a job from an array of values, assigning a uuid and timestamps.
	 *
	 * @param array<string, mixed> $data The job values.
	 *
	 * @return BulkJob The persisted job.
	 */
	public function createFromArray(array $data): BulkJob {
		$job = new BulkJob();
		$job->hydrate($data);

		if ($job->getUuid() === null) {
			$job->setUuid(Uuid::v4()->toRfc4122());
		}

		$now = new DateTime();
		if ($job->getCreated() === null) {
			$job->setCreated($now);
		}

		$job->setUpdated($now);

		return $this->insert(entity: $job);
	}//end createFromArray()

	/**
	 * Persist job progress or state, refreshing the updated timestamp.
	 *
	 * @param BulkJob $job The job to persist.
	 *
	 * @return BulkJob The updated job.
	 */
	public function save(BulkJob $job): BulkJob {
		$job->setUpdated(new DateTime());

		return $this->update(entity: $job);
	}//end save()
}//end class
