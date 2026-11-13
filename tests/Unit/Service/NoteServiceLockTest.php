<?php

/**
 * Unit tests for locking a note: the author or a manager of the object locks
 * it, a reader cannot, the lock records who and when, a locked note cannot
 * be edited or deleted, and locking twice changes nothing.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Exception\NoteLockedException;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteEdit;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityPlatform;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Files\Service\NoteService
 */
class NoteServiceLockTest extends TestCase {

	private ICommentsManager&MockObject $comments;

	private IUserSession&MockObject $session;

	private NoteVersionService&MockObject $versions;

	private NoteService $service;

	/**
	 * The comment's stored verb and metadata, as the comments manager keeps them.
	 *
	 * @var array{verb: string, meta: array<string, mixed>|null}
	 */
	private array $stored = ['verb' => 'comment', 'meta' => null];

	protected function setUp(): void {
		parent::setUp();
		$this->comments = $this->createMock(ICommentsManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn(null);
		$logger = $this->createMock(LoggerInterface::class);
		$this->versions = $this->createMock(NoteVersionService::class);
		$this->versions->method('summaries')->willReturn([]);

		$this->service = new NoteService(
			commentsManager: $this->comments,
			accounts: new NoteAccounts(userSession: $this->session, userManager: $users),
			visibility: new TimelineVisibilityService(
				new TimelineVisibilityPlatform($this->createMock(SchemaMapper::class), $this->session, $logger),
				$this->createMock(PermissionHandler::class),
				$this->createMock(AuditTrailMapper::class)
			),
			versions: $this->versions
		);
	}//end setUp()

	/**
	 * A note written by this author whose verb and metadata live in $this->stored.
	 *
	 * @param string $author The note's author.
	 *
	 * @return IComment&MockObject
	 */
	private function note(string $author = 'sanne'): IComment&MockObject {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('12');
		$comment->method('getMessage')->willReturn('Gebeld met mevrouw Jansen.');
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn($author);
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2026-11-01T10:00:00+00:00'));
		$comment->method('getVerb')->willReturnCallback(fn (): string => $this->stored['verb']);
		$comment->method('getMetaData')->willReturnCallback(fn (): ?array => $this->stored['meta']);
		$comment->method('setVerb')->willReturnCallback(
			function (string $verb) use ($comment): IComment {
				$this->stored['verb'] = $verb;
				return $comment;
			}
		);
		$comment->method('setMetaData')->willReturnCallback(
			function (?array $meta) use ($comment): IComment {
				$this->stored['meta'] = $meta;
				return $comment;
			}
		);
		$this->comments->method('get')->with('12')->willReturn($comment);

		return $comment;
	}//end note()

	/**
	 * Sign in as this user.
	 *
	 * @param string $uid The user.
	 *
	 * @return void
	 */
	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end signIn()

	public function testTheAuthorLocksTheirNoteAndTheLockSaysWhoAndWhen(): void {
		$this->signIn('sanne');
		$this->stored['meta'] = ['visibility' => 'internal'];
		$this->note('sanne');
		$this->comments->expects($this->once())->method('save');

		$note = $this->service->lockNote(noteId: 12, mayManage: false);

		$this->assertSame('note-locked', $this->stored['verb']);
		$this->assertTrue($note['locked']);
		$this->assertSame('sanne', $note['lockedBy']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $note['lockedAt']);
		$this->assertSame('internal', $this->stored['meta']['visibility'], 'the lock keeps the other metadata');
	}

	public function testAManagerOfTheObjectLocksSomeoneElsesNote(): void {
		$this->signIn('teamleider');
		$this->note('sanne');

		$this->assertSame('teamleider', $this->service->lockNote(noteId: 12, mayManage: true)['lockedBy']);
	}

	public function testAReaderWhoIsNotTheAuthorCannotLock(): void {
		$this->signIn('mark');
		$this->note('sanne');
		$this->comments->expects($this->never())->method('save');

		try {
			$this->service->lockNote(noteId: 12, mayManage: false);
			$this->fail('a reader must not lock someone else\'s note');
		} catch (NoteEditForbiddenException $refusal) {
			$this->assertSame(403, $refusal->getHttpStatus());
		}

		$this->assertSame('comment', $this->stored['verb']);
	}

	public function testLockingALockedNoteChangesNothing(): void {
		$this->signIn('teamleider');
		$this->stored = ['verb' => 'note-locked', 'meta' => ['lock' => ['by' => 'sanne', 'at' => '2026-11-02T09:00:00+00:00']]];
		$this->note('sanne');
		$this->comments->expects($this->never())->method('save');

		$note = $this->service->lockNote(noteId: 12, mayManage: true);

		$this->assertSame(['sanne', '2026-11-02T09:00:00+00:00'], [$note['lockedBy'], $note['lockedAt']]);
	}

	public function testAMissingNoteOrNoUserIsRefused(): void {
		$this->comments->method('get')->willThrowException(new CommentsNotFoundException());
		$this->signIn('sanne');

		try {
			$this->service->lockNote(noteId: 99, mayManage: true);
			$this->fail('a missing note must not lock');
		} catch (Exception $missing) {
			$this->assertSame('Note not found', $missing->getMessage());
			$this->assertSame(404, $missing->getCode());
		}
	}

	public function testWithoutAUserNothingIsLocked(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->comments->expects($this->never())->method('get');

		$this->expectExceptionMessage('No user logged in');
		$this->service->lockNote(noteId: 12, mayManage: true);
	}

	public function testALockedNoteCannotBeDeleted(): void {
		$this->stored['verb'] = 'note-locked';
		$this->note('sanne');
		$this->comments->expects($this->never())->method('delete');
		$this->versions->expects($this->never())->method('forget');

		try {
			$this->service->deleteNote(12);
			$this->fail('a locked note must not be deleted');
		} catch (NoteLockedException $refusal) {
			$this->assertSame(423, $refusal->getHttpStatus());
			$this->assertSame(12, $refusal->getNoteId());
		}
	}

	public function testAnUnlockedNoteIsStillDeleted(): void {
		$this->note('sanne');
		$this->comments->expects($this->once())->method('delete')->with('12');
		$this->versions->expects($this->once())->method('forget')->with([12]);

		$this->service->deleteNote(12);
	}

	public function testALockedNoteCannotBeEditedEvenByItsAuthor(): void {
		$this->signIn('sanne');
		$this->stored['verb'] = 'note-locked';
		$this->note('sanne');

		$this->expectException(NoteLockedException::class);
		$this->service->editNote(12, new NoteEdit(message: 'Anders gezegd.', mayManage: true));
	}

	public function testAnUnlockedNoteReportsNoLock(): void {
		$this->signIn('sanne');
		$this->stored['meta'] = ['lock' => ['by' => 'stale', 'at' => 'x']];
		$this->note('sanne');
		$this->comments->method('getForObject')->willReturn([]);

		$note = $this->service->getNote(12);

		$this->assertFalse($note['locked']);
		$this->assertNull($note['lockedBy']);
		$this->assertNull($note['lockedAt']);
	}

	public function testTheLockIsAuditedOnTheObject(): void {
		$object = new ObjectEntity();
		$note = ['id' => 12, 'lockedBy' => 'sanne', 'lockedAt' => '2026-11-02T09:00:00+00:00'];
		$this->versions->expects($this->once())->method('auditLock')->with($this->isInstanceOf(ObjectEntity::class), $note)->willReturn(true);

		$this->assertTrue($this->service->auditLock($object, $note));
	}
}
