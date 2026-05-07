<?php

/**
 * Integration tests for `RealtimeEventRetentionJob`.
 *
 * The realtime-updates spec defers SSE + notify_push to v1.1, but
 * pinpoints retention pruning as a v1 follow-up: "wire a daily TimedJob
 * that calls `deleteOlderThan(7 * 86400)` for default 7-day retention".
 * This test locks the contract on the new job:
 *
 *   1. Default retention (no override) is 7 days — events older than
 *      that are deleted; events inside the window survive.
 *   2. Setting `realtime_event_retention_seconds` to `0` disables the
 *      prune entirely (the job ticks but the row count is unchanged).
 *   3. A custom retention window (e.g. 1 day) is honoured, proving the
 *      app-config override path works end-to-end.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\BackgroundJob\RealtimeEventRetentionJob;
use OCA\OpenRegister\Db\RealtimeEventMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;
use Symfony\Component\Uid\Uuid;

/**
 * @group DB
 */
class RealtimeEventRetentionJobTest extends TestCase
{

    private RealtimeEventMapper $mapper;

    private IAppConfig $appConfig;

    private IDBConnection $db;

    private RealtimeEventRetentionJob $job;

    /**
     * Auto-increment IDs of rows we inserted so tearDown only removes
     * the rows this test created.
     *
     * @var array<int, int>
     */
    private array $insertedEventIds = [];

    private ?string $previousRetention = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper    = \OC::$server->get(RealtimeEventMapper::class);
        $this->appConfig = \OC::$server->get(IAppConfig::class);
        $this->db        = \OC::$server->get(IDBConnection::class);

        // Snapshot any operator override so we can restore it after the
        // test — we mutate this key for a couple of cases.
        $this->previousRetention = $this->appConfig->getValueString(
            app: 'openregister',
            key: 'realtime_event_retention_seconds',
            default: ''
        );

        $this->job = new RealtimeEventRetentionJob(
            time: \OC::$server->get(ITimeFactory::class),
            appConfig: $this->appConfig,
            eventMapper: $this->mapper,
            logger: new NullLogger()
        );

    }//end setUp()

    protected function tearDown(): void
    {
        // Restore the original retention setting.
        if ($this->previousRetention === '' || $this->previousRetention === null) {
            try {
                $this->appConfig->deleteKey(app: 'openregister', key: 'realtime_event_retention_seconds');
            } catch (\Throwable) {
            }
        } else {
            $this->appConfig->setValueString(
                app: 'openregister',
                key: 'realtime_event_retention_seconds',
                value: $this->previousRetention
            );
        }

        // Clean any rows we inserted.
        foreach ($this->insertedEventIds as $eventId) {
            try {
                $qb = $this->db->getQueryBuilder();
                $qb->delete('openregister_realtime_events')
                    ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)));
                $qb->executeStatement();
            } catch (\Throwable) {
            }
        }

        parent::tearDown();

    }//end tearDown()

    public function testPrunesEventsOlderThanRetentionWindow(): void
    {
        // Two rows: one 10 days old, one 1 day old. Default retention
        // is 7 days, so the 10-day row MUST disappear.
        $oldId    = $this->insertEvent(daysAgo: 10);
        $recentId = $this->insertEvent(daysAgo: 1);

        $this->runJob();

        $this->assertFalse($this->eventExists($oldId), 'event older than the 7-day window MUST be pruned');
        $this->assertTrue($this->eventExists($recentId), 'event inside the 7-day window MUST survive');

    }//end testPrunesEventsOlderThanRetentionWindow()

    public function testRetentionDisabledByZeroSetting(): void
    {
        $this->appConfig->setValueString(
            app: 'openregister',
            key: 'realtime_event_retention_seconds',
            value: '0'
        );

        $oldId = $this->insertEvent(daysAgo: 30);

        $this->runJob();

        $this->assertTrue(
            $this->eventExists($oldId),
            'a 30-day-old event MUST NOT be pruned when retention is disabled (value=0)'
        );

    }//end testRetentionDisabledByZeroSetting()

    public function testCustomRetentionWindowIsHonoured(): void
    {
        // Operator override: 1-day retention. A 2-day-old event MUST go.
        $this->appConfig->setValueString(
            app: 'openregister',
            key: 'realtime_event_retention_seconds',
            value: (string) (1 * 86400)
        );

        $oldId    = $this->insertEvent(daysAgo: 2);
        $recentId = $this->insertEvent(daysAgo: 0);

        $this->runJob();

        $this->assertFalse($this->eventExists($oldId), 'override of 1-day retention MUST prune the 2-day-old row');
        $this->assertTrue(
            $this->eventExists($recentId),
            'today-old event MUST survive the 1-day retention window'
        );

    }//end testCustomRetentionWindowIsHonoured()

    /**
     * Insert a synthetic event row directly via the DB layer.
     *
     * The realtime listener writes through `RealtimeService::record`,
     * which depends on a full ObjectEntity context — far too heavy for
     * a retention test. We construct minimal rows by hand so we can
     * control the `created` timestamp precisely.
     */
    private function insertEvent(int $daysAgo): int
    {
        $traceUuid = 'phpunit-realtime-'.Uuid::v4()->toRfc4122();
        $createdAt = (new \DateTime())->modify('-'.$daysAgo.' days');

        $qb = $this->db->getQueryBuilder();
        $qb->insert('openregister_realtime_events')
            ->values(
                [
                    'event_type'   => $qb->createNamedParameter('object.created'),
                    'source'       => $qb->createNamedParameter('phpunit'),
                    'subject'      => $qb->createNamedParameter('urn:nl-or:test:phpunit:retention:'.$traceUuid),
                    'register_id'  => $qb->createNamedParameter('phpunit-retention'),
                    'schema_id'    => $qb->createNamedParameter('phpunit-retention'),
                    'object_uuid'  => $qb->createNamedParameter($traceUuid),
                    'actor_uid'    => $qb->createNamedParameter('phpunit'),
                    'organisation' => $qb->createNamedParameter(null),
                    'payload'      => $qb->createNamedParameter(json_encode(['phpunit' => 'retention'])),
                    'created'      => $qb->createNamedParameter($createdAt, IQueryBuilder::PARAM_DATE),
                ]
            );
        $qb->executeStatement();

        $eventId = (int) $this->db->lastInsertId('oc_openregister_realtime_events_id_seq');
        $this->insertedEventIds[] = $eventId;

        return $eventId;

    }//end insertEvent()

    private function eventExists(int $eventId): bool
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('openregister_realtime_events')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($eventId, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $row    = $result->fetch();
        $result->closeCursor();

        return is_array($row);

    }//end eventExists()

    /**
     * `TimedJob::run()` is protected; the integration scheduler invokes
     * it through reflection. We mirror that here.
     */
    private function runJob(): void
    {
        $method = new ReflectionMethod(RealtimeEventRetentionJob::class, 'run');
        $method->setAccessible(true);
        $method->invoke($this->job, null);

    }//end runJob()
}//end class
