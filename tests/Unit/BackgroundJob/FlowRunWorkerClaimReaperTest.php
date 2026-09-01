<?php

/**
 * The claim reaper: an abandoned claim is released after the SAME cutoff the
 * run reaper uses, its branch is failed and named, and it is never
 * re-dispatched.
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
 *
 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-branch-abandoned-by-a-crashed-worker-must-be-recovered-and-must-not-be-silently-re-run
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowRunWorker;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Abandoned claims.
 *
 * @covers \OCA\OpenRegister\BackgroundJob\FlowRunWorker
 * @covers \OCA\OpenRegister\Db\FlowClaim
 * @covers \OCA\OpenRegister\Db\FlowStream
 * @covers \OCA\OpenRegister\Db\FlowRun
 */
class FlowRunWorkerClaimReaperTest extends TestCase {

	private FlowRunMapper&MockObject $mapper;

	private FlowRunAdvancer&MockObject $advancer;

	private FlowClaimMapper&MockObject $claims;

	private FlowStreamMapper&MockObject $streams;

	private FlowLocator&MockObject $flows;

	private FlowRun $run;

	private FlowStream $branch;

	private FlowStream $sibling;

	/** @var array<int, DateTime> the cutoffs the claim reaper asked for */
	private array $cutoffs = [];

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->advancer = $this->createMock(FlowRunAdvancer::class);
		$this->claims = $this->createMock(FlowClaimMapper::class);
		$this->streams = $this->createMock(FlowStreamMapper::class);
		$this->flows = $this->createMock(FlowLocator::class);

		$this->mapper->method('findQueued')->willReturn([]);
		$this->mapper->method('findDue')->willReturn([]);
		$this->mapper->method('findStale')->willReturn([]);
		$this->mapper->method('update')->willReturnCallback(static fn (FlowRun $r): FlowRun => $r);

		$this->run = new FlowRun();
		$this->run->setUuid('run-1');
		$this->run->setFlowId('flow-1');
		$this->run->setStatus(FlowRun::STATUS_RUNNING);
		$this->mapper->method('findByUuid')->willReturn($this->run);

		$this->branch = new FlowStream();
		$this->branch->setRunUuid('run-1');
		$this->branch->setStreamId('branch-2');
		$this->branch->setOrdinalPath('0001.0002');
		$this->branch->setStatus(FlowRun::STATUS_RUNNING);

		$this->sibling = new FlowStream();
		$this->sibling->setRunUuid('run-1');
		$this->sibling->setStreamId('branch-1');
		$this->sibling->setOrdinalPath('0001.0001');
		$this->sibling->setStatus(FlowRun::STATUS_SUSPENDED);

		$this->streams->method('findByRunAndStream')->willReturn($this->branch);
		$this->streams->method('findByRun')->willReturnCallback(fn (): array => [$this->sibling, $this->branch]);
		$this->streams->method('update')->willReturnCallback(static fn (FlowStream $s): FlowStream => $s);
	}//end setUp()

	/**
	 * The worker under test.
	 *
	 * @param array<string, string> $config App-config values.
	 *
	 * @return FlowRunWorker The worker.
	 */
	private function worker(array $config = []): FlowRunWorker {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new FlowRunWorker(
			$this->createMock(ITimeFactory::class),
			$this->mapper,
			$this->advancer,
			$appConfig,
			new NullLogger(),
			null,
			$this->claims,
			$this->streams,
			$this->flows
		);
	}//end worker()

	/**
	 * One pass.
	 *
	 * @param FlowRunWorker $worker The worker.
	 *
	 * @return void
	 */
	private function pass(FlowRunWorker $worker): void {
		$method = new \ReflectionMethod(FlowRunWorker::class, 'run');
		$method->invoke($worker, null);
	}//end pass()

	/**
	 * A stale claim on the branch.
	 *
	 * @return FlowClaim The claim.
	 */
	private function staleClaim(): FlowClaim {
		$claim = new FlowClaim();
		$claim->setRunUuid('run-1');
		$claim->setPlace('review');
		$claim->setOwner('dead-pass');
		$claim->setStreamId('branch-2');
		$claim->setTransition('review');
		$claim->setClaimedAt(new DateTime('-3 hours'));

		return $claim;
	}//end staleClaim()

	public function testAStaleClaimIsReleasedItsBranchFailedAndNamedAndNeverReDispatched(): void {
		$this->claims->method('findOlderThan')->willReturnCallback(function (DateTime $before): array {
			$this->cutoffs[] = $before;
			return [$this->staleClaim()];
		});
		$this->claims->expects($this->once())->method('releaseByOwner')->with('run-1', 'dead-pass')->willReturn(1);
		// Reaped, never re-dispatched.
		$this->advancer->expects($this->never())->method('advance');

		$this->pass($this->worker(['flow_run_retention_days' => '0']));

		$this->assertSame(FlowRun::STATUS_FAILED, $this->branch->getStatus());
		$this->assertStringContainsString('branch-2', (string)$this->branch->getError());
		$this->assertStringContainsString('review', (string)$this->branch->getError());
		$this->assertStringContainsString('NOT re-run', (string)$this->branch->getError());
		// Default policy (`stop`): the run fails with the branch.
		$this->assertSame(FlowRun::STATUS_FAILED, $this->run->getStatus());
	}//end testAStaleClaimIsReleasedItsBranchFailedAndNamedAndNeverReDispatched()

	public function testAContinuePolicyKeepsTheSiblingsAndReArmsTheRun(): void {
		$this->claims->method('findOlderThan')->willReturn([$this->staleClaim()]);
		$this->claims->method('releaseByOwner')->willReturn(1);
		$this->flows->method('resolveFlow')->willReturn(
			[
				'id' => 'flow-1',
				'nodes' => [['id' => 'review', 'type' => 'x', 'onError' => 'continue']],
				'edges' => [],
			]
		);

		$this->pass($this->worker(['flow_run_retention_days' => '0']));

		$this->assertSame(FlowRun::STATUS_FAILED, $this->branch->getStatus());
		$this->assertSame(FlowRun::STATUS_QUEUED, $this->run->getStatus());
	}//end testAContinuePolicyKeepsTheSiblingsAndReArmsTheRun()

	public function testTheClaimReaperUsesTheRunReapersCutoff(): void {
		// stale 15 min vs max runtime 60 + grace 5: the run reaper waits 65
		// minutes, and so must the claim reaper — one expression, not two.
		$this->claims->method('findOlderThan')->willReturnCallback(function (DateTime $before): array {
			$this->cutoffs[] = $before;
			return [];
		});

		$before = new DateTime();
		$this->pass($this->worker(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '15', 'flow_max_runtime_minutes' => '60']));

		$this->assertCount(1, $this->cutoffs);
		$minutes = (int)round(($before->getTimestamp() - $this->cutoffs[0]->getTimestamp()) / 60);
		$this->assertSame(65, $minutes);
	}//end testTheClaimReaperUsesTheRunReapersCutoff()

	public function testALiveLongRunningFiringIsNotReaped(): void {
		// The mapper is asked for claims older than the cutoff only; a claim
		// inside its granted runtime is simply not in the answer, and nothing
		// is released.
		$this->claims->method('findOlderThan')->willReturn([]);
		$this->claims->expects($this->never())->method('releaseByOwner');
		$this->streams->expects($this->never())->method('update');

		$this->pass($this->worker(['flow_run_retention_days' => '0']));
		$this->assertSame(FlowRun::STATUS_RUNNING, $this->run->getStatus());
	}//end testALiveLongRunningFiringIsNotReaped()

	public function testTheReaperIsOffWhenTheStaleWindowIsZero(): void {
		$this->claims->expects($this->never())->method('findOlderThan');
		$this->pass($this->worker(['flow_run_retention_days' => '0', 'flow_run_stale_minutes' => '0']));
	}//end testTheReaperIsOffWhenTheStaleWindowIsZero()
}//end class
