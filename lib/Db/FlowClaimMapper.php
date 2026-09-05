<?php

/**
 * Mapper for place claims — the database as the mutual-exclusion primitive.
 *
 * `insertOrRefuse()` is the whole lock: an INSERT that the unique index on
 * `(run_uuid, place)` either admits or refuses. No `SELECT ... FOR UPDATE` is
 * ever taken on this table and nothing waits on a claim. The rest is release
 * and recovery: a holder releases what it took, the reaper finds what a dead
 * holder left behind.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Place claims.
 *
 * @template-extends QBMapper<FlowClaim>
 */
class FlowClaimMapper extends QBMapper {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'openregister_flow_claims', entityClass: FlowClaim::class);
	}//end __construct()

	/**
	 * Take one claim, or be refused by the unique index.
	 *
	 * Returns false ONLY on a unique-constraint violation — another holder has
	 * the place. Every other database failure propagates: a claim that fails
	 * for an unrelated reason must not read as "somebody else has it".
	 *
	 * @param FlowClaim $claim The claim to take.
	 *
	 * @return bool True when the claim landed.
	 *
	 * @throws DbException On any database failure other than a unique violation.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-firing-must-exclusively-claim-every-place-it-touches
	 */
	public function insertOrRefuse(FlowClaim $claim): bool {
		try {
			$this->insert(entity: $claim);
		} catch (DbException $e) {
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}

			return false;
		}

		return true;
	}//end insertOrRefuse()

	/**
	 * Release the named places of one run.
	 *
	 * @param string $runUuid The run.
	 * @param array<int, string> $places The places to release.
	 *
	 * @return int Rows deleted.
	 */
	public function release(string $runUuid, array $places): int {
		if ($places === []) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->in('place', $qb->createNamedParameter(array_values($places), IQueryBuilder::PARAM_STR_ARRAY)));

		return $qb->executeStatement();
	}//end release()

	/**
	 * Release every claim a holder took on a run.
	 *
	 * @param string $runUuid The run.
	 * @param string $owner The holder's pass token.
	 *
	 * @return int Rows deleted.
	 */
	public function releaseByOwner(string $runUuid, string $owner): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($owner)));

		return $qb->executeStatement();
	}//end releaseByOwner()

	/**
	 * How many claims a run currently has outstanding — the per-run cap's input.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Claims held.
	 */
	public function countHeldForRun(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'held'))
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		$result = $qb->executeQuery();
		$held = (int)$result->fetchOne();
		$result->closeCursor();

		return $held;
	}//end countHeldForRun()

	/**
	 * How many claims one pass holds across ALL runs — the pass ceiling's input.
	 *
	 * @param string $owner The pass token.
	 *
	 * @return int Claims held.
	 */
	public function countHeldByOwner(string $owner): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'held'))
			->from($this->getTableName())
			->where($qb->expr()->eq('owner', $qb->createNamedParameter($owner)));

		$result = $qb->executeQuery();
		$held = (int)$result->fetchOne();
		$result->closeCursor();

		return $held;
	}//end countHeldByOwner()

	/**
	 * The claims a run currently holds.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return array<int, FlowClaim> The claims.
	 */
	public function findByRun(string $runUuid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
			->orderBy('place', 'ASC');

		return $this->findEntities(query: $qb);
	}//end findByRun()

	/**
	 * Claims older than a cutoff — the reaper's read.
	 *
	 * @param DateTime $before Claims taken before this moment.
	 * @param int $limit Bound on one pass.
	 *
	 * @return array<int, FlowClaim> The abandoned claims.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-branch-abandoned-by-a-crashed-worker-must-be-recovered-and-must-not-be-silently-re-run
	 */
	public function findOlderThan(DateTime $before, int $limit = 25): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lt('claimed_at', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATE)))
			->orderBy('claimed_at', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findOlderThan()

	/**
	 * Drop every claim of a run — with the run's own deletion.
	 *
	 * @param string $runUuid The run.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteByRun(string $runUuid): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

		return $qb->executeStatement();
	}//end deleteByRun()

	/**
	 * Drop every claim row whose run no longer exists — the retention pass
	 * prunes runs by age, and their claims go with them.
	 *
	 * @return int Rows deleted.
	 */
	public function deleteOrphans(): int {
		$runs = $this->db->getQueryBuilder();
		$runs->select('uuid')->from('openregister_flow_runs');

		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->createFunction('run_uuid NOT IN (' . $runs->getSQL() . ')'));

		return $qb->executeStatement();
	}//end deleteOrphans()
}//end class
