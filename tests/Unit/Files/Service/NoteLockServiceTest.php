<?php

/**
 * Unit tests for the note lock rules: who may lock, what the lock writes,
 * how it reads back, and that an already-locked note is answered unchanged.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-a-note-can-be-locked-and-a-locked-note-is-immutable
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use Exception;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteLockService;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Service\NoteLockService
 */
class NoteLockServiceTest extends TestCase {

	/**
	 * The comment's stored verb and metadata.
	 *
	 * @var array{verb: string, meta: array<string, mixed>|null}
	 */
	private array $stored = ['verb' => 'comment', 'meta' => null];

	private ICommentsManager&MockObject $comments;

	private IUserSession&MockObject $session;

	/**
	 * A comment double reflecting and mutating $this->stored.
	 *
	 * @param string $author The actor id.
	 *
	 * @return IComment&MockObject
	 */
	private function comment(string $author = 'sanne'): IComment&MockObject {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('5');
		$comment->method('getActorId')->willReturn($author);
		$comment->method('getVerb')->willReturnCallback(fn (): string => $this->stored['verb']);
		$comment->method('getMetaData')->willReturnCallback(fn (): ?array => $this->stored['meta']);
		$comment->method('setVerb')->willReturnCallback(function (string $v) use ($comment): IComment {
			$this->stored['verb'] = $v;
			return $comment;
		});
		$comment->method('setMetaData')->willReturnCallback(function (?array $m) use ($comment): IComment {
			$this->stored['meta'] = $m;
			return $comment;
		});
		return $comment;
	}//end comment()

	/**
	 * A lock service wired to a comments store holding one comment, signed in as $uid.
	 *
	 * @param IComment|null $comment The note the store holds, or null for none.
	 * @param string|null   $uid     The signed-in user, or null for nobody.
	 *
	 * @return NoteLockService
	 */
	private function service(?IComment $comment, ?string $uid): NoteLockService {
		$this->comments = $this->createMock(ICommentsManager::class);
		if ($comment !== null) {
			$this->comments->method('get')->with('5')->willReturn($comment);
		} else {
			$this->comments->method('get')->willThrowException(new CommentsNotFoundException());
		}

		$this->session = $this->createMock(IUserSession::class);
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$this->session->method('getUser')->willReturn($user);
		}

		return new NoteLockService(
			commentsManager: $this->comments,
			accounts: new NoteAccounts(userSession: $this->session, userManager: $this->createMock(IUserManager::class))
		);
	}//end service()

	public function testApplyLocksTheNoteAndSavesIt(): void {
		$service = $this->service($this->comment('sanne'), 'sanne');
		$this->comments->expects($this->once())->method('save');

		$locked = $service->apply(noteId: 5, mayManage: false);

		$this->assertTrue($service->isLocked(comment: $locked));
		$this->assertSame('sanne', $service->lockedBy(comment: $locked)['by']);
	}

	public function testApplyOnALockedNoteSavesNothing(): void {
		$this->stored = ['verb' => 'note-locked', 'meta' => ['lock' => ['by' => 'sanne', 'at' => '2026-11-02T09:00:00+00:00']]];
		$service = $this->service($this->comment('sanne'), 'teamleider');
		$this->comments->expects($this->never())->method('save');

		$this->assertSame('sanne', $service->lockedBy(comment: $service->apply(noteId: 5, mayManage: true))['by']);
	}

	public function testApplyRefusesAReaderWhoIsNotTheAuthor(): void {
		$service = $this->service($this->comment('sanne'), 'mark');
		$this->comments->expects($this->never())->method('save');

		$this->expectException(NoteEditForbiddenException::class);
		$service->apply(noteId: 5, mayManage: false);
	}

	public function testApplyWithoutAUserOrNoteIsRefused(): void {
		$noUser = $this->service($this->comment('sanne'), null);
		try {
			$noUser->apply(noteId: 5, mayManage: true);
			$this->fail('no user must refuse');
		} catch (Exception $e) {
			$this->assertSame('No user logged in', $e->getMessage());
		}

		$missing = $this->service(null, 'sanne');
		try {
			$missing->apply(noteId: 5, mayManage: true);
			$this->fail('a missing note must refuse');
		} catch (Exception $e) {
			$this->assertSame(404, $e->getCode());
		}
	}

	public function testLockedByIsNullsWhenTheNoteIsNotLocked(): void {
		$service = new NoteLockService();
		$this->stored = ['verb' => 'comment', 'meta' => ['lock' => ['by' => 'stale', 'at' => 'x']]];

		$this->assertSame(['by' => null, 'at' => null], $service->lockedBy(comment: $this->comment('sanne')));
		$this->assertFalse($service->isLocked(comment: $this->comment('sanne')));
	}
}
