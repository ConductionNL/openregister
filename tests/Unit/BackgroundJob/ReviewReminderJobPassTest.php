<?php

declare(strict_types=1);

/**
 * Characterisation tests for the reminder pass: what it logs, and how one
 * failing reminder or a failing list read is contained.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use Exception;
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
use Psr\Log\AbstractLogger;
use ReflectionClass;
use RuntimeException;
use Stringable;

/**
 * Pins the reminder pass's logging and failure containment.
 */
class ReviewReminderJobPassTest extends TestCase {

	private DestructionListRepository&MockObject $lists;

	/**
	 * Every log line, as "level message".
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * Every notification the pass sent, in order, as "reviewer:count".
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	/**
	 * The reviewers whose notification fails.
	 *
	 * @var array<int, string>
	 */
	private array $failingReviewers = [];

	private string $frequency = 'P7D';

	protected function setUp(): void {
		parent::setUp();

		$this->lists = $this->createMock(DestructionListRepository::class);
	}

	/**
	 * Run the pass, which TimedJob keeps protected.
	 *
	 * @return void
	 */
	private function runPass(): void {
		$logged = &$this->logged;
		$logger = new class ($logged) extends AbstractLogger {
			/**
			 * @param array<int, string> $lines The collected lines.
			 */
			public function __construct(private array &$lines) {
			}

			public function log($level, string|Stringable $message, array $context = []): void {
				$this->lines[] = $level . ' ' . $message;
			}
		};

		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturnCallback(fn (): INotification => $this->notification());
		$notificationManager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
				$reviewer = $notification->getUser();
				if (in_array($reviewer, $this->failingReviewers, true) === true) {
					throw new RuntimeException('push down');
				}

				$this->notified[] = $reviewer . ':' . $notification->getSubjectParameters()['pendingCount'];
			}
		);

		$settings = $this->createMock(ObjectRetentionHandler::class);
		$settings->method('getArchivalSettingsOnly')->willReturn(['reviewReminderFrequency' => $this->frequency]);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				DestructionListRepository::class => $this->lists,
				DestructionReviewService::class => new DestructionReviewService(),
				INotificationManager::class => $notificationManager,
				ObjectRetentionHandler::class => $settings,
				default => $logger,
			}
		);

		$job = new ReviewReminderJob($this->createMock(ITimeFactory::class), $container);
		$run = (new ReflectionClass(ReviewReminderJob::class))->getMethod('run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}

	/**
	 * A notification that remembers its user and subject parameters.
	 *
	 * @return INotification
	 */
	private function notification(): INotification {
		$notification = $this->createMock(INotification::class);
		$user = '';
		$parameters = [];
		foreach (['setApp', 'setDateTime', 'setObject'] as $fluent) {
			$notification->method($fluent)->willReturn($notification);
		}

		$notification->method('setUser')->willReturnCallback(
			static function (string $uid) use ($notification, &$user): INotification {
				$user = $uid;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			static function (string $subject, array $params = []) use ($notification, &$parameters): INotification {
				$parameters = $params;
				return $notification;
			}
		);
		$notification->method('getUser')->willReturnCallback(static function () use (&$user): string {
			return $user;
		});
		$notification->method('getSubjectParameters')->willReturnCallback(static function () use (&$parameters): array {
			return $parameters;
		});

		return $notification;
	}

	/**
	 * Two lists: els waits on two entries, joris on one, all assigned at $assignedAt.
	 *
	 * @param string $assignedAt The relative assignment moment.
	 *
	 * @return void
	 */
	private function givenWaitingEntries(string $assignedAt): void {
		$at = (new DateTimeImmutable($assignedAt))->format('c');
		$first = new ObjectEntity();
		$first->setUuid('dl-1');
		$first->setObject(
			[
				'status' => 'in_review',
				'objects' => [
					['uuid' => 'obj-1', 'title' => 'A', 'reviewer' => 'els', 'assignedAt' => $at],
					['uuid' => 'obj-2', 'title' => 'B', 'reviewer' => 'joris', 'assignedAt' => $at],
				],
			]
		);
		$second = new ObjectEntity();
		$second->setUuid('dl-2');
		$second->setObject(['status' => 'in_review', 'objects' => [['uuid' => 'obj-3', 'title' => 'C', 'reviewer' => 'els', 'assignedAt' => $at]]]);

		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->method('findLists')->willReturn([$first, $second]);
	}

	public function testEachReviewerIsNotifiedAndTheCountIsLogged(): void {
		$this->givenWaitingEntries('-30 days');

		$this->runPass();

		$this->assertSame(['els:2', 'joris:1'], $this->notified);
		$this->assertSame(['info [ReviewReminderJob] Reminded 2 reviewers'], $this->logged);
	}

	public function testOneFailingReminderDoesNotStopTheOthers(): void {
		$this->givenWaitingEntries('-30 days');
		$this->failingReviewers = ['els'];

		$this->runPass();

		$this->assertSame(['joris:1'], $this->notified);
		$this->assertSame(
			[
				'warning [ReviewReminderJob] Could not remind els: push down',
				'info [ReviewReminderJob] Reminded 2 reviewers',
			],
			$this->logged
		);
	}

	public function testNothingWaitingLongEnoughIsLoggedAndNobodyNotified(): void {
		$this->givenWaitingEntries('-1 hour');

		$this->runPass();

		$this->assertSame([], $this->notified);
		$this->assertSame(['debug [ReviewReminderJob] No review entries are waiting'], $this->logged);
	}

	public function testAnUnconfiguredRegisterIsLogged(): void {
		$this->lists->method('isConfigured')->willReturn(false);

		$this->runPass();

		$this->assertSame([], $this->notified);
		$this->assertSame(['debug [ReviewReminderJob] No destruction list register configured, skipping'], $this->logged);
	}

	public function testAFailingListReadIsLoggedAsAnError(): void {
		$this->lists->method('isConfigured')->willReturn(true);
		$this->lists->method('findLists')->willThrowException(new Exception('register offline'));

		$this->runPass();

		$this->assertSame([], $this->notified);
		$this->assertSame(['error [ReviewReminderJob] Error: register offline'], $this->logged);
	}

	public function testAnUnreadableFrequencyFallsBackToOneWeek(): void {
		$this->frequency = 'every week';
		$this->givenWaitingEntries('-8 days');

		$this->runPass();

		$this->assertSame(['els:2', 'joris:1'], $this->notified);
	}

	public function testAnUnreadableFrequencyDoesNotChaseAThreeDayOldEntry(): void {
		$this->frequency = 'every week';
		$this->givenWaitingEntries('-3 days');

		$this->runPass();

		$this->assertSame([], $this->notified);
	}

	public function testADeclaredFrequencyIsTheAgeAnEntryMustReach(): void {
		$this->frequency = 'P1D';
		$this->givenWaitingEntries('-3 days');

		$this->runPass();

		$this->assertSame(['els:2', 'joris:1'], $this->notified);
	}
}
