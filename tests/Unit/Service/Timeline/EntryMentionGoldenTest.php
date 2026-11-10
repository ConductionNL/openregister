<?php

/**
 * Golden pin of who a timeline mention pulls in, and what each step is asked.
 *
 * The service is assembled by walking its constructor the way the container
 * does, handing one double per collaborator type, so the pin holds whatever
 * shape the constructor takes.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Timeline
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Timeline;

use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Service\Timeline\WrittenEntry;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Db\Watcher;
use OCA\OpenRegister\Notifications\Service\Timeline\EntryMentionService;
use OCA\OpenRegister\Service\DeepLinkRegistryServiceInterface;
use OCA\OpenRegister\Service\Interaction\WatcherServiceInterface;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Notifications\Service\Timeline\EntryMentionService
 */
class EntryMentionGoldenTest extends TestCase {

	/**
	 * One double per collaborator type.
	 *
	 * @var array<string, object>
	 */
	private array $doubles = [];

	/**
	 * Everything the collaborators were asked, in order.
	 *
	 * @var list<string>
	 */
	private array $calls = [];

	/**
	 * Build a class, handing a double for every collaborator type known here
	 * and building anything else from its own constructor.
	 *
	 * @param string $class The class to build.
	 *
	 * @return object The instance.
	 */
	private function build(string $class): object {
		if (isset($this->doubles[$class]) === true) {
			return $this->doubles[$class];
		}

		$args = [];
		foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $param) {
			$type = $param->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$args[] = $this->build($type->getName());
		}

		return (new ReflectionClass($class))->newInstanceArgs($args);
	}//end build()

	/**
	 * The service over doubles that record what they are asked.
	 *
	 * @return EntryMentionService The service.
	 */
	private function service(): EntryMentionService {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['handler', 'anna', 'bob', 'carl', 'dora', "o'brien"], true)
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('handler');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturnCallback(
			function (string|int $id, ?array $_extend = [], bool $_rbac = true, bool $_multitenancy = true): Schema {
				$this->calls[] = 'schema ' . $id . ' ' . json_encode([$_extend, $_rbac, $_multitenancy]);
				return new Schema();
			}
		);

		$permissions = $this->createMock(PermissionHandlerInterface::class);
		$permissions->method('hasPermission')->willReturnCallback(
			function (Schema $schema, string $action, ?string $userId = null, ?string $objectOwner = null, bool $_rbac = true, ?ObjectEntity $object = null): bool {
				$this->calls[] = 'read? ' . $userId . ' ' . $action . ' ' . json_encode([$objectOwner, $_rbac, $object?->getUuid()]);
				return $userId !== 'bob';
			}
		);

		$watchers = $this->createMock(WatcherServiceInterface::class);
		$watchers->method('subscribeMentioned')->willReturnCallback(
			function (ObjectEntity $object, string $userId, ?string $register = null, ?string $schema = null): Watcher {
				$this->calls[] = 'subscribe ' . $userId . ' ' . json_encode([$object->getUuid(), $register, $schema]);
				if ($userId === 'carl') {
					throw new RuntimeException('down');
				}

				return new Watcher();
			}
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $params) use ($notification) {
				$this->calls[] = 'subject ' . $subject . ' ' . json_encode($params);
				return $notification;
			}
		);
		$notification->method('setLink')->willReturnCallback(
			function (string $link) use ($notification) {
				$this->calls[] = 'link ' . $link;
				return $notification;
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(
			function (): void {
				$this->calls[] = 'notify';
			}
		);

		$deepLinks = $this->createMock(DeepLinkRegistryServiceInterface::class);
		$deepLinks->method('resolveUrl')->willReturn(null);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $params): string => 'https://or.test/' . $route . '?' . http_build_query($params)
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->calls[] = 'warn ' . $message;
			}
		);

		$this->doubles = [
			WatcherServiceInterface::class => $watchers,
			IUserManager::class => $users,
			IUserSession::class => $session,
			SchemaMapperInterface::class => $schemas,
			PermissionHandlerInterface::class => $permissions,
			INotificationManager::class => $notifications,
			LoggerInterface::class => $logger,
			DeepLinkRegistryServiceInterface::class => $deepLinks,
			IURLGenerator::class => $urls,
		];

		$service = $this->build(EntryMentionService::class);
		$this->assertInstanceOf(EntryMentionService::class, $service);

		return $service;
	}//end service()

	/**
	 * Self, unreadable, unsubscribable and readable principals, in one entry.
	 *
	 * @return void
	 */
	public function testApplyPullsInExactlyTheReadableSubscribablePeople(): void {
		$service = $this->service();
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		$object->setRegister('7');
		$object->setSchema('3');
		$object->setName('Case one');

		$entry = new TimelineEntry();
		$entry->setUuid('entry-9');

		$pulled = $service->apply(
			new WrittenEntry(
				$object,
				$entry,
				'@handler asks @anna, @bob, @"carl" and @dora\'s team; @o\'brien, @nobody and info@anna.nl, @anna again'
			),
			'zaken',
			'zaak'
		);

		$this->assertSame(['anna', 'dora', "o'brien"], $pulled);
		$this->assertSame(
			[
				'schema 3 [[],false,false]',
				'read? anna read [null,true,"case-1"]',
				'subscribe anna ["case-1","zaken","zaak"]',
				'subject timeline_mention {"objectUuid":"case-1","objectTitle":"Case one","registerId":"7","schemaId":"3","entryUuid":"entry-9","author":"handler"}',
				'link https://or.test/openregister.ui.objectDetail?register=7&schema=3&id=case-1',
				'notify',
				'schema 3 [[],false,false]',
				'read? bob read [null,true,"case-1"]',
				'schema 3 [[],false,false]',
				'read? carl read [null,true,"case-1"]',
				'subscribe carl ["case-1","zaken","zaak"]',
				'warn [EntryMentionService] carl was named in entry entry-9 but could not be subscribed',
				'schema 3 [[],false,false]',
				'read? dora read [null,true,"case-1"]',
				'subscribe dora ["case-1","zaken","zaak"]',
				'subject timeline_mention {"objectUuid":"case-1","objectTitle":"Case one","registerId":"7","schemaId":"3","entryUuid":"entry-9","author":"handler"}',
				'link https://or.test/openregister.ui.objectDetail?register=7&schema=3&id=case-1',
				'notify',
				'schema 3 [[],false,false]',
				'read? o\'brien read [null,true,"case-1"]',
				'subscribe o\'brien ["case-1","zaken","zaak"]',
				'subject timeline_mention {"objectUuid":"case-1","objectTitle":"Case one","registerId":"7","schemaId":"3","entryUuid":"entry-9","author":"handler"}',
				'link https://or.test/openregister.ui.objectDetail?register=7&schema=3&id=case-1',
				'notify',
			],
			$this->calls
		);
	}//end testApplyPullsInExactlyTheReadableSubscribablePeople()
}//end class
