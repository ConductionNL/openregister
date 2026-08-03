<?php

/**
 * Reads and writes flow-run steps.
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
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Reads and writes flow-run steps.
 *
 * @template-extends QBMapper<FlowRunStep>
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
 */
class FlowRunStepMapper extends QBMapper
{
    /**
     * Constructor.
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_flow_steps', entityClass: FlowRunStep::class);

    }//end __construct()

    /**
     * Every step of one run, in walk order.
     *
     * @param string $runUuid The run uuid.
     *
     * @return array<int, FlowRunStep> The steps.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function findByRun(string $runUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)))
            ->orderBy('sequence', 'ASC');

        return $this->findEntities(query: $qb);

    }//end findByRun()

    /**
     * The highest sequence recorded for a run so far.
     *
     * A resumed run must APPEND, so the walk continues numbering from here
     * rather than restarting at zero — otherwise a suspended-then-resumed run
     * reads as two overlapping histories and `ORDER BY sequence` interleaves
     * them.
     *
     * @param string $runUuid The run uuid.
     *
     * @return integer The highest recorded sequence, or -1 when the run has no steps.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function highestSequence(string $runUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('sequence', 'top'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        $top = ($row['top'] ?? null);
        if ($top === null) {
            return -1;
        }

        return (int) $top;

    }//end highestSequence()

    /**
     * Steps filtered by node type and/or status, newest first.
     *
     * This is the diagnostic read the table exists for: "which node type fails",
     * answered without loading and walking every run's log blob.
     *
     * @param string|null $nodeType Restrict to one catalogue node id.
     * @param string|null $status   Restrict to one step status.
     * @param string|null $flowId   Restrict to one flow.
     * @param integer     $limit    Page size.
     * @param integer     $offset   Page offset.
     *
     * @return array<int, FlowRunStep> The steps.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function findSteps(
        ?string $nodeType=null,
        ?string $status=null,
        ?string $flowId=null,
        int $limit=100,
        int $offset=0
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($nodeType !== null && $nodeType !== '') {
            $qb->andWhere($qb->expr()->eq('node_type', $qb->createNamedParameter($nodeType)));
        }

        if ($status !== null && $status !== '') {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
        }

        if ($flowId !== null && $flowId !== '') {
            $qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
        }

        return $this->findEntities(query: $qb);

    }//end findSteps()

    /**
     * Delete the steps of one run.
     *
     * @param string $runUuid The run uuid.
     *
     * @return integer The number of rows removed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function deleteByRun(string $runUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('run_uuid', $qb->createNamedParameter($runUuid)));

        return (int) $qb->executeStatement();

    }//end deleteByRun()

    /**
     * Delete steps older than a cutoff, optionally for one flow only.
     *
     * `$flowId` is what makes a per-flow retention override work: the sweep
     * applies the instance cutoff to everything EXCEPT the flows that declare
     * their own, then applies each of those flows' own cutoff by id.
     *
     * @param DateTime    $cutoff Steps created before this are removed.
     * @param string|null $flowId Restrict the deletion to one flow.
     *
     * @return integer The number of rows removed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function deleteOlderThan(DateTime $cutoff, ?string $flowId=null): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->lt(
                    'created',
                    $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)
                )
            );

        if ($flowId !== null && $flowId !== '') {
            $qb->andWhere($qb->expr()->eq('flow_id', $qb->createNamedParameter($flowId)));
        }

        return (int) $qb->executeStatement();

    }//end deleteOlderThan()

    /**
     * Delete steps older than a cutoff, EXCLUDING a set of flows.
     *
     * The instance-wide half of the sweep: every flow that does not declare its
     * own retention. Excluding by id rather than filtering in PHP keeps the
     * delete a single statement regardless of how many runs are involved.
     *
     * @param DateTime           $cutoff         Steps created before this are removed.
     * @param array<int, string> $excludeFlowIds Flow ids with their own retention.
     *
     * @return integer The number of rows removed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    public function deleteOlderThanExcluding(DateTime $cutoff, array $excludeFlowIds): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->lt(
                    'created',
                    $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE)
                )
            );

        if (empty($excludeFlowIds) === false) {
            $qb->andWhere(
                $qb->expr()->notIn(
                    'flow_id',
                    $qb->createNamedParameter($excludeFlowIds, IQueryBuilder::PARAM_STR_ARRAY)
                )
            );
        }

        return (int) $qb->executeStatement();

    }//end deleteOlderThanExcluding()
}//end class
