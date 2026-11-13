<?php

/**
 * NotesController::create() with a parent note.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notes-replies-by-parent/specs/object-interactions/spec.md#requirement-a-note-can-reply-to-another-note-on-the-same-record
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\InvalidNoteParentException;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Notifications\Controller\NotesController;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Tests\Unit\Controller\Support\NotesControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The controller reads parentId, passes the caller's readable view, and answers a bad parent with 422.
 *
 * @covers \OCA\OpenRegister\Notifications\Controller\NotesController
 */
class NotesControllerRepliesTest extends TestCase {

	private IRequest&MockObject $request;

	private NoteService&MockObject $notes;

	private TimelineVisibilityService&MockObject $visibility;

	private NotesController $controller;

	/**
	 * Set up a controller on object uuid-123.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->request = $this->createMock(IRequest::class);
		$this->notes = $this->createMock(NoteService::class);
		$objects = $this->createMock(ObjectService::class);
		$object = new ObjectEntity();
		$id = new \ReflectionProperty($object, 'id');
		$id->setValue($object, 1);
		$object->setUuid('uuid-123');
		$objects->method('getObject')->willReturn($object);

		$this->visibility = $this->createMock(TimelineVisibilityService::class);
		$this->visibility->method('isKnownValue')->willReturnCallback(static fn (?string $value): bool => in_array($value, ['internal', 'public'], true));
		$this->visibility->method('normalise')->willReturnCallback(static fn (?string $value): string => ($value === 'public' ? 'public' : 'internal'));

		$this->controller = NotesControllerFactory::create(
			$this->request,
			$this->notes,
			$objects,
			$this->visibility,
			$this->createMock(TimelineWriteService::class)
		);
	}//end setUp()

	/**
	 * A reply passes its parent and the caller's readable view to the service, and answers 201.
	 *
	 * @return void
	 */
	public function testAReplyPassesItsParentAndTheCallersView(): void {
		$this->request->method('getParams')->willReturn(['message' => 'Yes, see the files tab', 'parentId' => '7']);
		$this->visibility->method('effectiveFilter')->willReturn('public');
		$this->notes->expects($this->once())->method('createNote')
			->with(new NoteDraft(objectUuid: 'uuid-123', message: 'Yes, see the files tab', visibility: null, parentId: 7, readable: 'public'))
			->willReturn(['id' => 100, 'message' => 'Yes, see the files tab', 'parentId' => 7]);

		$response = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(201, $response->getStatus());
		$this->assertSame(7, $response->getData()['parentId']);
	}//end testAReplyPassesItsParentAndTheCallersView()

	/**
	 * A refused parent is a 422 with the fixed sentence.
	 *
	 * @return void
	 */
	public function testABadParentIsA422(): void {
		$this->request->method('getParams')->willReturn(['message' => 'Answer', 'parentId' => 9]);
		$this->notes->method('createNote')->willThrowException(new InvalidNoteParentException());

		$response = $this->controller->create('reg', 'schema', 'obj-id');

		$this->assertSame(422, $response->getStatus());
		$this->assertSame(['error' => 'The note you reply to is not on this record.'], $response->getData());
	}//end testABadParentIsA422()

	/**
	 * A parentId that is no note id is refused as an unknown parent, not read as top-level.
	 *
	 * @return void
	 */
	public function testAnUnusableParentIdNamesNoNote(): void {
		$this->request->method('getParams')->willReturn(['message' => 'Answer', 'parentId' => 'abc']);
		$this->notes->expects($this->once())->method('createNote')
			->with($this->callback(static fn (NoteDraft $draft): bool => $draft->parentId === 0))
			->willThrowException(new InvalidNoteParentException());

		$this->assertSame(422, $this->controller->create('reg', 'schema', 'obj-id')->getStatus());
	}//end testAnUnusableParentIdNamesNoNote()

	/**
	 * Without a parentId the draft is exactly today's: no parent, no readable view.
	 *
	 * @return void
	 */
	public function testANoteWithoutAParentIsUnchanged(): void {
		$this->request->method('getParams')->willReturn(['message' => 'Hello']);
		$this->visibility->expects($this->never())->method('effectiveFilter');
		$this->notes->expects($this->once())->method('createNote')
			->with(new NoteDraft(objectUuid: 'uuid-123', message: 'Hello'))
			->willReturn(['id' => 1, 'message' => 'Hello']);

		$this->assertSame(201, $this->controller->create('reg', 'schema', 'obj-id')->getStatus());
	}//end testANoteWithoutAParentIsUnchanged()
}//end class
