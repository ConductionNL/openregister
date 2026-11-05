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
	 * A notifier whose user and group lookups know `adviser` and the group `advisers` (ann, bob).
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
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'adviser');

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => $gid === 'advisers' ? $this->group() : null);

		return new ExternalGrantLapseNotifier(notifications: $this->manager, users: $users, groups: $groups);
	}//end notifier()

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
	 * A user principal is warned once, and the earlier warning for the same grant is replaced.
	 *
	 * @return void
	 */
	public function testAUserHolderIsWarnedAndTheEarlierWarningReplaced(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->once())->method('markProcessed');
		$this->manager->expects($this->once())->method('notify');

		$count = $notifier->warn(schema: $this->schema(), grant: ['action' => 'update', 'principal' => 'adviser', 'endsAt' => 'x', 'daysLeft' => 3]);

		$this->assertSame(1, $count);
		$this->assertSame(['adviser'], $this->warned);
	}//end testAUserHolderIsWarnedAndTheEarlierWarningReplaced()

	/**
	 * A group principal warns each member.
	 *
	 * @return void
	 */
	public function testAGroupHolderWarnsEachMember(): void {
		$notifier = $this->notifier();

		$this->assertSame(2, $notifier->warn(schema: $this->schema(), grant: ['action' => 'read', 'principal' => 'advisers']));
		$this->assertSame(['ann', 'bob'], $this->warned);
	}//end testAGroupHolderWarnsEachMember()

	/**
	 * A principal nobody resolves warns nobody.
	 *
	 * @return void
	 */
	public function testAnUnknownPrincipalWarnsNobody(): void {
		$notifier = $this->notifier();
		$this->manager->expects($this->never())->method('notify');

		$this->assertSame(0, $notifier->warn(schema: $this->schema(), grant: ['action' => 'read', 'principal' => 'ghost']));
	}//end testAnUnknownPrincipalWarnsNobody()

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
							['user' => 'advisers', 'external' => true, 'until' => $later],
							['user' => 'advisers', 'until' => $soon],
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
