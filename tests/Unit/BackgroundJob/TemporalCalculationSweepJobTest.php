<?php

/**
 * TemporalCalculationSweepJob unit tests.
 *
 * Covers the interval override with its floor, the enable toggle, the
 * quiet-unless-something-happened logging and the failure path.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-deadline-escalation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\BackgroundJob\TemporalCalculationSweepJob;
use OCA\OpenRegister\Service\Calculation\TemporalCalculationSweepServiceInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for the temporal calculation sweep job.
 */
class TemporalCalculationSweepJobTest extends TestCase {

	/**
	 * The mocked sweep service.
	 *
	 * @var TemporalCalculationSweepServiceInterface&MockObject
	 */
	private TemporalCalculationSweepServiceInterface&MockObject $sweep;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->sweep  = $this->createMock(TemporalCalculationSweepServiceInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * Build the job with the given app-config values.
	 *
	 * @param array<string, string> $config Config values by key; absent keys return the default.
	 *
	 * @return TemporalCalculationSweepJob The job.
	 */
	private function job(array $config): TemporalCalculationSweepJob {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($config): string {
				if ($app !== 'openregister') {
					return 'wrong-app';
				}

				return ($config[$key] ?? $default);
			}
		);

		return new TemporalCalculationSweepJob(
			$this->createMock(ITimeFactory::class),
			$appConfig,
			$this->sweep,
			$this->logger,
		);
	}//end job()

	/**
	 * Run the job once.
	 *
	 * @param TemporalCalculationSweepJob $job The job.
	 *
	 * @return void
	 */
	private function tick(TemporalCalculationSweepJob $job): void {
		(new ReflectionMethod(TemporalCalculationSweepJob::class, 'run'))->invoke($job, null);
	}//end tick()

	/**
	 * Interval overrides and the floor that sends too-short values back to hourly.
	 *
	 * @return array<string, array{0: array<string, string>, 1: int}>
	 */
	public static function intervalProvider(): array {
		return [
			'unset uses hourly'           => [[], 3600],
			'override honoured'           => [['temporal_calculation_sweep_interval' => '7200'], 7200],
			'exact minimum honoured'      => [['temporal_calculation_sweep_interval' => '300'], 300],
			'below minimum falls back'    => [['temporal_calculation_sweep_interval' => '299'], 3600],
			'non-numeric falls back'      => [['temporal_calculation_sweep_interval' => 'often'], 3600],
		];
	}//end intervalProvider()

	/**
	 * The constructor applies the operator interval, refusing values under the floor.
	 *
	 * @param array<string, string> $config   App config.
	 * @param int                   $expected Expected interval.
	 *
	 * @return void
	 */
	#[DataProvider('intervalProvider')]
	public function testInterval(array $config, int $expected): void {
		$this->assertSame($expected, $this->job($config)->getInterval());
	}//end testInterval()

	/**
	 * Disabled: the sweep is never run and the skip is logged.
	 *
	 * @return void
	 */
	public function testDisabledSkipsTheSweep(): void {
		$this->sweep->expects($this->never())->method('runSweep');
		$this->logger->expects($this->once())->method('info')->with(
			$this->stringContains('sweep disabled'),
			$this->anything()
		);

		$this->tick($this->job(['temporal_calculation_sweep_enabled' => 'false']));
	}//end testDisabledSkipsTheSweep()

	/**
	 * Enabled by default; a sweep that changed nothing logs nothing.
	 *
	 * @return void
	 */
	public function testQuietSweepIsNotLogged(): void {
		$this->sweep->expects($this->once())->method('runSweep')->willReturn(['objectsRewritten' => 0, 'errors' => 0]);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');

		$this->tick($this->job([]));
	}//end testQuietSweepIsNotLogged()

	/**
	 * A sweep that rewrote objects or hit errors logs its summary.
	 *
	 * @return array<string, array{0: array<string, int>}>
	 */
	public static function noteworthyProvider(): array {
		return [
			'rewrites' => [['objectsRewritten' => 4, 'errors' => 0]],
			'errors'   => [['objectsRewritten' => 0, 'errors' => 2]],
		];
	}//end noteworthyProvider()

	/**
	 * A noteworthy summary is logged with the summary merged into the context.
	 *
	 * @param array<string, int> $summary The sweep summary.
	 *
	 * @return void
	 */
	#[DataProvider('noteworthyProvider')]
	public function testNoteworthySweepIsLogged(array $summary): void {
		$this->sweep->expects($this->once())->method('runSweep')->willReturn($summary);
		$this->logger->expects($this->once())->method('info')->with(
			'[TemporalCalculationSweepJob] sweep complete',
			$this->callback(
				static fn (array $ctx): bool => $ctx['objectsRewritten'] === $summary['objectsRewritten']
					&& $ctx['errors'] === $summary['errors']
			)
		);

		$this->tick($this->job(['temporal_calculation_sweep_enabled' => 'yes']));
	}//end testNoteworthySweepIsLogged()

	/**
	 * A sweep that throws is logged as an error and does not escape.
	 *
	 * @return void
	 */
	public function testFailingSweepIsLoggedAsError(): void {
		$this->sweep->expects($this->once())->method('runSweep')->willThrowException(new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('error')->with(
			'[TemporalCalculationSweepJob] sweep failed: db down',
			$this->anything()
		);
		$this->logger->expects($this->never())->method('info');

		$this->tick($this->job([]));
	}//end testFailingSweepIsLoggedAsError()
}//end class
