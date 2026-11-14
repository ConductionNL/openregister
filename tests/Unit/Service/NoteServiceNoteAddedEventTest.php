<?php

/**
 * A saved note is announced as ObjectNoteAddedEvent, after the save, with the
 * object, the note, the author and the visibility.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectNoteAddedEvent;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteAuthor;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityPlatform;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Files\Service\NoteService
 * @covers \OCA\OpenRegister\Event\ObjectNoteAddedEvent
 */
final class NoteServiceNoteAddedEventTest extends TestCase {

	private ICommentsManager&MockObject $comments;

	private IUserSession&MockObject $session;

	private IEventDispatcher&MockObject $events;

	/**
	 * Calls in order: `save`, then `dispatch`.
	 *
	 * @var list<string>
	 */
	private array $order = [];

	/**
	 * The note's metadata, as applyVisibility() sets it.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $meta = null;

	protected function setUp(): void {
		$this->comments = $this->createMock(ICommentsManager::class);
		$this->session  = $this->createMock(IUserSession::class);
		$this->events   = $this->createMock(IEventDispatcher::class);

		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('77');
		$comment->method('getMessage')->willReturn('Graag voor vrijdag');
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn('alice');
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2026-01-01T00:00:00+00:00'));
		$comment->method('setMetaData')->willReturnCallback(function (?array $meta) use ($comment) {
			$this->meta = $meta;
			return $comment;
		});
		$comment->method('getMetaData')->willReturnCallback(fn () => $this->meta);
		$this->comments->method('create')->willReturn($comment);
		$this->comments->method('save')->willReturnCallback(function (): void {
			$this->order[] = 'save';
		});
	}

	private function service(?IEventDispatcher $events): NoteService {
		$logger = $this->createMock(LoggerInterface::class);
		return new NoteService(
			commentsManager: $this->comments,
			accounts: new NoteAccounts(userSession: $this->session, userManager: $this->createMock(IUserManager::class)),
			visibility: new TimelineVisibilityService(
				new TimelineVisibilityPlatform($this->createMock(SchemaMapper::class), $this->session, $logger),
				$this->createMock(PermissionHandler::class),
				$this->createMock(AuditTrailMapper::class)
			),
			versions: $this->createMock(NoteVersionService::class),
			events: $events
		);
	}

	public function testASavedNoteIsAnnouncedOnceAfterTheSave(): void {
		$seen = [];
		$this->events->expects($this->once())->method('dispatchTyped')->willReturnCallback(function (ObjectNoteAddedEvent $event) use (&$seen): void {
			$this->order[] = 'dispatch';
			$seen = [$event->getObjectUuid(), $event->getNoteId(), $event->getActorType(), $event->getActorId(), $event->getVisibility()];
		});

		$this->service($this->events)->postNote(
			new NoteDraft(objectUuid: 'task-1', message: 'Graag voor vrijdag', visibility: 'internal'),
			new NoteAuthor(type: 'users', id: 'alice')
		);

		$this->assertSame(['save', 'dispatch'], $this->order);
		$this->assertSame(['task-1', '77', 'users', 'alice', 'internal'], $seen);
	}

	public function testANoteThroughALinkIsAnnouncedAsTheLink(): void {
		$seen = null;
		$this->events->method('dispatchTyped')->willReturnCallback(function (ObjectNoteAddedEvent $event) use (&$seen): void {
			$seen = [$event->getActorType(), $event->getActorId(), $event->getVisibility()];
		});

		$this->service($this->events)->postNote(new NoteDraft(objectUuid: 'task-1', message: 'Hallo'), new NoteAuthor(type: 'openregister_links', id: 'link-9'));

		$this->assertSame(['openregister_links', 'link-9', 'internal'], $seen);
	}

	public function testANoteOfTheSessionUserIsAnnounced(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$this->session->method('getUser')->willReturn($user);
		$this->events->expects($this->once())->method('dispatchTyped')->with($this->callback(
			static fn (ObjectNoteAddedEvent $event): bool => $event->getActorId() === 'bob' && $event->getObjectUuid() === 'task-2'
		));

		$this->service($this->events)->createNote(new NoteDraft(objectUuid: 'task-2', message: 'Gezien'));
	}

	public function testANoteThatFailsToSaveIsNotAnnounced(): void {
		$this->comments = $this->createMock(ICommentsManager::class);
		$this->comments->method('create')->willReturn($this->createMock(IComment::class));
		$this->comments->method('save')->willThrowException(new Exception('db down'));
		$this->events->expects($this->never())->method('dispatchTyped');

		$this->expectException(Exception::class);
		$this->service($this->events)->postNote(new NoteDraft(objectUuid: 'task-1', message: 'x'), new NoteAuthor(type: 'users', id: 'alice'));
	}

	public function testWithoutADispatcherANoteIsStillSaved(): void {
		$this->service(null)->postNote(new NoteDraft(objectUuid: 'task-1', message: 'x'), new NoteAuthor(type: 'users', id: 'alice'));

		$this->assertSame(['save'], $this->order);
	}
}
