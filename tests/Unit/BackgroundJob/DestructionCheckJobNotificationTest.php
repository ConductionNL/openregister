<?php

declare(strict_types=1);

/**
 * Pre-destruction notification tests for DestructionCheckJob.
 *
 * 🔴 THE WARNING WAS NEVER SENT ON A MIGRATED INSTALL. This scan paged the
 * legacy blob table `openregister_objects`, which `BlobMigrationJob` drains
 * into the per-schema magic tables every five minutes, so it read zero rows and
 * the first a records officer heard of a destruction was after it happened.
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
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
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
 * Tests that the pre-destruction warning reaches the archivist group.
 */
class DestructionCheckJobNotificationTest extends TestCase {

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
				RetentionService::class => $this->retentionService,
				INotificationManager::class => $this->notificationManager,
				IGroupManager::class => $groupManager,
				IAppConfig::class => $appConfig,
				ObjectRetentionHandler::class => $this->createMock(ObjectRetentionHandler::class),
				default => $this->createMock(LoggerInterface::class),
			}
		);
	}//end setUp()

	/**
	 * An object inside the lead window is warned about, through the scan that
	 * reads both stores rather than the drained blob table alone.
	 */
	public function testAnObjectInsideTheWindowIsNotifiedAbout(): void {
		$due = (new DateTime())->modify('+10 days')->format('Y-m-d');

		$object = new ObjectEntity();
		$object->setUuid('warn-uuid');
		$object->setRetention(
			[
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => 'nog_te_archiveren',
				'archiefactiedatum' => $due,
			]
		);

		$this->retentionService->expects($this->once())
			->method('scanObjectsWithRetention')
			->willReturn(['objects' => [$object], 'scanned' => 1, 'truncated' => false]);

		$this->runNotificationPass();

		$this->assertSame(['warn-uuid'], $this->notifiedUuids);
	}//end testAnObjectInsideTheWindowIsNotifiedAbout()

	/**
	 * A held object is warned about by nobody: the hold is why it is not going
	 * to be destroyed.
	 */
	public function testAHeldObjectIsNotNotifiedAbout(): void {
		$due = (new DateTime())->modify('+10 days')->format('Y-m-d');

		$object = new ObjectEntity();
		$object->setUuid('held-uuid');
		$object->setRetention(
			[
				'archiefnominatie' => 'vernietigen',
				'archiefstatus' => 'active',
				'archiefactiedatum' => $due,
				'legalHold' => ['active' => true],
			]
		);

		$this->retentionService->method('scanObjectsWithRetention')
			->willReturn(['objects' => [$object], 'scanned' => 1, 'truncated' => false]);

		$this->runNotificationPass();

		$this->assertSame([], $this->notifiedUuids);
	}//end testAHeldObjectIsNotNotifiedAbout()

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
