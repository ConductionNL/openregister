<?php

declare(strict_types=1);

/**
 * ReviewReminderJob tests.
 *
 * The review only happens if somebody is asked. These pin what the pass asks
 * for: one notification per reviewer, naming their count, only for entries that
 * have waited longer than the declared frequency, and nothing at all on an
 * instance that keeps no destruction lists.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\OpenRegister\BackgroundJob\ReviewReminderJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionListRepository;
use OCA\OpenRegister\Service\Archival\DestructionReviewService;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Tests for ReviewReminderJob.
 */
class ReviewReminderJobTest extends TestCase {

	private DestructionListRepository&MockObject $lists;
	private ContainerInterface&MockObject $container;

	/**
	 * Every reminder the pass sent, as reviewer => pending count.
	 *
	 * @var array<string, int>
	 */
	private array $reminded = [];

	protected function setUp(): void {
		parent::setUp();

		$this->reminded = [];
		$this->lists = $this->createMock(DestructionListRepository::class);

		$notification = $this->createMock(INotification::class);
		$recipient = '';
		foreach (['setApp', 'setDateTime', 'setObject'] as $fluent) {
			$notification->method($fluent)->willReturn($notification);
		}

		$notification->method('setUser')->willReturnCallback(
			static function (string $uid) use ($notification, &$recipient): INotification {
				$recipient = $uid;
				return $notification;
			}
		);

		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters = []) use ($notification, &$recipient): INotification {
				$this->reminded[$recipient] = (int)($parameters['pendingCount'] ?? 0);
				return $notification;
			}
		);

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);

		$settings = $this->createMock(ObjectRetentionHandler::class);
		$settings->method('getArchivalSettingsOnly')->willReturn(['reviewReminderFrequency' => 'P7D']);

		$this->container = $this->createMock(ContainerInterface::class);
		$this->container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				DestructionListRepository::class => $this->lists,
				DestructionReviewService::class => new DestructionReviewService(),
				INotificationManager::class => $notificationManager,
				ObjectRetentionHandler::class => $settings,
				default => $this->createMock(LoggerInterface::class),
			}
		);
	}

	/**
	 * Run the pass, which TimedJob keeps protected.
	 *
	 * @return void
	 */
	private function runPass(): void {
		$job = new ReviewReminderJob($this->createMock(ITimeFactory::class), $this->container);

		$run = (new ReflectionClass(ReviewReminderJob::class))->getMethod('run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}

	/**
	 * A destruction list holding entries assigned at given moments.
	 *
	 * @param string                             $uuid    The list uuid.
	 * @param array<int, array<string, mixed>> $entries The entries.
	 *
	 * @return ObjectEntity The list.
	 */
	private function listWith(string $uuid, array $entries): ObjectEntity {
		$list = new ObjectEntity();
		$list->setUuid($uuid);
		$list->setObject(['status' => 'in_review', 'objects' => $entries]);

		return $list;
	}

	public function testEachReviewerIsRemindedOnceWithTheirOwnCount(): void {
		$old = (new DateTimeImmutable('-30 days'))->format('c');

		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->method('findLists')->willReturn(
			[
				$this->listWith(
					'dl-1',
					[
						['uuid' => 'obj-1', 'title' => 'A', 'reviewer' => 'els', 'assignedAt' => $old],
						['uuid' => 'obj-2', 'title' => 'B', 'reviewer' => 'joris', 'assignedAt' => $old],
					]
				),
				$this->listWith(
					'dl-2',
					[
						['uuid' => 'obj-3', 'title' => 'C', 'reviewer' => 'els', 'assignedAt' => $old],
					]
				),
			]
		);

		$this->runPass();

		$this->assertSame(['els' => 2, 'joris' => 1], $this->reminded);
	}

	public function testAnEntryAssignedThisMorningIsNotChasedYet(): void {
		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->method('findLists')->willReturn(
			[
				$this->listWith(
					'dl-1',
					[
						[
							'uuid' => 'obj-1',
							'title' => 'A',
							'reviewer' => 'els',
							'assignedAt' => (new DateTimeImmutable('-1 hour'))->format('c'),
						],
					]
				),
			]
		);

		$this->runPass();

		$this->assertSame([], $this->reminded);
	}

	public function testAnAnsweredEntryStopsBeingChased(): void {
		$old = (new DateTimeImmutable('-30 days'))->format('c');

		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->method('findLists')->willReturn(
			[
				$this->listWith(
					'dl-1',
					[
						[
							'uuid' => 'obj-1',
							'title' => 'A',
							'reviewer' => 'els',
							'assignedAt' => $old,
							'decision' => 'destroy',
						],
					]
				),
			]
		);

		$this->runPass();

		$this->assertSame([], $this->reminded);
	}

	public function testAnInstanceWithNoDestructionListRegisterRemindsNobody(): void {
		$this->lists->method('isConfigured')->willReturn(false);
		$this->lists->expects($this->never())->method('findLists');

		$this->runPass();

		$this->assertSame([], $this->reminded);
	}
}
