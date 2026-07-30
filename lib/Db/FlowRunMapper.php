<?php

/**
 * Persistence for flow runs.
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes flow runs.
 *
 * @template-extends QBMapper<FlowRun>
 */
class FlowRunMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_flow_runs', entityClass: FlowRun::class);

    }//end __construct()

    /**
     * Find a run by its public uuid.
     *
     * @param string $uuid The run uuid.
     *
     * @return FlowRun The run.
     *
     * @throws \OCP\AppFramework\Db\DoesNotExistException When no such run exists.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function findByUuid(string $uuid): FlowRun
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));

        return $this->findEntity(query: $qb);

    }//end findByUuid()

    /**
     * List runs, newest first.
     *
     * @param string|null $flowId Restrict to one flow.
     * @param string|null $status Restrict to one status.
     * @param integer     $limit  Page size.
     * @param integer     $offset Page offset.
     *
     * @return array<int, FlowRun> The runs.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function findAllRuns(?string $flowId=null, ?string $status=null, int $limit=50, int $offset=0): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($flowId !== null && $flowId !== '') {
            $qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        return $this->findEntities(query: $qb);

    }//end findAllRuns()

    /**
     * The runs that are still going, newest first.
     *
     * "Still going" is every NON-terminal status — `queued` (about to start),
     * `running` (executing now) and `suspended` (mid-graph, waiting on a timer
     * or a child run). A dashboard that showed only `running` would be empty
     * almost all of the time: a run holds that status for the duration of one
     * worker pass, while `queued` and `suspended` are where a run actually
     * spends its wall-clock.
     *
     * Scoping is STRICT: pass an organisation and only that organisation's runs
     * come back. A run recorded before runs carried an organisation (or queued
     * with no session to attribute it to) has none, and is therefore returned
     * to nobody — deliberately, since this feeds a widget every app renders to
     * every user, and guessing a tenant for an unattributed run would leak one
     * tenant's activity into another's dashboard.
     *
     * @param string|null $organisation Restrict to one organisation uuid.
     * @param integer     $limit        Page size.
     *
     * @return array<int, FlowRun> The non-terminal runs.
     *
     * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
     */
    public function findActive(?string $organisation=null, int $limit=25): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)
                )
            )
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        if ($organisation !== null && $organisation !== '') {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
        }

        return $this->findEntities(query: $qb);

    }//end findActive()

    /**
     * How many runs are still going, for one organisation.
     *
     * Separate from {@see findActive} because a widget shows a bounded list but
     * an honest total — "3 of 47 running" needs the 47, and paging the whole
     * set to count it would be absurd.
     *
     * @param string|null $organisation Restrict to one organisation uuid.
     *
     * @return integer The number of non-terminal runs.
     *
     * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
     */
    public function countActive(?string $organisation=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*) AS `total`'))
            ->from($this->getTableName())
            ->where(
                $qb->expr()->in(
                    'status',
                    $qb->createNamedParameter(FlowRun::ACTIVE, IQueryBuilder::PARAM_STR_ARRAY)
                )
            );

        if ($organisation !== null && $organisation !== '') {
            $qb->andWhere($qb->expr()->eq('organisation', $qb->createNamedParameter($organisation)));
        }

        $result = $qb->executeQuery();
        $total  = (int) $result->fetchOne();
        $result->closeCursor();

        return $total;

    }//end countActive()

    /**
     * Suspended runs that are due to resume.
     *
     * A run with no `resume_at` is waiting on something other than a clock — a
     * child run, or a webhook — and is NOT picked up here. Resuming those on a
     * timer would run them before whatever they are waiting for arrives.
     *
     * @param DateTime $now   The current time.
     * @param integer  $limit Maximum runs to claim in one pass.
     *
     * @return array<int, FlowRun> The due runs.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function findDue(DateTime $now, int $limit=25): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_SUSPENDED)))
            ->andWhere($qb->expr()->isNotNull('resume_at'))
            ->andWhere(
                $qb->expr()->lte(
                    'resume_at',
                    $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)
                )
            )
            ->orderBy('resume_at', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities(query: $qb);

    }//end findDue()

    /**
     * Runs left in `running` by a worker pass that never came back.
     *
     * `execute()` sets `running` and clears it when the walk returns. A pass
     * that dies instead — a fatal, a PHP timeout, an OOM, a container
     * restart — never clears it, and no `catch` can help because the process
     * is gone. The row then sits in `running` forever: the worker will not
     * pick it up (it only reads `queued` and due `suspended` runs), so it is
     * not just stale, it is unreachable.
     *
     * That was invisible while nothing read `running`. It stops being
     * invisible the moment a dashboard widget shows live runs — 68 such rows
     * existed on one dev instance, the oldest two days old, and every one of
     * them would have read as "running right now".
     *
     * @param DateTime $before Runs not touched since this moment are stale.
     * @param integer  $limit  Maximum runs to reap in one pass.
     *
     * @return array<int, FlowRun> The abandoned runs.
     *
     * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
     */
    public function findStale(DateTime $before, int $limit=25): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_RUNNING)))
            ->andWhere(
                $qb->expr()->lt(
                    'updated',
                    $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
                )
            )
            ->orderBy('updated', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities(query: $qb);

    }//end findStale()

    /**
     * Runs waiting in the queue to start.
     *
     * @param integer $limit Maximum runs to claim in one pass.
     *
     * @return array<int, FlowRun> The queued runs.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function findQueued(int $limit=25): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(FlowRun::STATUS_QUEUED)))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities(query: $qb);

    }//end findQueued()

    /**
     * Delete terminal runs older than a cut-off.
     *
     * Runs are operational data and grow without bound; this instance has
     * already been taken down once by an unbounded log file. Only TERMINAL
     * runs are eligible — deleting a suspended run would strand whatever it
     * was waiting for.
     *
     * @param DateTime $before Delete terminal runs last updated before this.
     *
     * @return integer The number of runs deleted.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function pruneBefore(DateTime $before): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter(FlowRun::TERMINAL, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere(
                $qb->expr()->lt(
                    'updated',
                    $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_MUTABLE)
                )
            );

        return (int) $qb->executeStatement();

    }//end pruneBefore()
}//end class
