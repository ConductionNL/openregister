<?php

/**
 * Unit tests for FlowRunWorker: the parts of a pass around the reapers —
 * draining the queue and the due runs, the orphaned-lock sweep, retention,
 * and the abandoned-claim fallbacks when the step's policy cannot be read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\BackgroundJob
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowRunRecoveryServices;
use OCA\OpenRegister\BackgroundJob\FlowRunWorker;
use OCA\OpenRegister\BackgroundJob\FlowRunWorkerServices;
use OCA\OpenRegister\BackgroundJob\Support\AbandonedFlowClaim;
use OCA\OpenRegister\BackgroundJob\Support\FlowReapRun;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapperInterface;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancerInterface;
use OCA\OpenRegister\Service\Flow\FlowTokenRouter;
use OCA\OpenRegister\Service\Object\RunLockReleaseInterface;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use ReflectionMethod;
use RuntimeException;

/**
 * One worker pass, with the reapers switched off unless a test needs them.
 */
#[CoversClass(FlowRunWorker::class)]
#[UsesClass(FlowRunWorkerServices::class)]
#[UsesClass(FlowRunRecoveryServices::class)]
#[UsesClass(AbandonedFlowClaim::class)]
#[UsesClass(FlowReapRun::class)]
#[UsesClass(FlowTokenRouter::class)]
#[UsesClass(FlowRun::class)]
#[UsesClass(FlowStream::class)]
#[UsesClass(FlowClaim::class)]
final class FlowRunWorkerHousekeepingTest extends TestCase {

	private FlowRunMapperInterface&MockObject $mapper;
	private FlowRunAdvancerInterface&MockObject $advancer;

	/**
	 * Every log line as [level, message, context without file and line].
	 *
	 * @var AbstractLogger
	 */
	private AbstractLogger $logger;

	/**
	 * App-config values; anything unset takes the worker's default.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	protected function setUp(): void {
		$this->mapper   = $this->createMock(FlowRunMapperInterface::class);
		$this->advancer = $this->createMock(FlowRunAdvancerInterface::class);
		$this->mapper->method('findAwaitingConsent')->willReturn([]);
		$this->logger = new class extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
			public array $trail = [];

			public function log($level, $message, array $context = []): void {
				unset($context['file'], $context['line'], $context['exception']);
				$this->trail[] = [(string) $level, (string) $message, $context];
			}
		};
		// The reapers and the queued expiry are off unless a test turns them on.
		$this->config = [
			'flow_run_stale_minutes'    => '0',
			'flow_run_queued_ttl_hours' => '0',
			'flow_signal_wait_days'     => '0',
			'flow_run_retention_days'   => '0',
		];
	}//end setUp()

	/**
	 * Run one pass of a worker on these recovery services.
	 *
	 * @param FlowRunRecoveryServices $recovery The recovery services.
	 *
	 * @return void
	 */
	private function pass(FlowRunRecoveryServices $recovery = new FlowRunRecoveryServices()): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($this->config[$key] ?? $default)
		);

		$worker = new FlowRunWorker(
			$this->createMock(ITimeFactory::class),
			new FlowRunWorkerServices($this->mapper, $this->advancer, $appConfig, $this->logger),
			$recovery
		);
		(new ReflectionMethod(FlowRunWorker::class, 'run'))->invoke($worker, null);
	}//end pass()

	/**
	 * A run in the given status.
	 *
	 * @param string $uuid   The uuid.
	 * @param string $status The status.
	 *
	 * @return FlowRun
	 */
	private static function flowRun(string $uuid, string $status = FlowRun::STATUS_RUNNING): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setFlowId('flow-' . $uuid);
		$run->setStatus($status);
		return $run;
	}//end flowRun()

	public function testItAdvancesTheQueuedRunsThenTheDueOnesWithoutRethrowing(): void {
		$queued = self::flowRun('q-1', FlowRun::STATUS_QUEUED);
		$due    = self::flowRun('d-1', FlowRun::STATUS_SUSPENDED);
		$this->mapper->expects($this->once())->method('findQueued')->with(25)->willReturn([$queued]);
		$this->mapper->expects($this->once())
			->method('findDue')
			->with($this->isInstanceOf(DateTime::class), 25)
			->willReturn([$due]);

		$advanced = [];
		$this->advancer->expects($this->exactly(2))
			->method('advance')
			->willReturnCallback(
				static function (FlowRun $run, bool $rethrow) use (&$advanced): FlowRun {
					$advanced[] = [$run->getUuid(), $rethrow];
					return $run;
				}
			);

		$this->pass();

		$this->assertSame([['q-1', false], ['d-1', false]], $advanced);
		$this->assertSame([], $this->logger->trail);
	}//end testItAdvancesTheQueuedRunsThenTheDueOnesWithoutRethrowing()

	public function testARetentionOfZeroPrunesNothing(): void {
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$this->mapper->expects($this->never())->method('pruneBefore');

		$this->pass();

		$this->assertSame([], $this->logger->trail);
	}//end testARetentionOfZeroPrunesNothing()

	public function testAFailedRetentionPassIsLoggedAndSwallowed(): void {
		$this->config['flow_run_retention_days'] = '7';
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$this->mapper->expects($this->once())->method('pruneBefore')->willThrowException(new RuntimeException('db gone'));

		$this->pass();

		$this->assertSame([['error', '[FlowRunWorker] Retention pass failed', ['error' => 'db gone']]], $this->logger->trail);
	}//end testAFailedRetentionPassIsLoggedAndSwallowed()

	public function testNothingPrunedIsNotLoggedButOrphanRowsAreStillCleared(): void {
		$this->config['flow_run_retention_days'] = '7';
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$this->mapper->expects($this->once())->method('pruneBefore')->willReturn(0);
		$streams = $this->createMock(FlowStreamMapperInterface::class);
		$streams->expects($this->once())->method('deleteOrphans')->willReturn(0);
		$claims = $this->createMock(FlowClaimMapperInterface::class);
		$claims->expects($this->once())->method('deleteOrphans')->willReturn(0);

		$this->pass(new FlowRunRecoveryServices(claims: $claims, streams: $streams));

		$this->assertSame([], $this->logger->trail);
	}//end testNothingPrunedIsNotLoggedButOrphanRowsAreStillCleared()

	public function testTheOrphanedLockSweepReportsWhatItReleased(): void {
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$locks = $this->createMock(RunLockReleaseInterface::class);
		$locks->expects($this->once())
			->method('sweepOrphaned')
			->with($this->isInstanceOf(DateTime::class), 25)
			->willReturn(3);

		$this->pass(new FlowRunRecoveryServices(locks: $locks));

		$this->assertSame([['info', '[FlowRunWorker] Swept 3 orphaned object lock(s).', []]], $this->logger->trail);
	}//end testTheOrphanedLockSweepReportsWhatItReleased()

	public function testASweepThatReleasesNothingIsSilent(): void {
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$locks = $this->createMock(RunLockReleaseInterface::class);
		$locks->expects($this->once())->method('sweepOrphaned')->willReturn(0);

		$this->pass(new FlowRunRecoveryServices(locks: $locks));

		$this->assertSame([], $this->logger->trail);
	}//end testASweepThatReleasesNothingIsSilent()

	public function testAFailingSweepIsAWarningAndThePassStillPrunes(): void {
		$this->config['flow_run_retention_days'] = '7';
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$this->mapper->expects($this->once())->method('pruneBefore')->willReturn(2);
		$locks = $this->createMock(RunLockReleaseInterface::class);
		$locks->method('sweepOrphaned')->willThrowException(new RuntimeException('lock table locked'));

		$this->pass(new FlowRunRecoveryServices(locks: $locks));

		$this->assertSame(
			[
				['warning', '[FlowRunWorker] Orphaned-lock sweep failed: lock table locked', []],
				['info', '[FlowRunWorker] Pruned old flow runs', ['deleted' => 2, 'olderThanDays' => 7]],
			],
			$this->logger->trail
		);
	}//end testAFailingSweepIsAWarningAndThePassStillPrunes()

	/**
	 * The final status and error of a run whose claim on step "review" was
	 * abandoned, given the flow locator (or none) and the run's streams.
	 *
	 * @param FlowLocatorInterface|null $flows   The flow locator.
	 * @param array<int, FlowStream>    $streams The run's streams.
	 *
	 * @return array{0: string|null, 1: bool}
	 */
	private function reapedRun(?FlowLocatorInterface $flows, array $streams): array {
		$this->config['flow_run_stale_minutes'] = '15';
		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$this->mapper->method('findStale')->willReturn([]);
		$claimed = self::flowRun('r-1');
		$this->mapper->method('findByUuid')->with('r-1')->willReturn($claimed);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$claim = new FlowClaim();
		$claim->setRunUuid('r-1');
		$claim->setOwner('dead-pass');
		$claim->setStreamId('b-2');
		$claim->setTransition('review');
		$claims = $this->createMock(FlowClaimMapperInterface::class);
		$claims->method('findOlderThan')->willReturn([$claim]);
		$claims->expects($this->once())->method('releaseByOwner')->with('r-1', 'dead-pass')->willReturn(1);
		$streamMapper = $this->createMock(FlowStreamMapperInterface::class);
		$streamMapper->method('findByRunAndStream')->willReturn(null);
		$streamMapper->method('findByRun')->willReturn($streams);

		$this->pass(new FlowRunRecoveryServices(claims: $claims, streams: $streamMapper, flows: $flows));

		return [$claimed->getStatus(), $claimed->getError() !== null];
	}//end reapedRun()

	public function testWithoutAFlowLocatorTheReapedRunFails(): void {
		$this->assertSame([FlowRun::STATUS_FAILED, true], $this->reapedRun(null, []));
	}//end testWithoutAFlowLocatorTheReapedRunFails()

	public function testAFlowThatCannotBeResolvedFailsTheReapedRun(): void {
		$flows = $this->createMock(FlowLocatorInterface::class);
		$flows->expects($this->once())->method('resolveFlow')->with('flow-r-1')->willThrowException(new RuntimeException('gone'));

		$this->assertSame([FlowRun::STATUS_FAILED, true], $this->reapedRun($flows, []));
	}//end testAFlowThatCannotBeResolvedFailsTheReapedRun()

	public function testAnUnknownFlowFailsTheReapedRun(): void {
		$flows = $this->createMock(FlowLocatorInterface::class);
		$flows->method('resolveFlow')->willReturn(null);

		$this->assertSame([FlowRun::STATUS_FAILED, true], $this->reapedRun($flows, []));
	}//end testAnUnknownFlowFailsTheReapedRun()

	public function testAContinuePolicyWithoutALiveSiblingStillFailsTheRun(): void {
		$flows = $this->createMock(FlowLocatorInterface::class);
		$flows->method('resolveFlow')->willReturn(
			['id' => 'f', 'nodes' => [['id' => 'review', 'type' => 'x', 'onError' => 'continue']], 'edges' => []]
		);
		$failed = new FlowStream();
		$failed->setStreamId('b-2');
		$failed->setStatus(FlowRun::STATUS_RUNNING);
		$done = new FlowStream();
		$done->setStreamId('b-1');
		$done->setStatus(FlowRun::STATUS_COMPLETED);

		$this->assertSame([FlowRun::STATUS_FAILED, true], $this->reapedRun($flows, [$failed, $done]));
	}//end testAContinuePolicyWithoutALiveSiblingStillFailsTheRun()

	public function testAContinuePolicyWithALiveSiblingRequeuesTheRun(): void {
		$flows = $this->createMock(FlowLocatorInterface::class);
		$flows->method('resolveFlow')->willReturn(
			['id' => 'f', 'nodes' => [['id' => 'review', 'type' => 'x', 'onError' => 'continue']], 'edges' => []]
		);
		$live = new FlowStream();
		$live->setStreamId('b-1');
		$live->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->assertSame([FlowRun::STATUS_QUEUED, false], $this->reapedRun($flows, [$live]));
	}//end testAContinuePolicyWithALiveSiblingRequeuesTheRun()
}//end class
