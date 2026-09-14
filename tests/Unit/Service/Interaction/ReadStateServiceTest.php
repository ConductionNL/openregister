<?php

/**
 * Unit tests for the read-state primitive.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Interaction;

use DateTime;
use OCA\OpenRegister\Db\FileMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The one permission rule, the derivation, and the memo.
 *
 * Each test names the CONSEQUENCE rather than the mechanism, because a service
 * that quietly does nothing looks identical to one that worked when only the
 * mechanism is asserted.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\ReadStateService
 */
class ReadStateServiceTest extends TestCase {

	/**
	 * The read-state rows, mocked.
	 *
	 * @var ObjectReadStateMapper
	 */
	private ObjectReadStateMapper $mapper;

	/**
	 * The substantive-change evaluator, mocked.
	 *
	 * @var SubstantiveChangeEvaluator
	 */
	private SubstantiveChangeEvaluator $evaluator;

	/**
	 * The file mapper behind the files badge, mocked.
	 *
	 * @var FileMapper
	 */
	private FileMapper $fileMapper;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: ObjectReadStateMapper::class);
		$this->evaluator = $this->createMock(originalClassName: SubstantiveChangeEvaluator::class);
		$this->fileMapper = $this->createMock(originalClassName: FileMapper::class);

	}//end setUp()

	/**
	 * An object with a uuid and a body.
	 *
	 * @param array<string, mixed> $body The object body.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(array $body = []): ObjectEntity {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$object->method('getUuid')->willReturn('uuid-case-1');
		$object->method('getSchema')->willReturn('777');
		$object->method('getObject')->willReturn($body);

		return $object;

	}//end makeObject()

	/**
	 * A service acting as the given user.
	 *
	 * @param string|null $uid The acting user's uid, or null for anonymous.
	 *
	 * @return ReadStateService
	 */
	private function serviceAs(?string $uid): ReadStateService {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new ReadStateService(
			mapper: $this->mapper,
			evaluator: $this->evaluator,
			userSession: $session,
			fileMapper: $this->fileMapper,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end serviceAs()

	/**
	 * A stored read state row.
	 *
	 * @param DateTime|null $seenAt When the user last saw the object.
	 * @param array<string, string> $subSeen The per-sub-resource moments.
	 *
	 * @return ObjectReadState
	 */
	private function makeRow(?DateTime $seenAt = null, array $subSeen = []): ObjectReadState {
		$row = new ObjectReadState();
		$row->setUserId('alice');
		$row->setObjectUuid('uuid-case-1');
		$row->setLastSeenAt(($seenAt ?? new DateTime('2026-09-01T10:00:00+00:00')));
		$row->setSubSeen($subSeen);

		return $row;

	}//end makeRow()

	/**
	 * Opening an object writes the caller's own row, and only theirs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testMarkingReadWritesTheCallersOwnRow(): void {
		$this->mapper->expects($this->once())
			->method('markSeen')
			->with(
				$this->equalTo('alice'),
				$this->equalTo('uuid-case-1'),
				$this->equalTo('cases'),
				$this->equalTo('case')
			)
			->willReturn($this->makeRow());

		$this->serviceAs('alice')->markRead(
			object: $this->makeObject(),
			register: 'cases',
			schema: 'case'
		);

	}//end testMarkingReadWritesTheCallersOwnRow()

	/**
	 * Marking back to unread removes the caller's row and nobody else's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testMarkingUnreadRemovesOnlyTheCallersRow(): void {
		$this->mapper->expects($this->once())
			->method('markUnread')
			->with($this->equalTo('alice'), $this->equalTo('uuid-case-1'))
			->willReturn(true);

		$this->assertTrue($this->serviceAs('alice')->markUnread(object: $this->makeObject()));

	}//end testMarkingUnreadRemovesOnlyTheCallersRow()

	/**
	 * Asking about another user's read state is refused, not answered.
	 *
	 * This is the whole permission rule: a read state is a fact about a person,
	 * so there is no admin override and no `manage` escape. Answering the caller
	 * about themselves instead would be worse than refusing, because it looks
	 * like an answer.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testAnotherUsersReadStateIsRefused(): void {
		$this->mapper->expects($this->never())->method('findOne');

		$this->expectException(NotAuthorizedException::class);

		$this->serviceAs('alice')->readStateFor(object: $this->makeObject(), userId: 'bob');

	}//end testAnotherUsersReadStateIsRefused()

	/**
	 * An anonymous caller cannot write a read state at all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testAnonymousCannotMarkAnythingRead(): void {
		$this->mapper->expects($this->never())->method('markSeen');

		$this->expectException(NotAuthorizedException::class);

		$this->serviceAs(null)->markRead(object: $this->makeObject());

	}//end testAnonymousCannotMarkAnythingRead()

	/**
	 * A substantive change invalidates everyone's row except the author's.
	 *
	 * The exception is the scenario: the person who made the change has just
	 * seen it, so their own badge must not light up for their own edit.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testInvalidationSparesTheAuthor(): void {
		$this->mapper->expects($this->once())
			->method('invalidate')
			->with($this->equalTo('uuid-case-1'), $this->equalTo('bob'))
			->willReturn(1);

		$this->assertSame(
			1,
			$this->serviceAs('bob')->invalidate(objectUuid: 'uuid-case-1', actorUid: 'bob')
		);

	}//end testInvalidationSparesTheAuthor()

	/**
	 * The marker is answered from ONE query for a whole page of objects.
	 *
	 * This is the assertion the N+1 would fail: the mapper is allowed exactly
	 * one lookup, and twelve rows are then answered from it. Without the memo
	 * this test goes red on the query count rather than on the answer, which is
	 * the point.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testAPageOfObjectsCostsOneQuery(): void {
		$this->mapper->expects($this->once())
			->method('uuidsForUser')
			->with($this->equalTo('alice'))
			->willReturn(['uuid-seen-1', 'uuid-seen-2']);

		$service = $this->serviceAs('alice');

		$unread = [];
		foreach (['uuid-seen-1', 'uuid-new-1', 'uuid-seen-2', 'uuid-new-2'] as $uuid) {
			$unread[$uuid] = $service->isUnreadForCaller(objectUuid: $uuid);
		}

		// Twelve more reads over the same set, to make the count assertion above
		// mean "once per request" rather than "once per four rows".
		for ($i = 0; $i < 12; $i++) {
			$service->isUnreadForCaller(objectUuid: 'uuid-new-1');
		}

		$this->assertSame(
			['uuid-seen-1' => false, 'uuid-new-1' => true, 'uuid-seen-2' => false, 'uuid-new-2' => true],
			$unread
		);

	}//end testAPageOfObjectsCostsOneQuery()

	/**
	 * A write inside the request drops the memo, so the marker is not stale.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testTheMemoIsDroppedAfterAWrite(): void {
		$this->mapper->expects($this->exactly(2))
			->method('uuidsForUser')
			->willReturnOnConsecutiveCalls([], ['uuid-case-1']);

		$this->mapper->method('markSeen')->willReturn($this->makeRow());

		$service = $this->serviceAs('alice');
		$this->assertTrue($service->isUnreadForCaller(objectUuid: 'uuid-case-1'));

		$service->markRead(object: $this->makeObject());

		$this->assertFalse($service->isUnreadForCaller(objectUuid: 'uuid-case-1'));

	}//end testTheMemoIsDroppedAfterAWrite()

	/**
	 * The tab badges come back as one map, from one read state row.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testTheTabsBadgeFromOneRead(): void {
		$seenAt = new DateTime('2026-09-01T10:00:00+00:00');
		$object = $this->makeObject(
			[
				'messages' => [
					['created' => '2026-09-02T09:00:00+00:00'],
					['created' => '2026-08-30T09:00:00+00:00'],
				],
			]
		);

		$this->mapper->expects($this->once())
			->method('findOne')
			->willReturn($this->makeRow(seenAt: $seenAt));

		$this->evaluator->method('subResources')->willReturn(
			[
				'files' => ['kind' => 'files'],
				'messages' => ['kind' => 'property', 'property' => 'messages', 'dateField' => 'created'],
			]
		);

		// Two files on the object, one of them touched after the seen moment.
		$this->fileMapper->method('getFilesForObject')->willReturn(
			[
				['mtime' => (new DateTime('2026-09-03T09:00:00+00:00'))->getTimestamp()],
				['mtime' => (new DateTime('2026-08-20T09:00:00+00:00'))->getTimestamp()],
				['mtime' => (new DateTime('2026-09-04T09:00:00+00:00'))->getTimestamp()],
			]
		);

		$this->assertSame(
			['files' => 2, 'messages' => 1],
			$this->serviceAs('alice')->unreadCounts(object: $object)
		);

	}//end testTheTabsBadgeFromOneRead()

	/**
	 * A sub-resource opened later badges only what arrived after that moment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testASubResourceUsesItsOwnSeenMoment(): void {
		$object = $this->makeObject(
			[
				'messages' => [
					['created' => '2026-09-02T09:00:00+00:00'],
					['created' => '2026-09-05T09:00:00+00:00'],
				],
			]
		);

		$this->mapper->method('findOne')->willReturn(
			$this->makeRow(
				seenAt: new DateTime('2026-09-01T10:00:00+00:00'),
				subSeen: ['messages' => '2026-09-03T00:00:00+00:00']
			)
		);

		$this->evaluator->method('subResources')->willReturn(
			['messages' => ['kind' => 'property', 'property' => 'messages', 'dateField' => 'created']]
		);

		$this->assertSame(
			['messages' => 1],
			$this->serviceAs('alice')->unreadCounts(object: $object)
		);

	}//end testASubResourceUsesItsOwnSeenMoment()
}//end class
