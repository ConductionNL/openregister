<?php

declare(strict_types=1);

/**
 * ArchivalService Unit Tests
 *
 * Tests for the archival and destruction workflow service including
 * retention metadata validation, date calculation, destruction list
 * generation, approval, and rejection.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\DestructionList;
use OCA\OpenRegister\Db\DestructionListMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SelectionList;
use OCA\OpenRegister\Db\SelectionListMapper;
use OCA\OpenRegister\Service\ArchivalService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test class for ArchivalService
 */
class ArchivalServiceTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private SelectionListMapper&MockObject $selectionListMapper;
    private DestructionListMapper&MockObject $destructionListMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private LoggerInterface&MockObject $logger;
    private ArchivalService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db                    = $this->createMock(IDBConnection::class);
        $this->selectionListMapper   = $this->createMock(SelectionListMapper::class);
        $this->destructionListMapper = $this->createMock(DestructionListMapper::class);
        $this->auditTrailMapper      = $this->createMock(AuditTrailMapper::class);
        $this->logger                = $this->createMock(LoggerInterface::class);

        $this->service = new ArchivalService(
            $this->db,
            $this->selectionListMapper,
            $this->destructionListMapper,
            $this->auditTrailMapper,
            $this->logger
        );
    }

    // ==================================================================================
    // setRetentionMetadata tests
    // ==================================================================================

    /**
     * Test setting valid retention metadata with all fields.
     */
    public function testSetRetentionMetadataValidFull(): void
    {
        $object = new ObjectEntity();
        $retention = [
            'archiefnominatie'  => 'vernietigen',
            'archiefactiedatum' => '2031-03-01',
            'archiefstatus'     => 'nog_te_archiveren',
            'classificatie'     => 'B1',
        ];

        $result = $this->service->setRetentionMetadata($object, $retention);

        $resultRetention = $result->getRetention();
        $this->assertSame('vernietigen', $resultRetention['archiefnominatie']);
        $this->assertSame('nog_te_archiveren', $resultRetention['archiefstatus']);
        $this->assertSame('B1', $resultRetention['classificatie']);
        $this->assertNotNull($resultRetention['archiefactiedatum']);
    }

    /**
     * Test that defaults are applied when optional fields are missing.
     */
    public function testSetRetentionMetadataDefaults(): void
    {
        $object    = new ObjectEntity();
        $retention = ['classificatie' => 'A1'];

        $result = $this->service->setRetentionMetadata($object, $retention);

        $resultRetention = $result->getRetention();
        $this->assertSame('nog_niet_bepaald', $resultRetention['archiefnominatie']);
        $this->assertSame('nog_te_archiveren', $resultRetention['archiefstatus']);
    }

    /**
     * Test that invalid archiefnominatie throws exception.
     */
    public function testSetRetentionMetadataInvalidNominatie(): void
    {
        $object    = new ObjectEntity();
        $retention = ['archiefnominatie' => 'invalid_value'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid archiefnominatie');

        $this->service->setRetentionMetadata($object, $retention);
    }

    /**
     * Test that invalid archiefstatus throws exception.
     */
    public function testSetRetentionMetadataInvalidStatus(): void
    {
        $object    = new ObjectEntity();
        $retention = [
            'archiefnominatie' => 'vernietigen',
            'archiefstatus'    => 'bad_status',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid archiefstatus');

        $this->service->setRetentionMetadata($object, $retention);
    }

    /**
     * Test that invalid date format throws exception.
     */
    public function testSetRetentionMetadataInvalidDateFormat(): void
    {
        $object    = new ObjectEntity();
        $retention = [
            'archiefnominatie'  => 'vernietigen',
            'archiefactiedatum' => 'not-a-date',
        ];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid archiefactiedatum format');

        $this->service->setRetentionMetadata($object, $retention);
    }

    /**
     * Test that existing retention data is preserved when merging.
     */
    public function testSetRetentionMetadataMergesExisting(): void
    {
        $object = new ObjectEntity();
        $object->setRetention(['customField' => 'preserved']);

        $retention = ['archiefnominatie' => 'bewaren'];

        $result          = $this->service->setRetentionMetadata($object, $retention);
        $resultRetention = $result->getRetention();

        $this->assertSame('preserved', $resultRetention['customField']);
        $this->assertSame('bewaren', $resultRetention['archiefnominatie']);
    }

    // ==================================================================================
    // calculateArchivalDate tests
    // ==================================================================================

    /**
     * Test calculating archival date with standard retention.
     */
    public function testCalculateArchivalDateStandard(): void
    {
        $selectionList = new SelectionList();
        $selectionList->setRetentionYears(5);

        $closeDate = new DateTime('2026-03-01');

        $result = $this->service->calculateArchivalDate($selectionList, $closeDate);

        $this->assertSame('2031-03-01', $result->format('Y-m-d'));
    }

    /**
     * Test calculating archival date with schema override.
     */
    public function testCalculateArchivalDateWithSchemaOverride(): void
    {
        $selectionList = new SelectionList();
        $selectionList->setRetentionYears(10);
        $selectionList->setSchemaOverrides(['schema-uuid-123' => 20]);

        $closeDate = new DateTime('2026-03-01');

        $result = $this->service->calculateArchivalDate(
            $selectionList,
            $closeDate,
            'schema-uuid-123'
        );

        $this->assertSame('2046-03-01', $result->format('Y-m-d'));
    }

    /**
     * Test calculating archival date without matching schema override uses default.
     */
    public function testCalculateArchivalDateNoMatchingOverride(): void
    {
        $selectionList = new SelectionList();
        $selectionList->setRetentionYears(10);
        $selectionList->setSchemaOverrides(['other-schema' => 20]);

        $closeDate = new DateTime('2026-03-01');

        $result = $this->service->calculateArchivalDate(
            $selectionList,
            $closeDate,
            'non-existing-schema'
        );

        $this->assertSame('2036-03-01', $result->format('Y-m-d'));
    }

    /**
     * Test with zero retention years.
     */
    public function testCalculateArchivalDateZeroYears(): void
    {
        $selectionList = new SelectionList();
        $selectionList->setRetentionYears(0);

        $closeDate = new DateTime('2026-06-15');

        $result = $this->service->calculateArchivalDate($selectionList, $closeDate);

        $this->assertSame('2026-06-15', $result->format('Y-m-d'));
    }

    // ==================================================================================
    // generateDestructionList tests
    // ==================================================================================

    /**
     * Test that null is returned when no objects are due for destruction.
     */
    public function testGenerateDestructionListEmpty(): void
    {
        // Mock the database query to return no results.
        $qb     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr   = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $expr->method('like')->willReturn('dummy');
        $qb->method('createNamedParameter')->willReturn('dummy');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor');

        $list = $this->service->generateDestructionList();

        $this->assertNull($list);
    }

    /**
     * Test that destruction list is created when objects are found.
     */
    public function testGenerateDestructionListWithObjects(): void
    {
        $pastDate = (new DateTime('-1 year'))->format('c');

        // Mock DB query to return one object.
        $qb     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr   = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $expr->method('like')->willReturn('dummy');
        $qb->method('createNamedParameter')->willReturn('dummy');
        $qb->method('executeQuery')->willReturn($result);

        $row = [
            'uuid'      => 'obj-uuid-1',
            'register'  => '1',
            'schema'    => '1',
            'name'      => 'Test Object',
            'retention' => json_encode([
                'archiefnominatie'  => 'vernietigen',
                'archiefstatus'     => 'nog_te_archiveren',
                'archiefactiedatum' => $pastDate,
            ]),
        ];

        $callCount = 0;
        $result->method('fetch')->willReturnCallback(function () use (&$callCount, $row) {
            $callCount++;
            return $callCount === 1 ? $row : false;
        });
        $result->method('closeCursor');

        // Mock destruction list creation.
        $createdList = new DestructionList();
        $createdList->setUuid('dl-uuid-1');
        $createdList->setObjects(['obj-uuid-1']);
        $createdList->setStatus(DestructionList::STATUS_PENDING_REVIEW);

        $this->destructionListMapper
            ->expects($this->once())
            ->method('createEntry')
            ->willReturnCallback(function (DestructionList $list) use ($createdList) {
                $this->assertContains('obj-uuid-1', $list->getObjects());
                return $createdList;
            });

        $generated = $this->service->generateDestructionList();

        $this->assertNotNull($generated);
        $this->assertSame('dl-uuid-1', $generated->getUuid());
    }

    // ==================================================================================
    // approveDestructionList tests
    // ==================================================================================

    /**
     * Test approving a destruction list that is not in pending_review status.
     */
    public function testApproveDestructionListInvalidStatus(): void
    {
        $list = new DestructionList();
        $list->setStatus(DestructionList::STATUS_COMPLETED);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Must be \'pending_review\'');

        $this->service->approveDestructionList($list, 'admin');
    }

    /**
     * Test approving a destruction list with objects.
     */
    public function testApproveDestructionListSuccess(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-uuid-1');
        $list->setStatus(DestructionList::STATUS_PENDING_REVIEW);
        $list->setObjects(['obj-uuid-1']);

        // Mock DB for destroyObject.
        $qb     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr   = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('delete')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('dummy');
        $qb->method('createNamedParameter')->willReturn('dummy');
        $qb->method('executeQuery')->willReturn($result);
        $qb->method('executeStatement')->willReturn(1);

        $result->method('fetch')->willReturn([
            'uuid'     => 'obj-uuid-1',
            'register' => '1',
            'schema'   => '1',
            'name'     => 'Test',
        ]);
        $result->method('closeCursor');

        $this->auditTrailMapper
            ->expects($this->once())
            ->method('createAuditTrail');

        $this->destructionListMapper
            ->expects($this->once())
            ->method('updateEntry')
            ->willReturnCallback(function (DestructionList $l) {
                $this->assertSame(DestructionList::STATUS_COMPLETED, $l->getStatus());
                return $l;
            });

        $resultArr = $this->service->approveDestructionList($list, 'admin');

        $this->assertSame(1, $resultArr['destroyed']);
        $this->assertSame(0, $resultArr['errors']);
    }

    // ==================================================================================
    // rejectFromDestructionList tests
    // ==================================================================================

    /**
     * Test rejecting from a non-pending list throws exception.
     */
    public function testRejectFromDestructionListInvalidStatus(): void
    {
        $list = new DestructionList();
        $list->setStatus(DestructionList::STATUS_COMPLETED);

        $this->expectException(InvalidArgumentException::class);

        $this->service->rejectFromDestructionList($list, ['obj-1']);
    }

    /**
     * Test rejecting objects removes them from the list.
     */
    public function testRejectFromDestructionListRemovesObjects(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-1');
        $list->setStatus(DestructionList::STATUS_PENDING_REVIEW);
        $list->setObjects(['obj-1', 'obj-2', 'obj-3']);

        // Mock DB for extendRetentionForObject — return empty row so it gracefully skips.
        $qb     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr   = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('dummy');
        $qb->method('createNamedParameter')->willReturn('dummy');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor');

        $this->destructionListMapper
            ->expects($this->once())
            ->method('updateEntry')
            ->willReturnCallback(function (DestructionList $l) {
                $this->assertCount(1, $l->getObjects());
                $this->assertContains('obj-2', $l->getObjects());
                return $l;
            });

        $updated = $this->service->rejectFromDestructionList($list, ['obj-1', 'obj-3']);

        $this->assertSame(DestructionList::STATUS_PENDING_REVIEW, $updated->getStatus());
    }

    /**
     * Test rejecting all objects cancels the list.
     */
    public function testRejectAllObjectsCancelsList(): void
    {
        $list = new DestructionList();
        $list->setUuid('dl-1');
        $list->setStatus(DestructionList::STATUS_PENDING_REVIEW);
        $list->setObjects(['obj-1']);

        // Mock DB.
        $qb     = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
        $expr   = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
        $result = $this->createMock(\OCP\DB\IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $expr->method('eq')->willReturn('dummy');
        $qb->method('createNamedParameter')->willReturn('dummy');
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(false);
        $result->method('closeCursor');

        $this->destructionListMapper
            ->expects($this->once())
            ->method('updateEntry')
            ->willReturnCallback(function (DestructionList $l) {
                $this->assertSame(DestructionList::STATUS_CANCELLED, $l->getStatus());
                $this->assertCount(0, $l->getObjects());
                return $l;
            });

        $this->service->rejectFromDestructionList($list, ['obj-1']);
    }
}
