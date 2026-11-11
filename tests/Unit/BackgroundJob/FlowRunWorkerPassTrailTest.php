<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\BackgroundJob;

use DateTime;
use OCA\OpenRegister\BackgroundJob\FlowRunRecoveryServices;
use OCA\OpenRegister\BackgroundJob\FlowRunWorker;
use OCA\OpenRegister\BackgroundJob\FlowRunWorkerServices;
use OCA\OpenRegister\Db\FlowClaim;
use OCA\OpenRegister\Db\FlowClaimMapper;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunQueueExpiry;
use OCA\OpenRegister\Db\FlowStream;
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunAdvancer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Characterisation test of one FlowRunWorker pass: the exact error texts it
 * writes, the order in which it sets entity fields (which is the column order
 * of the UPDATE), the cutoffs it asks for and its log trail.
 */
class FlowRunWorkerPassTrailTest extends TestCase {

	/** @var array<int, string> */
	private array $events = [];

	private function recordingLogger(): AbstractLogger {
		return new class extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array}> */
			public array $trail = [];

			public function log($level, $message, array $context = []): void {
				unset($context['line'], $context['file']);
				$this->trail[] = [$level, (string) $message, $context];
			}
		};
	}

	private function flowRun(string $uuid): FlowRun {
		$run = new FlowRun();
		$run->setUuid($uuid);
		$run->setFlowId('flow-' . $uuid);
		$run->setStatus(FlowRun::STATUS_RUNNING);
		$run->resetUpdatedFields();
		return $run;
	}

	private function minutesBefore(DateTime $now, DateTime $cutoff): int {
		return (int) round(($now->getTimestamp() - $cutoff->getTimestamp()) / 60);
	}

	/**
	 * One pass with a stale run, two stale claims (one reaped, one whose
	 * release throws), a run whose signal never came, an expiry and a prune.
	 */
	public function testOnePassTrail(): void {
		$logger = $this->recordingLogger();
		$stale = $this->flowRun('stale');
		$signal = $this->flowRun('signal');
		$claimed = $this->flowRun('claimed');
		$branch = new FlowStream();
		$branch->setStreamId('b-2');
		$branch->setStatus(FlowRun::STATUS_RUNNING);
		$branch->resetUpdatedFields();
		$sibling = new FlowStream();
		$sibling->setStreamId('b-1');
		$sibling->setStatus(FlowRun::STATUS_SUSPENDED);
		$cutoffs = [];
		$now = new DateTime();

		$mapper = $this->createMock(FlowRunMapper::class);
		$mapper->method('findQueued')->willReturn([]);
		$mapper->method('findDue')->willReturn([]);
		$mapper->method('findAwaitingConsent')->willReturn([]);
		$mapper->method('findStale')->willReturnCallback(
			function (DateTime $before, int $limit) use (&$cutoffs, $stale, $now) {
				$cutoffs[] = ['stale', $this->minutesBefore($now, $before), $limit];
				return [$stale];
			}
		);
		$mapper->method('findAbandonedSignals')->willReturnCallback(
			function (DateTime $before, int $limit) use (&$cutoffs, $signal, $now) {
				$cutoffs[] = ['signal', $this->minutesBefore($now, $before), $limit];
				return [$signal];
			}
		);
		$mapper->method('expireQueuedBefore')->willReturnCallback(
			function (FlowRunQueueExpiry $expiry) use (&$cutoffs, $now) {
				$cutoffs[] = ['queued', $this->minutesBefore($now, $expiry->before), $expiry->limit, $expiry->reason];
				return ['a', 'b'];
			}
		);
		$mapper->method('pruneBefore')->willReturnCallback(
			function (DateTime $before) use (&$cutoffs, $now) {
				$cutoffs[] = ['prune', $this->minutesBefore($now, $before)];
				return 4;
			}
		);
		$mapper->method('findByUuid')->willReturn($claimed);
		$mapper->method('update')->willReturnCallback(
			function (FlowRun $run) {
				$this->events[] = 'update ' . $run->getUuid() . ' ' . implode(',', array_keys($run->getUpdatedFields()))
					. ' ' . $run->getStatus() . ' | ' . $run->getError();
				return $run;
			}
		);

		$claimOk = new FlowClaim();
		$claimOk->setRunUuid('claimed');
		$claimOk->setOwner('dead-pass');
		$claimOk->setStreamId('b-2');
		$claimOk->setTransition('review');
		$claimBad = new FlowClaim();
		$claimBad->setRunUuid('broken');
		$claimBad->setOwner('other');
		$claims = $this->createMock(FlowClaimMapper::class);
		$claims->method('findOlderThan')->willReturnCallback(
			function (DateTime $before, int $limit) use (&$cutoffs, $claimOk, $claimBad, $now) {
				$cutoffs[] = ['claims', $this->minutesBefore($now, $before), $limit];
				return [$claimOk, $claimBad];
			}
		);
		$claims->method('releaseByOwner')->willReturnCallback(
			function (string $runUuid, string $owner) {
				$this->events[] = 'release ' . $runUuid . ' ' . $owner;
				if ($runUuid === 'broken') {
					throw new \RuntimeException('release failed');
				}
				return 1;
			}
		);
		$claims->method('deleteOrphans')->willReturnCallback(
			function () {
				$this->events[] = 'claims orphans';
				return 0;
			}
		);
		$streams = $this->createMock(FlowStreamMapper::class);
		$streams->method('findByRunAndStream')->willReturn($branch);
		$streams->method('findByRun')->willReturn([$sibling, $branch]);
		$streams->method('deleteOrphans')->willReturnCallback(
			function () {
				$this->events[] = 'streams orphans';
				return 0;
			}
		);
		$streams->method('update')->willReturnCallback(
			function (FlowStream $stream) {
				$this->events[] = 'stream ' . implode(',', array_keys($stream->getUpdatedFields())) . ' | ' . $stream->getError();
				return $stream;
			}
		);
		$flows = $this->createMock(FlowLocator::class);
		$flows->method('resolveFlow')->willReturn(
			['id' => 'f', 'nodes' => [['id' => 'review', 'type' => 'x', 'onError' => 'continue']], 'edges' => []]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$config = ['flow_run_stale_minutes' => '15', 'flow_max_runtime_minutes' => '60', 'flow_signal_wait_days' => '2', 'flow_run_queued_ttl_hours' => '3', 'flow_run_retention_days' => '1'];
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$worker = new FlowRunWorker(
			$this->createMock(ITimeFactory::class),
			new FlowRunWorkerServices($mapper, $this->createMock(FlowRunAdvancer::class), $appConfig, $logger),
			new FlowRunRecoveryServices($claims, $streams, $flows),
			null
		);
		(new \ReflectionMethod(FlowRunWorker::class, 'run'))->invoke($worker, null);

		$branchError = 'Abandoned branch: worker pass "dead-pass" took stream "b-2" at step "review" and never finished it '
			. '(no commit for over 65 minutes). The step may have performed its side effect; it was NOT re-run.';
		$queuedReason = 'Expired: this run waited in the queue for more than 3 hours, so whatever it was '
			. 'meant to do now is no longer what it would do. Retry it to run it again from the start.';
		$this->assertSame(
			[
				['claims', 65, 25],
				['stale', 65, 25],
				['queued', 180, 500, $queuedReason],
				['signal', 2880, 25],
				['prune', 1440],
			],
			$cutoffs
		);
		$this->assertSame(
			[
				'release claimed dead-pass',
				'stream status,error,updated | ' . $branchError,
				'update claimed status,error,updated queued | ',
				'release broken other',
				'update stale status,error,updated failed | Abandoned: no worker pass has touched this run for over 65 minutes, '
					. 'so the pass that started it did not finish (a fatal, a timeout or a restart). Retry it to run it again from the start.',
				'update signal status,error,updated failed | Abandoned: this run suspended waiting for an external signal '
					. '(an approval, a webhook, a child run) and none arrived within 2 days. Retry it to run it again from the start.',
				'streams orphans',
				'claims orphans',
			],
			$this->events
		);
		$this->assertSame(
			[
				['warning', '[FlowRunWorker] Released an abandoned place claim and failed its branch', ['run' => 'claimed', 'stream' => 'b-2', 'transition' => 'review', 'owner' => 'dead-pass']],
				['error', '[FlowRunWorker] Could not reap an abandoned claim', ['run' => 'broken', 'error' => 'release failed']],
				['warning', '[FlowRunWorker] Failed a run abandoned in `running`', ['run' => 'stale', 'flow' => 'flow-stale']],
				['warning', '[FlowRunWorker] Expired queued runs that waited past their TTL', ['expired' => 2, 'ttlHours' => 3]],
				['warning', '[FlowRunWorker] Failed a run whose signal never arrived', ['run' => 'signal', 'flow' => 'flow-signal']],
				['info', '[FlowRunWorker] Pruned old flow runs', ['deleted' => 4, 'olderThanDays' => 1]],
			],
			$logger->trail
		);
	}
}
