<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A lock held by someone else and the archive refuse a delete.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/changes/run-scoped-object-locking/specs/run-scoped-object-locking/spec.md#requirement-a-lock-refuses-a-write-and-names-its-holder
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\ObjectStateDeleteGuardListener;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Object\RetentionDestruction;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Service\Flow\FlowRunFrame;

/**
 * Caller `alice` deletes one object in a given state.
 *
 * @covers \OCA\OpenRegister\Listener\ObjectStateDeleteGuardListener
 * @covers \OCA\OpenRegister\Service\Object\RetentionDestruction
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Event\AbstractPreWriteObjectEvent
 * @uses   \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses   \OCA\OpenRegister\Event\ObjectDeletingEvent
 * @uses   \OCA\OpenRegister\Exception\LockedException
 * @uses   \OCA\OpenRegister\Exception\ObjectStateWriteException
 * @uses   \OCA\OpenRegister\Service\Flow\FlowRunContext
 */
class ObjectStateDeleteGuardListenerTest extends TestCase {

	private const RUN = '22222222-2222-4222-8222-222222222222';

	private const BOB_LOCK = ['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'bob'];

	private const ARCHIVE = ['by' => 'anna', 'at' => '2026-09-12T00:00:00+00:00', 'reason' => 'closed'];

	private ObjectEntity $object;

	private FlowRunContext $flow;

	protected function setUp(): void {
		$this->flow = new FlowRunContext();
		$this->object = new ObjectEntity();
		$this->object->setUuid('11111111-1111-4111-8111-111111111111');
	}//end setUp()

	/**
	 * Run the guard on the object.
	 *
	 * @return ObjectDeletingEvent
	 */
	private function delete(): ObjectDeletingEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$event = new ObjectDeletingEvent(object: $this->object);
		(new ObjectStateDeleteGuardListener(userSession: $session, runContext: $this->flow, retention: new RetentionDestruction()))->handle($event);

		return $event;
	}//end delete()

	public function testALockHeldBySomeoneElseRefusesAndNamesTheHolder(): void {
		$this->object->setLocked(self::BOB_LOCK);

		$event = $this->delete();

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => 'object-locked',
				'message' => 'Cannot delete object: Object is locked by bob. Please unlock the object before attempting to delete it.',
				'status' => 423,
				'uuid' => $this->object->getUuid(),
			],
			$event->getErrors()
		);
	}//end testALockHeldBySomeoneElseRefusesAndNamesTheHolder()

	public function testTheLockHolderMayDelete(): void {
		$this->object->setLocked(['kind' => ObjectEntity::LOCK_KIND_USER, 'user' => 'alice']);

		$this->assertFalse($this->delete()->isPropagationStopped());
	}//end testTheLockHolderMayDelete()

	public function testTheRunHoldingALockMayDelete(): void {
		$this->object->setLocked(['kind' => ObjectEntity::LOCK_KIND_RUN, 'runUuid' => self::RUN, 'user' => 'bob']);
		$this->flow->push(frame: new FlowRunFrame(runUuid: self::RUN, nodeId: 'write', sequence: 1));

		$this->assertFalse($this->delete()->isPropagationStopped());
	}//end testTheRunHoldingALockMayDelete()

	public function testAnArchivedObjectRefusesAndNamesTheArchive(): void {
		$this->object->setArchived(self::ARCHIVE);

		$event = $this->delete();

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame('object-archived', $event->getErrors()['code']);
		$this->assertSame(409, $event->getErrors()['status']);
		$this->assertStringContainsString('archived by anna', $event->getErrors()['message']);
	}//end testAnArchivedObjectRefusesAndNamesTheArchive()

	public function testAnOpenObjectMayGo(): void {
		$this->assertFalse($this->delete()->isPropagationStopped());
	}//end testAnOpenObjectMayGo()

	public function testARowInTheTrashMayBePurged(): void {
		$this->object->setArchived(self::ARCHIVE);
		$this->object->setDeleted(['deletedBy' => 'bob']);

		$this->assertFalse($this->delete()->isPropagationStopped());
	}//end testARowInTheTrashMayBePurged()

	public function testRetentionDestroysAnArchivedAndLockedObject(): void {
		$this->object->setArchived(self::ARCHIVE);
		$this->object->setLocked(self::BOB_LOCK);

		$event = (new RetentionDestruction())->run(fn (): ObjectDeletingEvent => $this->delete());

		$this->assertFalse($event->isPropagationStopped());
		$this->assertFalse((new RetentionDestruction())->isRunning());
	}//end testRetentionDestroysAnArchivedAndLockedObject()

	public function testAnotherEventIsIgnored(): void {
		$this->object->setArchived(self::ARCHIVE);
		$event = new ObjectCreatingEvent(object: $this->object);

		$session = $this->createMock(IUserSession::class);
		(new ObjectStateDeleteGuardListener(userSession: $session, runContext: $this->flow, retention: new RetentionDestruction()))->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnotherEventIsIgnored()
}//end class
