<?php

/**
 * Unit tests for the `watchers` recipient kind.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-watchers/specs/notificatie-engine/spec.md#requirement-a-notification-rule-may-address-the-objects-watchers
 */

declare(strict_types=1);

namespace Unit\Service\Notification;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The dispatcher resolves the block, and checks read while it does.
 *
 * The failure worth testing is not "a watcher is notified" — that one is
 * obvious the first time anyone tries it. It is the silent one: a watcher
 * whose group membership changed keeps receiving a sensitive case forever,
 * because the only read check ran when they subscribed.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Notification\NotificationRecipientResolver
 */
class NotificationRecipientResolverWatchersTest extends TestCase {

	/**
	 * The object the rule fired on.
	 *
	 * @var ObjectEntity
	 */
	private ObjectEntity $object;

	/**
	 * The watcher primitive, mocked.
	 *
	 * @var WatcherService
	 */
	private WatcherService $watchers;

	/**
	 * The RBAC evaluator, mocked.
	 *
	 * @var PermissionHandler
	 */
	private PermissionHandler $permissions;

	/**
	 * Build the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->object = $this->createMock(originalClassName: ObjectEntity::class);
		$this->object->method('getUuid')->willReturn('uuid-case-1');

		$this->watchers = $this->createMock(originalClassName: WatcherService::class);
		$this->permissions = $this->createMock(originalClassName: PermissionHandler::class);
	}//end setUp()

	/**
	 * A resolver wired to the mocked watcher service and permission handler.
	 *
	 * @return NotificationRecipientResolver
	 */
	private function makeResolver(): NotificationRecipientResolver {
		$container = $this->createMock(originalClassName: IServerContainer::class);
		$container->method('get')->willReturnCallback(
			function (string $id) {
				if ($id === WatcherService::class) {
					return $this->watchers;
				}

				if ($id === PermissionHandler::class) {
					return $this->permissions;
				}

				return null;
			}
		);

		$userManager = $this->createMock(originalClassName: IUserManager::class);
		$userManager->method('userExists')->willReturn(true);

		return new NotificationRecipientResolver(
			$userManager,
			$this->createMock(originalClassName: IGroupManager::class),
			$this->createMock(originalClassName: LoggerInterface::class),
			$container
		);
	}//end makeResolver()

	/**
	 * Every watcher who may still read the object is addressed.
	 *
	 * @return void
	 */
	public function testWatchersAreResolvedToUids(): void {
		$this->watchers->method('watcherUids')->willReturn(['teamlead', 'clerk']);
		$this->permissions->method('getReadableByUsers')->willReturn(['teamlead', 'clerk', 'owner']);

		$uids = $this->makeResolver()->resolve([['watchers' => true]], [], $this->object);

		$this->assertEqualsCanonicalizing(expected: ['teamlead', 'clerk'], actual: $uids);
	}//end testWatchersAreResolvedToUids()

	/**
	 * A watcher who is also addressed another way is told once, not twice.
	 *
	 * @return void
	 */
	public function testAWatcherWhoIsAlsoTheAssigneeIsToldOnce(): void {
		$this->watchers->method('watcherUids')->willReturn(['teamlead']);
		$this->permissions->method('getReadableByUsers')->willReturn(['teamlead']);

		$uids = $this->makeResolver()->resolve(
			[
				['kind' => 'users', 'users' => ['teamlead']],
				['watchers' => true],
			],
			[],
			$this->object
		);

		$this->assertSame(expected: ['teamlead'], actual: $uids);
	}//end testAWatcherWhoIsAlsoTheAssigneeIsToldOnce()

	/**
	 * A watcher who lost read receives nothing, and is dropped.
	 *
	 * @return void
	 */
	public function testAWatcherWhoLostReadIsSkippedAndDropped(): void {
		$this->watchers->method('watcherUids')->willReturn(['teamlead', 'moved-on']);
		$this->permissions->method('getReadableByUsers')->willReturn(['teamlead']);

		$this->watchers->expects($this->once())
			->method('dropWatchers')
			->with('uuid-case-1', ['moved-on']);

		$uids = $this->makeResolver()->resolve([['watchers' => true]], [], $this->object);

		$this->assertSame(expected: ['teamlead'], actual: $uids);
	}//end testAWatcherWhoLostReadIsSkippedAndDropped()

	/**
	 * An empty readable-user list means "open read rule", not "nobody may
	 * read", so no watcher is dropped and every one is kept.
	 *
	 * @return void
	 */
	public function testAnOpenReadRuleDropsNobody(): void {
		$this->watchers->method('watcherUids')->willReturn(['teamlead', 'clerk']);
		$this->permissions->method('getReadableByUsers')->willReturn([]);

		$this->watchers->expects($this->never())->method('dropWatchers');

		$uids = $this->makeResolver()->resolve([['watchers' => true]], [], $this->object);

		$this->assertEqualsCanonicalizing(expected: ['teamlead', 'clerk'], actual: $uids);
	}//end testAnOpenReadRuleDropsNobody()

	/**
	 * An object nobody follows addresses nobody.
	 *
	 * @return void
	 */
	public function testAnUnwatchedObjectAddressesNobody(): void {
		$this->watchers->method('watcherUids')->willReturn([]);

		$uids = $this->makeResolver()->resolve([['watchers' => true]], [], $this->object);

		$this->assertSame(expected: [], actual: $uids);
	}//end testAnUnwatchedObjectAddressesNobody()

	/**
	 * Without an object there is no audience to resolve, and the block is a
	 * no-op rather than a broadcast.
	 *
	 * @return void
	 */
	public function testNoObjectMeansNoRecipients(): void {
		$this->watchers->method('watcherUids')->willReturn(['teamlead']);

		$uids = $this->makeResolver()->resolve([['watchers' => true]], []);

		$this->assertSame(expected: [], actual: $uids);
	}//end testNoObjectMeansNoRecipients()

	/**
	 * A failing watcher lookup tells nobody rather than everybody.
	 *
	 * @return void
	 */
	public function testAFailingLookupFailsClosed(): void {
		$this->watchers->method('watcherUids')->willThrowException(new \RuntimeException('db down'));

		$uids = $this->makeResolver()->resolve([['watchers' => true]], [], $this->object);

		$this->assertSame(expected: [], actual: $uids);
	}//end testAFailingLookupFailsClosed()
}//end class
