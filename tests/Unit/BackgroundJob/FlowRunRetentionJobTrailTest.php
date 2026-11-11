<?php

declare(strict_types=1);

/**
 * Characterisation of one FlowRunRetentionJob run: which sweeps run with
 * which retention period, what is purged, and everything it logs, including
 * every contained failure.
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
use OCA\OpenRegister\BackgroundJob\FlowRunRetentionJob;
use OCA\OpenRegister\BackgroundJob\Support\FlowRunRetentionServices;
use OCA\OpenRegister\BackgroundJob\Support\FlowRunRetentionStores;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowRunStepMapperInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;

/**
 * Pins the retention sweeps of FlowRunRetentionJob and their log trail.
 */
class FlowRunRetentionJobTrailTest extends TestCase {

	/**
	 * Everything observable, in order.
	 *
	 * @var array<int, string>
	 */
	private array $trail = [];

	/**
	 * Flow ids whose sweep throws; '*' fails the instance-wide sweep.
	 *
	 * @var array<int, string>
	 */
	private array $failing = [];

	/**
	 * Run uuids whose step purge throws.
	 *
	 * @var array<int, string>
	 */
	private array $failingPurges = [];

	public function testBothPassesSweepAndPurgeAndTheTotalIsLogged(): void {
		$this->runJob(flows: [$this->flow(uuid: 'f-short', days: 7), $this->flow(uuid: 'f-long', days: 400)], listFails: false);

		$this->assertSame(
			[
				'list',
				'instance 31 excluding f-short,f-long',
				'purge r-*-1', 'purge r-*-2',
				'flow f-short 7',
				'purge r-f-short-1', 'purge r-f-short-2',
				'flow f-long 400',
				'purge r-f-long-1', 'purge r-f-long-2',
				'info [FlowRunRetention] Swept 6 expired flow run(s). retentionDays=31 overrides=2',
			],
			$this->trail
		);
	}//end testBothPassesSweepAndPurgeAndTheTotalIsLogged()

	public function testAFailingListingStopsTheRun(): void {
		$this->runJob(flows: [], listFails: true);

		$this->assertSame(
			['list', 'warning [FlowRunRetention] Could not list flows with a retention override: no flows'],
			$this->trail
		);
	}//end testAFailingListingStopsTheRun()

	public function testAFailingInstanceSweepStillSweepsEachOverride(): void {
		$this->failing = ['*'];

		$this->runJob(flows: [$this->flow(uuid: 'f-short', days: 7)], listFails: false);

		$this->assertSame(
			[
				'list',
				'instance 31 excluding f-short',
				'warning [FlowRunRetention] Instance-wide sweep failed: sweep failed',
				'flow f-short 7',
				'purge r-f-short-1', 'purge r-f-short-2',
				'info [FlowRunRetention] Swept 2 expired flow run(s). retentionDays=31 overrides=1',
			],
			$this->trail
		);
	}//end testAFailingInstanceSweepStillSweepsEachOverride()

	public function testAFailingFlowSweepDoesNotStopTheOthersAndAFailedPurgeStillCounts(): void {
		$this->failing       = ['f-short'];
		$this->failingPurges = ['r-f-long-1'];

		$this->runJob(flows: [$this->flow(uuid: 'f-short', days: 7), $this->flow(uuid: 'f-long', days: 400)], listFails: false);

		$this->assertSame(
			[
				'list',
				'instance 31 excluding f-short,f-long',
				'purge r-*-1', 'purge r-*-2',
				'flow f-short 7',
				'warning [FlowRunRetention] Sweep failed for flow f-short: sweep failed',
				'flow f-long 400',
				'purge r-f-long-1',
				'warning [FlowRunRetention] Could not purge steps for run r-f-long-1: purge failed',
				'purge r-f-long-2',
				'info [FlowRunRetention] Swept 4 expired flow run(s). retentionDays=31 overrides=2',
			],
			$this->trail
		);
	}//end testAFailingFlowSweepDoesNotStopTheOthersAndAFailedPurgeStillCounts()

	public function testNothingSweptLogsNothing(): void {
		$this->failing = ['*'];

		$this->runJob(flows: [], listFails: false);

		$this->assertSame(
			['list', 'instance 31 excluding ', 'warning [FlowRunRetention] Instance-wide sweep failed: sweep failed'],
			$this->trail
		);
	}//end testNothingSweptLogsNothing()

	/**
	 * A flow with its own retention period.
	 *
	 * @param string $uuid The flow uuid.
	 * @param int    $days Its retention period.
	 *
	 * @return Flow
	 */
	private function flow(string $uuid, int $days): Flow {
		$flow = new Flow();
		$flow->setUuid($uuid);
		$flow->setRetentionDays($days);
		return $flow;
	}//end flow()

	/**
	 * Run the job over recording stores.
	 *
	 * @param array<int, Flow> $flows     The flows with a retention override.
	 * @param bool             $listFails Whether listing them throws.
	 *
	 * @return void
	 */
	private function runJob(array $flows, bool $listFails): void {
		$this->trail = [];

		$flowMapper = $this->createMock(FlowMapperInterface::class);
		$flowMapper->method('findWithRetentionOverride')->willReturnCallback(
			function () use ($flows, $listFails): array {
				$this->trail[] = 'list';
				if ($listFails === true) {
					throw new RuntimeException('no flows');
				}

				return $flows;
			}
		);

		$runs = $this->createMock(FlowRunMapperInterface::class);
		$runs->method('deleteTerminalOlderThanExcluding')->willReturnCallback(
			function (DateTime $cutoff, array $excludeFlowIds): array {
				$this->trail[] = 'instance ' . self::daysAgo(cutoff: $cutoff) . ' excluding ' . implode(',', $excludeFlowIds);
				return $this->sweepResult(flowId: '*');
			}
		);
		$runs->method('deleteTerminalOlderThan')->willReturnCallback(
			function (DateTime $cutoff, ?string $flowId = null): array {
				$this->trail[] = 'flow ' . $flowId . ' ' . self::daysAgo(cutoff: $cutoff);
				return $this->sweepResult(flowId: (string)$flowId);
			}
		);

		$steps = $this->createMock(FlowRunStepMapperInterface::class);
		$steps->method('deleteByRun')->willReturnCallback(
			function (string $runUuid): int {
				$this->trail[] = 'purge ' . $runUuid;
				if (in_array($runUuid, $this->failingPurges, true) === true) {
					throw new RuntimeException('purge failed');
				}

				return 1;
			}
		);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default): string => $default);

		$job = new FlowRunRetentionJob(
			$this->createMock(ITimeFactory::class),
			new FlowRunRetentionServices(new FlowRunRetentionStores($runs, $steps, $flowMapper), $config, $this->logger())
		);

		$method = new \ReflectionMethod(FlowRunRetentionJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}//end runJob()

	/**
	 * The run uuids a sweep deletes, or its failure.
	 *
	 * @param string $flowId The flow swept; '*' for the instance-wide sweep.
	 *
	 * @return array<int, string>
	 */
	private function sweepResult(string $flowId): array {
		if (in_array($flowId, $this->failing, true) === true) {
			throw new RuntimeException('sweep failed');
		}

		return ['r-' . $flowId . '-1', 'r-' . $flowId . '-2'];
	}//end sweepResult()

	/**
	 * How many whole days before now a cutoff lies.
	 *
	 * @param DateTime $cutoff The cutoff.
	 *
	 * @return int
	 */
	private static function daysAgo(DateTime $cutoff): int {
		return (int)round(((new DateTime())->getTimestamp() - $cutoff->getTimestamp()) / 86400);
	}//end daysAgo()

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
				$extra = '';
				if (isset($context['retentionDays']) === true) {
					$extra = ' retentionDays=' . $context['retentionDays'] . ' overrides=' . $context['overrides'];
				}

				$this->trail[] = $level . ' ' . $message . $extra;
			}
		};
	}//end logger()
}//end class
