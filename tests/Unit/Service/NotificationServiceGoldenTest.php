<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Service\NotificationService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use TypeError;

/**
 * Golden test for NotificationService: pins recipients (groups + admin, deduplicated),
 * the notification each recipient gets, failure handling and every log line.
 *
 * @covers \OCA\OpenRegister\Service\NotificationService
 */
final class NotificationServiceGoldenTest extends TestCase {

	/** @var array<int,array> Recorded calls on notifications and the manager, in order */
	private array $calls = [];

	/** @var array<int,array{level:string,message:string}> Captured log lines */
	private array $logLines = [];

	/** @var array<string,array<int,string>|null> Members per group id; null means the group is missing */
	private array $groups = [];

	/** @var array<int,string> User ids whose notify() throws */
	private array $failingUsers = [];

	/** @var string|null The user of the notification built last */
	private ?string $lastUser = null;

	/** @var NotificationService The service under test */
	private NotificationService $service;

	protected function setUp(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->calls[] = ['notify'];
				if (in_array($this->lastUser, $this->failingUsers, true) === true) {
					throw new Exception('push down');
				}
			}
		);
		$manager->method('markProcessed')->willReturnCallback(
			function (INotification $notification): void {
				$this->calls[] = ['markProcessed'];
			}
		);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturnCallback(
			function ($groupId): ?IGroup {
				$this->calls[] = ['groupManager.get', $groupId];
				$members = $this->groups[$groupId] ?? null;
				if ($members === null) {
					return null;
				}

				$group = $this->createMock(IGroup::class);
				$group->method('getUsers')->willReturn(array_map(fn (string $uid): IUser => $this->user($uid), $members));
				return $group;
			}
		);

		$lines = &$this->logLines;
		$logger = new class($lines) extends AbstractLogger {
			/** @var array Captured log lines */
			private array $lines;

			public function __construct(array &$lines) {
				$this->lines = &$lines;
			}

			public function log($level, $message, array $context = []): void {
				$this->lines[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => array_keys($context)];
			}
		};

		$this->service = new NotificationService($manager, $groupManager, $logger);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnCallback(
				function (...$args) use ($method, $notification): INotification {
					if ($args[0] instanceof DateTime) {
						$this->assertEqualsWithDelta(time(), $args[0]->getTimestamp(), 60);
						$args = ['<now>'];
					}

					if ($method === 'setUser') {
						$this->lastUser = $args[0];
					}

					$this->calls[] = array_merge([$method], $args);
					return $notification;
				}
			);
		}

		return $notification;
	}

	private function configuration(?array $groups, ?string $local = '1.0.0', ?string $remote = '1.1.0'): Configuration {
		$configuration = new Configuration();
		$configuration->setId(8);
		$configuration->setTitle('Zaken');
		$configuration->setNotificationGroups($groups);
		$configuration->setLocalVersion($local);
		$configuration->setRemoteVersion($remote);
		return $configuration;
	}

	private function sent(string $uid, string $local = '1.0.0', string $remote = '1.1.0'): array {
		return [
			['setApp', 'openregister'],
			['setUser', $uid],
			['setDateTime', '<now>'],
			['setObject', 'configuration', '8'],
			[
				'setSubject',
				'configuration_update_available',
				['configurationTitle' => 'Zaken', 'configurationId' => 8, 'currentVersion' => $local, 'newVersion' => $remote],
			],
			['notify'],
		];
	}

	public function testNotifiesGroupMembersAndAdminsOnce(): void {
		$this->groups = ['editors' => ['u1', 'u2'], 'admin' => ['u2', 'u3']];

		$count = $this->service->notifyConfigurationUpdate($this->configuration(['editors', 'missing']));

		$this->assertSame(3, $count);
		$this->assertSame(
			array_merge(
				[['groupManager.get', 'editors'], ['groupManager.get', 'missing'], ['groupManager.get', 'admin']],
				$this->sent('u1'),
				$this->sent('u2'),
				$this->sent('u3')
			),
			$this->calls
		);
		$this->assertSame(
			[
				['level' => 'info', 'message' => '[NotificationService] Sending configuration update notification for: Zaken', 'context' => ['file', 'line']],
				['level' => 'warning', 'message' => '[NotificationService] Group missing not found, skipping', 'context' => ['file', 'line']],
				['level' => 'info', 'message' => '[NotificationService] Sent 3 notifications for configuration update', 'context' => ['file', 'line']],
			],
			$this->logLines
		);
	}

	public function testAdminGroupIsNotAddedTwice(): void {
		$this->groups = ['admin' => ['root'], 'editors' => ['root']];

		$this->assertSame(1, $this->service->notifyConfigurationUpdate($this->configuration(['admin', 'editors'])));
		$this->assertSame([['groupManager.get', 'admin'], ['groupManager.get', 'editors']], array_slice($this->calls, 0, 2));
		$this->assertCount(8, $this->calls);
	}

	public function testNullGroupsNotifyAdminsOnlyAndUnknownVersionsAreNamed(): void {
		$this->groups = ['admin' => ['root']];

		$this->assertSame(1, $this->service->notifyConfigurationUpdate($this->configuration(null, null, null)));
		$this->assertSame(
			array_merge([['groupManager.get', 'admin']], $this->sent('root', 'unknown', 'unknown')),
			$this->calls
		);
	}

	public function testNumericUserIdsAreSentAsStrings(): void {
		$this->groups = ['admin' => ['42']];

		$this->service->notifyConfigurationUpdate($this->configuration([]));

		$this->assertSame(['setUser', '42'], $this->calls[2]);
	}

	public function testFailedNotificationIsLoggedAndNotCounted(): void {
		$this->groups = ['admin' => ['u1', 'u2', 'u3']];
		$this->failingUsers = ['u2'];

		$this->assertSame(2, $this->service->notifyConfigurationUpdate($this->configuration([])));
		$this->assertSame(
			[
				'info|[NotificationService] Sending configuration update notification for: Zaken',
				'error|[NotificationService] Failed to send notification to user u2: push down',
				'info|[NotificationService] Sent 2 notifications for configuration update',
			],
			array_map(static fn (array $line): string => $line['level'] . '|' . $line['message'], $this->logLines)
		);
	}

	public function testNoRecipientsSendsNothing(): void {
		$this->assertSame(0, $this->service->notifyConfigurationUpdate($this->configuration(['ghosts'])));
		$this->assertSame([['groupManager.get', 'ghosts'], ['groupManager.get', 'admin']], $this->calls);
		$this->assertSame('[NotificationService] Sent 0 notifications for configuration update', $this->logLines[3]['message']);
	}

	public function testUntitledConfigurationFailsOnlyOnceThereIsARecipient(): void {
		$configuration = $this->configuration([]);
		$configuration->setTitle(null);

		$this->assertSame(0, $this->service->notifyConfigurationUpdate($configuration));
		$this->assertSame('[NotificationService] Sending configuration update notification for: ', $this->logLines[0]['message']);

		$this->groups = ['admin' => ['root']];
		$this->expectException(TypeError::class);
		$this->service->notifyConfigurationUpdate($configuration);
	}

	public function testMarkConfigurationUpdated(): void {
		$this->service->markConfigurationUpdated($this->configuration([]));

		$this->assertSame(
			[['setApp', 'openregister'], ['setObject', 'configuration', '8'], ['markProcessed']],
			$this->calls
		);
		$this->assertSame(
			[['level' => 'info', 'message' => '[NotificationService] Marked configuration Zaken notifications as processed', 'context' => ['file', 'line']]],
			$this->logLines
		);
	}
}
