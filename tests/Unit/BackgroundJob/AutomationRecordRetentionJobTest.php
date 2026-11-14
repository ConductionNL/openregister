<?php

/**
 * Unit tests for AutomationRecordRetentionJob: the period, the cutoff and a failing sweep.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/automation-run-records/design.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

use DateTime;
use OCA\OpenRegister\BackgroundJob\AutomationRecordRetentionJob;
use OCA\OpenRegister\Db\AutomationRecordMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Records older than the configured days (default 90) go; a failing sweep is logged.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\AutomationRecordRetentionJob
 */
final class AutomationRecordRetentionJobTest extends TestCase {

	private const NOW = 1_800_000_000;

	private AutomationRecordMapper&MockObject $records;

	private LoggerInterface&MockObject $logger;

	private function job(string $days): AutomationRecordRetentionJob {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('openregister', 'automation_record_retention_days', '90')->willReturn($days);
		$this->records = $this->createMock(AutomationRecordMapper::class);
		$this->logger  = $this->createMock(LoggerInterface::class);

		return new AutomationRecordRetentionJob($time, $this->records, $config, $this->logger);
	}

	private function runJob(AutomationRecordRetentionJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testTheConfiguredPeriodSetsTheCutoff(): void {
		$job = $this->job('30');
		$this->records->expects($this->once())->method('deleteOlderThan')->with(
			$this->callback(static fn (DateTime $cutoff): bool => $cutoff->getTimestamp() === self::NOW - (30 * 86400) && $cutoff->getTimezone()->getName() === 'UTC')
		)->willReturn(4);
		$this->logger->expects($this->once())->method('info')->with('[AutomationRecordRetention] Deleted 4 expired automation record(s).', ['retentionDays' => 30]);

		$this->runJob($job);
	}

	public function testAPeriodThatIsNotPositiveFallsBackToNinetyDays(): void {
		$this->assertSame(90, $this->job('0')->retentionDays());
		$this->assertSame(90, $this->job('nonsense')->retentionDays());
		$this->assertSame(7, $this->job('7')->retentionDays());
	}

	public function testNothingDeletedLogsNothing(): void {
		$job = $this->job('90');
		$this->records->method('deleteOlderThan')->willReturn(0);
		$this->logger->expects($this->never())->method('info');

		$this->runJob($job);
	}

	public function testAFailingSweepIsLoggedNotThrown(): void {
		$job = $this->job('90');
		$this->records->method('deleteOlderThan')->willThrowException(new RuntimeException('locked'));
		$this->logger->expects($this->once())->method('warning')->with(
			'[AutomationRecordRetention] Sweep failed: locked',
			$this->arrayHasKey('file')
		);

		$this->runJob($job);
	}
}
