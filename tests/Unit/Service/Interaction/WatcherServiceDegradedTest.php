<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Service\Interaction\AddressedObject;
use OCA\OpenRegister\Service\Interaction\WatcherCaller;
use OCA\OpenRegister\Service\Interaction\WatcherService;
use OCA\OpenRegister\Service\Interaction\WatcherStore;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Service\Rbac\ObjectScopeResolver;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The watcher service when its inputs are empty or its storage fails: every
 * read answers the fail-closed value and logs, and nothing reaches the mapper
 * for an object without a uuid.
 *
 * @covers \OCA\OpenRegister\Service\Interaction\WatcherService
 * @uses   \OCA\OpenRegister\Service\Interaction\WatcherStore
 * @uses   \OCA\OpenRegister\Service\Interaction\WatcherCaller
 * @uses   \OCA\OpenRegister\Service\Interaction\AddressedObject
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectScopeResolver
 * @uses   \OCA\OpenRegister\Notifications\Db\Watcher
 * @uses   \OCA\OpenRegister\Exception\NotAuthorizedException
 * @uses   \OCA\OpenRegister\Db\LockTerms
 * @uses   \OCA\OpenRegister\Db\SoftDeletion
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class WatcherServiceDegradedTest extends TestCase {

	/** @var WatcherMapperInterface&MockObject */
	private WatcherMapperInterface $mapper;

	/** @var SchemaMapperInterface&MockObject */
	private SchemaMapperInterface $schemas;

	/** @var PermissionCheckInterface&MockObject */
	private PermissionCheckInterface $permissions;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->mapper      = $this->createMock(WatcherMapperInterface::class);
		$this->schemas     = $this->createMock(SchemaMapperInterface::class);
		$this->permissions = $this->createMock(PermissionCheckInterface::class);
		$this->logger      = $this->createMock(LoggerInterface::class);
	}

	private function service(?string $uid = 'alice'): WatcherService {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$session->method('getUser')->willReturn($user);
		}

		return new WatcherService(
			new WatcherStore($this->mapper, $this->schemas, $this->logger),
			new WatcherCaller($session, $this->createMock(IGroupManager::class)),
			$this->permissions,
			new ObjectScopeResolver()
		);
	}

	private function object(string $uuid = 'uuid-1'): ObjectEntity {
		$object = $this->createMock(ObjectEntity::class);
		$object->method('getUuid')->willReturn($uuid);
		$object->method('getSchema')->willReturn('7');
		$object->method('getOwner')->willReturn('owner');

		return $object;
	}

	public function testTheCallerUidIsTheSessionUser(): void {
		$this->assertSame('alice', $this->service()->callerUid());
		$this->assertNull($this->service(uid: null)->callerUid());
	}

	public function testAMentionedPrincipalIsSubscribedWithTheAddressedRegisterAndSchema(): void {
		$saved = new Watcher();
		$this->mapper->expects($this->once())->method('subscribe')
			->with(
				$this->callback(static fn (Watcher $w): bool => $w->getUserId() === 'bob'
					&& $w->getObjectUuid() === 'uuid-1'
					&& $w->getRegister() === 'zaken'
					&& $w->getSchema() === 'zaak'),
				null
			)
			->willReturn($saved);

		$this->assertSame($saved, $this->service()->subscribeMentioned(new AddressedObject($this->object(), 'zaken', 'zaak'), 'bob'));
	}

	public function testAnObjectWithoutUuidCannotBeFollowed(): void {
		$this->mapper->expects($this->never())->method('subscribe');

		$this->expectException(NotAuthorizedException::class);
		$this->expectExceptionMessage('This object cannot be watched');

		$this->service()->watch(new AddressedObject($this->object(uuid: ''), 'zaken', 'zaak'));
	}

	public function testAnEmptyUuidIsNeitherFollowedNorNotifying(): void {
		$this->mapper->expects($this->never())->method('notifyMapForUser');

		$service = $this->service();

		$this->assertFalse($service->isWatchedByCaller(''));
		$this->assertNull($service->notifyForCaller(''));
	}

	public function testAFailedFollowLookupFollowsNothingAndLogs(): void {
		$this->mapper->method('notifyMapForUser')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[WatcherService] follow lookup failed for "alice": db down');

		$this->assertFalse($this->service()->isWatchedByCaller('uuid-1'));
	}

	public function testAnEmptyUuidHasNoWatchers(): void {
		$this->mapper->expects($this->never())->method('countsByObject');

		$this->assertSame(0, $this->service()->watcherCount(''));
	}

	public function testAnObjectMissingFromACappedCountMapIsCountedOnItsOwn(): void {
		$map = [];
		for ($i = 0; $i < WatcherMapperInterface::COUNT_MAP_LIMIT; $i++) {
			$map['u-'.$i] = 1;
		}

		$this->mapper->method('countsByObject')->willReturn($map);
		$this->mapper->expects($this->once())->method('countForObject')->with('uuid-1')->willReturn(9);

		$this->assertSame(9, $this->service()->watcherCount('uuid-1'));
	}

	public function testAFailedSingleCountIsZeroAndLogs(): void {
		$map = [];
		for ($i = 0; $i < WatcherMapperInterface::COUNT_MAP_LIMIT; $i++) {
			$map['u-'.$i] = 1;
		}

		$this->mapper->method('countsByObject')->willReturn($map);
		$this->mapper->method('countForObject')->willThrowException(new RuntimeException('timeout'));
		$this->logger->expects($this->once())->method('warning')
			->with('[WatcherService] watcher count failed for "uuid-1": timeout');

		$this->assertSame(0, $this->service()->watcherCount('uuid-1'));
	}

	public function testAFailedCountMapCountsNobodyAndLogs(): void {
		$this->mapper->method('countsByObject')->willThrowException(new RuntimeException('boom'));
		$this->mapper->expects($this->never())->method('countForObject');
		$this->logger->expects($this->once())->method('warning')
			->with('[WatcherService] watcher count map failed: boom');

		$this->assertSame(0, $this->service()->watcherCount('uuid-1'));
	}

	public function testWatchersAreHiddenWhenTheSchemaDoesNotResolve(): void {
		$this->schemas->method('find')->willThrowException(new RuntimeException('gone'));
		$this->permissions->expects($this->never())->method('hasPermission');

		$this->assertFalse($this->service()->maySeeWatchers($this->object()));
	}

	public function testWatchersAreHiddenWhenThePermissionCheckThrows(): void {
		$this->schemas->method('find')->willReturn(new Schema());
		$this->permissions->method('hasPermission')->willThrowException(new RuntimeException('rbac'));

		$this->assertFalse($this->service()->maySeeWatchers($this->object()));
	}

	public function testWatchersAreShownToACallerWhoMayUpdate(): void {
		$schema = new Schema();
		$object = $this->object();
		$this->schemas->method('find')->willReturn($schema);
		$this->permissions->expects($this->once())->method('hasPermission')
			->with($schema, 'update', null, 'owner', true, $object, null)
			->willReturn(true);

		$this->assertTrue($this->service()->maySeeWatchers($object));
	}

	public function testAnEmptyUuidHasNoUidsToNotify(): void {
		$this->mapper->expects($this->never())->method('findNotifyingByObject');

		$this->assertSame([], $this->service()->watcherUids(''));
	}

	public function testAFailedNotifyLookupNotifiesNobodyAndLogs(): void {
		$this->mapper->method('findNotifyingByObject')->willThrowException(new RuntimeException('down'));
		$this->logger->expects($this->once())->method('warning')
			->with('[WatcherService] watcher lookup failed for "uuid-1": down');

		$this->assertSame([], $this->service()->watcherUids('uuid-1'));
	}

	public function testNotifyUidsAreDeduplicatedAndSkipBlankUsers(): void {
		$rows = [];
		foreach (['alice', '', 'bob', 'alice'] as $uid) {
			$row = new Watcher();
			$row->setUserId($uid);
			$rows[] = $row;
		}

		$this->mapper->method('findNotifyingByObject')->willReturn($rows);

		$this->assertSame(['alice', 'bob'], $this->service()->watcherUids('uuid-1'));
	}

	public function testDroppingWatchersSkipsBlankIdsAndSurvivesAFailure(): void {
		$calls = [];
		$this->mapper->method('unsubscribe')->willReturnCallback(
			static function (string $userId, string $objectUuid) use (&$calls): bool {
				$calls[] = $userId.'@'.$objectUuid;
				if ($userId === 'bob') {
					throw new RuntimeException('locked');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())->method('warning')
			->with('[WatcherService] could not drop watcher "bob": locked');

		$this->service()->dropWatchers('uuid-1', ['alice', '', 42, 'bob', 'carol']);

		$this->assertSame(['alice@uuid-1', 'bob@uuid-1', 'carol@uuid-1'], $calls);
	}

	public function testDroppingWatchersForgetsTheFollowMarker(): void {
		$this->mapper->expects($this->exactly(2))->method('notifyMapForUser')
			->willReturnOnConsecutiveCalls(['uuid-1' => true], []);

		$service = $this->service();
		$this->assertTrue($service->isWatchedByCaller('uuid-1'));

		$service->dropWatchers('uuid-1', ['alice']);

		$this->assertFalse($service->isWatchedByCaller('uuid-1'));
	}

	public function testCleaningUpAnEmptyUuidRemovesNothing(): void {
		$this->mapper->expects($this->never())->method('deleteByObject');

		$this->assertSame(0, $this->service()->cleanupForObject(''));
	}
}
