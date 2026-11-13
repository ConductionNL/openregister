<?php

/**
 * Unit tests for the guards and failure paths of the read-state primitive.
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
 * @spec openspec/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\AddressedObject;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\ObjectReadStateMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\Interaction\ReadStateService;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCA\OpenRegister\Service\Interaction\UnreadCountCalculator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The anonymous, uuid-less and failing-mapper branches ReadStateServiceTest leaves open.
 *
 * @covers \OCA\OpenRegister\Service\Interaction\ReadStateService
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\ObjectReadState
 * @uses \OCA\OpenRegister\Service\Interaction\UnreadCountCalculator
 * @uses \OCA\OpenRegister\Service\Interaction\AddressedObject
 */
class ReadStateServiceGuardsTest extends TestCase {

	/**
	 * The read-state rows, mocked.
	 *
	 * @var ObjectReadStateMapperInterface&MockObject
	 */
	private ObjectReadStateMapperInterface&MockObject $mapper;

	/**
	 * The logger, mocked.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(ObjectReadStateMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * A service acting as the given user.
	 *
	 * @param string|null $uid The acting uid, null for anonymous.
	 *
	 * @return ReadStateService
	 */
	private function serviceAs(?string $uid): ReadStateService {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$counter = new UnreadCountCalculator(
			$this->createMock(SubstantiveChangeEvaluator::class),
			$this->createMock(FileMapperInterface::class),
			$this->logger
		);

		return new ReadStateService($this->mapper, $session, $counter, $this->logger);

	}//end serviceAs()

	/**
	 * An object with the given uuid.
	 *
	 * @param string $uuid The uuid.
	 *
	 * @return ObjectEntity
	 */
	private function objectWith(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);

		return $object;

	}//end objectWith()

	/**
	 * The caller uid is the session user's, or null without one.
	 *
	 * @return void
	 */
	public function testCallerUidFollowsTheSession(): void {
		$this->assertSame('alice', $this->serviceAs('alice')->callerUid());
		$this->assertNull($this->serviceAs(null)->callerUid());

	}//end testCallerUidFollowsTheSession()

	/**
	 * An empty uid counts as no caller: marking read is refused.
	 *
	 * @return void
	 */
	public function testAnEmptyUidCannotMarkRead(): void {
		$this->mapper->expects($this->never())->method('markSeen');
		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('A read state needs a signed-in user');

		$this->serviceAs('')->markRead(new AddressedObject(object: $this->objectWith('uuid-1')));

	}//end testAnEmptyUidCannotMarkRead()

	/**
	 * An object without a uuid cannot carry a read state.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutUuidIsRefused(): void {
		$this->mapper->expects($this->never())->method('markUnread');
		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('This object cannot carry a read state');

		$this->serviceAs('alice')->markUnread($this->objectWith(''));

	}//end testAnObjectWithoutUuidIsRefused()

	/**
	 * Marking read with an empty sub-resource records no sub-seen moment.
	 *
	 * @return void
	 */
	public function testAnEmptySubResourceRecordsNoSubSeen(): void {
		$this->mapper->expects($this->once())
			->method('markSeen')
			->with(
				$this->callback(
					static function (ObjectReadState $row): bool {
						return $row->getUserId() === 'alice'
							&& $row->getObjectUuid() === 'uuid-1'
							&& $row->getRegister() === 'r'
							&& $row->getSchema() === 's'
							&& empty($row->getSubSeen()) === true;
					}
				)
			)
			->willReturnArgument(0);

		$this->serviceAs('alice')->markRead(new AddressedObject(object: $this->objectWith('uuid-1'), register: 'r', schema: 's'), '');

	}//end testAnEmptySubResourceRecordsNoSubSeen()

	/**
	 * Naming yourself, or nobody, reads your own row.
	 *
	 * @return void
	 */
	public function testOwnReadStateIsReadForSelfAndForNobody(): void {
		$row = new ObjectReadState();
		$this->mapper->expects($this->exactly(3))
			->method('findOne')
			->with('alice', 'uuid-1')
			->willReturn($row);

		$service = $this->serviceAs('alice');
		$this->assertSame($row, $service->readStateFor($this->objectWith('uuid-1'), 'alice'));
		$this->assertSame($row, $service->readStateFor($this->objectWith('uuid-1')));
		$this->assertSame($row, $service->readStateFor($this->objectWith('uuid-1'), ''));

	}//end testOwnReadStateIsReadForSelfAndForNobody()

	/**
	 * An empty uuid is never unread, and costs no lookup.
	 *
	 * @return void
	 */
	public function testAnEmptyUuidIsNeverUnread(): void {
		$this->mapper->expects($this->never())->method('uuidsForUser');

		$this->assertFalse($this->serviceAs('alice')->isUnreadForCaller(''));

	}//end testAnEmptyUuidIsNeverUnread()

	/**
	 * For an anonymous caller everything is unread, without a lookup.
	 *
	 * @return void
	 */
	public function testAnonymousSeesEverythingUnread(): void {
		$this->mapper->expects($this->never())->method('uuidsForUser');

		$service = $this->serviceAs(null);
		$this->assertTrue($service->isUnreadForCaller('uuid-1'));
		$this->assertTrue($service->isUnreadForCaller('uuid-2'));

	}//end testAnonymousSeesEverythingUnread()

	/**
	 * A failing seen-set lookup is logged and treats everything as unread, once.
	 *
	 * @return void
	 */
	public function testAFailingSeenSetLookupReadsAsUnread(): void {
		$this->mapper->expects($this->once())
			->method('uuidsForUser')
			->willThrowException(new RuntimeException('db gone'));
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('seen set lookup failed for "alice": db gone'));

		$service = $this->serviceAs('alice');
		$this->assertTrue($service->isUnreadForCaller('uuid-1'));
		$this->assertTrue($service->isUnreadForCaller('uuid-1'));

	}//end testAFailingSeenSetLookupReadsAsUnread()

	/**
	 * Without a caller or a uuid there are no counts and no lookup.
	 *
	 * @return void
	 */
	public function testUnreadCountsNeedACallerAndAUuid(): void {
		$this->mapper->expects($this->never())->method('findOne');

		$this->assertSame([], $this->serviceAs(null)->unreadCounts($this->objectWith('uuid-1')));
		$this->assertSame([], $this->serviceAs('')->unreadCounts($this->objectWith('uuid-1')));
		$this->assertSame([], $this->serviceAs('alice')->unreadCounts($this->objectWith('')));

	}//end testUnreadCountsNeedACallerAndAUuid()

	/**
	 * With a caller and a uuid the caller's row is read for the counts.
	 *
	 * @return void
	 */
	public function testUnreadCountsReadTheCallersRow(): void {
		$this->mapper->expects($this->once())
			->method('findOne')
			->with('alice', 'uuid-1')
			->willReturn(null);

		$this->assertSame([], $this->serviceAs('alice')->unreadCounts($this->objectWith('uuid-1')));

	}//end testUnreadCountsReadTheCallersRow()

	/**
	 * Invalidating an empty uuid touches nothing.
	 *
	 * @return void
	 */
	public function testInvalidatingAnEmptyUuidDoesNothing(): void {
		$this->mapper->expects($this->never())->method('invalidate');

		$this->assertSame(0, $this->serviceAs('alice')->invalidate(''));

	}//end testInvalidatingAnEmptyUuidDoesNothing()

	/**
	 * A failing invalidation is logged and reports nothing invalidated.
	 *
	 * @return void
	 */
	public function testAFailingInvalidationReportsZero(): void {
		$this->mapper->method('invalidate')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ReadStateService] invalidation failed for uuid-1: locked');

		$this->assertSame(0, $this->serviceAs('alice')->invalidate('uuid-1', 'bob'));

	}//end testAFailingInvalidationReportsZero()

	/**
	 * Cleanup deletes the object's rows and reports how many.
	 *
	 * @return void
	 */
	public function testCleanupDeletesTheObjectsRows(): void {
		$this->mapper->expects($this->once())
			->method('deleteByObject')
			->with('uuid-1')
			->willReturn(4);

		$this->assertSame(4, $this->serviceAs(null)->cleanupForObject('uuid-1'));

	}//end testCleanupDeletesTheObjectsRows()

	/**
	 * Cleanup of an empty uuid touches nothing.
	 *
	 * @return void
	 */
	public function testCleanupOfAnEmptyUuidDoesNothing(): void {
		$this->mapper->expects($this->never())->method('deleteByObject');

		$this->assertSame(0, $this->serviceAs('alice')->cleanupForObject(''));

	}//end testCleanupOfAnEmptyUuidDoesNothing()

	/**
	 * A failing cleanup is logged and reports nothing removed.
	 *
	 * @return void
	 */
	public function testAFailingCleanupReportsZero(): void {
		$this->mapper->method('deleteByObject')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())
			->method('warning')
			->with('[ReadStateService] cleanup failed for uuid-1: locked');

		$this->assertSame(0, $this->serviceAs('alice')->cleanupForObject('uuid-1'));

	}//end testAFailingCleanupReportsZero()

	/**
	 * Cleanup drops the memo, so the next unread check looks again.
	 *
	 * @return void
	 */
	public function testCleanupDropsTheMemo(): void {
		$this->mapper->expects($this->exactly(2))
			->method('uuidsForUser')
			->willReturnOnConsecutiveCalls(['uuid-1'], []);

		$service = $this->serviceAs('alice');
		$this->assertFalse($service->isUnreadForCaller('uuid-1'));
		$service->cleanupForObject('uuid-1');
		$this->assertTrue($service->isUnreadForCaller('uuid-1'));

	}//end testCleanupDropsTheMemo()
}//end class
