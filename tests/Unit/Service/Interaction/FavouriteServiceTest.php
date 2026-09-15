<?php

/**
 * Unit tests for FavouriteService.
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
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
 */

declare(strict_types=1);

namespace Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectFavourite;
use OCA\OpenRegister\Db\ObjectFavouriteMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\FavouriteService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The permission rule, the memo, and the cascade.
 *
 * The permission rule is the short one: your own star and nobody else's, with
 * no admin override. The memo is the part that can fail silently, so it is
 * asserted by COUNTING mapper calls rather than by reading a result, which
 * would be the same whether the memo worked or not.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\FavouriteService
 */
class FavouriteServiceTest extends TestCase {

	/**
	 * The mapper double the service under test is built over.
	 *
	 * @var ObjectFavouriteMapper&MockObject
	 */
	private $mapper;

	/**
	 * Build a fresh mapper double for each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: ObjectFavouriteMapper::class);

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
	 * A service acting as the given user.
	 *
	 * @param string|null $uid The acting user's uid, or null for anonymous.
	 *
	 * @return FavouriteService
	 */
	private function serviceAs(?string $uid): FavouriteService {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new FavouriteService(
			$this->mapper,
			$session,
			$this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end serviceAs()

	/**
	 * Starring writes a row for the caller, carrying the addressed scope.
	 *
	 * The scope is asserted because it is what a narrowed lens and a purge by
	 * register both read; a star written without it still works and quietly
	 * drops out of both.
	 *
	 * @return void
	 */
	public function testStarWritesTheCallersOwnRow(): void {
		$this->mapper->expects($this->once())
			->method('star')
			->with('alice', 'uuid-case-1', 'cases', 'case')
			->willReturn(new ObjectFavourite());

		$this->serviceAs(uid: 'alice')->star(
			object: $this->makeObject(),
			register: 'cases',
			schema: 'case'
		);

	}//end testStarWritesTheCallersOwnRow()

	/**
	 * An anonymous caller cannot star anything.
	 *
	 * @return void
	 */
	public function testAnonymousCannotStar(): void {
		$this->mapper->expects($this->never())->method('star');

		$this->expectException(exception: NotAuthorizedException::class);
		$this->serviceAs(uid: null)->star(object: $this->makeObject());

	}//end testAnonymousCannotStar()

	/**
	 * Unstarring removes the caller's own row and nobody else's.
	 *
	 * @return void
	 */
	public function testUnstarRemovesTheCallersOwnRow(): void {
		$this->mapper->expects($this->once())
			->method('unstar')
			->with('alice', 'uuid-case-1')
			->willReturn(true);

		$this->assertTrue(condition: $this->serviceAs(uid: 'alice')->unstar(object: $this->makeObject()));

	}//end testUnstarRemovesTheCallersOwnRow()

	/**
	 * Asking about another user's star is refused, not answered about yourself.
	 *
	 * Answering about the caller instead would be a lie the caller cannot
	 * detect, which is why this is the one place the refusal is explicit.
	 *
	 * @return void
	 */
	public function testAskingAboutAnotherUserIsRefused(): void {
		$this->mapper->expects($this->never())->method('findOne');

		$this->expectException(exception: NotAuthorizedException::class);
		$this->serviceAs(uid: 'alice')->favouriteFor(object: $this->makeObject(), userId: 'bob');

	}//end testAskingAboutAnotherUserIsRefused()

	/**
	 * Naming yourself is allowed, and reads your own row.
	 *
	 * The control for the test above: without it, a refusal that fired for
	 * every named user would look exactly like the rule working.
	 *
	 * @return void
	 */
	public function testNamingYourselfIsAllowed(): void {
		$this->mapper->expects($this->once())
			->method('findOne')
			->with('alice', 'uuid-case-1')
			->willReturn(null);

		$this->assertNull(
			actual: $this->serviceAs(uid: 'alice')->favouriteFor(object: $this->makeObject(), userId: 'alice')
		);

	}//end testNamingYourselfIsAllowed()

	/**
	 * The starred set is loaded once, however many rows are rendered.
	 *
	 * This is the N+1 guard, and it can only be asserted by COUNTING: the
	 * answers are identical whether the memo works or the mapper is asked forty
	 * times, so a test reading only the return value cannot fail.
	 *
	 * @return void
	 */
	public function testTheStarredSetIsLoadedOncePerRequest(): void {
		$this->mapper->expects($this->once())
			->method('uuidsForUser')
			->willReturn(['uuid-case-1', 'uuid-case-9']);

		$service = $this->serviceAs(uid: 'alice');

		$this->assertTrue(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-1'));
		$this->assertTrue(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-9'));
		$this->assertFalse(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-4'));

	}//end testTheStarredSetIsLoadedOncePerRequest()

	/**
	 * A write drops the memo, so a star renders as starred immediately.
	 *
	 * @return void
	 */
	public function testAWriteDropsTheMemo(): void {
		$this->mapper->expects($this->exactly(count: 2))
			->method('uuidsForUser')
			->willReturnOnConsecutiveCalls([], ['uuid-case-1']);
		$this->mapper->method('star')->willReturn(new ObjectFavourite());

		$service = $this->serviceAs(uid: 'alice');

		$this->assertFalse(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-1'));
		$service->star(object: $this->makeObject());
		$this->assertTrue(condition: $service->isStarredByCaller(objectUuid: 'uuid-case-1'));

	}//end testAWriteDropsTheMemo()

	/**
	 * An anonymous reader is starred by nothing, and the mapper is never asked.
	 *
	 * @return void
	 */
	public function testAnonymousIsStarredByNothing(): void {
		$this->mapper->expects($this->never())->method('uuidsForUser');

		$this->assertFalse(
			condition: $this->serviceAs(uid: null)->isStarredByCaller(objectUuid: 'uuid-case-1')
		);

	}//end testAnonymousIsStarredByNothing()

	/**
	 * A failed lookup answers "not starred" rather than taking out the render.
	 *
	 * @return void
	 */
	public function testAFailedLookupDoesNotTakeOutTheRender(): void {
		$this->mapper->method('uuidsForUser')->willThrowException(new \RuntimeException('db down'));

		$this->assertFalse(
			condition: $this->serviceAs(uid: 'alice')->isStarredByCaller(objectUuid: 'uuid-case-1')
		);

	}//end testAFailedLookupDoesNotTakeOutTheRender()

	/**
	 * Deleting an object takes every star on it, whoever placed them.
	 *
	 * The cascade runs for the OBJECT and not for a user, because the rows it
	 * removes belong to people who are not making this request.
	 *
	 * @return void
	 */
	public function testTheCascadeRemovesEveryStarOnTheObject(): void {
		$this->mapper->expects($this->once())
			->method('deleteByObject')
			->with('uuid-case-1')
			->willReturn(3);

		$this->assertSame(
			expected: 3,
			actual: $this->serviceAs(uid: 'alice')->cleanupForObject(objectUuid: 'uuid-case-1')
		);

	}//end testTheCascadeRemovesEveryStarOnTheObject()

	/**
	 * The cascade on an empty uuid touches nothing.
	 *
	 * @return void
	 */
	public function testTheCascadeIgnoresAnEmptyUuid(): void {
		$this->mapper->expects($this->never())->method('deleteByObject');

		$this->assertSame(
			expected: 0,
			actual: $this->serviceAs(uid: 'alice')->cleanupForObject(objectUuid: '')
		);

	}//end testTheCascadeIgnoresAnEmptyUuid()
}//end class
