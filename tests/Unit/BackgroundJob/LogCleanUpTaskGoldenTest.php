<?php

/**
 * Pins what the hourly log cleanup sweeps, in which order, and every line it logs.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\LogCleanUpTask;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SearchTrailMapper;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCA\OpenRegister\Kernel\Contracts\MetricsRetentionInterface;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\LogCleanUpTask
 */
class LogCleanUpTaskGoldenTest extends TestCase {
	private AuditTrailMapperInterface&MockObject $audit;
	private SearchTrailMapper&MockObject $search;
	private ObjectRetentionHandler&MockObject $retention;
	private LoggerInterface&MockObject $logger;
	private StateHistoryMapper&MockObject $history;
	private MetricsRetentionInterface&MockObject $metrics;

	/** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
	private array $logged = [];

	/** @var list<string> */
	private array $order = [];

	protected function setUp(): void {
		$this->audit     = $this->createMock(AuditTrailMapperInterface::class);
		$this->search    = $this->createMock(SearchTrailMapper::class);
		$this->retention = $this->createMock(ObjectRetentionHandler::class);
		$this->logger    = $this->createMock(LoggerInterface::class);
		$this->history   = $this->createMock(StateHistoryMapper::class);
		$this->metrics   = $this->createMock(MetricsRetentionInterface::class);

		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->assertArrayHasKey('file', $context);
					$this->assertArrayHasKey('line', $context);
					unset($context['file'], $context['line']);
					$this->logged[] = [$level, $message, $context];
				}
			);
		}
	}//end setUp()

	private function runTask(bool $optional = true): void {
		$task = new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			$this->audit,
			$this->search,
			$this->retention,
			$this->logger,
			$optional === true ? $this->history : null,
			$optional === true ? $this->metrics : null
		);
		(new ReflectionMethod($task, 'run'))->invoke($task, null);
	}//end runTask()

	/**
	 * With nothing to do every sweep says so at debug level.
	 */
	public function testNothingToDo(): void {
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 1000]);
		$this->search->method('setExpiryDate')->willReturn(0);
		$this->audit->method('findPurgedHorizons')->willReturn([]);

		$this->runTask();

		$this->assertSame(
			[
				['debug', '[LogCleanUpTask] No expired audit trail logs found to clear', ['app' => 'openregister']],
				[
					'debug',
					'[LogCleanUpTask] No expired search trail logs found to clear',
					['app' => 'openregister', 'searchTrailRetention' => 1000, 'newlyStamped' => 0],
				],
				['info', '[LogCleanUpTask] Deleted 0 metric rows past their retention', []],
			],
			$this->logged
		);
	}//end testNothingToDo()

	/**
	 * Cleared trails are reported at info level, with the search trail's retention and stamp count.
	 */
	public function testClearedTrails(): void {
		$this->audit->method('clearLogs')->willReturn(true);
		$this->audit->method('findPurgedHorizons')->willReturn([]);
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 1000]);
		$this->search->expects($this->once())->method('setExpiryDate')->with(1000)->willReturn(4);
		$this->search->method('clearLogs')->willReturn(true);
		$this->metrics->method('cleanOldMetrics')->willReturn(9);

		$this->runTask();

		$this->assertSame(
			[
				['info', '[LogCleanUpTask] Tombstoned expired audit trail rows (payload purged, chain preserved)', ['app' => 'openregister']],
				[
					'info',
					'[LogCleanUpTask] Deleted search trail rows past the configured retention',
					['app' => 'openregister', 'searchTrailRetention' => 1000, 'newlyStamped' => 4],
				],
				['info', '[LogCleanUpTask] Deleted 9 metric rows past their retention', []],
			],
			$this->logged
		);
	}//end testClearedTrails()

	/**
	 * An absent setting falls back to 30 days.
	 */
	public function testTheDefaultRetentionIsThirtyDays(): void {
		$this->retention->method('getRetentionSettingsOnly')->willReturn([]);
		$this->search->expects($this->once())->method('setExpiryDate')->with(2592000000)->willReturn(0);

		$this->runTask(optional: false);
	}//end testTheDefaultRetentionIsThirtyDays()

	/**
	 * A retention of zero or below keeps everything: nothing is stamped or deleted.
	 *
	 * @dataProvider keptRetentions
	 */
	public function testANonPositiveRetentionKeeps(int $retention): void {
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => $retention]);
		$this->search->expects($this->never())->method('setExpiryDate');
		$this->search->expects($this->never())->method('clearLogs');

		$this->runTask(optional: false);

		$this->assertSame(
			[
				'debug',
				'[LogCleanUpTask] Search trail retention is not positive, search trails are kept',
				['app' => 'openregister', 'searchTrailRetention' => $retention],
			],
			$this->logged[1]
		);
	}//end testANonPositiveRetentionKeeps()

	/**
	 * @return array<string, array{0: int}>
	 */
	public static function keptRetentions(): array {
		return ['zero' => [0], 'negative' => [-1]];
	}//end keptRetentions()

	/**
	 * A failing sweep logs its own error with the exception, and the next sweep still runs.
	 */
	public function testEachFailureIsContained(): void {
		$audit  = new RuntimeException('audit down');
		$search = new RuntimeException('search down');
		$this->audit->method('clearLogs')->willThrowException($audit);
		$this->audit->method('findPurgedHorizons')->willThrowException(new RuntimeException('history down'));
		$this->retention->method('getRetentionSettingsOnly')->willThrowException($search);
		$this->metrics->method('cleanOldMetrics')->willThrowException(new RuntimeException('metrics down'));

		$this->runTask();

		$this->assertSame(
			[
				['error', '[LogCleanUpTask] Failed to clear expired audit trail logs: audit down', ['app' => 'openregister', 'exception' => $audit]],
				['error', '[LogCleanUpTask] Failed to clear expired search trail logs: search down', ['app' => 'openregister', 'exception' => $search]],
				['warning', '[LogCleanUpTask] State-history prune failed: history down', []],
				['warning', '[LogCleanUpTask] Metrics cleanup failed: metrics down', []],
			],
			$this->logged
		);
	}//end testEachFailureIsContained()

	/**
	 * A failing stamp or delete of the search trail is that sweep's own error.
	 */
	public function testAFailingSearchTrailWriteIsContained(): void {
		$failure = new RuntimeException('no write');
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 1000]);
		$this->search->method('setExpiryDate')->willReturn(1);
		$this->search->method('clearLogs')->willThrowException($failure);

		$this->runTask(optional: false);

		$this->assertSame(
			['error', '[LogCleanUpTask] Failed to clear expired search trail logs: no write', ['app' => 'openregister', 'exception' => $failure]],
			$this->logged[1]
		);
	}//end testAFailingSearchTrailWriteIsContained()

	/**
	 * The sweeps run in order, the prune after the purge whose rows it follows.
	 */
	public function testThePruneFollowsThePurge(): void {
		$note = function (string $step, mixed $result): callable {
			return function () use ($step, $result): mixed {
				$this->order[] = $step;
				return $result;
			};
		};
		$this->audit->method('clearLogs')->willReturnCallback($note('audit', false));
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 1000]);
		$this->search->method('clearLogs')->willReturnCallback($note('search', false));
		$this->audit->method('findPurgedHorizons')->willReturnCallback($note('prune', []));
		$this->metrics->method('cleanOldMetrics')->willReturnCallback($note('metrics', 0));

		$this->runTask();

		$this->assertSame(['audit', 'search', 'prune', 'metrics'], $this->order);
	}//end testThePruneFollowsThePurge()

	/**
	 * Every purged object's closed intervals go, in one bounded batch; an empty horizon is passed over.
	 */
	public function testPurgedHorizonsPruneTheirIntervals(): void {
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 0]);
		$this->audit->expects($this->once())->method('findPurgedHorizons')->with(500)
			->willReturn(['one' => '2026-01-02 03:04:05', 'skip' => '', 'two' => '2026-02-03 04:05:06']);
		$seen = [];
		$this->history->method('pruneClosedIntervalsBefore')->willReturnCallback(
			static function (string $objectUuid, DateTime $horizon) use (&$seen): int {
				$seen[] = [$objectUuid, $horizon->format('Y-m-d H:i:s')];
				return 2;
			}
		);

		$this->runTask();

		$this->assertSame([['one', '2026-01-02 03:04:05'], ['two', '2026-02-03 04:05:06']], $seen);
		$this->assertContains(['info', '[LogCleanUpTask] Pruned 4 state-history intervals whose trail was purged', []], $this->logged);
	}//end testPurgedHorizonsPruneTheirIntervals()

	/**
	 * Nothing pruned logs nothing, and without the projection no horizons are read.
	 */
	public function testNoPruneNoLine(): void {
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 0]);
		$this->audit->method('findPurgedHorizons')->willReturn(['one' => '2026-01-02 03:04:05']);
		$this->history->method('pruneClosedIntervalsBefore')->willReturn(0);

		$this->runTask();

		$this->assertCount(3, $this->logged);
	}//end testNoPruneNoLine()

	/**
	 * Without the optional collaborators the purges run alone.
	 */
	public function testWithoutTheProjectionAndMetrics(): void {
		$this->retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 0]);
		$this->audit->expects($this->never())->method('findPurgedHorizons');

		$this->runTask(optional: false);

		$this->assertCount(2, $this->logged);
	}//end testWithoutTheProjectionAndMetrics()
}//end class
