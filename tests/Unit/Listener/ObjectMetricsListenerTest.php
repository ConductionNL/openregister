<?php

declare(strict_types=1);

/**
 * ObjectMetricsListener unit tests (openregister#393).
 *
 * Proves `MetricsService::recordMetric()` now actually RUNS on its real trigger —
 * the object lifecycle events dispatched by MagicMapper — and that it produces its
 * artefact: an INSERT of a populated row into `openregister_metrics`.
 *
 * Before this listener existed, `recordMetric()` had zero callers anywhere in lib/:
 * the table was created by a migration and never written to, so the entire
 * production-observability metrics feature was dead while looking healthy.
 *
 * The tests deliberately drive a REAL MetricsService (over a mocked query builder)
 * rather than asserting on a mocked service, so they verify the row that would be
 * written — metric type, entity, and the register/schema metadata the spec's
 * `{register=…,schema=…}` counter labels are derived from.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\ObjectMetricsListener;
use OCA\OpenRegister\Service\MetricsService;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\ObjectMetricsListener
 * @covers \OCA\OpenRegister\Service\MetricsService::recordMetric
 */
class ObjectMetricsListenerTest extends TestCase
{
    /**
     * Captured `values()` payload of the INSERT the service builds.
     *
     * @var array<string, mixed>|null
     */
    private ?array $insertedValues = null;

    /**
     * Table the INSERT targeted.
     */
    private ?string $insertedTable = null;

    private ObjectMetricsListener $listener;

    protected function setUp(): void
    {
        parent::setUp();

        // A query builder that records what recordMetric() tries to insert, and
        // echoes each named parameter back as its literal value so the captured
        // payload is directly assertable.
        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('createNamedParameter')->willReturnArgument(0);
        $qb->method('insert')->willReturnCallback(
            function (string $table) use ($qb): IQueryBuilder {
                $this->insertedTable = $table;
                return $qb;
            }
        );
        $qb->method('values')->willReturnCallback(
            function (array $values) use ($qb): IQueryBuilder {
                // MetricsService passes a list containing one row map.
                $this->insertedValues = $values[0];
                return $qb;
            }
        );
        $qb->method('executeStatement')->willReturn(1);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $metricsService = new MetricsService($db, $this->createMock(LoggerInterface::class));

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('alice');
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn($user);

        $this->listener = new ObjectMetricsListener(
            $metricsService,
            $userSession,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * Build an ObjectEntity carrying the identity the metric row records.
     */
    private function makeObject(): ObjectEntity
    {
        $object = new ObjectEntity();
        $object->setUuid('obj-uuid-1');
        $object->setRegister('zaken');
        $object->setSchema('meldingen');

        return $object;
    }

    /**
     * Creating an object writes an `object_created` metric row.
     */
    public function testObjectCreatedEventWritesACreatedMetricRow(): void
    {
        $this->listener->handle(new ObjectCreatedEvent(object: $this->makeObject()));

        $this->assertSame('openregister_metrics', $this->insertedTable);
        $this->assertIsArray($this->insertedValues, 'No metric row was inserted at all.');
        $this->assertSame(MetricsService::METRIC_OBJECT_CREATED, $this->insertedValues['metric_type']);
        $this->assertSame('object_created', $this->insertedValues['metric_type']);
        $this->assertSame('object', $this->insertedValues['entity_type']);
        $this->assertSame('obj-uuid-1', $this->insertedValues['entity_id']);
        $this->assertSame('success', $this->insertedValues['status']);
        $this->assertSame('alice', $this->insertedValues['user_id']);

        // The register/schema labels the spec's counters are derived from.
        $metadata = json_decode($this->insertedValues['metadata'], true);
        $this->assertSame('zaken', $metadata['register']);
        $this->assertSame('meldingen', $metadata['schema']);
    }

    /**
     * Updating an object writes an `object_updated` metric row (from the NEW object).
     */
    public function testObjectUpdatedEventWritesAnUpdatedMetricRow(): void
    {
        $this->listener->handle(
            new ObjectUpdatedEvent(newObject: $this->makeObject(), oldObject: $this->makeObject())
        );

        $this->assertSame(MetricsService::METRIC_OBJECT_UPDATED, $this->insertedValues['metric_type']);
        $this->assertSame('obj-uuid-1', $this->insertedValues['entity_id']);
    }

    /**
     * Deleting an object writes an `object_deleted` metric row.
     */
    public function testObjectDeletedEventWritesADeletedMetricRow(): void
    {
        $this->listener->handle(new ObjectDeletedEvent(object: $this->makeObject()));

        $this->assertSame(MetricsService::METRIC_OBJECT_DELETED, $this->insertedValues['metric_type']);
        $this->assertSame('obj-uuid-1', $this->insertedValues['entity_id']);
    }

    /**
     * Observability must never break the write it observes: a metrics failure is
     * swallowed, not propagated to the object save/delete that triggered it.
     */
    public function testMetricsFailureNeverBreaksTheObjectWrite(): void
    {
        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willThrowException(new \RuntimeException('db down'));

        $listener = new ObjectMetricsListener(
            new MetricsService($db, $this->createMock(LoggerInterface::class)),
            $this->createMock(IUserSession::class),
            $this->createMock(LoggerInterface::class)
        );

        $listener->handle(new ObjectCreatedEvent(object: $this->makeObject()));

        $this->addToAssertionCount(1);
    }
}
