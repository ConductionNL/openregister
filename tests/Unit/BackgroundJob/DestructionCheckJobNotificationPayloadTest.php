<?php

declare(strict_types=1);

/**
 * Characterisation of the pre-destruction notification payload of DestructionCheckJob.
 *
 * Pins what the archivist is told about one approaching object: the subject
 * (a destruction warning, or an e-Depot transfer for a permanently retained
 * record), the title (the object's name, else its uuid), the action date and
 * the selectielijst category (empty when none is recorded).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\DestructionCheckJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\RetentionService;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCA\OpenRegister\Service\Settings\ArchivalSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The payload one approaching object's notification carries.
 */
class DestructionCheckJobNotificationPayloadTest extends TestCase {

	/**
	 * Every notification sent: its user, object and subject.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Whether the archivist group exists.
	 *
	 * @var boolean
	 */
	private bool $groupExists = true;

	/**
	 * What the job set on the notification being built.
	 *
	 * @var array<string, mixed>
	 */
	private array $current = [];

	/**
	 * A destruction warning carries the name, date and category; an e-Depot
	 * record gets the transfer subject; a nameless one is titled by its uuid.
	 *
	 * @return void
	 */
	public function testThePayloadOfEachApproachingObject(): void {
		$due = (new DateTime())->modify('+10 days')->format('Y-m-d');

		$destroy = $this->object(uuid: 'u-destroy', retention: ['archiefnominatie' => 'vernietigen', 'classification' => '1.1']);
		$destroy->setName('Besluit A');
		$keep = $this->object(uuid: 'u-keep', retention: ['archiefnominatie' => 'bewaren']);
		$keep->setName('Besluit B');
		$nameless = $this->object(uuid: 'u-nameless', retention: ['archiefnominatie' => 'blijvend_bewaren', 'classification' => '2.3']);

		$this->runPass(objects: [$destroy, $keep, $nameless]);

		$this->assertSame(
			[
				$this->expected(uuid: 'u-destroy', subject: 'Object approaching destruction date', title: 'Besluit A', date: $due, classification: '1.1'),
				$this->expected(uuid: 'u-destroy', subject: 'Object approaching destruction date', title: 'Besluit A', date: $due, classification: '1.1', user: 'archivaris-2'),
				$this->expected(uuid: 'u-keep', subject: 'Object requires e-Depot transfer', title: 'Besluit B', date: $due, classification: ''),
				$this->expected(uuid: 'u-keep', subject: 'Object requires e-Depot transfer', title: 'Besluit B', date: $due, classification: '', user: 'archivaris-2'),
				$this->expected(uuid: 'u-nameless', subject: 'Object requires e-Depot transfer', title: 'u-nameless', date: $due, classification: '2.3'),
				$this->expected(uuid: 'u-nameless', subject: 'Object requires e-Depot transfer', title: 'u-nameless', date: $due, classification: '2.3', user: 'archivaris-2'),
			],
			$this->sent
		);
	}//end testThePayloadOfEachApproachingObject()

	/**
	 * Without an archivist group nobody is told, and the pass does not fail.
	 *
	 * @return void
	 */
	public function testNoGroupMeansNoNotification(): void {
		$this->groupExists = false;

		$this->runPass(objects: [$this->object(uuid: 'u-1', retention: ['archiefnominatie' => 'vernietigen'])]);

		$this->assertSame([], $this->sent);
	}//end testNoGroupMeansNoNotification()

	/**
	 * An approaching object with the given retention extras.
	 *
	 * @param string               $uuid      The uuid.
	 * @param array<string, mixed> $retention Extra retention fields.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention(
			$retention + [
				'archiefstatus' => 'active',
				'archiefactiedatum' => (new DateTime())->modify('+10 days')->format('Y-m-d'),
			]
		);

		return $object;
	}//end object()

	/**
	 * One expected notification.
	 *
	 * @param string $uuid           The object uuid.
	 * @param string $subject        The subject text.
	 * @param string $title          The title.
	 * @param string $date           The action date.
	 * @param string $classification The category.
	 * @param string $user           The receiving user.
	 *
	 * @return array<string, mixed>
	 */
	private function expected(string $uuid, string $subject, string $title, string $date, string $classification, string $user = 'archivaris-1'): array {
		return [
			'app' => 'openregister',
			'user' => $user,
			'object' => ['retention', $uuid],
			'subject' => [
				'retention_approaching',
				['subject' => $subject, 'title' => $title, 'actieDate' => $date, 'classification' => $classification],
			],
		];
	}//end expected()

	/**
	 * Run the private pre-destruction pass over the scanned objects.
	 *
	 * @param array<int, ObjectEntity> $objects What the scan returns.
	 *
	 * @return void
	 */
	private function runPass(array $objects): void {
		$retention = $this->createMock(RetentionService::class);
		$retention->method('scanObjectsWithRetention')->willReturn(['objects' => $objects, 'scanned' => count($objects), 'truncated' => false]);

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$this->sent[] = $this->current;
			}
		);

		$users = [];
		foreach (['archivaris-1', 'archivaris-2'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$users[] = $user;
		}

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($users);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(fn (string $gid): ?IGroup => ($this->groupExists === true && $gid === 'archivaris') ? $group : null);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				RetentionServiceInterface::class => $retention,
				INotificationManager::class => $manager,
				IGroupManager::class => $groups,
				IAppConfig::class => $appConfig,
				ArchivalSettingsReaderInterface::class => $this->createMock(ArchivalSettingsReaderInterface::class),
				default => $this->createMock(LoggerInterface::class),
			}
		);

		$job = new DestructionCheckJob($this->createMock(ITimeFactory::class), $container);
		$method = (new ReflectionClass(DestructionCheckJob::class))->getMethod('sendPreDestructionNotifications');
		$method->setAccessible(true);
		$method->invoke($job, ['notificationLeadDays' => 30], $this->createMock(LoggerInterface::class));
	}//end runPass()

	/**
	 * A notification stand-in that records what the job set on it.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$this->current = [];
		$mock = $this->createMock(INotification::class);
		$record = function (string $field, mixed $value) use ($mock): INotification {
			$this->current[$field] = $value;
			return $mock;
		};
		$mock->method('setApp')->willReturnCallback(fn (string $app): INotification => $record('app', $app));
		$mock->method('setUser')->willReturnCallback(fn (string $user): INotification => $record('user', $user));
		$mock->method('setDateTime')->willReturn($mock);
		$mock->method('setObject')->willReturnCallback(fn (string $type, string $id): INotification => $record('object', [$type, $id]));
		$mock->method('setSubject')->willReturnCallback(fn (string $subject, array $parameters): INotification => $record('subject', [$subject, $parameters]));

		return $mock;
	}//end notification()
}//end class
