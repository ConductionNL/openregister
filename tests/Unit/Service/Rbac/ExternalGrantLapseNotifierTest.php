<?php

/**
 * The holder of an external grant is warned before it lapses.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\BackgroundJob\ExternalGrantLapseJob;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\ExternalGrantLapseNotifier
 * @covers \OCA\OpenRegister\BackgroundJob\ExternalGrantLapseJob
 * @uses \OCA\OpenRegister\Service\Rbac\ExternalGrantGuard
 * @uses \OCA\OpenRegister\Db\Schema
 */
class ExternalGrantLapseNotifierTest extends TestCase {

	/**
	 * The notification manager double.
	 *
	 * @var IManager&MockObject
	 */
	private IManager&MockObject $manager;

	/**
	 * The users who received a warning.
	 *
	 * @var array<int, string>
	 */
	private array $warned = [];

	/**
	 * A notifier whose lookups know the users `adviser` and `advisers`, and the group `advisers` (ann, bob).
	 *
	 * The user and the group named `advisers` collide on purpose.
	 *
	 * @return ExternalGrantLapseNotifier
	 */
	private function notifier(): ExternalGrantLapseNotifier {
		$this->manager = $this->createMock(IManager::class);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setObject', 'setSubject', 'setDateTime'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $uid) use ($notification): INotification {
				$this->warned[] = $uid;
				return $notification;
			}
		);
		$this->manager->method('createNotification')->willReturn($notification);

		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(fn (string $uid): bool => $this->lookedUp(name: $uid) !== null);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $this->lookedUp(name: $uid));

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $gid === 'advisers' ? $this->group() : null);

		return new ExternalGrantLapseNotifier(notifications: $this->manager, users: $users, groups: $groups);
	}//end notifier()

	/**
	 * The user a name finds: the lookup ignores case and takes an email address, as the real one may.
	 *
	 * @param string $name The name asked for.
	 *
	 * @return IUser|null The user.
	 */
	private function lookedUp(string $name): ?IUser {
		$uid = strtolower(str_replace('@example.org', '', $name));
		if (in_array($uid, ['adviser', 'advisers'], true) === false) {
			return null;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end lookedUp()

	/**
	 * The group `advisers` with two members.
	 *
	 * @return IGroup
	 */
	private function group(): IGroup {
		$members = [];
		foreach (['ann', 'bob'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$members[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($members);

		return $group;
	}//end group()

	/**
	 * A schema.
	 *
	 * @param array<string, mixed>|null $authorization Its authorization block.
	 *
	 * @return Schema
	 */
	private function schema(?array $authorization = null): Schema {
		$schema = new Schema();
		$schema->setId(9);
		$schema->setTitle('Zaken');
		$schema->setAuthorization($authorization);

		return $schema;
	}//end schema()

	/**
	 * A `{user}` holder is warned once, and the earlier warning for the same grant is replaced.
	 *
	 * @return void
	 */
	public function testAUserHolderIsWarnedAndTheEarlierWarningReplaced(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->once())->method('markProcessed');
		$this->manager->expects($this->once())->method('notify');

		$count = $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => 'adviser']));

		$this->assertSame(1, $count);
		$this->assertSame(['adviser'], $this->warned);
	}//end testAUserHolderIsWarnedAndTheEarlierWarningReplaced()

	/**
	 * A `{group}` holder warns each member.
	 *
	 * @return void
	 */
	public function testAGroupHolderWarnsEachMember(): void {
		$notifier = $this->notifier();

		$this->assertSame(2, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['group' => 'advisers'])));
		$this->assertSame(['ann', 'bob'], $this->warned);
	}//end testAGroupHolderWarnsEachMember()

	/**
	 * An entry naming both a user and a group warns both, each person once.
	 *
	 * @return void
	 */
	public function testAnEntryWithAUserAndAGroupWarnsBoth(): void {
		$notifier = $this->notifier();

		$this->assertSame(3, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => 'adviser', 'group' => 'advisers'])));
		$this->assertSame(['adviser', 'ann', 'bob'], $this->warned);
	}//end testAnEntryWithAUserAndAGroupWarnsBoth()

	/**
	 * A padded id grants nobody, so it warns nobody.
	 *
	 * @return void
	 */
	public function testAPaddedIdWarnsNobody(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->never())->method('notify');

		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => ' adviser '])));
		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['group' => ' advisers '])));
	}//end testAPaddedIdWarnsNobody()

	/**
	 * Another casing or an email address finds a user but is not their uid, so it warns nobody.
	 *
	 * @return void
	 */
	public function testANameThatOnlyLooksUpToAUserWarnsNobody(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->never())->method('notify');

		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => 'ADVISER'])));
		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => 'adviser@example.org'])));
	}//end testANameThatOnlyLooksUpToAUserWarnsNobody()

	/**
	 * 🔴 A user and a group with the same name are never confused, either way.
	 *
	 * @return void
	 */
	public function testAUserAndAGroupWithTheSameNameAreNotConfused(): void {
		$notifier = $this->notifier();

		$notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['group' => 'advisers']));
		$this->assertSame(['ann', 'bob'], $this->warned, 'a group grant warns its members, not the user of that name');

		$this->warned = [];
		$notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => 'advisers']));
		$this->assertSame(['advisers'], $this->warned, 'a user grant warns that user, not the group of that name');
	}//end testAUserAndAGroupWithTheSameNameAreNotConfused()

	/**
	 * A holder nobody resolves, or an entry naming no holder, warns nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownOrUnnamedHolderWarnsNobody(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->never())->method('notify');

		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['user' => 'ghost'])));
		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['group' => 'ghosts'])));
		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: $this->lapsing(entry: ['name' => 'adviser'])));
	}//end testAnUnknownOrUnnamedHolderWarnsNobody()

	/**
	 * The one lapsing grant ExternalGrantGuard reports for an entry ending in three days.
	 *
	 * @param array<string, mixed> $entry The entry, without its `external` flag and end.
	 *
	 * @return array<string, mixed> The lapsing grant.
	 */
	private function lapsing(array $entry): array {
		$entry['external'] = true;
		$entry['until'] = (new \DateTimeImmutable('+3 days'))->format(DATE_ATOM);

		$lapsing = (new \OCA\OpenRegister\Service\Rbac\ExternalGrantGuard())->lapsingWithin(authorization: ['update' => [$entry]]);
		$this->assertCount(1, $lapsing);

		return $lapsing[0];
	}//end lapsing()

	/**
	 * The daily sweep warns only for external grants inside the window, across every schema.
	 *
	 * @return void
	 */
	public function testTheSweepWarnsForLapsingExternalGrantsOnly(): void {
		$soon = (new \DateTimeImmutable('+3 days'))->format(DATE_ATOM);
		$later = (new \DateTimeImmutable('+60 days'))->format(DATE_ATOM);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->expects($this->once())->method('findAll')->with(null, null, [], [], [], [], false, false)->willReturn(
			[
				$this->schema(
					[
						'update' => [
							['user' => 'adviser', 'external' => true, 'until' => $soon],
							['group' => 'advisers', 'external' => true, 'until' => $later],
							['group' => 'advisers', 'until' => $soon],
						],
					]
				),
				$this->schema(),
			]
		);

		$job = new ExternalGrantLapseJob(
			time: $this->createMock(ITimeFactory::class),
			schemas: $schemas,
			notifier: $this->notifier(),
			logger: $this->createMock(LoggerInterface::class)
		);
		(new ReflectionMethod(ExternalGrantLapseJob::class, 'run'))->invoke($job, null);

		$this->assertSame(['adviser'], $this->warned);
	}//end testTheSweepWarnsForLapsingExternalGrantsOnly()

	/**
	 * A failing sweep is logged, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingSweepDoesNotThrow(): void {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('findAll')->willThrowException(new \RuntimeException('db down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		$job = new ExternalGrantLapseJob(
			time: $this->createMock(ITimeFactory::class),
			schemas: $schemas,
			notifier: $this->notifier(),
			logger: $logger
		);
		(new ReflectionMethod(ExternalGrantLapseJob::class, 'run'))->invoke($job, null);
	}//end testAFailingSweepDoesNotThrow()
}//end class
