<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use DateTimeImmutable;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class FlowScheduleServiceTest extends TestCase {

	private FlowLocator&MockObject $registry;

	private FlowRunService&MockObject $runs;

	private IAppConfig&MockObject $config;

	/**
	 * last-fire values keyed by config key, mutated by setValueString.
	 */
	private array $store = [];

	private FlowScheduleService $service;

	protected function setUp(): void {
		$this->registry = $this->createMock(FlowLocator::class);
		$this->runs = $this->createMock(FlowRunService::class);
		$this->config = $this->createMock(IAppConfig::class);

		$this->config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default
		);
		$this->config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$this->service = new FlowScheduleService(
			$this->registry,
			$this->runs,
			$this->config,
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A candidate as a source reports it.
	 */
	private function candidate(string $id, array $data): array {
		return array_merge(
			['id' => $id, 'enabled' => false, 'trigger' => '', 'cron' => '', 'owner' => null],
			$data
		);
	}//end candidate()

	private function schedule(string $id, string $cron, ?string $owner = null): array {
		return $this->candidate($id, ['enabled' => true, 'trigger' => 'schedule', 'cron' => $cron, 'owner' => $owner]);
	}//end schedule()

	/**
	 * SINGLETON: a due flow whose previous run has not finished is skipped.
	 *
	 * A scheduled flow can outlive its own interval — a pipeline poll on a
	 * five-minute cron easily does — and without this guard tick N+1 starts
	 * while tick N is still going. Two runs of one flow then race on whatever
	 * that flow is bookkeeping, which is the failure openregister#2212
	 * documented one layer down at the object store.
	 *
	 * @return void
	 */
	public function testADueFlowIsSkippedWhileItsPreviousRunIsStillGoing(): void {
		$this->registry->method('scheduledFlows')->willReturn([$this->schedule('f1', '*/5 * * * *')]);
		$this->runs->method('hasActiveRun')->with('f1')->willReturn(true);

		$this->runs->expects($this->never())->method('queue');

		$fired = $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));

		$this->assertSame([], $fired);

	}//end testADueFlowIsSkippedWhileItsPreviousRunIsStillGoing()

	/**
	 * Skipping must NOT advance the last-fire marker.
	 *
	 * If it did, a flow skipped at 10:00 would not be due again until 10:05
	 * even though its previous run finished at 10:01 — the pipeline would idle
	 * a whole interval for no reason. Leaving the marker alone means the flow
	 * starts on the first tick after its run completes.
	 *
	 * @return void
	 */
	public function testSkippingDoesNotRecordAFireSoTheFlowStaysDue(): void {
		$this->registry->method('scheduledFlows')->willReturn([$this->schedule('f1', '*/5 * * * *')]);
		$this->runs->method('hasActiveRun')->with('f1')->willReturn(true);

		$this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));

		$this->assertArrayNotHasKey('flow_sched_last_f1', $this->store);

	}//end testSkippingDoesNotRecordAFireSoTheFlowStaysDue()

	public function testADueScheduledFlowFiresAndRecordsTheFire(): void {
		// No last-fire recorded -> due on first sight.
		$this->registry->method('scheduledFlows')->willReturn([$this->schedule('f1', '*/5 * * * *')]);

		$this->runs->expects($this->once())->method('queue')
			->with('f1', $this->anything(), 'schedule', $this->anything());

		$fired = $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));

		$this->assertSame(['f1'], $fired);
		// A last-fire was recorded, so the next tick will not re-fire immediately.
		$this->assertArrayHasKey('flow_sched_last_f1', $this->store);
	}//end testADueScheduledFlowFiresAndRecordsTheFire()

	/**
	 * FAILING PATH: a flow living in a LEAF APP's store must fire.
	 *
	 * The scheduler used to enumerate one hard-coded store — the
	 * `flow_register`/`flow_schema` pair — so a flow contributed by any other
	 * app was invisible to it and could never fire, however correct its cron.
	 * hermiq's agentflows were the casualty: `hydra-sequencer`,
	 * `hydra-dispatch` and `hydra-lock-reaper` all declared a schedule and the
	 * instance recorded ZERO runs with trigger `schedule`, ever. The scheduler
	 * now asks the resolver registry, so a source is a source wherever it lives.
	 *
	 * @return void
	 */
	public function testAFlowContributedByAnotherAppFires(): void {
		// The registry's answer is deliberately not shaped like an OpenRegister
		// object — it is whatever a contributing app reported.
		$this->registry->method('scheduledFlows')->willReturn(
			[
				$this->schedule('agentflow-uuid', '*/5 * * * *', 'admin'),
			]
		);

		// Four arguments, not five: the scheduler no longer hands an identity
		// down. See testAScheduledRunIsNotAttributedToTheFlowsOwner().
		$this->runs->expects($this->once())->method('queue')
			->with('agentflow-uuid', $this->anything(), 'schedule', $this->anything());

		$this->assertSame(
			['agentflow-uuid'],
			$this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'))
		);
	}//end testAFlowContributedByAnotherAppFires()

	/**
	 * A scheduled run is NOT attributed to the flow's owner.
	 *
	 * This inverts the previous behaviour deliberately. or#2158 (fourth
	 * instance) fixed an ownerless scheduled run — `context['triggeredBy']` was
	 * null, every attribution-requiring node refused, and ObjectWriteNode
	 * answered "this flow run has no owner", so every natively-scheduled flow
	 * was silently unable to write — by handing `flow.owner` down as the
	 * identity.
	 *
	 * That solved a loud problem by creating a quiet one. `flow.owner` says who
	 * may EDIT the definition; using it as an acting identity turned authoring a
	 * flow into standing consent to unattended execution as the author, under
	 * whatever triggers anyone later added (ADR-099).
	 *
	 * The scheduler therefore passes no identity at all, and the schedule
	 * TRIGGER NODE's `runAs` answers instead — the one place an author states it
	 * deliberately. Asserting the argument is absent is the whole point: a
	 * regression here is silent, because handing the owner down again produces a
	 * run that works, writes, and is attributed to the wrong person.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function testAScheduledRunIsNotAttributedToTheFlowsOwner(): void {
		$this->registry->method('scheduledFlows')->willReturn([$this->schedule('f1', '*/5 * * * *', 'alice')]);

		$this->runs->expects($this->once())->method('queue')
			->willReturnCallback(
				function (string $flowId, array $subject, string $trigger, array $context, ?string $user = null) {
					$this->assertSame('f1', $flowId);
					$this->assertSame('schedule', $trigger);
					$this->assertNull(
						$user,
						"the scheduler must not name an identity — the trigger node's runAs does"
					);

					return new FlowRun();
				}
			);

		$this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00'));
	}//end testAScheduledRunIsNotAttributedToTheFlowsOwner()

	public function testAFlowThatFiredRecentlyIsNotDueAgain(): void {
		// Fired at 10:00; the next */5 occurrence is 10:05, so at 10:02 it is
		// not due yet.
		$this->store['flow_sched_last_f1'] = '2026-07-25T10:00:00+00:00';
		$this->registry->method('scheduledFlows')->willReturn([$this->schedule('f1', '*/5 * * * *')]);

		$this->runs->expects($this->never())->method('queue');

		$this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:02:00')));
	}//end testAFlowThatFiredRecentlyIsNotDueAgain()

	/**
	 * A disabled flow never runs — and the check lives HERE, not in the source.
	 *
	 * Enumerating through the resolvers means the scheduler now sees flows from
	 * apps it does not control, so `enabled` cannot be something a contributing
	 * app is trusted to have filtered on. This candidate is reported by a source
	 * that did not filter; the scheduler must still decline it. Every hydra flow
	 * currently ships `enabled: false` on purpose, so this is the property that
	 * keeps widening the scheduler's reach from starting ten flows.
	 *
	 * @return void
	 */
	public function testADisabledScheduleDoesNotFire(): void {
		$this->registry->method('scheduledFlows')->willReturn(
			[
				$this->candidate('f1', ['enabled' => false, 'trigger' => 'schedule', 'cron' => '*/5 * * * *']),
			]
		);
		$this->runs->expects($this->never())->method('queue');
		$this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
	}//end testADisabledScheduleDoesNotFire()

	public function testANonScheduleTriggerDoesNotFire(): void {
		$this->registry->method('scheduledFlows')->willReturn(
			[
				$this->candidate('f1', ['enabled' => true, 'trigger' => 'object.created', 'cron' => '*/5 * * * *']),
			]
		);
		$this->runs->expects($this->never())->method('queue');
		$this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
	}//end testANonScheduleTriggerDoesNotFire()

	public function testAnInvalidOrMissingCronIsSkipped(): void {
		$this->registry->method('scheduledFlows')->willReturn(
			[
				$this->candidate('bad', ['enabled' => true, 'trigger' => 'schedule', 'cron' => 'not a cron']),
				$this->candidate('none', ['enabled' => true, 'trigger' => 'schedule']),
			]
		);
		$this->runs->expects($this->never())->method('queue');
		$this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
	}//end testAnInvalidOrMissingCronIsSkipped()

	public function testNoFlowStoreFiresNothing(): void {
		$this->registry->method('scheduledFlows')->willThrowException(new \RuntimeException('no such register'));
		$this->assertSame([], $this->service->fireDueFlows(new DateTimeImmutable('2026-07-25 10:00:00')));
	}//end testNoFlowStoreFiresNothing()
}//end class
