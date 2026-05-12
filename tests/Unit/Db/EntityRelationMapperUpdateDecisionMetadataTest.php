<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for `EntityRelationMapper::updateDecisionMetadata`.
 *
 * Covers the per-spec contract of the audited decision-metadata write
 * path: whitelist enforcement, shape validation, diff-awareness, and
 * audit-trail emission. Inherited `find()` / `update()` are mocked.
 *
 * @spec openspec/changes/entity-relation-grondslagen/specs/entity-relation-grondslagen/spec.md
 */
class EntityRelationMapperUpdateDecisionMetadataTest extends TestCase
{
    private IDBConnection&MockObject $db;
    private AuditTrailMapper&MockObject $auditTrailMapper;
    private IUserSession&MockObject $userSession;
    private LoggerInterface&MockObject $logger;
    private IUser&MockObject $user;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->user = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('alice');
        $this->user->method('getDisplayName')->willReturn('Alice');
    }

    /**
     * Build a mapper with `update()` (the only concrete inherited write method) mocked.
     */
    private function mapperWithMockedUpdate(): EntityRelationMapper&MockObject
    {
        $mapper = $this->getMockBuilder(EntityRelationMapper::class)
            ->setConstructorArgs([
                $this->db,
                $this->auditTrailMapper,
                $this->userSession,
                $this->logger,
            ])
            ->onlyMethods(['update'])
            ->getMock();

        $mapper->method('update')->willReturnArgument(0);

        return $mapper;
    }

    public function testRejectsUnknownField(): void
    {
        $relation = new EntityRelation();
        $mapper = $this->mapperWithMockedUpdate();

        $this->auditTrailMapper->expects($this->never())->method('insert');

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/anonymized/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['anonymized' => true], actingUser: $this->user);
    }

    public function testRejectsBasesAsNonArray(): void
    {
        $relation = new EntityRelation();
        $mapper = $this->mapperWithMockedUpdate();

        $this->auditTrailMapper->expects($this->never())->method('insert');

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/bases shape/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['bases' => 'uuid-a'], actingUser: $this->user);
    }

    public function testRejectsBasesArrayWithNonStringElement(): void
    {
        $relation = new EntityRelation();
        $mapper = $this->mapperWithMockedUpdate();

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/bases shape/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['bases' => ['uuid-a', 42]], actingUser: $this->user);
    }

    public function testRejectsSkipAnonymizationAsNonBool(): void
    {
        $relation = new EntityRelation();
        $mapper = $this->mapperWithMockedUpdate();

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/skipAnonymization shape/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => 'yes'], actingUser: $this->user);
    }

    public function testSemanticNoOpSkipsAuditEmission(): void
    {
        $relation = new EntityRelation();
        $relation->setBases(['uuid-a']);
        $relation->setSkipAnonymization(true);

        $mapper = $this->mapperWithMockedUpdate();
        $mapper->expects($this->never())->method('update');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-a'], 'skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertSame(['uuid-a'], $result->getBases());
        $this->assertTrue($result->getSkipAnonymization());
    }

    public function testEmptyBodyIsNoOp(): void
    {
        $relation = new EntityRelation();
        $mapper = $this->mapperWithMockedUpdate();

        $mapper->expects($this->never())->method('update');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $result = $mapper->updateDecisionMetadata(relation: $relation, fields: [], actingUser: $this->user);

        $this->assertNull($result->getBases());
        $this->assertFalse($result->getSkipAnonymization());
    }

    public function testSettingBasesForFirstTimeEmitsAuditEntry(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $mapper = $this->mapperWithMockedUpdate();

        $captured = null;
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $entry) use (&$captured) {
                $captured = $entry;
                return $entry;
            });

        $mapper->expects($this->once())->method('update');

        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-a']],
            actingUser: $this->user
        );

        $this->assertSame(['uuid-a'], $result->getBases());

        $this->assertNotNull($captured);
        $this->assertSame('entity_relation_decision_updated', $captured->getAction());
        $this->assertSame('alice', $captured->getUser());

        $changed = $captured->getChanged();
        $this->assertSame('openregister_entity_relations', $changed['subjectType']);
        $this->assertSame(42, $changed['subjectId']);
        $this->assertArrayHasKey('bases', $changed['fields']);
        $this->assertSame(['previous' => null, 'new' => ['uuid-a']], $changed['fields']['bases']);
        $this->assertArrayNotHasKey('skipAnonymization', $changed['fields']);
    }

    public function testFlippingSkipEmitsAuditEntry(): void
    {
        $relation = new EntityRelation();
        $relation->setId(7);
        $relation->setSkipAnonymization(false);

        $mapper = $this->mapperWithMockedUpdate();

        $captured = null;
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $entry) use (&$captured) {
                $captured = $entry;
                return $entry;
            });

        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertTrue($result->getSkipAnonymization());
        $this->assertNotNull($captured);
        $changed = $captured->getChanged();
        $this->assertSame(['previous' => false, 'new' => true], $changed['fields']['skipAnonymization']);
        $this->assertArrayNotHasKey('bases', $changed['fields']);
    }

    public function testPartialChangeOnlyAuditsTheChangedField(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $relation->setBases(['uuid-a']);
        $relation->setSkipAnonymization(false);

        $mapper = $this->mapperWithMockedUpdate();

        $captured = null;
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $entry) use (&$captured) {
                $captured = $entry;
                return $entry;
            });

        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-a'], 'skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertNotNull($captured);
        $changed = $captured->getChanged();
        $this->assertArrayNotHasKey('bases', $changed['fields']);
        $this->assertSame(['previous' => false, 'new' => true], $changed['fields']['skipAnonymization']);
    }

    public function testAuditEmissionFailureDoesNotMaskUpdate(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $mapper = $this->mapperWithMockedUpdate();

        $this->auditTrailMapper
            ->method('insert')
            ->willThrowException(new \RuntimeException('audit storage broken'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        // Despite the audit-write throwing, the row update must still
        // succeed and the method must return the updated relation.
        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertTrue($result->getSkipAnonymization());
    }

    public function testFallsBackToSessionUserWhenActingUserIsNull(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $mapper = $this->mapperWithMockedUpdate();

        $this->userSession->method('getUser')->willReturn($this->user);

        $captured = null;
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $entry) use (&$captured) {
                $captured = $entry;
                return $entry;
            });

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => true]);

        $this->assertNotNull($captured);
        $this->assertSame('alice', $captured->getUser());
    }

    public function testRecordsSystemActorWhenNoUserIsAvailable(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $mapper = $this->mapperWithMockedUpdate();

        $this->userSession->method('getUser')->willReturn(null);

        $captured = null;
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (AuditTrail $entry) use (&$captured) {
                $captured = $entry;
                return $entry;
            });

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => true]);

        $this->assertNotNull($captured);
        $this->assertSame('system', $captured->getUser());
    }
}
