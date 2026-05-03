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
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for the realtime event log table.
 *
 * @template-extends QBMapper<RealtimeEvent>
 */
class RealtimeEventMapper extends QBMapper
{
    /**
     * Construct the mapper bound to the realtime events table.
     *
     * @param IDBConnection $db Database connection handle.
     *
     * @return void
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(db: $db, tableName: 'openregister_realtime_events', entityClass: RealtimeEvent::class);
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
     * @param int|null              $since   Lower-bound cursor; null returns from the beginning.
     * @param int                   $limit   Maximum number of rows to return.
     * @param array<string, scalar> $filters Optional filter map (see column map above).
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

        return $this->findEntities(query: $qb);
    }//end findSince()

    /**
     * Get the highest id in the log — used by clients to fast-forward
     * past historical events on initial subscription.
     *
     * @return int Highest event id, or 0 when the log is empty.
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
     *
     * @param int $retentionSeconds Maximum age in seconds before rows are deleted.
     *
     * @return int Number of rows deleted.
     */
    public function deleteOlderThan(int $retentionSeconds): int
    {
        $cutoff = (new DateTime())->modify("-{$retentionSeconds} seconds");
        $qb     = $this->db->getQueryBuilder();
        $qb->delete('openregister_realtime_events')
            ->where(
                $qb->expr()->lt('created', $qb->createNamedParameter($cutoff, IQueryBuilder::PARAM_DATE))
            );
        return $qb->executeStatement();
    }//end deleteOlderThan()
}//end class
