<?php

/**
 * Unit tests for FlowRunSignalService — the guarded server-side signal seam.
 *
 * The seam exists so a PHP consumer cannot forget the assignee guard the HTTP
 * endpoint applies: resolve, guard, audit, deliver is ONE call. The tests that
 * matter most are the refusals, because each has a way of passing while
 * broken:
 *
 *   - the STRANGER case is the mutation check: were the guard skipped, the
 *     signal would be delivered and the expectException here reds the suite —
 *     "disabling the guard" cannot go green;
 *   - a refusal must touch NOTHING: a 403 that still woke the run would be
 *     the vulnerability with extra steps;
 *   - the GROUP branch admits the step's intended audience, and a broken
 *     group lookup reads as "the guard works" because refusing is what a
 *     guard does.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FlowRunSignalServiceTest extends TestCase {

	/**
	 * The delivery primitive underneath the seam.
	 *
	 * @var FlowRunService&MockObject
	 */
	private FlowRunService $runner;

	/**
	 * Resolves uuids to runs.
	 *
	 * @var FlowRunMapper&MockObject
	 */
	private FlowRunMapper $mapper;

	/**
	 * Receives the refusal audit.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->runner = $this->createMock(FlowRunService::class);
		$this->mapper = $this->createMock(FlowRunMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A suspended run whose resume slots are exactly as given.
	 *
	 * @param array $slots The per-node resume slots.
	 *
	 * @return FlowRun The run.
	 */
	private function suspendedRun(array $slots): FlowRun {
		$run = new FlowRun();
		$run->setUuid('run-1');
		$run->setStatus(FlowRun::STATUS_SUSPENDED);
		$run->setContext([FlowResumeState::CONTEXT_KEY => $slots]);

		return $run;
	}//end suspendedRun()

	/**
	 * The seam under test.
	 *
	 * @param IGroupManager|null $groups The group resolver, when the test needs one.
	 *
	 * @return FlowRunSignalService The seam.
	 */
	private function seam(?IGroupManager $groups = null): FlowRunSignalService {
		return new FlowRunSignalService(
			mapper: $this->mapper,
			runner: $this->runner,
			logger: $this->logger,
			groupManager: $groups
		);
	}//end seam()

	public function testTheRecordedAssigneeMayAnswer(): void {
		$run = $this->suspendedRun(['ask' => ['askedAt' => 'now', 'assignee' => 'alice']]);

		$this->runner->expects($this->once())
			->method('signal')
			->with($run, ['decision' => 'approved'])
			->willReturn($run);

		$signalled = $this->seam()->signalRunAs(run: $run, payload: ['decision' => 'approved'], actorUid: 'alice');

		$this->assertSame($run, $signalled);
	}//end testTheRecordedAssigneeMayAnswer()

	/**
	 * 🔴 THE GROUP BRANCH — the half a hand-written copy of the guard tends to
	 * forget, refusing the step's own intended audience while reading as "the
	 * guard works".
	 */
	public function testAGroupMemberMayAnswerAGroupAssignedStep(): void {
		$run = $this->suspendedRun(['ask' => ['askedAt' => 'now', 'assignee' => 'behandelaars']]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->with('carol', 'behandelaars')->willReturn(true);

		$this->runner->expects($this->once())->method('signal')->willReturn($run);

		$this->seam(groups: $groups)->signalRunAs(run: $run, payload: [], actorUid: 'carol');
	}//end testAGroupMemberMayAnswerAGroupAssignedStep()

	/**
	 * 🔴 THE MUTATION CHECK. Skip the guard and this test reds: the signal
	 * would be delivered instead of the refusal being thrown, and the
	 * never-called delivery assertion fails with it.
	 */
	public function testAStrangerIsRefusedAndTheRunIsUntouched(): void {
		$run = $this->suspendedRun(['ask' => ['askedAt' => 'now', 'assignee' => 'alice']]);

		$this->runner->expects($this->never())->method('signal');

		try {
			$this->seam()->signalRunAs(run: $run, payload: ['decision' => 'approved'], actorUid: 'mallory');
			$this->fail('Expected the signal to be refused.');
		} catch (FlowSignalRefused $refused) {
			$this->assertSame(FlowSignalRefused::NOT_ASSIGNEE, $refused->getReason());
			$this->assertSame('mallory', $refused->getActorUid());
			$this->assertSame('run-1', $refused->getRunUuid());
		}
	}//end testAStrangerIsRefusedAndTheRunIsUntouched()

	/**
	 * The refusal is AUDITED by the engine, so the trace exists whether or not
	 * the consumer remembers to log it — a listener sees the refusal after its
	 * own object is already saved, and this record is what is left.
	 */
	public function testARefusalIsAudited(): void {
		$run = $this->suspendedRun(['ask' => ['askedAt' => 'now', 'assignee' => 'alice']]);

		$this->logger->expects($this->once())->method('warning');

		$this->expectException(FlowSignalRefused::class);
		$this->seam()->signalRunAs(run: $run, payload: [], actorUid: 'mallory');
	}//end testARefusalIsAudited()

	public function testAnAnonymousActorIsRefusedOnAnAssignedStep(): void {
		$run = $this->suspendedRun(['ask' => ['askedAt' => 'now', 'assignee' => 'alice']]);

		$this->runner->expects($this->never())->method('signal');

		try {
			$this->seam()->signalRunAs(run: $run, payload: [], actorUid: null);
			$this->fail('Expected the signal to be refused.');
		} catch (FlowSignalRefused $refused) {
			$this->assertSame(FlowSignalRefused::NOT_ASSIGNEE, $refused->getReason());
			// A blank actor is anonymous, and the refusal says so.
			$this->assertNull($refused->getActorUid());
		}
	}//end testAnAnonymousActorIsRefusedOnAnAssignedStep()

	/**
	 * The unassigned contract is UNCHANGED by the seam: webhook and child-run
	 * signals are not human decisions, and silence still means anyone.
	 */
	public function testAnUnassignedStepIsAnswerableByAnyone(): void {
		$run = $this->suspendedRun(['hook' => ['askedAt' => 'now']]);

		$this->runner->expects($this->once())->method('signal')->willReturn($run);

		$this->seam()->signalRunAs(run: $run, payload: [], actorUid: 'anyone');
	}//end testAnUnassignedStepIsAnswerableByAnyone()

	/**
	 * ADDRESSING. A caller that knows which node its answer addresses is
	 * checked against that node's own assignee — the run-level scan would have
	 * refused the second node's audience.
	 */
	public function testAddressingANodeChecksThatNodesAssignee(): void {
		$run = $this->suspendedRun([
			'ask-a' => ['askedAt' => 'now', 'assignee' => 'alice'],
			'ask-b' => ['askedAt' => 'now', 'assignee' => 'bob'],
		]);

		$this->runner->expects($this->once())->method('signal')->willReturn($run);

		$this->seam()->signalRunAs(run: $run, payload: [], actorUid: 'bob', nodeId: 'ask-b');
	}//end testAddressingANodeChecksThatNodesAssignee()

	/**
	 * 🔴 ...and addressing can never LOOSEN the guard: a node that is not
	 * asking has no slot, so the run-level rule still refuses the stranger.
	 */
	public function testAddressingASilentNodeCannotLoosenTheGuard(): void {
		$run = $this->suspendedRun(['ask-a' => ['askedAt' => 'now', 'assignee' => 'alice']]);

		$this->runner->expects($this->never())->method('signal');

		$this->expectException(FlowSignalRefused::class);
		$this->seam()->signalRunAs(run: $run, payload: [], actorUid: 'mallory', nodeId: 'never-asked');
	}//end testAddressingASilentNodeCannotLoosenTheGuard()

	public function testANonSuspendedRunRefusesWithItsOwnReason(): void {
		$run = $this->suspendedRun([]);
		$run->setStatus(FlowRun::STATUS_RUNNING);

		// The primitive's contract: null means "not suspended".
		$this->runner->method('signal')->willReturn(null);

		try {
			$this->seam()->signalRunAs(run: $run, payload: [], actorUid: 'alice');
			$this->fail('Expected the signal to be refused.');
		} catch (FlowSignalRefused $refused) {
			$this->assertSame(FlowSignalRefused::NOT_SUSPENDED, $refused->getReason());
		}
	}//end testANonSuspendedRunRefusesWithItsOwnReason()

	public function testSignalAsResolvesTheRunByUuid(): void {
		$run = $this->suspendedRun(['ask' => ['askedAt' => 'now', 'assignee' => 'alice']]);

		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->runner->expects($this->once())->method('signal')->willReturn($run);

		$signalled = $this->seam()->signalAs(runUuid: 'run-1', payload: ['ok' => true], actorUid: 'alice');

		$this->assertSame($run, $signalled);
	}//end testSignalAsResolvesTheRunByUuid()

	public function testSignalAsRefusesAnUnknownUuid(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('nope'));
		$this->runner->expects($this->never())->method('signal');

		try {
			$this->seam()->signalAs(runUuid: 'ghost', payload: [], actorUid: 'alice');
			$this->fail('Expected the signal to be refused.');
		} catch (FlowSignalRefused $refused) {
			$this->assertSame(FlowSignalRefused::RUN_NOT_FOUND, $refused->getReason());
			$this->assertSame('ghost', $refused->getRunUuid());
		}
	}//end testSignalAsRefusesAnUnknownUuid()
}//end class
