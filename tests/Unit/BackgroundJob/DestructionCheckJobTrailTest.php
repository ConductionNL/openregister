<?php

declare(strict_types=1);

/**
 * Characterisation of one DestructionCheckJob run: the log trail, the persisted
 * notified set and the notifications sent, for every branch of run() and the
 * pre-destruction notification pass.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use Exception;
use OCA\OpenRegister\BackgroundJob\DestructionCheckJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Object\SaveObjectWriterInterface;
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
use Psr\Log\AbstractLogger;
use ReflectionClass;
use Stringable;

/**
 * Pins what one DestructionCheckJob run logs, stores and sends.
 */
class DestructionCheckJobTrailTest extends TestCase {

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	/**
	 * The notified set as stored in app config.
	 *
	 * @var string
	 */
	private string $stored = '[]';

	/**
	 * The archival settings the job reads.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings = [
		'destructionListRegister' => 'reg-7',
		'destructionListSchema' => 'schema-9',
		'notificationLeadDays' => 30,
	];

	/**
	 * What scanObjectsWithRetention returns, or throws.
	 *
	 * @var array<string, mixed>|Exception
	 */
	private array|Exception $scan = ['objects' => [], 'scanned' => 0, 'truncated' => false];

	/**
	 * The accept filter the scan was given.
	 *
	 * @var callable|null
	 */
	private $accept = null;

	/**
	 * What findEligibleForDestruction returns.
	 *
	 * @var array<int, mixed>
	 */
	private array $eligible = [];

	/**
	 * What createDestructionList returns.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $listData = ['title' => 'list'];

	/**
	 * Thrown from getObjectsOnPendingDestructionLists when set.
	 *
	 * @var Exception|null
	 */
	private ?Exception $pendingFailure = null;

	/**
	 * Thrown from notify() for these subjects.
	 *
	 * @var array<int, string>
	 */
	private array $failingSubjects = [];

	/**
	 * What each notification was told, by object id.
	 *
	 * @var array<int, array{line: string, subject: string}>
	 */
	private array $lines = [];

	public function testUnconfiguredListTargetSkipsEverything(): void {
		$this->settings = ['notificationLeadDays' => 30];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'info [DestructionCheckJob] Destruction list register/schema not configured, skipping',
			],
			$this->trail
		);
	}//end testUnconfiguredListTargetSkipsEverything()

	public function testANotificationPassKeepsKnownPrunesGoneAndSkipsHeld(): void {
		$this->stored = json_encode(['known', 'gone']);
		$this->scan   = [
			'objects' => [
				$this->object(uuid: 'known', extra: []),
				$this->object(uuid: 'fresh', extra: []),
				$this->object(uuid: 'held', extra: ['legalHold' => ['active' => true]]),
			],
			'scanned' => 3,
			'truncated' => false,
		];
		$this->eligible = [];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'notify user-1 retention/fresh retention_approaching Object approaching destruction date',
				'store ["known","fresh"]',
				'info [DestructionCheckJob] Sent 1 pre-destruction notifications',
				'info [DestructionCheckJob] No objects eligible for destruction',
			],
			$this->trail
		);
	}//end testANotificationPassKeepsKnownPrunesGoneAndSkipsHeld()

	public function testATruncatedPassPrunesNothing(): void {
		$this->stored = json_encode(['known', 'gone']);
		$this->scan   = [
			'objects' => [$this->object(uuid: 'fresh', extra: ['archiefnominatie' => 'bewaren'])],
			'scanned' => 1,
			'truncated' => true,
		];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'notify user-1 retention/fresh retention_approaching Object requires e-Depot transfer',
				'store ["known","gone","fresh"]',
				'info [DestructionCheckJob] Sent 1 pre-destruction notifications',
				'info [DestructionCheckJob] No objects eligible for destruction',
			],
			$this->trail
		);
	}//end testATruncatedPassPrunesNothing()

	public function testAnUnchangedSetIsNotStored(): void {
		$this->stored = json_encode(['known']);
		$this->scan   = ['objects' => [$this->object(uuid: 'known', extra: [])], 'scanned' => 1, 'truncated' => false];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'info [DestructionCheckJob] No objects eligible for destruction',
			],
			$this->trail
		);
	}//end testAnUnchangedSetIsNotStored()

	public function testAPrunedSetIsStoredEvenWithNothingNew(): void {
		$this->stored = json_encode(['known', 'gone']);
		$this->scan   = ['objects' => [$this->object(uuid: 'known', extra: [])], 'scanned' => 1, 'truncated' => false];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'store ["known"]',
				'info [DestructionCheckJob] Sent 0 pre-destruction notifications',
				'info [DestructionCheckJob] No objects eligible for destruction',
			],
			$this->trail
		);
	}//end testAPrunedSetIsStoredEvenWithNothingNew()

	public function testAFailedSendIsLoggedAndStillCountsAsNotified(): void {
		$this->scan            = ['objects' => [$this->object(uuid: 'fresh', extra: [])], 'scanned' => 1, 'truncated' => false];
		$this->failingSubjects = ['retention_approaching'];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'notify user-1 retention/fresh retention_approaching Object approaching destruction date',
				'warning [DestructionCheckJob] Notification send error: send failed',
				'store ["fresh"]',
				'info [DestructionCheckJob] Sent 1 pre-destruction notifications',
				'info [DestructionCheckJob] No objects eligible for destruction',
			],
			$this->trail
		);
	}//end testAFailedSendIsLoggedAndStillCountsAsNotified()

	public function testAFailingScanIsAWarningAndTheRunGoesOn(): void {
		$this->scan = new Exception('scan broke');

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'warning [DestructionCheckJob] Notification error: scan broke',
				'info [DestructionCheckJob] No objects eligible for destruction',
			],
			$this->trail
		);
	}//end testAFailingScanIsAWarningAndTheRunGoesOn()

	public function testAnEligibleSetBecomesAListAndAReviewNotice(): void {
		$this->eligible = ['a', 'b'];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'info [DestructionCheckJob] Found 2 objects eligible',
				'save reg-7 schema-9',
				'info [DestructionCheckJob] Created destruction list, UUID: list-1',
				'notify user-1 destruction_list/list-1 destruction_list_review {"listUuid":"list-1","objectCount":2}',
			],
			$this->trail
		);
	}//end testAnEligibleSetBecomesAListAndAReviewNotice()

	public function testAFailedReviewNoticeIsAWarning(): void {
		$this->eligible        = ['a'];
		$this->failingSubjects = ['destruction_list_review'];

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'info [DestructionCheckJob] Found 1 objects eligible',
				'save reg-7 schema-9',
				'info [DestructionCheckJob] Created destruction list, UUID: list-1',
				'notify user-1 destruction_list/list-1 destruction_list_review {"listUuid":"list-1","objectCount":1}',
				'warning [DestructionCheckJob] Review notification error: send failed',
			],
			$this->trail
		);
	}//end testAFailedReviewNoticeIsAWarning()

	public function testAListThatCouldNotBeBuiltIsAWarning(): void {
		$this->eligible = ['a'];
		$this->listData = null;

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'info [DestructionCheckJob] Found 1 objects eligible',
				'warning [DestructionCheckJob] Failed to create destruction list',
			],
			$this->trail
		);
	}//end testAListThatCouldNotBeBuiltIsAWarning()

	public function testAFailingRetentionLookupIsAnError(): void {
		$this->pendingFailure = new Exception('db down');

		$this->runJob();

		$this->assertSame(
			[
				'info [DestructionCheckJob] Starting destruction check',
				'scan',
				'error [DestructionCheckJob] Error: db down exception=db down',
			],
			$this->trail
		);
	}//end testAFailingRetentionLookupIsAnError()

	public function testTheScanAcceptsOnlyLiveObjectsInsideTheLeadWindow(): void {
		$this->runJob();

		$accept = $this->accept;
		$this->assertNotNull($accept);

		$soon = (new DateTime())->modify('+10 days')->format('Y-m-d');
		$cases = [
			'live, inside' => [['archiefstatus' => 'nog_te_archiveren', 'archiefactiedatum' => $soon], true],
			'live alias, inside' => [['archiefstatus' => 'active', 'archiefactiedatum' => $soon], true],
			'archived, inside' => [['archiefstatus' => 'gearchiveerd', 'archiefactiedatum' => $soon], false],
			'live, no date' => [['archiefstatus' => 'active'], false],
			'live, past' => [['archiefstatus' => 'active', 'archiefactiedatum' => (new DateTime())->modify('-1 day')->format('Y-m-d')], false],
			'live, today' => [['archiefstatus' => 'active', 'archiefactiedatum' => (new DateTime())->format('Y-m-d')], false],
			'live, beyond' => [['archiefstatus' => 'active', 'archiefactiedatum' => (new DateTime())->modify('+40 days')->format('Y-m-d')], false],
		];
		foreach ($cases as $name => [$retention, $expected]) {
			$object = new ObjectEntity();
			$object->setUuid('u');
			$object->setRetention($retention);
			$this->assertSame($expected, $accept($object), $name);
		}
	}//end testTheScanAcceptsOnlyLiveObjectsInsideTheLeadWindow()

	/**
	 * An in-window, live object.
	 *
	 * @param string               $uuid  The uuid.
	 * @param array<string, mixed> $extra Retention keys to add.
	 *
	 * @return ObjectEntity
	 */
	private function object(string $uuid, array $extra): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRetention(
			array_merge(
				[
					'archiefnominatie' => 'vernietigen',
					'archiefstatus' => 'nog_te_archiveren',
					'archiefactiedatum' => (new DateTime())->modify('+10 days')->format('Y-m-d'),
				],
				$extra
			)
		);
		return $object;
	}//end object()

	/**
	 * Run the job's run() over the stubbed container.
	 *
	 * @return void
	 */
	private function runJob(): void {
		$this->trail = [];
		$job         = new DestructionCheckJob($this->createMock(ITimeFactory::class), $this->container());
		$method      = (new ReflectionClass(DestructionCheckJob::class))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * The container the job resolves from; one logger for every lookup.
	 *
	 * @return ContainerInterface
	 */
	private function container(): ContainerInterface {
		$logger    = $this->logger();
		$services  = [
			RetentionServiceInterface::class => $this->retention(),
			ArchivalSettingsReaderInterface::class => $this->settingsReader(),
			IAppConfig::class => $this->appConfig(),
			INotificationManager::class => $this->notificationManager(),
			IGroupManager::class => $this->groupManager(),
			SaveObjectWriterInterface::class => $this->saveObject(),
			\Psr\Log\LoggerInterface::class => $logger,
		];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static fn (string $id): object => $services[$id]);
		return $container;
	}//end container()

	/**
	 * A logger that writes to the trail.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$trail = &$this->trail;
		return new class($trail) extends AbstractLogger {
			/**
			 * @param array<int, string> $trail The shared trail.
			 */
			public function __construct(private array &$trail) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$suffix = '';
				if (isset($context['exception']) === true) {
					$suffix = ' exception=' . $context['exception']->getMessage();
				}

				$this->trail[] = $level . ' ' . $message . $suffix;
			}
		};
	}//end logger()

	/**
	 * The retention service stub.
	 *
	 * @return RetentionServiceInterface&MockObject
	 */
	private function retention(): RetentionServiceInterface&MockObject {
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('scanObjectsWithRetention')->willReturnCallback(
			function (callable $accept): array {
				$this->trail[] = 'scan';
				$this->accept  = $accept;
				if ($this->scan instanceof Exception) {
					throw $this->scan;
				}

				return $this->scan;
			}
		);
		$retention->method('getObjectsOnPendingDestructionLists')->willReturnCallback(
			function (): array {
				if ($this->pendingFailure !== null) {
					throw $this->pendingFailure;
				}

				return ['pending'];
			}
		);
		$retention->method('findEligibleForDestruction')->willReturnCallback(fn (): array => $this->eligible);
		$retention->method('createDestructionList')->willReturnCallback(fn (): ?array => $this->listData);
		return $retention;
	}//end retention()

	/**
	 * The archival settings reader stub.
	 *
	 * @return ArchivalSettingsReaderInterface&MockObject
	 */
	private function settingsReader(): ArchivalSettingsReaderInterface&MockObject {
		$reader = $this->createMock(ArchivalSettingsReaderInterface::class);
		$reader->method('getArchivalSettingsOnly')->willReturnCallback(fn (): array => $this->settings);
		return $reader;
	}//end settingsReader()

	/**
	 * The app config stub holding the notified set.
	 *
	 * @return IAppConfig&MockObject
	 */
	private function appConfig(): IAppConfig&MockObject {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (): string => $this->stored);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->trail[] = 'store ' . $value;
				$this->stored  = $value;
				return true;
			}
		);
		return $appConfig;
	}//end appConfig()

	/**
	 * A notification manager that writes each notice to the trail.
	 *
	 * @return INotificationManager&MockObject
	 */
	private function notificationManager(): INotificationManager&MockObject {
		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$seen = $this->lines[spl_object_id($notification)];
				$this->trail[] = 'notify ' . $seen['line'];
				if (in_array($seen['subject'], $this->failingSubjects, true) === true) {
					throw new Exception('send failed');
				}
			}
		);
		return $manager;
	}//end notificationManager()

	/**
	 * One recording notification; what it was told lands in $this->lines.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$id = spl_object_id($notification);
		$this->lines[$id] = ['line' => '', 'subject' => ''];
		$notification->method('setApp')->willReturn($notification);
		$notification->method('setUser')->willReturnCallback(
			function (string $user) use ($notification, $id): INotification {
				$this->lines[$id]['line'] = $user;
				return $notification;
			}
		);
		$notification->method('setDateTime')->willReturn($notification);
		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $objectId) use ($notification, $id): INotification {
				$this->lines[$id]['line'] .= ' ' . $type . '/' . $objectId;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification, $id): INotification {
				$this->lines[$id]['subject'] = $subject;
				$detail = json_encode($parameters);
				if ($subject === 'retention_approaching') {
					$detail = $parameters['subject'];
				}

				$this->lines[$id]['line'] .= ' ' . $subject . ' ' . $detail;
				return $notification;
			}
		);
		return $notification;
	}//end notification()

	/**
	 * A group manager with one archivist.
	 *
	 * @return IGroupManager&MockObject
	 */
	private function groupManager(): IGroupManager&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user-1');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$user]);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('get')->with('archivaris')->willReturn($group);
		return $groupManager;
	}//end groupManager()

	/**
	 * A save-object writer that records the save and returns list-1.
	 *
	 * @return SaveObjectWriterInterface&MockObject
	 */
	private function saveObject(): SaveObjectWriterInterface&MockObject {
		$saved = new ObjectEntity();
		$saved->setUuid('list-1');
		$writer = $this->createMock(SaveObjectWriterInterface::class);
		$writer->method('saveObject')->willReturnCallback(
			function ($register, $schema) use ($saved): ObjectEntity {
				$this->trail[] = 'save ' . $register . ' ' . $schema;
				return $saved;
			}
		);
		return $writer;
	}//end saveObject()
}//end class
