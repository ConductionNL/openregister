<?php

/**
 * Unit tests for the note lock route and for deleting a locked note: the
 * lock passes the caller's manage right, is audited once, answers 403 to a
 * reader and 404 for another object's note, and a delete of a locked note
 * answers 423.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Exception\NoteLockedException;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\NotesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotesController::class)]
#[UsesClass(NotesControllerServices::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(NoteEditForbiddenException::class)]
#[UsesClass(NoteLockedException::class)]
#[UsesClass(\OCA\OpenRegister\Exception\NoteWriteRefusedException::class)]
#[UsesClass(\OCA\OpenRegister\Notifications\Controller\Support\NotePath::class)]
#[UsesClass(\OCA\OpenRegister\Notifications\Controller\Support\NoteStep::class)]
class NotesControllerLockTest extends TestCase {

	private NoteService&MockObject $notes;

	private TimelineVisibilityService&MockObject $visibility;

	private TimelineWriteService&MockObject $timeline;

	private ObjectEntity $object;

	/**
	 * The controller over an object `uuid-1` the caller may read.
	 *
	 * @return NotesController
	 */
	private function controller(): NotesController {
		$this->object = new ObjectEntity();
		$this->object->setUuid('uuid-1');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setObject')->willReturnSelf();
		$objects->method('getObject')->willReturn($this->object);
		$this->notes = $this->createMock(NoteService::class);
		$this->visibility = $this->createMock(TimelineVisibilityService::class);
		$this->timeline = $this->createMock(TimelineWriteService::class);

		return new NotesController(
			$this->createMock(IRequest::class),
			new NotesControllerServices($this->notes, $objects, $this->visibility, $this->timeline)
		);
	}//end controller()

	public function testANoteIsLockedWithTheCallersManageRightAndAuditedOnce(): void {
		$controller = $this->controller();
		$this->visibility->method('mayManageObject')->with($this->object)->willReturn(true);
		$this->notes->expects($this->once())->method('requireNoteOf')->with('uuid-1', 12);
		$this->notes->method('getNote')->with(12)->willReturn(['id' => 12, 'locked' => false]);
		$locked = ['id' => 12, 'locked' => true, 'lockedBy' => 'teamleider', 'lockedAt' => '2026-11-13T10:00:00+00:00'];
		$this->notes->expects($this->once())->method('lockNote')->with(12, true)->willReturn($locked);
		$this->notes->expects($this->once())->method('auditLock')->with($this->object, $locked)->willReturn(true);

		$response = $controller->lock('reg', 'sch', 'id', '12');

		$this->assertSame(200, $response->getStatus());
		$this->assertSame($locked, $response->getData());
	}

	public function testLockingAnAlreadyLockedNoteWritesNoSecondAuditEntry(): void {
		$controller = $this->controller();
		$this->visibility->method('mayManageObject')->willReturn(false);
		$this->notes->method('getNote')->willReturn(['id' => 12, 'locked' => true]);
		$this->notes->method('lockNote')->with(12, false)->willReturn(['id' => 12, 'locked' => true]);
		$this->notes->expects($this->never())->method('auditLock');

		$this->assertSame(200, $controller->lock('reg', 'sch', 'id', '12')->getStatus());
	}

	public function testAReaderWhoMayNotLockIsAnswered403(): void {
		$controller = $this->controller();
		$this->visibility->method('mayManageObject')->willReturn(false);
		$this->notes->method('getNote')->willReturn(['id' => 12, 'locked' => false]);
		$this->notes->method('lockNote')->willThrowException(new NoteEditForbiddenException());
		$this->notes->expects($this->never())->method('auditLock');

		$response = $controller->lock('reg', 'sch', 'id', '12');

		$this->assertSame(403, $response->getStatus());
	}

	public function testAnotherObjectsNoteIsNotFound(): void {
		$controller = $this->controller();
		$this->notes->method('requireNoteOf')->willThrowException(new Exception('Note not found', 404));
		$this->notes->expects($this->never())->method('lockNote');

		$this->assertSame(404, $controller->lock('reg', 'sch', 'id', '99')->getStatus());
	}

	public function testDeletingALockedNoteIsAnswered423AndKeepsItsTimelineRecord(): void {
		$controller = $this->controller();
		$this->notes->method('deleteNote')->willThrowException(new NoteLockedException(noteId: 12));
		$this->timeline->expects($this->never())->method('forgetNote');

		$response = $controller->destroy('reg', 'sch', 'id', '12');

		$this->assertSame(423, $response->getStatus());
		$this->assertSame(['error' => 'This note is locked and can no longer be changed'], $response->getData());
	}

	public function testDeletingAMissingNoteIsStill404(): void {
		$controller = $this->controller();
		$this->notes->method('requireNoteOf')->willThrowException(new Exception('Note not found', 404));

		$this->assertSame(404, $controller->destroy('reg', 'sch', 'id', '99')->getStatus());
	}
}
