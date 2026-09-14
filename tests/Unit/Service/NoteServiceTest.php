<?php

namespace Unit\Service;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable Squiz.Commenting.VariableComment.Missing -- PHPUnit fixture properties are named by their type.
// phpcs:disable Squiz.PHP.DisallowInlineIf.Found -- PHPUnit fixture defaults.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use Exception;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\TimelineVisibilityService;
use OCP\Comments\IComment;
use OCP\Comments\ICommentsManager;
use OCP\Comments\NotFoundException as CommentsNotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteServiceTest extends TestCase {
	private ICommentsManager&MockObject $commentsManager;
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private TimelineVisibilityService $visibility;
	private NoteService $service;

	protected function setUp(): void {
		$this->commentsManager = $this->createMock(ICommentsManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->visibility = new TimelineVisibilityService(
			$this->createMock(SchemaMapper::class),
			$this->createMock(PermissionHandler::class),
			$this->createMock(AuditTrailMapper::class),
			$this->userSession,
			$this->logger
		);

		$this->service = new NoteService(
			$this->commentsManager,
			$this->userSession,
			$this->userManager,
			$this->logger,
			$this->visibility
		);
	}

	private function createComment(string $id, string $message, string $actorId, string $createdAt = '2024-01-01T00:00:00+00:00'): IComment&MockObject {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn($id);
		$comment->method('getMessage')->willReturn($message);
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getCreationDateTime')->willReturn(new \DateTime($createdAt));
		return $comment;
	}

	private function createUser(string $uid, string $displayName = 'User'): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		return $user;
	}

	public function testGetNotesForObjectReturnsNotes(): void {
		$comment = $this->createComment('1', 'Hello', 'admin');
		$adminUser = $this->createUser('admin', 'Administrator');

		$this->commentsManager->method('getForObject')->willReturn([$comment]);
		$this->userManager->method('get')->willReturn($adminUser);
		$this->userSession->method('getUser')->willReturn($adminUser);

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertCount(1, $result);
		$this->assertSame(1, $result[0]['id']);
		$this->assertSame('Hello', $result[0]['message']);
		$this->assertSame('users', $result[0]['actorType']);
		$this->assertSame('admin', $result[0]['actorId']);
		$this->assertSame('Administrator', $result[0]['actorDisplayName']);
		$this->assertTrue($result[0]['isCurrentUser']);
	}

	public function testGetNotesForObjectEmpty(): void {
		$this->commentsManager->method('getForObject')->willReturn([]);

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertSame([], $result);
	}

	public function testGetNotesForObjectWithLimitAndOffset(): void {
		$this->commentsManager->expects($this->once())
			->method('getForObject')
			->with('openregister', 'obj-uuid', 10, 5)
			->willReturn([]);

		$this->service->getNotesForObject('obj-uuid', 10, 5);
	}

	public function testGetNotesForObjectIsCurrentUserFalse(): void {
		$comment = $this->createComment('1', 'Hello', 'other-user');
		$otherUser = $this->createUser('other-user', 'Other User');
		$currentUser = $this->createUser('admin', 'Admin');

		$this->commentsManager->method('getForObject')->willReturn([$comment]);
		$this->userManager->method('get')->willReturn($otherUser);
		$this->userSession->method('getUser')->willReturn($currentUser);

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertFalse($result[0]['isCurrentUser']);
	}

	public function testGetNotesForObjectNoCurrentUser(): void {
		$comment = $this->createComment('1', 'Hello', 'admin');

		$this->commentsManager->method('getForObject')->willReturn([$comment]);
		$this->userManager->method('get')->willReturn(null);
		$this->userSession->method('getUser')->willReturn(null);

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertFalse($result[0]['isCurrentUser']);
		// When user manager returns null, actorDisplayName falls back to actorId
		$this->assertSame('admin', $result[0]['actorDisplayName']);
	}

	public function testCreateNoteSuccess(): void {
		$user = $this->createUser('admin', 'Admin');
		$comment = $this->createComment('1', 'New note', 'admin');

		$this->userSession->method('getUser')->willReturn($user);
		$this->commentsManager->method('create')->willReturn($comment);
		$comment->expects($this->once())->method('setVerb')->with('comment');
		$this->commentsManager->expects($this->once())->method('save');
		$this->userManager->method('get')->willReturn($user);

		$result = $this->service->createNote('obj-uuid', 'New note');

		$this->assertSame(1, $result['id']);
		$this->assertSame('New note', $result['message']);
	}

	public function testCreateNoteThrowsWhenNoUser(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->createNote('obj-uuid', 'Note text');
	}

	public function testDeleteNoteSuccess(): void {
		$comment = $this->createComment('5', 'To delete', 'admin');

		$this->commentsManager->method('get')->with('5')->willReturn($comment);
		$this->commentsManager->expects($this->once())->method('delete')->with('5');

		$this->service->deleteNote(5);
	}

	public function testDeleteNoteNotFound(): void {
		$this->commentsManager->method('get')
			->willThrowException(new CommentsNotFoundException());

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('Note not found');

		$this->service->deleteNote(999);
	}

	public function testDeleteNotesForObject(): void {
		$this->commentsManager->expects($this->once())
			->method('deleteCommentsAtObject')
			->with('openregister', 'obj-uuid');

		$this->service->deleteNotesForObject('obj-uuid');
	}

	public function testGetNotesForObjectMultiple(): void {
		$comment1 = $this->createComment('1', 'First', 'admin');
		$comment2 = $this->createComment('2', 'Second', 'user1');

		$adminUser = $this->createUser('admin', 'Admin');
		$user1 = $this->createUser('user1', 'User One');

		$this->commentsManager->method('getForObject')->willReturn([$comment1, $comment2]);
		$this->userManager->method('get')->willReturnMap([
			['admin', $adminUser],
			['user1', $user1],
		]);
		$this->userSession->method('getUser')->willReturn($adminUser);

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertCount(2, $result);
		$this->assertSame('Admin', $result[0]['actorDisplayName']);
		$this->assertSame('User One', $result[1]['actorDisplayName']);
		$this->assertTrue($result[0]['isCurrentUser']);
		$this->assertFalse($result[1]['isCurrentUser']);
	}

	public function testCreateNoteCallsCreateWithCorrectParams(): void {
		$user = $this->createUser('testuser', 'Test');
		$comment = $this->createComment('1', 'msg', 'testuser');

		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);

		$this->commentsManager->expects($this->once())
			->method('create')
			->with('users', 'testuser', 'openregister', 'my-uuid')
			->willReturn($comment);
		$this->commentsManager->expects($this->once())->method('save');

		$this->service->createNote('my-uuid', 'msg');
	}

	/**
	 * A comment mock whose metadata array is actually stored, so a write can be
	 * read back rather than merely observed being attempted.
	 *
	 * @param string $id The comment id.
	 * @param string $message The comment message.
	 * @param string $actorId The author.
	 * @param array|null $metaData The metadata the comment starts with.
	 *
	 * @return IComment&MockObject
	 */
	private function createStatefulComment(string $id, string $message, string $actorId, ?array $metaData = null): IComment&MockObject {
		$comment = $this->createComment($id, $message, $actorId);
		$state = new \ArrayObject(['meta' => $metaData]);
		$comment->method('getMetaData')->willReturnCallback(static fn () => $state['meta']);
		$comment->method('setMetaData')->willReturnCallback(
			static function (?array $written) use ($state, $comment) {
				$state['meta'] = $written;
				return $comment;
			}
		);
		return $comment;
	}

	public function testNoteWithoutTheFlagReadsInternal(): void {
		$comment = $this->createStatefulComment('1', 'Hello', 'admin', null);
		$this->commentsManager->method('getForObject')->willReturn([$comment]);
		$this->userManager->method('get')->willReturn($this->createUser('admin'));

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertSame(TimelineVisibilityService::INTERNAL, $result[0]['visibility']);
	}

	public function testStoredFlagIsReadBack(): void {
		$comment = $this->createStatefulComment('1', 'Hello', 'admin', ['visibility' => 'public']);
		$this->commentsManager->method('getForObject')->willReturn([$comment]);
		$this->userManager->method('get')->willReturn($this->createUser('admin'));

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertSame(TimelineVisibilityService::PUBLIC_ENTRY, $result[0]['visibility']);
	}

	public function testAValueOutsideTheVocabularyReadsInternal(): void {
		$comment = $this->createStatefulComment('1', 'Hello', 'admin', ['visibility' => 'everyone']);
		$this->commentsManager->method('getForObject')->willReturn([$comment]);
		$this->userManager->method('get')->willReturn($this->createUser('admin'));

		$result = $this->service->getNotesForObject('obj-uuid');

		$this->assertSame(TimelineVisibilityService::INTERNAL, $result[0]['visibility']);
	}

	public function testCreateNoteDefaultsToInternal(): void {
		$user = $this->createUser('admin');
		$comment = $this->createStatefulComment('1', 'msg', 'admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->commentsManager->method('create')->willReturn($comment);

		$note = $this->service->createNote('my-uuid', 'msg');

		$this->assertSame(TimelineVisibilityService::INTERNAL, $note['visibility']);
		$this->assertSame(['visibility' => 'internal'], $comment->getMetaData());
	}

	public function testCreateNoteStoresThePublicFlag(): void {
		$user = $this->createUser('admin');
		$comment = $this->createStatefulComment('1', 'msg', 'admin');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->commentsManager->method('create')->willReturn($comment);

		$note = $this->service->createNote('my-uuid', 'msg', 'public');

		$this->assertSame(TimelineVisibilityService::PUBLIC_ENTRY, $note['visibility']);
	}

	public function testWritingTheFlagKeepsOtherMetadata(): void {
		$user = $this->createUser('admin');
		$comment = $this->createStatefulComment('1', 'msg', 'admin', ['source' => 'berichtenbox']);
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->commentsManager->method('get')->willReturn($comment);

		$this->service->updateNote(1, null, 'public');

		$this->assertSame(
			['source' => 'berichtenbox', 'visibility' => 'public'],
			$comment->getMetaData()
		);
	}

	public function testGetNotesForObjectKeepsOnlyThePublicOnes(): void {
		$internal = $this->createStatefulComment('1', 'Internal', 'admin');
		$public = $this->createStatefulComment('2', 'Public', 'admin', ['visibility' => 'public']);
		$this->commentsManager->method('getForObject')->willReturn([$internal, $public]);
		$this->userManager->method('get')->willReturn($this->createUser('admin'));

		$result = $this->service->getNotesForObject('obj-uuid', 50, 0, 'public');

		$this->assertCount(1, $result);
		$this->assertSame('Public', $result[0]['message']);
	}

	public function testMovingTheFlagDoesNotAskWhoWroteTheNote(): void {
		$user = $this->createUser('supervisor');
		$comment = $this->createStatefulComment('1', 'msg', 'handler');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->willReturn($user);
		$this->commentsManager->method('get')->willReturn($comment);

		$note = $this->service->updateNote(1, null, 'public');

		$this->assertSame(TimelineVisibilityService::PUBLIC_ENTRY, $note['visibility']);
	}

	public function testEditingSomeoneElsesMessageIsStillRefused(): void {
		$user = $this->createUser('supervisor');
		$comment = $this->createStatefulComment('1', 'msg', 'handler');
		$this->userSession->method('getUser')->willReturn($user);
		$this->commentsManager->method('get')->willReturn($comment);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('You can only edit your own notes');

		$this->service->updateNote(1, 'rewritten', 'public');
	}

	public function testGetNoteReturnsTheFlagItCarries(): void {
		$comment = $this->createStatefulComment('7', 'msg', 'admin', ['visibility' => 'public']);
		$this->commentsManager->method('get')->willReturn($comment);
		$this->userManager->method('get')->willReturn($this->createUser('admin'));

		$note = $this->service->getNote(7);

		$this->assertSame(7, $note['id']);
		$this->assertSame(TimelineVisibilityService::PUBLIC_ENTRY, $note['visibility']);
	}
}
