<?php

/**
 * OpenRegister RealtimeEventMapper
 *
 * Reads + writes `openregister_realtime_events`. Cursor-based polling:
 * clients call `findSince(?int $since, int $limit, ?array $filters)` to
 * page through the append-only event log.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RealtimeEvent>
 */
class RealtimeEventMapper extends QBMapper
{
    public function __construct(IDBConnection $db)
    {
        parent::__construct($db, 'openregister_realtime_events', RealtimeEvent::class);
    }//end __construct()

    /**
     * Find events with id strictly greater than `$since` (or all events
     * when `$since` is null), most-recent-id-first. Optional filters:
     *
     * - `register`     — exact registerId match
     * - `schema`       — exact schemaId match
     * - `objectUuid`   — exact uuid match (per-object subscriptions)
     * - `eventType`    — exact event-type match (e.g. `or.object.updated`)
     * - `organisation` — exact organisation match (multi-tenancy gate)
     *
     * @param array<string, scalar> $filters
     *
     * @return RealtimeEvent[]
     *
     * @psalm-return list<RealtimeEvent>
     */
    public function findSince(?int $since=null, int $limit=100, array $filters=[]): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('openregister_realtime_events');

        if ($since !== null) {
            $qb->andWhere(
                $qb->expr()->gt('id', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT))
            );
        }

        $columnMap = [
            'register'     => 'register_id',
            'schema'       => 'schema_id',
            'objectUuid'   => 'object_uuid',
            'eventType'    => 'event_type',
            'organisation' => 'organisation',
        ];
        foreach ($filters as $key => $value) {
            if (isset($columnMap[$key]) === false || $value === null || $value === '') {
                continue;
            }

            $qb->andWhere(
                $qb->expr()->eq($columnMap[$key], $qb->createNamedParameter((string) $value))
            );
        }

        $qb->orderBy('id', 'ASC');
        $qb->setMaxResults(max(1, min(1000, $limit)));

        return $this->findEntities($qb);
    }//end findSince()

    /**
     * Get the highest id in the log — used by clients to fast-forward
     * past historical events on initial subscription.
     */
    public function getMaxId(): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('MAX(id) AS max_id'))
            ->from('openregister_realtime_events');
        $result = $qb->executeQuery()->fetch();
        return (int) ($result['max_id'] ?? 0);
    }//end getMaxId()

    /**
     * Prune events older than `$retentionSeconds`. Used by a daily TimedJob
     * to keep the event log bounded.
     */
    public function deleteOlderThan(int $retentionSeconds): int
    {
        $cutoff = (new \DateTime())->modify("-{$retentionSeconds} seconds");
        $qb     = $this->db->getQueryBuilder();
        $qb->delete('openregister_realtime_events')
            ->where(
                $qb->expr()->lt('created', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE))
            );
        return $qb->executeStatement();
    }//end deleteOlderThan()
}//end class
