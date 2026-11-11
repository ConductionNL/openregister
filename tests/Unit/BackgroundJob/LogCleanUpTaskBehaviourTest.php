<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTimeInterface;
use OCA\OpenRegister\BackgroundJob\LogCleanUpTask;
use OCA\OpenRegister\BackgroundJob\Support\LogCleanUpTrails;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\SearchTrailCleanupInterface;
use OCA\OpenRegister\Db\StateHistoryMapperInterface;
use OCA\OpenRegister\Service\Settings\RetentionSettingsReaderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The state-history prune step of LogCleanUpTask.
 */
class LogCleanUpTaskBehaviourTest extends TestCase {

	private AuditTrailMapperInterface&MockObject $audit;

	private StateHistoryMapperInterface&MockObject $history;

	/** @var array<int, array{0: string, 1: string}> */
	private array $logged = [];

	protected function setUp(): void {
		parent::setUp();
		$this->audit = $this->createMock(AuditTrailMapperInterface::class);
		$this->history = $this->createMock(StateHistoryMapperInterface::class);
		$this->logged = [];
	}

	private function runTask(): void {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'debug', 'error', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message];
				}
			);
		}
		$retention = $this->createMock(RetentionSettingsReaderInterface::class);
		$retention->method('getRetentionSettingsOnly')->willReturn(['searchTrailRetention' => 0]);

		$task = new LogCleanUpTask(
			$this->createMock(ITimeFactory::class),
			new LogCleanUpTrails($this->audit, $this->createMock(SearchTrailCleanupInterface::class), $this->history),
			$retention,
			$logger
		);
		(new \ReflectionMethod($task, 'run'))->invoke($task, null);
	}

	/** @return string[] */
	private function messages(string $level): array {
		$out = [];
		foreach ($this->logged as [$lvl, $msg]) {
			if ($lvl === $level && str_contains($msg, 'tate-history') === true) {
				$out[] = $msg;
			}
		}
		return $out;
	}

	public function testPurgedHorizonsPruneClosedIntervalsAndSkipEmptyHorizons(): void {
		$this->audit->method('findPurgedHorizons')->willReturn([
			'obj-1' => '2026-01-01T00:00:00+00:00',
			'obj-2' => '',
			'obj-3' => '2025-06-01T00:00:00+00:00',
		]);
		$calls = [];
		$this->history->expects($this->exactly(2))->method('pruneClosedIntervalsBefore')->willReturnCallback(
			function (string $objectUuid, DateTimeInterface $horizon) use (&$calls): int {
				$calls[] = [$objectUuid, $horizon->format('Y-m-d')];
				return 2;
			}
		);

		$this->runTask();

		$this->assertSame([['obj-1', '2026-01-01'], ['obj-3', '2025-06-01']], $calls);
		$this->assertSame(['[LogCleanUpTask] Pruned 4 state-history intervals whose trail was purged'], $this->messages('info'));
	}

	public function testNothingPrunedLogsNothing(): void {
		$this->audit->method('findPurgedHorizons')->willReturn(['obj-1' => '2026-01-01']);
		$this->history->method('pruneClosedIntervalsBefore')->willReturn(0);

		$this->runTask();

		$this->assertSame([], $this->messages('info'));
		$this->assertSame([], $this->messages('warning'));
	}

	public function testNoPurgedObjectsPrunesNothing(): void {
		$this->audit->method('findPurgedHorizons')->willReturn([]);
		$this->history->expects($this->never())->method('pruneClosedIntervalsBefore');

		$this->runTask();

		$this->assertSame([], $this->messages('info'));
	}

	public function testPruneFailureIsOnlyLoggedAsWarning(): void {
		$this->audit->method('findPurgedHorizons')->willThrowException(new \RuntimeException('db gone'));

		$this->runTask();

		$this->assertSame(['[LogCleanUpTask] State-history prune failed: db gone'], $this->messages('warning'));
	}
}
