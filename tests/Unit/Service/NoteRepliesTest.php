<?php

/**
 * A note can reply to another note on the same record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

namespace OCA\OpenRegister\Tests\Unit\Service;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\InvalidNoteParentException;
use OCA\OpenRegister\Files\Service\NoteAccounts;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Files\Service\NoteVersionService;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectNotesQuery;
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
 * Replies through NoteService, over an in-memory comments store.
 *
 * @covers \OCA\OpenRegister\Files\Service\NoteService
 * @covers \OCA\OpenRegister\Files\Service\NoteReplies
 * @covers \OCA\OpenRegister\Exception\InvalidNoteParentException
 * @covers \OCA\OpenRegister\Kernel\Contracts\Value\NoteDraft
 */
class NoteRepliesTest extends TestCase {

	private const CASE_A = 'aaaaaaaa-0000-4000-8000-000000000014';

	private const CASE_B = 'bbbbbbbb-0000-4000-8000-000000000015';

	/**
	 * Stored comments by id.
	 *
	 * @var array<string, IComment&MockObject>
	 */
	private array $store = [];

	/**
	 * Saved comments, in order.
	 *
	 * @var array<int, IComment>
	 */
	private array $saved = [];

	/**
	 * The service under test.
	 *
	 * @var NoteService
	 */
	private NoteService $service;

	/**
	 * Set up the service over the store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$user->method('getDisplayName')->willReturn('Case Handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($user);

		$manager = $this->createMock(ICommentsManager::class);
		$manager->method('get')->willReturnCallback(
			function (string $id): IComment {
				if (isset($this->store[$id]) === false) {
					throw new CommentsNotFoundException();
				}

				return $this->store[$id];
			}
		);
		$manager->method('create')->willReturnCallback(
			fn (string $actorType, string $actorId, string $objectType, string $objectId): IComment => $this->comment(id: '100', objectId: $objectId)
		);
		$manager->method('save')->willReturnCallback(
			function (IComment $comment): bool {
				$this->saved[] = $comment;

				return true;
			}
		);
		$manager->method('getForObject')->willReturnCallback(
			fn (string $objectType, string $objectId): array => array_values(array_filter($this->store, static fn (IComment $c): bool => $c->getObjectId() === $objectId))
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new NoteService(
			commentsManager: $manager,
			accounts: new NoteAccounts(userSession: $session, userManager: $users),
			visibility: new TimelineVisibilityService(
				new TimelineVisibilityPlatform($this->createMock(SchemaMapper::class), $session, $logger),
				$this->createMock(PermissionHandler::class),
				$this->createMock(AuditTrailMapper::class)
			),
			versions: $this->createMock(NoteVersionService::class)
		);
	}//end setUp()

	/**
	 * A stateful comment double.
	 *
	 * @param string $id         Its id.
	 * @param string $objectId   The object it hangs on.
	 * @param string $visibility Its visibility.
	 * @param string $parentId   Its parent, '0' for none.
	 * @param int    $children   Its reply count.
	 *
	 * @return IComment&MockObject
	 */
	private function comment(string $id, string $objectId, string $visibility='internal', string $parentId='0', int $children=0): IComment {
		$state = ['parentId' => $parentId, 'meta' => ['visibility' => $visibility], 'message' => ''];
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn($id);
		$comment->method('getObjectType')->willReturn('openregister');
		$comment->method('getObjectId')->willReturn($objectId);
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn('handler');
		$comment->method('getVerb')->willReturn('comment');
		$comment->method('getChildrenCount')->willReturn($children);
		$comment->method('getCreationDateTime')->willReturn(new \DateTime('2026-11-13T10:00:00+00:00'));
		$comment->method('getParentId')->willReturnCallback(static function () use (&$state): string {
			return $state['parentId'];
		});
		$comment->method('setParentId')->willReturnCallback(static function (string $parent) use (&$state, $comment): IComment {
			$state['parentId'] = $parent;

			return $comment;
		});
		$comment->method('getMetaData')->willReturnCallback(static function () use (&$state): array {
			return $state['meta'];
		});
		$comment->method('setMetaData')->willReturnCallback(static function (?array $meta) use (&$state, $comment): IComment {
			$state['meta'] = ($meta ?? []);

			return $comment;
		});
		$comment->method('getMessage')->willReturnCallback(static function () use (&$state): string {
			return $state['message'];
		});
		$comment->method('setMessage')->willReturnCallback(static function (string $message) use (&$state, $comment): IComment {
			$state['message'] = $message;

			return $comment;
		});

		return $comment;
	}//end comment()

	/**
	 * Assert that a reply is refused with the fixed sentence and nothing is saved.
	 *
	 * @param NoteDraft $draft The reply.
	 *
	 * @return void
	 */
	private function assertRefused(NoteDraft $draft): void {
		try {
			$this->service->createNote(draft: $draft);
			$this->fail('Expected the reply to be refused.');
		} catch (InvalidNoteParentException $e) {
			$this->assertSame('The note you reply to is not on this record.', $e->getMessage());
		}

		$this->assertSame([], $this->saved);
	}//end assertRefused()

	/**
	 * A reply to a note on the same object is stored with that parent and returned with it.
	 *
	 * @return void
	 */
	public function testAValidReplyCarriesItsParent(): void {
		$this->store['7'] = $this->comment(id: '7', objectId: self::CASE_A, visibility: 'public', children: 1);

		$note = $this->service->createNote(
			draft: new NoteDraft(objectUuid: self::CASE_A, message: 'Yes, see the files tab', visibility: 'public', parentId: 7)
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('7', $this->saved[0]->getParentId());
		$this->assertSame(7, $note['parentId']);
		$this->assertFalse($note['parentDeleted']);
		$this->assertSame('public', $note['visibility']);
		$this->assertSame('Yes, see the files tab', $note['message']);
	}//end testAValidReplyCarriesItsParent()

	/**
	 * A reply never shows more widely than the note it answers.
	 *
	 * @return void
	 */
	public function testAReplyToAnInternalNoteStaysInternal(): void {
		$this->store['7'] = $this->comment(id: '7', objectId: self::CASE_A, visibility: 'internal');

		$note = $this->service->createNote(
			draft: new NoteDraft(objectUuid: self::CASE_A, message: 'Answer', visibility: 'public', parentId: 7)
		);

		$this->assertSame('internal', $note['visibility']);
	}//end testAReplyToAnInternalNoteStaysInternal()

	/**
	 * A parent on another object is refused.
	 *
	 * @return void
	 */
	public function testAParentOnAnotherObjectIsRefused(): void {
		$this->store['9'] = $this->comment(id: '9', objectId: self::CASE_B);

		$this->assertRefused(draft: new NoteDraft(objectUuid: self::CASE_A, message: 'Answer', parentId: 9));
	}//end testAParentOnAnotherObjectIsRefused()

	/**
	 * A missing parent is refused.
	 *
	 * @return void
	 */
	public function testAMissingParentIsRefused(): void {
		$this->assertRefused(draft: new NoteDraft(objectUuid: self::CASE_A, message: 'Answer', parentId: 404));
	}//end testAMissingParentIsRefused()

	/**
	 * A parent the writer may not read is refused, the same as a missing one.
	 *
	 * @return void
	 */
	public function testAParentTheCallerCannotSeeIsRefused(): void {
		$this->store['7'] = $this->comment(id: '7', objectId: self::CASE_A, visibility: 'internal');

		$this->assertRefused(draft: new NoteDraft(objectUuid: self::CASE_A, message: 'Answer', parentId: 7, readable: 'public'));
	}//end testAParentTheCallerCannotSeeIsRefused()

	/**
	 * A note without a parent is written as before, and reads as top-level.
	 *
	 * @return void
	 */
	public function testATopLevelNoteIsUnchanged(): void {
		$note = $this->service->createNote(draft: new NoteDraft(objectUuid: self::CASE_A, message: 'Is the permit attached?'));

		$this->assertSame('0', $this->saved[0]->getParentId());
		$this->assertNull($note['parentId']);
		$this->assertSame(0, $note['replyCount']);
		$this->assertFalse($note['parentDeleted']);
		$this->assertSame('internal', $note['visibility']);
	}//end testATopLevelNoteIsUnchanged()

	/**
	 * The list carries the question with its reply count, the reply with its parent,
	 * and a reply whose parent was deleted marked so.
	 *
	 * @return void
	 */
	public function testTheListCarriesTheConversation(): void {
		$this->store['7'] = $this->comment(id: '7', objectId: self::CASE_A, children: 1);
		$this->store['8'] = $this->comment(id: '8', objectId: self::CASE_A, parentId: '7');
		$this->store['12'] = $this->comment(id: '12', objectId: self::CASE_A, parentId: '11');

		$notes = $this->service->listNotes(query: new ObjectNotesQuery(objectUuid: self::CASE_A, page: new ListQuery(limit: 50, offset: 0)));
		$byId = array_column($notes, null, 'id');

		$this->assertSame([null, 1, false], [$byId[7]['parentId'], $byId[7]['replyCount'], $byId[7]['parentDeleted']]);
		$this->assertSame([7, 0, false], [$byId[8]['parentId'], $byId[8]['replyCount'], $byId[8]['parentDeleted']]);
		$this->assertSame([11, true], [$byId[12]['parentId'], $byId[12]['parentDeleted']]);
	}//end testTheListCarriesTheConversation()
}//end class
