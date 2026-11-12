<?php

/**
 * Pins the daily retention jobs: their flags, what they run and what they log.
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

use OCA\OpenRegister\BackgroundJob\ConfiguredJobRuntime;
use OCA\OpenRegister\BackgroundJob\AvgRetentionJob;
use OCA\OpenRegister\BackgroundJob\DsarRetentionSweepJob;
use OCA\OpenRegister\Service\AvgRetentionService;
use OCA\OpenRegister\Service\Gdpr\Retention\RetentionSweepService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\BackgroundJob\AbstractRetentionJob
 * @covers \OCA\OpenRegister\BackgroundJob\AvgRetentionJob
 * @covers \OCA\OpenRegister\BackgroundJob\DsarRetentionSweepJob
 */
class RetentionJobsGoldenTest extends TestCase {
	private const AVG_SUMMARY = ['evaluatedActivities' => 5, 'skippedActivities' => 1, 'objectsErased' => 3, 'objectsWithheld' => 2];
	private const DSAR_SUMMARY = ['evaluated' => 4, 'purged' => ['a', 'b'], 'skippedHeld' => ['c'], 'withinWindow' => 6, 'withheldCount' => 7];

	private IAppConfig&MockObject $appConfig;
	private AvgRetentionService&MockObject $retention;
	private RetentionSweepService&MockObject $sweep;
	private LoggerInterface&MockObject $logger;

	/** @var array<string, string> */
	private array $config = [];

	/** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
	private array $logged = [];

	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->retention = $this->createMock(AvgRetentionService::class);
		$this->sweep     = $this->createMock(RetentionSweepService::class);
		$this->logger    = $this->createMock(LoggerInterface::class);

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame('openregister', $app);
				return ($this->config[$key] ?? $default);
			}
		);
		foreach (['info', 'error'] as $level) {
			$this->logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					$this->logged[] = [$level, $message, $context];
				}
			);
		}
	}//end setUp()

	private function avg(): AvgRetentionJob {
		return new AvgRetentionJob(new ConfiguredJobRuntime($this->createMock(ITimeFactory::class), $this->appConfig, $this->logger), $this->retention);
	}//end avg()

	private function dsar(): DsarRetentionSweepJob {
		return new DsarRetentionSweepJob(new ConfiguredJobRuntime($this->createMock(ITimeFactory::class), $this->appConfig, $this->logger), $this->sweep);
	}//end dsar()

	private function runJob(TimedJob $job): void {
		(new ReflectionMethod($job, 'run'))->invoke($job, null);
	}//end runJob()

	/**
	 * What was logged, without the file and line every line carries.
	 *
	 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private function logged(): array {
		return array_map(
			function (array $line): array {
				$this->assertArrayHasKey('file', $line[2]);
				$this->assertArrayHasKey('line', $line[2]);
				unset($line[2]['file'], $line[2]['line']);
				return $line;
			},
			$this->logged
		);
	}//end logged()

	/**
	 * Both jobs run once a day.
	 */
	public function testBothRunDaily(): void {
		$this->assertSame(86400, $this->avg()->getInterval());
		$this->assertSame(86400, $this->dsar()->getInterval());
	}//end testBothRunDaily()

	/**
	 * Without configuration the retention pass runs for real and logs its counts.
	 */
	public function testTheRetentionPassRunsByDefault(): void {
		$this->retention->expects($this->once())->method('runRetentionPass')->with(false)->willReturn(self::AVG_SUMMARY);

		$this->runJob($this->avg());

		$this->assertSame(
			[
				[
					'info',
					'[AvgRetentionJob] Retention pass complete',
					['dryRun' => false, 'evaluatedActivities' => 5, 'skippedActivities' => 1, 'objectsErased' => 3, 'objectsWithheld' => 2],
				],
			],
			$this->logged()
		);
	}//end testTheRetentionPassRunsByDefault()

	/**
	 * Without configuration the sweep runs for real and logs its counts.
	 */
	public function testTheSweepRunsByDefault(): void {
		$this->sweep->expects($this->once())->method('runSweep')->with(false)->willReturn(self::DSAR_SUMMARY);

		$this->runJob($this->dsar());

		$this->assertSame(
			[
				[
					'info',
					'[DsarRetentionSweepJob] sweep complete',
					['dryRun' => false, 'evaluated' => 4, 'purgedCount' => 2, 'skippedHeld' => 1, 'withinWindow' => 6, 'withheldCount' => 7],
				],
			],
			$this->logged()
		);
	}//end testTheSweepRunsByDefault()

	/**
	 * The enable switch reads as a boolean: anything that is not true stops the retention pass.
	 *
	 * @dataProvider offValues
	 */
	public function testTheRetentionPassCanBeSwitchedOff(string $value): void {
		$this->config['avg_retention_enabled'] = $value;
		$this->retention->expects($this->never())->method('runRetentionPass');

		$this->runJob($this->avg());

		$this->assertSame(
			[['info', '[AvgRetentionJob] Retention enforcement disabled (avg_retention_enabled=false), skipping', []]],
			$this->logged()
		);
	}//end testTheRetentionPassCanBeSwitchedOff()

	/**
	 * The same switch holds for the sweep, under its own key.
	 *
	 * @dataProvider offValues
	 */
	public function testTheSweepCanBeSwitchedOff(string $value): void {
		$this->config['dsar_retention_sweep_enabled'] = $value;
		$this->sweep->expects($this->never())->method('runSweep');

		$this->runJob($this->dsar());

		$this->assertSame(
			[['info', '[DsarRetentionSweepJob] sweep disabled (dsar_retention_sweep_enabled=false), skipping', []]],
			$this->logged()
		);
	}//end testTheSweepCanBeSwitchedOff()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function offValues(): array {
		return ['false' => ['false'], 'zero' => ['0'], 'no' => ['no'], 'off' => ['off'], 'empty' => [''], 'word' => ['maybe']];
	}//end offValues()

	/**
	 * Truthy spellings keep the job on, and the dry-run flag reaches the service and the log.
	 *
	 * @dataProvider onValues
	 */
	public function testDryRunReachesTheService(string $value): void {
		$this->config = [
			'avg_retention_enabled' => $value,
			'avg_retention_dry_run' => $value,
			'dsar_retention_sweep_enabled' => $value,
			'dsar_retention_sweep_dry_run' => $value,
		];
		$this->retention->expects($this->once())->method('runRetentionPass')->with(true)->willReturn(self::AVG_SUMMARY);
		$this->sweep->expects($this->once())->method('runSweep')->with(true)->willReturn(self::DSAR_SUMMARY);

		$this->runJob($this->avg());
		$this->runJob($this->dsar());

		$this->assertTrue($this->logged()[0][2]['dryRun']);
		$this->assertTrue($this->logged()[1][2]['dryRun']);
	}//end testDryRunReachesTheService()

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function onValues(): array {
		return ['true' => ['true'], 'one' => ['1'], 'yes' => ['yes'], 'on' => ['on']];
	}//end onValues()

	/**
	 * One job's keys do not switch the other.
	 */
	public function testTheKeysAreEachJobsOwn(): void {
		$this->config = ['dsar_retention_sweep_enabled' => 'false', 'dsar_retention_sweep_dry_run' => 'true'];
		$this->retention->expects($this->once())->method('runRetentionPass')->with(false)->willReturn(self::AVG_SUMMARY);

		$this->runJob($this->avg());
	}//end testTheKeysAreEachJobsOwn()

	/**
	 * A failed retention pass is logged, not thrown.
	 */
	public function testAFailedRetentionPassIsLogged(): void {
		$this->retention->method('runRetentionPass')->willThrowException(new RuntimeException('boom'));

		$this->runJob($this->avg());

		$this->assertSame([['error', '[AvgRetentionJob] Retention pass failed: boom', ['error' => 'boom']]], $this->logged());
	}//end testAFailedRetentionPassIsLogged()

	/**
	 * A failed sweep is logged, not thrown.
	 */
	public function testAFailedSweepIsLogged(): void {
		$this->sweep->method('runSweep')->willThrowException(new RuntimeException('boom'));

		$this->runJob($this->dsar());

		$this->assertSame([['error', '[DsarRetentionSweepJob] sweep failed: boom', ['error' => 'boom']]], $this->logged());
	}//end testAFailedSweepIsLogged()
}//end class
