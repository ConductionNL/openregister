<?php

/**
 * Unit tests for the watcher subscription primitive.
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
 * @spec openspec/changes/object-watchers/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Watcher;
use OCA\OpenRegister\Db\WatcherMapper;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The three permission postures, and the memo.
 *
 * Each test names the CONSEQUENCE — "the row is written", "the call is
 * refused", "the second lookup costs no query" — rather than the mechanism,
 * because a service that quietly does nothing looks identical to one that
 * worked when only the mechanism is asserted.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\WatcherService
 */
class WatcherServiceTest extends TestCase {

	/**
	 * The watcher rows, mocked.
	 *
	 * @var WatcherMapper
	 */
	private WatcherMapper $mapper;

	/**
	 * The RBAC evaluator, mocked.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $permissions;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: WatcherMapper::class);
		$this->permissions = $this->createMock(originalClassName: PermissionHandler::class);
	}//end setUp()

	/**
	 * An object owned by `owner`, in a resolvable schema.
	 *
	 * @param string|null $owner The object's owner uid.
	 *
	 * @return ObjectEntity
	 */
	private function makeObject(?string $owner = 'owner'): ObjectEntity {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$object->method('getUuid')->willReturn('uuid-case-1');
		$object->method('getSchema')->willReturn('777');
		$object->method('getOwner')->willReturn($owner);

		return $object;
	}//end makeObject()

	/**
	 * A service acting as the given user, in the given groups.
	 *
	 * @param string|null $uid The calling uid, or null for anonymous.
	 * @param array<int, string> $groups The caller's groups.
	 *
	 * @return WatcherService
	 */
	private function makeService(?string $uid = 'alice', array $groups = []): WatcherService {
		$session = $this->createMock(originalClassName: IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		$groupManager = $this->createMock(originalClassName: IGroupManager::class);
		$groupManager->method('getUserGroupIds')->willReturn($groups);

		$schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);
		$schemaMapper->method('find')->willReturn($this->createMock(originalClassName: Schema::class));

		return new WatcherService(
			$this->mapper,
			$session,
			$groupManager,
			$schemaMapper,
			$this->permissions,
			new ObjectScopeResolver(),
			$this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end makeService()

	/**
	 * Following writes a row for the calling user, on the object they named.
	 *
	 * @return void
	 */
	public function testWatchingSubscribesTheCaller(): void {
		$this->mapper->expects($this->once())
			->method('subscribe')
			->with('alice', 'uuid-case-1', 'zaken', 'zaak')
			->willReturn(new Watcher());

		$this->makeService()->watch($this->makeObject(), 'zaken', 'zaak');
	}//end testWatchingSubscribesTheCaller()

	/**
	 * An anonymous caller cannot follow anything.
	 *
	 * @return void
	 */
	public function testAnonymousCallerCannotWatch(): void {
		$this->mapper->expects($this->never())->method('subscribe');

		$this->expectException(exception: NotAuthorizedException::class);
		$this->makeService(uid: null)->watch($this->makeObject());
	}//end testAnonymousCallerCannotWatch()

	/**
	 * Unfollowing removes the caller's own row and nobody else's.
	 *
	 * @return void
	 */
	public function testUnwatchingRemovesTheCallersOwnRow(): void {
		$this->mapper->expects($this->once())
			->method('unsubscribe')
			->with('alice', 'uuid-case-1')
			->willReturn(true);

		$this->assertTrue(condition: $this->makeService()->unwatch($this->makeObject()));
	}//end testUnwatchingRemovesTheCallersOwnRow()

	/**
	 * A reader without `update` may not see who follows the object.
	 *
	 * @return void
	 */
	public function testListingRequiresUpdate(): void {
		$this->permissions->method('hasPermission')->willReturn(false);
		$this->mapper->expects($this->never())->method('findByObject');

		$this->expectException(exception: NotAuthorizedException::class);
		$this->makeService()->listWatchers($this->makeObject());
	}//end testListingRequiresUpdate()

	/**
	 * An editor sees the list.
	 *
	 * @return void
	 */
	public function testAnEditorCanListTheWatchers(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->mapper->method('findByObject')->willReturn([new Watcher(), new Watcher()]);

		$this->assertCount(expectedCount: 2, haystack: $this->makeService()->listWatchers($this->makeObject()));
	}//end testAnEditorCanListTheWatchers()

	/**
	 * Subscribing somebody else needs `manage`, and `update` is not enough.
	 *
	 * @return void
	 */
	public function testAddingAnotherUserRequiresManage(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->mapper->expects($this->never())->method('subscribe');

		$this->expectException(exception: NotAuthorizedException::class);
		$this->makeService(uid: 'editor')->addWatcher($this->makeObject(), 'clerk');
	}//end testAddingAnotherUserRequiresManage()

	/**
	 * The object's owner may subscribe somebody else.
	 *
	 * @return void
	 */
	public function testTheOwnerCanAddAnotherUser(): void {
		$this->mapper->expects($this->once())
			->method('subscribe')
			->with('clerk', 'uuid-case-1')
			->willReturn(new Watcher());

		$this->makeService(uid: 'owner')->addWatcher($this->makeObject(), 'clerk');
	}//end testTheOwnerCanAddAnotherUser()

	/**
	 * An administrator may subscribe somebody else without owning the object.
	 *
	 * @return void
	 */
	public function testAnAdministratorCanAddAnotherUser(): void {
		$this->mapper->expects($this->once())->method('subscribe')->willReturn(new Watcher());

		$this->makeService(uid: 'root', groups: ['admin'])->addWatcher($this->makeObject(), 'clerk');
	}//end testAnAdministratorCanAddAnotherUser()

	/**
	 * A watcher may always stop following, whatever the manage posture says.
	 *
	 * @return void
	 */
	public function testAWatcherCanAlwaysRemoveThemselves(): void {
		$this->mapper->expects($this->once())
			->method('unsubscribe')
			->with('clerk', 'uuid-case-1')
			->willReturn(true);

		$this->assertTrue(condition: $this->makeService(uid: 'clerk')->removeWatcher($this->makeObject(), 'clerk'));
	}//end testAWatcherCanAlwaysRemoveThemselves()

	/**
	 * Removing somebody else's subscription needs `manage`.
	 *
	 * @return void
	 */
	public function testRemovingAnotherUserRequiresManage(): void {
		$this->permissions->method('hasPermission')->willReturn(true);
		$this->mapper->expects($this->never())->method('unsubscribe');

		$this->expectException(exception: NotAuthorizedException::class);
		$this->makeService(uid: 'editor')->removeWatcher($this->makeObject(), 'clerk');
	}//end testRemovingAnotherUserRequiresManage()

	/**
	 * The follow marker costs ONE query however many objects are rendered.
	 *
	 * This is the assertion that keeps `@self.watching` off the N+1 path: if
	 * the memo is ever removed, a page of twenty objects quietly becomes twenty
	 * queries and nothing else in the suite notices.
	 *
	 * @return void
	 */
	public function testTheFollowMarkerIsLoadedOncePerRequest(): void {
		$this->mapper->expects($this->once())
			->method('uuidsForUser')
			->willReturn(['uuid-case-1', 'uuid-case-2']);

		$service = $this->makeService();

		$this->assertTrue(condition: $service->isWatchedByCaller('uuid-case-1'));
		$this->assertTrue(condition: $service->isWatchedByCaller('uuid-case-2'));
		$this->assertFalse(condition: $service->isWatchedByCaller('uuid-case-3'));
	}//end testTheFollowMarkerIsLoadedOncePerRequest()

	/**
	 * An anonymous reader follows nothing.
	 *
	 * @return void
	 */
	public function testAnAnonymousReaderFollowsNothing(): void {
		$this->mapper->expects($this->never())->method('uuidsForUser');

		$this->assertFalse(condition: $this->makeService(uid: null)->isWatchedByCaller('uuid-case-1'));
	}//end testAnAnonymousReaderFollowsNothing()

	/**
	 * Deleting an object leaves no subscription behind.
	 *
	 * @return void
	 */
	public function testDeletingAnObjectRemovesItsWatchers(): void {
		$this->mapper->expects($this->once())
			->method('deleteByObject')
			->with('uuid-case-1')
			->willReturn(4);

		$this->assertSame(expected: 4, actual: $this->makeService()->cleanupForObject('uuid-case-1'));
	}//end testDeletingAnObjectRemovesItsWatchers()

	/**
	 * The follower count is read from one grouped query, not one per object.
	 *
	 * @return void
	 */
	public function testTheFollowerCountIsLoadedOncePerRequest(): void {
		$this->mapper->expects($this->once())
			->method('countsByObject')
			->willReturn(['uuid-case-1' => 3]);
		$this->mapper->expects($this->never())->method('countForObject');

		$service = $this->makeService();

		$this->assertSame(expected: 3, actual: $service->watcherCount('uuid-case-1'));
		$this->assertSame(expected: 0, actual: $service->watcherCount('uuid-case-2'));
	}//end testTheFollowerCountIsLoadedOncePerRequest()
}//end class
