<?php

/**
 * NoteServiceWriteContractTest
 *
 * Golden for the write entry points of NoteService: who may write, which
 * actor a note is filed under, and which edits are refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service;

use Exception;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Allowed and refused outcomes of the NoteService write paths.
 */
class NoteServiceWriteContractTest extends TestCase {

	/**
	 * Comments manager mock.
	 *
	 * @var ICommentsManager&MockObject
	 */
	private ICommentsManager&MockObject $comments;

	/**
	 * User session mock.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $session;

	/**
	 * Version store mock.
	 *
	 * @var NoteVersionService&MockObject
	 */
	private NoteVersionService&MockObject $versions;

	/**
	 * Service under test.
	 *
	 * @var NoteService
	 */
	private NoteService $service;

	/**
	 * Wire the service with mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->comments = $this->createMock(ICommentsManager::class);
		$this->session = $this->createMock(IUserSession::class);
		$this->versions = $this->createMock(NoteVersionService::class);
		$visibility = $this->createMock(TimelineVisibilityServiceInterface::class);
		$visibility->method('normalise')->willReturnCallback(static fn (?string $value) => ($value ?? 'internal'));
		$this->versions->method('summaries')->willReturn([]);

		$this->service = new NoteService(
			commentsManager: $this->comments,
			accounts: new NoteAccounts(userSession: $this->session, userManager: $this->createMock(IUserManager::class)),
			visibility: $visibility,
			versions: $this->versions
		);
	}//end setUp()

	/**
	 * A comment mock written by the given actor.
	 *
	 * @param string $actorId Author.
	 *
	 * @return IComment&MockObject
	 */
	private function comment(string $actorId): IComment&MockObject {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn('1');
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getMessage')->willReturn('msg');
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2024-01-01T00:00:00+00:00'));
		return $comment;
	}//end comment()

	/**
	 * Log in a user.
	 *
	 * @param string $uid User id.
	 *
	 * @return void
	 */
	private function login(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
	}//end login()

	/**
	 * Without a session user an edit is refused before the note is read.
	 *
	 * @return void
	 */
	public function testUpdateWithoutUserIsRefused(): void {
		$this->comments->expects($this->never())->method('get');
		$this->comments->expects($this->never())->method('save');
		$this->expectExceptionMessage('No user logged in');

		$this->service->updateNote(noteId: 1, message: 'x');
	}//end testUpdateWithoutUserIsRefused()

	/**
	 * An unknown note is refused.
	 *
	 * @return void
	 */
	public function testUpdateOfUnknownNoteIsRefused(): void {
		$this->login('alice');
		$this->comments->method('get')->willThrowException(new CommentsNotFoundException());
		$this->comments->expects($this->never())->method('save');
		$this->expectExceptionMessage('Note not found');

		$this->service->updateNote(noteId: 1, message: 'x');
	}//end testUpdateOfUnknownNoteIsRefused()

	/**
	 * Another user's text is refused without the manage right and allowed with it.
	 *
	 * @return void
	 */
	public function testRewriteOfAnothersNoteNeedsTheManageRight(): void {
		$this->login('bob');
		$this->comments->method('get')->willReturn($this->comment('alice'));
		$this->comments->expects($this->once())->method('save');

		$refused = false;
		try {
			$this->service->updateNote(noteId: 1, message: 'x', visibility: null, mayManage: false);
		} catch (NoteEditForbiddenException $e) {
			$refused = true;
		}

		self::assertTrue($refused);
		$note = $this->service->updateNote(noteId: 1, message: 'x', visibility: null, mayManage: true);
		self::assertSame(1, $note['id']);
	}//end testRewriteOfAnothersNoteNeedsTheManageRight()

	/**
	 * The author may rewrite their own note without the manage right.
	 *
	 * @return void
	 */
	public function testAuthorMayRewriteOwnNote(): void {
		$this->login('alice');
		$comment = $this->comment('alice');
		$this->comments->method('get')->willReturn($comment);
		$comment->expects($this->once())->method('setMessage')->with('new text');
		$this->versions->expects($this->once())->method('record');

		$this->service->updateNote(noteId: 1, message: 'new text');
	}//end testAuthorMayRewriteOwnNote()

	/**
	 * Without a message only the visibility moves and no version is written.
	 *
	 * @return void
	 */
	public function testVisibilityOnlyEditWritesNoVersion(): void {
		$this->login('bob');
		$comment = $this->comment('alice');
		$this->comments->method('get')->willReturn($comment);
		$comment->expects($this->never())->method('setMessage');
		$comment->expects($this->once())->method('setMetaData')->with(['visibility' => 'public']);
		$this->versions->expects($this->never())->method('record');

		$this->service->updateNote(noteId: 1, message: null, visibility: 'public');
	}//end testVisibilityOnlyEditWritesNoVersion()

	/**
	 * A blank actor is refused before a comment is created.
	 *
	 * @return void
	 */
	public function testCreateAsWithBlankActorIsRefused(): void {
		$this->comments->expects($this->never())->method('create');
		$this->expectException(Exception::class);
		$this->expectExceptionMessage('A note needs an actor');

		$this->service->createNoteAs(objectUuid: 'u', message: 'm', actorType: 'users', actorId: '  ');
	}//end testCreateAsWithBlankActorIsRefused()

	/**
	 * The actor is trimmed and the note filed as a comment on the object.
	 *
	 * @return void
	 */
	public function testCreateAsFilesTheTrimmedActor(): void {
		$comment = $this->comment('guest');
		$this->comments->expects($this->once())->method('create')
			->with('guests', 'guest', 'openregister', 'u')
			->willReturn($comment);
		$comment->expects($this->once())->method('setMessage')->with('m');
		$comment->expects($this->once())->method('setVerb')->with('comment');
		$comment->expects($this->once())->method('setMetaData')->with(['visibility' => 'public']);
		$this->comments->expects($this->once())->method('save')->with($comment);

		$this->service->createNoteAs(objectUuid: 'u', message: 'm', actorType: ' guests ', actorId: ' guest ', visibility: 'public');
	}//end testCreateAsFilesTheTrimmedActor()

	/**
	 * A session note is filed under the session user, and refused without one.
	 *
	 * @return void
	 */
	public function testCreateFilesUnderTheSessionUser(): void {
		$this->login('alice');
		$this->comments->expects($this->once())->method('create')
			->with('users', 'alice', 'openregister', 'u')
			->willReturn($this->comment('alice'));

		$this->service->createNote(objectUuid: 'u', message: 'm');
	}//end testCreateFilesUnderTheSessionUser()

	/**
	 * Without a session user a note is refused.
	 *
	 * @return void
	 */
	public function testCreateWithoutUserIsRefused(): void {
		$this->comments->expects($this->never())->method('create');
		$this->expectExceptionMessage('No user logged in');

		$this->service->createNote(objectUuid: 'u', message: 'm');
	}//end testCreateWithoutUserIsRefused()
}//end class
