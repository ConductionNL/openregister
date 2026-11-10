<?php

/**
 * Behaviour test for the purge outcomes of the processing-log and rule-run retention jobs.
 *
 * Asserts that a processing-log prune whose audit record cannot be written is
 * logged rather than failing the job, that a prune of nothing records nothing,
 * and that a rule-run sweep that removed nothing logs nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use DateTime;
use DateTimeZone;
use OCA\OpenRegister\BackgroundJob\ProcessingLogRetentionJob;
use OCA\OpenRegister\BackgroundJob\RuleRunRetentionJob;
use OCA\OpenRegister\BackgroundJob\Support\ProcessingLogRetentionStores;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ProcessingLogMapperInterface;
use OCA\OpenRegister\Db\RuleRunMapperInterface;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

class RetentionJobsPurgeBehaviourTest extends TestCase {

	private static function runJob(object $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}

	private function time(): ITimeFactory {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-06-01 12:00:00', new DateTimeZone('UTC')));
		return $time;
	}

	private function processingJob(int $deleted, AuditTrailMapperInterface $audit, LoggerInterface $logger): ProcessingLogRetentionJob {
		$entries = $this->createMock(ProcessingLogMapperInterface::class);
		$entries->method('deleteCreatedBefore')->willReturn($deleted);
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn('P1Y');
		return new ProcessingLogRetentionJob(new ConfiguredJobRuntime($this->time(), $config, $logger), new ProcessingLogRetentionStores($entries, $audit));
	}

	public function testAPurgeThatCannotBeRecordedIsLoggedWithItsFigures(): void {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->once())->method('insertAuditTrails')->willThrowException(new RuntimeException('chain locked'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'[ProcessingLogRetention] Entries were pruned but the purge was not recorded: chain locked',
			['retention' => 'P1Y', 'before' => '2025-06-01T12:00:00+00:00', 'deleted' => 4]
		);

		self::runJob($this->processingJob(4, $audit, $logger));
	}

	public function testAPruneOfNothingRecordsNothing(): void {
		$audit = $this->createMock(AuditTrailMapperInterface::class);
		$audit->expects($this->never())->method('insertAuditTrails');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$logger->expects($this->once())->method('info')->with(
			'[ProcessingLogRetention] Pruned expired entries',
			['retention' => 'P1Y', 'cutoff' => '2025-06-01T12:00:00+00:00', 'deleted' => 0]
		);

		self::runJob($this->processingJob(0, $audit, $logger));
	}

	private function ruleRunJob(int $deleted, LoggerInterface $logger): RuleRunRetentionJob {
		$runs = $this->createMock(RuleRunMapperInterface::class);
		$runs->expects($this->once())->method('pruneBefore')->willReturn($deleted);
		$recorder = $this->createMock(RuleRunRecorderInterface::class);
		$recorder->method('retentionDays')->willReturn(30);
		return new RuleRunRetentionJob($this->createMock(ITimeFactory::class), $runs, $recorder, $logger);
	}

	public function testARuleRunSweepThatRemovedNothingLogsNothing(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('info');
		self::runJob($this->ruleRunJob(0, $logger));
	}

	public function testARuleRunSweepReportsHowManyRowsWent(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')->with('[RuleRunRetention] Removed 5 rule run rows older than 30 days.');
		self::runJob($this->ruleRunJob(5, $logger));
	}
}
