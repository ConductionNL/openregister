<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Service\Object\ReferentialIntegrityService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Branch coverage tests for ReferentialIntegrityService — targets uncovered
 * branches in canDelete, isValidOnDeleteAction, logRestrictBlock,
 * hasIncomingOnDeleteReferences, applyDeletionActions.
 */
class ReferentialIntegrityServiceBranchTest extends TestCase
{
    private ReferentialIntegrityService $service;
    private SchemaMapper&MockObject $schemaMapper;
    private RegisterMapper&MockObject $registerMapper;
    private MagicMapper&MockObject $objectMapper;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->registerMapper = $this->createMock(RegisterMapper::class);
        $this->objectMapper = $this->createMock(MagicMapper::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ReferentialIntegrityService(
            $this->schemaMapper,
            $this->registerMapper,
            $this->objectMapper,
            $this->auditTrailMapper,
            $this->logger,
            $this->createMock(IDBConnection::class)
        );
    }

    private function createObjectMock(string $uuid, ?string $schemaId = null): ObjectEntity&MockObject
    {
        $mock = $this->getMockBuilder(ObjectEntity::class)
            ->addMethods(['getUuid', 'getSchema', 'getRegister', 'getDeleted'])
            ->getMock();
        $mock->method('getUuid')->willReturn($uuid);
        $mock->method('getSchema')->willReturn($schemaId);
        $mock->method('getDeleted')->willReturn(null);
        return $mock;
    }

    // =========================================================================
    // isValidOnDeleteAction
    // =========================================================================

    public function testIsValidOnDeleteActionCascade(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('CASCADE'));
    }

    public function testIsValidOnDeleteActionRestrict(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('RESTRICT'));
    }

    public function testIsValidOnDeleteActionSetNull(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_NULL'));
    }

    public function testIsValidOnDeleteActionSetDefault(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('SET_DEFAULT'));
    }

    public function testIsValidOnDeleteActionNoAction(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('NO_ACTION'));
    }

    public function testIsValidOnDeleteActionLowercase(): void
    {
        $this->assertTrue(ReferentialIntegrityService::isValidOnDeleteAction('cascade'));
    }

    public function testIsValidOnDeleteActionInvalid(): void
    {
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction('INVALID'));
    }

    public function testIsValidOnDeleteActionEmpty(): void
    {
        $this->assertFalse(ReferentialIntegrityService::isValidOnDeleteAction(''));
    }

    // =========================================================================
    // canDelete — null schema
    // =========================================================================

    public function testCanDeleteNullSchemaReturnsDeletable(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $object = $this->createObjectMock('uuid-1', null);

        $result = $this->service->canDelete($object);
        $this->assertTrue($result->deletable);
        $this->assertSame([], $result->blockers);
    }

    public function testCanDeleteNoIncomingReferences(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $object = $this->createObjectMock('uuid-2', '999');

        $result = $this->service->canDelete($object);
        $this->assertTrue($result->deletable);
    }

    // =========================================================================
    // hasIncomingOnDeleteReferences
    // =========================================================================

    public function testHasIncomingOnDeleteReferencesNoReferences(): void
    {
        $this->schemaMapper->method('findAll')->willReturn([]);
        $this->registerMapper->method('findAll')->willReturn([]);

        $result = $this->service->hasIncomingOnDeleteReferences('999');
        $this->assertFalse($result);
    }

    // =========================================================================
    // logRestrictBlock
    // =========================================================================

    public function testLogRestrictBlockLogsAuditTrail(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: [
                [
                    'objectUuid' => 'blocker-uuid-1',
                    'schema' => '10',
                    'property' => 'parentRef',
                    'action' => 'RESTRICT',
                ],
                [
                    'objectUuid' => 'blocker-uuid-2',
                    'schema' => '10',
                    'property' => 'parentRef',
                    'action' => 'RESTRICT',
                ],
            ]
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        $this->service->logRestrictBlock('target-uuid', '5', $analysis, 'admin');
    }

    public function testLogRestrictBlockEmptyBlockers(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: false,
            blockers: []
        );

        $this->auditTrailMapper->expects($this->once())
            ->method('insert');

        $this->service->logRestrictBlock('target-uuid', null, $analysis, 'admin');
    }

    // =========================================================================
    // applyDeletionActions — empty analysis
    // =========================================================================

    public function testApplyDeletionActionsEmptyAnalysis(): void
    {
        $analysis = DeletionAnalysis::empty();

        $this->service->applyDeletionActions(
            $analysis,
            'admin',
            'root-uuid',
            'org-1',
            'my-schema'
        );

        $this->assertTrue(true);
    }

    // =========================================================================
    // DeletionAnalysis
    // =========================================================================

    public function testDeletionAnalysisToArray(): void
    {
        $analysis = new DeletionAnalysis(
            deletable: true,
            cascadeTargets: [['objectUuid' => 'a']],
            nullifyTargets: [['objectUuid' => 'b']],
            defaultTargets: [],
            blockers: [],
            chainPaths: ['a -> b']
        );

        $arr = $analysis->toArray();
        $this->assertTrue($arr['deletable']);
        $this->assertCount(1, $arr['cascadeTargets']);
        $this->assertCount(1, $arr['nullifyTargets']);
    }

    public function testDeletionAnalysisEmpty(): void
    {
        $analysis = DeletionAnalysis::empty();
        $this->assertTrue($analysis->deletable);
        $this->assertSame([], $analysis->cascadeTargets);
    }

    // =========================================================================
    // ensureRelationIndex — schema load failure
    // =========================================================================

    public function testCanDeleteHandlesSchemaLoadFailure(): void
    {
        $this->schemaMapper->method('findAll')
            ->willThrowException(new \Exception('DB error'));

        $object = $this->createObjectMock('uuid-3', '5');

        $result = $this->service->canDelete($object);
        $this->assertTrue($result->deletable);
    }
}
