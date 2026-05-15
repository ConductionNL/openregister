<?php

/**
 * Unit tests for `EntityRelationMapper::updateDecisionMetadata`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests\Unit\Db
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\EntityRelation;
use OCA\OpenRegister\Db\EntityRelationMapper;
use OCA\OpenRegister\Event\EntityRelationDecisionUpdatedEvent;
use OCA\OpenRegister\Exception\CustomValidationException;
use OCP\EventDispatcher\IEventDispatcher;
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

    private IEventDispatcher&MockObject $eventDispatcher;

    private LoggerInterface&MockObject $logger;

    private IUser&MockObject $user;

    protected function setUp(): void
    {
        $this->db = $this->createMock(IDBConnection::class);
        $this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
        $this->userSession      = $this->createMock(IUserSession::class);
        $this->eventDispatcher  = $this->createMock(IEventDispatcher::class);
        $this->logger           = $this->createMock(LoggerInterface::class);
        $this->user = $this->createMock(IUser::class);

        $this->user->method('getUID')->willReturn('alice');
        $this->user->method('getDisplayName')->willReturn('Alice');
    }//end setUp()

    /**
     * Build a mapper with `update()` (the only concrete inherited write method) mocked.
     */
    private function mapperWithMockedUpdate(): EntityRelationMapper&MockObject
    {
        $mapper = $this->getMockBuilder(EntityRelationMapper::class)
            ->setConstructorArgs(
                    [
                        $this->db,
                        $this->auditTrailMapper,
                        $this->userSession,
                        $this->eventDispatcher,
                        $this->logger,
                    ]
                    )
            ->onlyMethods(['update'])
            ->getMock();

        $mapper->method('update')->willReturnArgument(0);

        return $mapper;
    }//end mapperWithMockedUpdate()

    public function testRejectsUnknownField(): void
    {
        $relation = new EntityRelation();
        $mapper   = $this->mapperWithMockedUpdate();

        $this->auditTrailMapper->expects($this->never())->method('insert');

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/anonymized/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['anonymized' => true], actingUser: $this->user);
    }//end testRejectsUnknownField()

    public function testRejectsBasesAsNonArray(): void
    {
        $relation = new EntityRelation();
        $mapper   = $this->mapperWithMockedUpdate();

        $this->auditTrailMapper->expects($this->never())->method('insert');

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/bases shape/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['bases' => 'uuid-a'], actingUser: $this->user);
    }//end testRejectsBasesAsNonArray()

    public function testRejectsBasesArrayWithNonStringElement(): void
    {
        $relation = new EntityRelation();
        $mapper   = $this->mapperWithMockedUpdate();

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/bases shape/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['bases' => ['uuid-a', 42]], actingUser: $this->user);
    }//end testRejectsBasesArrayWithNonStringElement()

    public function testRejectsSkipAnonymizationAsNonBool(): void
    {
        $relation = new EntityRelation();
        $mapper   = $this->mapperWithMockedUpdate();

        $this->expectException(CustomValidationException::class);
        $this->expectExceptionMessageMatches('/skipAnonymization shape/');

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => 'yes'], actingUser: $this->user);
    }//end testRejectsSkipAnonymizationAsNonBool()

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
    }//end testSemanticNoOpSkipsAuditEmission()

    public function testEmptyBodyIsNoOp(): void
    {
        $relation = new EntityRelation();
        $mapper   = $this->mapperWithMockedUpdate();

        $mapper->expects($this->never())->method('update');
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $result = $mapper->updateDecisionMetadata(relation: $relation, fields: [], actingUser: $this->user);

        $this->assertNull($result->getBases());
        $this->assertFalse($result->getSkipAnonymization());
    }//end testEmptyBodyIsNoOp()

    public function testSettingBasesForFirstTimeEmitsAuditEntry(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $mapper = $this->mapperWithMockedUpdate();

        $captured = null;
        $this->auditTrailMapper
            ->expects($this->once())
            ->method('insert')
            ->willReturnCallback(
                    function (AuditTrail $entry) use (&$captured) {
                        $captured = $entry;
                        return $entry;
                    }
                    );

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
    }//end testSettingBasesForFirstTimeEmitsAuditEntry()

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
            ->willReturnCallback(
                    function (AuditTrail $entry) use (&$captured) {
                        $captured = $entry;
                        return $entry;
                    }
                    );

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
    }//end testFlippingSkipEmitsAuditEntry()

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
            ->willReturnCallback(
                    function (AuditTrail $entry) use (&$captured) {
                        $captured = $entry;
                        return $entry;
                    }
                    );

        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-a'], 'skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertNotNull($captured);
        $changed = $captured->getChanged();
        $this->assertArrayNotHasKey('bases', $changed['fields']);
        $this->assertSame(['previous' => false, 'new' => true], $changed['fields']['skipAnonymization']);
    }//end testPartialChangeOnlyAuditsTheChangedField()

    public function testAuditEmissionFailureRollsBackUpdateAndThrows(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $mapper = $this->mapperWithMockedUpdate();

        $this->db->expects($this->once())->method('beginTransaction');
        // Rollback MUST fire when audit insert throws; commit MUST NOT.
        $this->db->expects($this->once())->method('rollBack');
        $this->db->expects($this->never())->method('commit');

        $this->auditTrailMapper
            ->method('insert')
            ->willThrowException(new \RuntimeException('audit storage broken'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        $this->expectException(\RuntimeException::class);
        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['skipAnonymization' => true],
            actingUser: $this->user
        );
    }//end testAuditEmissionFailureRollsBackUpdateAndThrows()

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
            ->willReturnCallback(
                    function (AuditTrail $entry) use (&$captured) {
                        $captured = $entry;
                        return $entry;
                    }
                    );

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => true]);

        $this->assertNotNull($captured);
        $this->assertSame('alice', $captured->getUser());
    }//end testFallsBackToSessionUserWhenActingUserIsNull()

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
            ->willReturnCallback(
                    function (AuditTrail $entry) use (&$captured) {
                        $captured = $entry;
                        return $entry;
                    }
                    );

        $mapper->updateDecisionMetadata(relation: $relation, fields: ['skipAnonymization' => true]);

        $this->assertNotNull($captured);
        $this->assertSame('system', $captured->getUser());
    }//end testRecordsSystemActorWhenNoUserIsAvailable()

    public function testDispatchesEventOnSkipAnonymizationFlip(): void
    {
        $relation = new EntityRelation();
        $relation->setId(99);
        $relation->setSkipAnonymization(false);

        $mapper = $this->mapperWithMockedUpdate();

        $captured = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(
                    function (EntityRelationDecisionUpdatedEvent $event) use (&$captured) {
                        $captured = $event;
                    }
                    );

        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertNotNull($captured);
        $this->assertSame(99, $captured->getRelation()->getId());
        $this->assertTrue($captured->isSkipAnonymizationActivated());
        $this->assertSame($this->user, $captured->getActingUser());

        $diff = $captured->getChangedFields();
        $this->assertArrayHasKey('skipAnonymization', $diff);
        $this->assertSame(['previous' => false, 'new' => true], $diff['skipAnonymization']);
    }//end testDispatchesEventOnSkipAnonymizationFlip()

    public function testDispatchesEventOnBasesChange(): void
    {
        $relation = new EntityRelation();
        $relation->setId(7);

        $mapper = $this->mapperWithMockedUpdate();

        $captured = null;
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatchTyped')
            ->willReturnCallback(
                    function (EntityRelationDecisionUpdatedEvent $event) use (&$captured) {
                        $captured = $event;
                    }
                    );

        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-a']],
            actingUser: $this->user
        );

        $this->assertNotNull($captured);
        // bases change is NOT a skip-flip — the convenience helper must return false.
        $this->assertFalse($captured->isSkipAnonymizationActivated());
        $diff = $captured->getChangedFields();
        $this->assertArrayHasKey('bases', $diff);
        $this->assertSame(['previous' => null, 'new' => ['uuid-a']], $diff['bases']);
    }//end testDispatchesEventOnBasesChange()

    public function testNoEventDispatchedOnSemanticNoOp(): void
    {
        $relation = new EntityRelation();
        $relation->setSkipAnonymization(true);

        $mapper = $this->mapperWithMockedUpdate();

        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');

        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['skipAnonymization' => true],
            actingUser: $this->user
        );
    }//end testNoEventDispatchedOnSemanticNoOp()

    public function testReorderedBasesAreSemanticNoOpAndSkipAudit(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $relation->setBases(['uuid-a', 'uuid-b']);

        $mapper = $this->mapperWithMockedUpdate();

        // Audit + dispatch + DB writes MUST NOT fire on semantic no-op.
        $this->auditTrailMapper->expects($this->never())->method('insert');
        $this->eventDispatcher->expects($this->never())->method('dispatchTyped');
        $this->db->expects($this->never())->method('beginTransaction');

        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-b', 'uuid-a']],
            actingUser: $this->user
        );

        // The stored bases order is preserved on no-op (no setBases call).
        $this->assertSame(['uuid-a', 'uuid-b'], $result->getBases());
    }//end testReorderedBasesAreSemanticNoOpAndSkipAudit()

    public function testDuplicateBasesAreSemanticNoOpAgainstUniqueSet(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $relation->setBases(['uuid-a', 'uuid-b']);

        $mapper = $this->mapperWithMockedUpdate();
        $this->auditTrailMapper->expects($this->never())->method('insert');

        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => ['uuid-a', 'uuid-b', 'uuid-a']],
            actingUser: $this->user
        );

        $this->assertSame(['uuid-a', 'uuid-b'], $result->getBases());
    }//end testDuplicateBasesAreSemanticNoOpAgainstUniqueSet()

    public function testNullVsEmptyArrayBasesAreNotEqual(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);
        $relation->setBases(null);

        $mapper = $this->mapperWithMockedUpdate();
        $this->auditTrailMapper->expects($this->once())->method('insert');

        $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['bases' => []],
            actingUser: $this->user
        );
    }//end testNullVsEmptyArrayBasesAreNotEqual()

    public function testDispatchFailureDoesNotMaskUpdate(): void
    {
        $relation = new EntityRelation();
        $relation->setId(42);

        $mapper = $this->mapperWithMockedUpdate();

        $this->eventDispatcher
            ->method('dispatchTyped')
            ->willThrowException(new \RuntimeException('listener exploded'));

        $this->logger->expects($this->atLeastOnce())->method('error');

        // Even though the dispatcher throws, the row update must still
        // succeed and the method must return the updated relation.
        $result = $mapper->updateDecisionMetadata(
            relation: $relation,
            fields: ['skipAnonymization' => true],
            actingUser: $this->user
        );

        $this->assertTrue($result->getSkipAnonymization());
    }//end testDispatchFailureDoesNotMaskUpdate()
}//end class
