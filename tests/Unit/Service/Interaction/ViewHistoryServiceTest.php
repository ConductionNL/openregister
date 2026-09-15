<?php

/**
 * Unit tests for ViewHistoryService.
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
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
 */

declare(strict_types=1);

namespace Unit\Service\Interaction;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectView;
use OCA\OpenRegister\Db\ObjectViewMapper;
use OCA\OpenRegister\Service\Interaction\ViewHistoryService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The throttle, the anonymous case, and the cascade.
 *
 * The throttle is asserted by counting WRITES, not by reading the result: a
 * throttle that never fires and one that always does both return something the
 * caller ignores, so only the call count separates them.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\ViewHistoryService
 */
class ViewHistoryServiceTest extends TestCase {

	/**
	 * The mapper double the service under test is built over.
	 *
	 * @var ObjectViewMapper&MockObject
	 */
	private $mapper;

	/**
	 * Build a fresh mapper double for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: ObjectViewMapper::class);

	}//end setUp()

	/**
	 * An object with a uuid.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(): ObjectEntity {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$object->method('getUuid')->willReturn('uuid-case-1');

		return $object;

	}//end makeObject()

	/**
	 * An already-stored view, seen at the given moment.
	 *
	 * @param DateTime $seenAt When the stored view was recorded.
	 *
	 * @return ObjectView
	 */
	private function storedView(DateTime $seenAt): ObjectView {
		$view = new ObjectView();
		$view->setUserId('alice');
		$view->setObjectUuid('uuid-case-1');
		$view->setViewedAt($seenAt);
		$view->setCreated($seenAt);

		return $view;

	}//end storedView()

	/**
	 * A service acting as the given user.
	 *
	 * @param string|null $uid The acting user's uid, or null for anonymous.
	 *
	 * @return ViewHistoryService
	 */
	private function serviceAs(?string $uid): ViewHistoryService {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new ViewHistoryService(
			$this->mapper,
			$session,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end serviceAs()

	/**
	 * A first open writes a row carrying the addressed scope.
	 *
	 * @return void
	 */
	public function testAFirstOpenIsRecorded(): void {
		$this->mapper->method('findOne')->willReturn(null);
		$this->mapper->expects($this->once())
			->method('record')
			->with('alice', 'uuid-case-1', 'cases', 'case')
			->willReturn(new ObjectView());

		$this->assertNotNull(
			actual: $this->serviceAs('alice')->recordView(
				object: $this->makeObject(),
				register: 'cases',
				schema: 'case'
			)
		);

	}//end testAFirstOpenIsRecorded()

	/**
	 * A second open inside the window writes nothing.
	 *
	 * The scenario the delta names is four reads in ten seconds, and what makes
	 * it "once, at the time of the first read" is that the refresh is SKIPPED
	 * rather than written with a new moment.
	 *
	 * @return void
	 */
	public function testASecondOpenInsideTheWindowIsThrottled(): void {
		$first = new DateTime('2026-09-15 10:00:00');
		$tenSecondsLater = new DateTime('2026-09-15 10:00:10');

		$this->mapper->method('findOne')->willReturn($this->storedView($first));
		$this->mapper->expects($this->never())->method('record');

		$this->assertNull(
			actual: $this->serviceAs('alice')->recordView(
				object: $this->makeObject(),
				now: $tenSecondsLater
			)
		);

	}//end testASecondOpenInsideTheWindowIsThrottled()

	/**
	 * An open past the window is recorded again.
	 *
	 * The control for the throttle test: without it, a throttle that swallowed
	 * every write would look exactly like a throttle that works, and the
	 * history would silently stop moving.
	 *
	 * @return void
	 */
	public function testAnOpenPastTheWindowIsRecorded(): void {
		$first = new DateTime('2026-09-15 10:00:00');
		$twoMinutesLater = new DateTime('2026-09-15 10:02:00');

		$this->mapper->method('findOne')->willReturn($this->storedView($first));
		$this->mapper->expects($this->once())->method('record')->willReturn(new ObjectView());

		$this->assertNotNull(
			actual: $this->serviceAs('alice')->recordView(
				object: $this->makeObject(),
				now: $twoMinutesLater
			)
		);

	}//end testAnOpenPastTheWindowIsRecorded()

	/**
	 * The window's own edge is the boundary, and it is exclusive.
	 *
	 * Sixty seconds after the stored view is no longer inside a sixty-second
	 * window, so it writes. Asserted because an off-by-one here is invisible:
	 * the history simply lags by a minute and nobody can tell.
	 *
	 * @return void
	 */
	public function testTheWindowEdgeWrites(): void {
		$first = new DateTime('2026-09-15 10:00:00');
		$exactlyAMinuteLater = new DateTime('2026-09-15 10:01:00');

		$this->mapper->method('findOne')->willReturn($this->storedView($first));
		$this->mapper->expects($this->once())->method('record')->willReturn(new ObjectView());

		$this->serviceAs('alice')->recordView(
			object: $this->makeObject(),
			now: $exactlyAMinuteLater
		);

	}//end testTheWindowEdgeWrites()

	/**
	 * An anonymous read records nothing, and asks the mapper nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousReadRecordsNothing(): void {
		$this->mapper->expects($this->never())->method('findOne');
		$this->mapper->expects($this->never())->method('record');

		$this->assertNull(actual: $this->serviceAs(null)->recordView(object: $this->makeObject()));

	}//end testAnAnonymousReadRecordsNothing()

	/**
	 * A failed write never takes out the read that triggered it.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsSwallowed(): void {
		$this->mapper->method('findOne')->willReturn(null);
		$this->mapper->method('record')->willThrowException(new \RuntimeException('db down'));

		$this->assertNull(actual: $this->serviceAs('alice')->recordView(object: $this->makeObject()));

	}//end testAFailedWriteIsSwallowed()

	/**
	 * The recent list is the caller's own, newest first.
	 *
	 * @return void
	 */
	public function testTheRecentListIsTheCallersOwn(): void {
		$this->mapper->expects($this->once())
			->method('uuidsForUser')
			->with('alice')
			->willReturn(['uuid-case-9', 'uuid-case-1']);

		$this->assertSame(
			expected: ['uuid-case-9', 'uuid-case-1'],
			actual: $this->serviceAs('alice')->recentUuidsForCaller()
		);

	}//end testTheRecentListIsTheCallersOwn()

	/**
	 * An anonymous caller has no recent list.
	 *
	 * @return void
	 */
	public function testAnonymousHasNoRecentList(): void {
		$this->mapper->expects($this->never())->method('uuidsForUser');

		$this->assertSame(expected: [], actual: $this->serviceAs(null)->recentUuidsForCaller());

	}//end testAnonymousHasNoRecentList()

	/**
	 * Deleting an object takes every view of it, whoever made them.
	 *
	 * @return void
	 */
	public function testTheCascadeRemovesEveryViewOfTheObject(): void {
		$this->mapper->expects($this->once())
			->method('deleteByObject')
			->with('uuid-case-1')
			->willReturn(7);

		$this->assertSame(
			expected: 7,
			actual: $this->serviceAs('alice')->cleanupForObject(objectUuid: 'uuid-case-1')
		);

	}//end testTheCascadeRemovesEveryViewOfTheObject()

	/**
	 * The cascade on an empty uuid touches nothing.
	 *
	 * @return void
	 */
	public function testTheCascadeIgnoresAnEmptyUuid(): void {
		$this->mapper->expects($this->never())->method('deleteByObject');

		$this->assertSame(
			expected: 0,
			actual: $this->serviceAs('alice')->cleanupForObject(objectUuid: '')
		);

	}//end testTheCascadeIgnoresAnEmptyUuid()
}//end class
