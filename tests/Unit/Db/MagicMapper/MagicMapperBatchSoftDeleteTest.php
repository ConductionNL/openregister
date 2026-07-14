<?php
/**
 * MagicMapper batched cascade soft delete unit tests.
 *
 * Covers softDeleteMultipleObjectEntities(): one UPDATE per magic table via a
 * parameterised CASE expression, per-object updating/updated event parity,
 * hook-stop skipping, and validation of malformed input.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\DateTimeNormalizer;
use OCA\OpenRegister\Service\SettingsService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for MagicMapper::softDeleteMultipleObjectEntities().
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MagicMapperBatchSoftDeleteTest extends TestCase
{

    private IDBConnection&MockObject $db;

    private IEventDispatcher&MockObject $eventDispatcher;

    /**
     * Every event passed to dispatchTyped(), in dispatch order.
     *
     * @var array<int, object>
     */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->createMock(IDBConnection::class);
        $this->eventDispatcher  = $this->createMock(IEventDispatcher::class);
        $this->dispatchedEvents = [];
    }//end setUp()

    /**
     * Build a MagicMapper with mocked dependencies.
     *
     * @return MagicMapper
     */
    private function makeMapper(): MagicMapper
    {
        $dateTimeNormalizer  = $this->createMock(DateTimeNormalizer::class);
        $conditionMatcher    = $this->createMock(\OCA\OpenRegister\Service\ConditionMatcher::class);
        $schemaTypeConverter = $this->createMock(\OCA\OpenRegister\Service\Object\SchemaTypeConverter::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) use ($dateTimeNormalizer, $conditionMatcher, $schemaTypeConverter) {
                if ($id === DateTimeNormalizer::class) {
                    return $dateTimeNormalizer;
                }

                if ($id === \OCA\OpenRegister\Service\ConditionMatcher::class) {
                    return $conditionMatcher;
                }

                if ($id === \OCA\OpenRegister\Service\Object\SchemaTypeConverter::class) {
                    return $schemaTypeConverter;
                }

                return null;
            }
        );

        return new MagicMapper(
            $this->db,
            $this->createMock(SchemaMapper::class),
            $this->createMock(RegisterMapper::class),
            $this->createMock(IConfig::class),
            $this->eventDispatcher,
            $this->createMock(IUserSession::class),
            $this->createMock(IGroupManager::class),
            $this->createMock(IUserManager::class),
            $this->createMock(IAppConfig::class),
            $this->createMock(LoggerInterface::class),
            $this->createMock(SettingsService::class),
            $container
        );
    }//end makeMapper()

    /**
     * Record every dispatched event; optionally stop propagation for one uuid.
     *
     * @param string|null $stopForUuid Stop ObjectUpdatingEvent propagation for this uuid.
     *
     * @return void
     */
    private function recordDispatchedEvents(?string $stopForUuid=null): void
    {
        $this->eventDispatcher->method('dispatchTyped')->willReturnCallback(
            function (object $event) use ($stopForUuid): void {
                $this->dispatchedEvents[] = $event;
                if ($stopForUuid !== null
                    && $event instanceof ObjectUpdatingEvent === true
                    && $event->getNewObject()->getUuid() === $stopForUuid
                ) {
                    $event->stopPropagation();
                }
            }
        );
    }//end recordDispatchedEvents()

    /**
     * Build an UPDATE query-builder mock that records the CASE expression and
     * named parameters handed to it.
     *
     * @param array $captured Reference array receiving 'case', 'params', 'table' keys.
     *
     * @return IQueryBuilder&MockObject
     */
    private function makeUpdateQb(array &$captured): IQueryBuilder
    {
        $qb   = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->method('in')->willReturn('in-cond');
        $qb->method('expr')->willReturn($expr);

        $qb->method('createNamedParameter')->willReturnCallback(
            function ($value) use (&$captured) {
                $captured['params'][] = $value;
                return ':p'.count($captured['params']);
            }
        );
        $qb->method('createFunction')->willReturnCallback(
            function (string $sql) use (&$captured) {
                $captured['case'][] = $sql;
                return $sql;
            }
        );
        $qb->method('update')->willReturnCallback(
            function (string $table) use (&$captured, $qb) {
                $captured['table'][] = $table;
                return $qb;
            }
        );
        foreach (['set', 'where'] as $chain) {
            $qb->method($chain)->willReturnSelf();
        }

        $qb->method('executeStatement')->willReturn(1);
        return $qb;
    }//end makeUpdateQb()

    /**
     * Build an ObjectEntity with uuid, register/schema ids and deleted metadata set.
     *
     * @param string $uuid     Entity uuid.
     * @param string $register Register id (string, as entities carry them).
     * @param string $schema   Schema id.
     *
     * @return ObjectEntity
     */
    private function makeDeletedEntity(string $uuid, string $register='1', string $schema='2'): ObjectEntity
    {
        $entity = new ObjectEntity();
        $entity->setUuid($uuid);
        $entity->setRegister($register);
        $entity->setSchema($schema);
        $entity->setDeleted(
            [
                'deletedBy'    => 'alice',
                'deletedAt'    => '2026-07-14T00:00:00+00:00',
                'objectId'     => $uuid,
                'organisation' => null,
            ]
        );
        return $entity;
    }//end makeDeletedEntity()

    // -------------------------------------------------------------------------
    // softDeleteMultipleObjectEntities()
    // -------------------------------------------------------------------------

    public function testEmptyInputReturnsEmptyArrayWithoutTouchingDatabase(): void
    {
        $mapper = $this->makeMapper();

        $this->db->expects($this->never())->method('getQueryBuilder');

        $this->assertSame([], $mapper->softDeleteMultipleObjectEntities(entities: []));
    }//end testEmptyInputReturnsEmptyArrayWithoutTouchingDatabase()

    public function testNonObjectEntityInputThrows(): void
    {
        $mapper = $this->makeMapper();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('expects ObjectEntity instances');

        $mapper->softDeleteMultipleObjectEntities(entities: [new \stdClass()]);
    }//end testNonObjectEntityInputThrows()

    public function testBatchIssuesOneUpdatePerMagicTableWithCaseExpression(): void
    {
        $mapper = $this->makeMapper();
        $this->recordDispatchedEvents();

        // Two entities in table 1_2, one in table 3_4 -> exactly TWO UPDATE builders.
        $entityA = $this->makeDeletedEntity('uuid-a', '1', '2');
        $entityB = $this->makeDeletedEntity('uuid-b', '1', '2');
        $entityC = $this->makeDeletedEntity('uuid-c', '3', '4');

        $captured = ['case' => [], 'params' => [], 'table' => []];
        $qb1      = $this->makeUpdateQb($captured);
        $qb2      = $this->makeUpdateQb($captured);
        $this->db->expects($this->exactly(2))
            ->method('getQueryBuilder')
            ->willReturnOnConsecutiveCalls($qb1, $qb2);

        $result = $mapper->softDeleteMultipleObjectEntities(entities: [$entityA, $entityB, $entityC]);

        $this->assertCount(3, $result);
        $this->assertSame(['openregister_table_1_2', 'openregister_table_3_4'], $captured['table']);

        // First table's CASE covers both uuids; JSON deleted metadata is bound per row.
        $this->assertStringContainsString('CASE _uuid', $captured['case'][0]);
        $this->assertSame(2, substr_count($captured['case'][0], ' WHEN '));
        $this->assertSame(1, substr_count($captured['case'][1], ' WHEN '));
        $this->assertContains('uuid-a', $captured['params']);
        $this->assertContains(json_encode($entityA->getDeleted()), $captured['params']);
        $this->assertContains(json_encode($entityC->getDeleted()), $captured['params']);
    }//end testBatchIssuesOneUpdatePerMagicTableWithCaseExpression()

    public function testDispatchesUpdatingAndUpdatedEventsPerEntity(): void
    {
        $mapper = $this->makeMapper();
        $this->recordDispatchedEvents();

        $entityA = $this->makeDeletedEntity('uuid-a');
        $entityB = $this->makeDeletedEntity('uuid-b');

        $captured = ['case' => [], 'params' => [], 'table' => []];
        $this->db->method('getQueryBuilder')->willReturn($this->makeUpdateQb($captured));

        $oldA = clone $entityA;
        $mapper->softDeleteMultipleObjectEntities(
            entities: [$entityA, $entityB],
            oldEntities: ['uuid-a' => $oldA]
        );

        $updating = array_values(
            array_filter($this->dispatchedEvents, fn($e) => $e instanceof ObjectUpdatingEvent)
        );
        $updated  = array_values(
            array_filter($this->dispatchedEvents, fn($e) => $e instanceof ObjectUpdatedEvent)
        );

        $this->assertCount(2, $updating);
        $this->assertCount(2, $updated);
        // The supplied pre-delete snapshot is used as the event old-object.
        $this->assertSame($oldA, $updating[0]->getOldObject());
        $this->assertSame($entityA, $updated[0]->getNewObject());
    }//end testDispatchesUpdatingAndUpdatedEventsPerEntity()

    public function testHookStoppedEntityIsSkippedButOthersAreDeleted(): void
    {
        $mapper = $this->makeMapper();
        $this->recordDispatchedEvents(stopForUuid: 'uuid-b');

        $entityA = $this->makeDeletedEntity('uuid-a');
        $entityB = $this->makeDeletedEntity('uuid-b');

        $captured = ['case' => [], 'params' => [], 'table' => []];
        $this->db->method('getQueryBuilder')->willReturn($this->makeUpdateQb($captured));

        $result = $mapper->softDeleteMultipleObjectEntities(entities: [$entityA, $entityB]);

        $this->assertCount(1, $result);
        $this->assertSame('uuid-a', $result[0]->getUuid());
        // The rejected entity never reaches the UPDATE nor gets an Updated event.
        $this->assertNotContains('uuid-b', $captured['params']);
        $updated = array_values(
            array_filter($this->dispatchedEvents, fn($e) => $e instanceof ObjectUpdatedEvent)
        );
        $this->assertCount(1, $updated);
    }//end testHookStoppedEntityIsSkippedButOthersAreDeleted()

    public function testEntityWithoutScopeIsSkippedWithoutError(): void
    {
        $mapper = $this->makeMapper();
        $this->recordDispatchedEvents();

        $noScope = new ObjectEntity();
        $noScope->setUuid('uuid-no-scope');

        $valid = $this->makeDeletedEntity('uuid-valid');

        $captured = ['case' => [], 'params' => [], 'table' => []];
        $this->db->method('getQueryBuilder')->willReturn($this->makeUpdateQb($captured));

        $result = $mapper->softDeleteMultipleObjectEntities(entities: [$noScope, $valid]);

        $this->assertCount(1, $result);
        $this->assertSame('uuid-valid', $result[0]->getUuid());
    }//end testEntityWithoutScopeIsSkippedWithoutError()
}//end class
