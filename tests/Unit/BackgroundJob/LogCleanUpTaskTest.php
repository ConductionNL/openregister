<?php

/**
 * LogCleanUpTask tests: the hourly audit trail and search trail retention sweeps.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\LogCleanUpTask;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LogCleanUpTaskTest extends TestCase {
	private const THIRTY_DAYS_MS = 2592000000;

	private LogCleanUpTask $task;
	private AuditTrailMapper&MockObject $auditTrailMapper;
	private SearchTrailMapper&MockObject $searchTrailMapper;
	private ObjectRetentionHandler&MockObject $retentionHandler;
	private LoggerInterface&MockObject $logger;

	/** @var array<int, array{level: string, message: string, context: array}> */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();

		$timeFactory = $this->createMock(ITimeFactory::class);
		$this->auditTrailMapper = $this->createMock(AuditTrailMapper::class);
		$this->searchTrailMapper = $this->createMock(SearchTrailMapper::class);
		$this->retentionHandler = $this->createMock(ObjectRetentionHandler::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default: the shipped retention, read the way the settings page reads it.
		$this->retentionHandler->method('getRetentionSettingsOnly')
			->willReturn(['searchTrailRetention' => self::THIRTY_DAYS_MS]);

		// Record every log line so a test can assert on the SET of outcomes.
		$this->logged = [];
		foreach (['info', 'debug', 'error', 'warning'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = ['level' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$this->task = new LogCleanUpTask(
			$timeFactory,
			$this->auditTrailMapper,
			$this->searchTrailMapper,
			$this->retentionHandler,
			$this->logger,
		);
	}

	private function runTask($argument = null): void {
		$reflection = new \ReflectionClass($this->task);
		$method = $reflection->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($this->task, $argument);
	}

	/**
	 * Messages logged at a level, in order.
	 *
	 * @return string[]
	 */
	private function messagesAt(string $level): array {
		return array_values(array_map(
			fn (array $entry): string => $entry['message'],
			array_filter($this->logged, fn (array $entry): bool => $entry['level'] === $level)
		));
	}

	public function testConstructorSetsInterval(): void {
		$reflection = new \ReflectionClass($this->task);
		$property = $reflection->getProperty('interval');
		$property->setAccessible(true);

		$this->assertEquals(3600, $property->getValue($this->task));
	}

	public function testConstructorSetsTimeSensitivity(): void {
		$reflection = new \ReflectionClass($this->task);
		$property = $reflection->getProperty('timeSensitivity');
		$property->setAccessible(true);

		$this->assertEquals(IJob::TIME_INSENSITIVE, $property->getValue($this->task));
	}

	public function testConstructorDisablesParallelRuns(): void {
		$reflection = new \ReflectionClass($this->task);
		$property = $reflection->getProperty('allowParallelRuns');
		$property->setAccessible(true);

		$this->assertFalse($property->getValue($this->task));
	}

	public function testRunSweepsBothTrailsAndLogsEachOutcome(): void {
		$this->auditTrailMapper->expects($this->once())->method('clearLogs')->willReturn(true);

		// Search trail rows are inserted without an expiry: stamp first, sweep second.
		$this->searchTrailMapper->expects($this->once())
			->method('setExpiryDate')
			->with(self::THIRTY_DAYS_MS)
			->willReturn(5);
		$this->searchTrailMapper->expects($this->once())->method('clearLogs')->willReturn(true);

		$this->runTask(null);

		$info = $this->messagesAt('info');
		$this->assertCount(2, $info, 'Each sweep logs its own outcome.');
		// Since or#2265 the audit sweep TOMBSTONES rather than deletes: the
		// payload is destroyed but the row and its hash links survive.
		$this->assertStringContainsString('Tombstoned expired audit trail rows', $info[0]);
		$this->assertStringContainsString('Deleted search trail rows past the configured retention', $info[1]);
		$this->assertSame([], $this->messagesAt('error'));
		$this->assertSame([], $this->messagesAt('debug'));

		$searchContext = $this->logged[1]['context'];
		$this->assertSame(self::THIRTY_DAYS_MS, $searchContext['searchTrailRetention']);
		$this->assertSame(5, $searchContext['newlyStamped']);
	}

	public function testRunNoExpiredLogs(): void {
		$this->auditTrailMapper->expects($this->once())->method('clearLogs')->willReturn(false);
		$this->searchTrailMapper->method('setExpiryDate')->willReturn(0);
		$this->searchTrailMapper->expects($this->once())->method('clearLogs')->willReturn(false);

		$this->runTask(null);

		$this->assertSame([], $this->messagesAt('info'));
		$debug = $this->messagesAt('debug');
		$this->assertCount(2, $debug);
		$this->assertStringContainsString('No expired audit trail logs found', $debug[0]);
		$this->assertStringContainsString('No expired search trail logs found', $debug[1]);
	}

	public function testAFailingAuditSweepDoesNotSkipTheSearchTrailSweep(): void {
		$exception = new \Exception('Database connection failed');
		$this->auditTrailMapper->expects($this->once())
			->method('clearLogs')
			->willThrowException($exception);

		$this->searchTrailMapper->expects($this->once())->method('setExpiryDate')->willReturn(0);
		$this->searchTrailMapper->expects($this->once())->method('clearLogs')->willReturn(true);

		$this->runTask(null);

		$errors = $this->messagesAt('error');
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('Failed to clear expired audit trail logs: Database connection failed', $errors[0]);

		$errorContext = array_values(array_filter($this->logged, fn (array $e): bool => $e['level'] === 'error'))[0]['context'];
		$this->assertSame($exception, $errorContext['exception']);
		$this->assertSame('openregister', $errorContext['app']);

		$this->assertStringContainsString('Deleted search trail rows', $this->messagesAt('info')[0]);
	}

	public function testAFailingSearchTrailSweepLeavesTheAuditOutcomeIntact(): void {
		$this->auditTrailMapper->expects($this->once())->method('clearLogs')->willReturn(true);
		$this->searchTrailMapper->expects($this->once())
			->method('setExpiryDate')
			->willThrowException(new \RuntimeException('Timeout'));
		$this->searchTrailMapper->expects($this->never())->method('clearLogs');

		$this->runTask(null);

		$this->assertStringContainsString('Tombstoned expired audit trail rows', $this->messagesAt('info')[0]);
		$errors = $this->messagesAt('error');
		$this->assertCount(1, $errors);
		$this->assertStringContainsString('Failed to clear expired search trail logs: Timeout', $errors[0]);
	}

	public function testTheConfiguredRetentionIsWhatTheMapperIsToldToStamp(): void {
		// Stored JSON may hand the value back as a string; the job normalises it.
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => '86400000']);
		$task = new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			$this->auditTrailMapper,
			$this->searchTrailMapper,
			$handler,
			$this->logger,
		);

		$this->searchTrailMapper->expects($this->once())->method('setExpiryDate')->with(86400000)->willReturn(1);
		$this->searchTrailMapper->method('clearLogs')->willReturn(false);

		$method = (new \ReflectionClass($task))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($task, null);
	}

	public function testANonPositiveRetentionKeepsSearchTrails(): void {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 0]);
		$task = new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			$this->auditTrailMapper,
			$this->searchTrailMapper,
			$handler,
			$this->logger,
		);

		$this->searchTrailMapper->expects($this->never())->method('setExpiryDate');
		$this->searchTrailMapper->expects($this->never())->method('clearLogs');
		$this->auditTrailMapper->expects($this->once())->method('clearLogs')->willReturn(false);

		$method = (new \ReflectionClass($task))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($task, null);

		$this->assertStringContainsString('search trails are kept', $this->messagesAt('debug')[1]);
	}

	public function testAnUnreadableSettingIsLoggedAndTheAuditSweepStillRuns(): void {
		$handler = $this->createMock(ObjectRetentionHandler::class);
		$handler->method('getRetentionSettingsOnly')->willThrowException(new \RuntimeException('Failed to retrieve Retention settings'));
		$task = new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			$this->auditTrailMapper,
			$this->searchTrailMapper,
			$handler,
			$this->logger,
		);

		$this->auditTrailMapper->expects($this->once())->method('clearLogs')->willReturn(true);
		$this->searchTrailMapper->expects($this->never())->method('setExpiryDate');

		$method = (new \ReflectionClass($task))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($task, null);

		$this->assertCount(1, $this->messagesAt('error'));
		$this->assertStringContainsString('Failed to retrieve Retention settings', $this->messagesAt('error')[0]);
	}

	public function testRunWithArrayArgument(): void {
		$this->auditTrailMapper->expects($this->once())->method('clearLogs')->willReturn(false);
		$this->searchTrailMapper->method('clearLogs')->willReturn(false);

		// Should not throw regardless of argument value
		$this->runTask(['some' => 'data']);
	}
}
