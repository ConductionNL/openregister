<?php

/**
 * DestructionCheckJob: no pre-destruction warning for an object a declared ancestor keeps.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * The pre-destruction pass asks RetentionService which approaching objects a
 * declared ancestor keeps, and warns about the others only.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\DestructionCheckJob
 * @uses   \OCA\OpenRegister\BackgroundJob\Support\PreDestructionPass
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class DestructionCheckJobInheritedTest extends TestCase {

	private RetentionService&MockObject $retentionService;
	private INotificationManager&MockObject $notificationManager;
	private ContainerInterface&MockObject $container;

	/**
	 * Object uuids the notification manager was asked to notify about.
	 *
	 * @var array<int, string>
	 */
	private array $notifiedUuids = [];

	protected function setUp(): void {
		parent::setUp();

		$this->notifiedUuids = [];
		$this->retentionService = $this->createMock(RetentionService::class);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setSubject'] as $fluent) {
			$notification->method($fluent)->willReturn($notification);
		}

		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification): INotification {
				$this->notifiedUuids[] = $id;
				return $notification;
			}
		);

		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('archivaris-1');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->willReturn($group);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				RetentionServiceInterface::class => $this->retentionService,
				INotificationManager::class => $this->notificationManager,
				IGroupManager::class => $groupManager,
				IAppConfig::class => $appConfig,
				ArchivalSettingsReaderInterface::class => $this->createMock(ArchivalSettingsReaderInterface::class),
				default => $this->createMock(LoggerInterface::class),
			}
		);
	}//end setUp()

	private function approaching(string $uuid): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention(
			[
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => 'nog_te_archiveren',
				'archiefactiedatum' => (new DateTime())->modify('+10 days')->format('Y-m-d'),
			]
		);
		return $object;
	}

	public function testAPublicationUnderAHotspotIsNotWarnedAbout(): void {
		$publication = $this->approaching('pub-1');
		$other       = $this->approaching('pub-2');
		$this->retentionService->method('scanObjectsWithRetention')
			->willReturn(['objects' => [$publication, $other], 'scanned' => 2, 'truncated' => false]);
		$this->retentionService->expects($this->exactly(2))->method('heldBackByAncestor')->willReturnCallback(
			static fn (ObjectEntity $object): ?string => ($object->getUuid() === 'pub-1' ? 'inherited_permanent_appraisal:subject-1' : null)
		);

		$this->runNotificationPass();

		$this->assertSame(['pub-2'], $this->notifiedUuids);
	}

	public function testAnUnresolvedAncestorIsNotWarnedAboutEither(): void {
		$this->retentionService->method('scanObjectsWithRetention')
			->willReturn(['objects' => [$this->approaching('pub-1')], 'scanned' => 1, 'truncated' => false]);
		$this->retentionService->method('heldBackByAncestor')->willReturn('unresolved_ancestor:subjects:gone');

		$this->runNotificationPass();

		$this->assertSame([], $this->notifiedUuids);
	}

	/**
	 * Run the job's private pre-destruction notification pass.
	 *
	 * @return void
	 */
	private function runNotificationPass(): void {
		$job = new DestructionCheckJob(
			$this->createMock(ITimeFactory::class),
			$this->container
		);

		$method = (new ReflectionClass(DestructionCheckJob::class))->getMethod('sendPreDestructionNotifications');
		$method->setAccessible(true);
		$method->invoke($job, ['notificationLeadDays' => 30], $this->createMock(LoggerInterface::class));
	}//end runNotificationPass()
}//end class
