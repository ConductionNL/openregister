<?php

/**
 * A fragment's flow may carry a run that waits on a user-task step, and a
 * fragment's task may name that run and carry its claim.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\FlowRun;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRuns
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskClaim
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @uses \OCA\OpenRegister\Db\Flow
 * @uses \OCA\OpenRegister\Db\FlowRun
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Db\TaskAudit
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAccessLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExternalTask
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetGrants
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskDates
 * @uses \OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Search\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\WriteCause
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCastShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetConfigShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentFile
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFragmentShape
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProofPlace
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlowRunCheck
 */
class ExampleSetFlowRunsTest extends ExampleSetTestCase {
	/**
	 * The cast and a case schema.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * The bezwaar flow: start, the hearing step that asks a person, end.
	 *
	 * @param array<int, array<string, mixed>> $runs The flow's runs.
	 *
	 * @return array<string, mixed>
	 */
	private static function bezwaar(array $runs): array {
		return [
			'name'    => 'Bezwaar',
			'trigger' => 'manual',
			'nodes'   => [
				['id' => 'start', 'type' => 'openregister.trigger-manual'],
				['id' => 'hoorzitting', 'type' => 'openregister.user-task', 'name' => 'Hoorzitting', 'config' => ['title' => 'Hoorzitting inplannen']],
				['id' => 'bericht', 'type' => 'dossiq.askPerson'],
				['id' => 'end', 'type' => 'openregister.end'],
			],
			'edges'   => [['from' => 'start', 'to' => 'hoorzitting'], ['from' => 'hoorzitting', 'to' => 'bericht'], ['from' => 'bericht', 'to' => 'end']],
			'runs'    => $runs,
		];
	}//end bezwaar()

	/**
	 * A waiting run on the hearing step.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function waitingRun(array $extra = []): array {
		return [...[
			'key'         => 'wacht',
			'status'      => 'suspended',
			'node'        => 'hoorzitting',
			'subject'     => '{{object:case-1.id}}',
			'triggeredBy' => '{{cast:esra.uid}}',
			'created'     => '{{datetime:-2 16:20}}',
		], ...$extra];
	}//end waitingRun()

	/**
	 * The hearing task, waiting in the run and claimed by its assignee.
	 *
	 * @param array<string, mixed> $extra Fields to add or replace.
	 *
	 * @return array<string, mixed>
	 */
	private static function hearingTask(array $extra = []): array {
		return [...[
			'title'      => 'Hoorzitting inplannen',
			'objectUuid' => '{{object:case-1.id}}',
			'state'      => 'active',
			'assignee'   => '{{cast:esra.uid}}',
			'runUuid'    => '{{flow:bezwaar.runs.wacht}}',
			'nodeId'     => 'hoorzitting',
			'claimedBy'  => '{{cast:esra.uid}}',
			'claimedAt'  => '{{datetime:-1 09:05}}',
		], ...$extra];
	}//end hearingTask()

	/**
	 * A fragment with a case, the flow and the tasks.
	 *
	 * @param array<int, array<string, mixed>> $runs  The flow's runs.
	 * @param array<int, array<string, mixed>> $tasks The tasks, as fields.
	 *
	 * @return void
	 */
	private function fragment(array $runs, array $tasks): void {
		$rows = [];
		foreach ($tasks as $index => $task) {
			$rows[] = ['key' => 'taak-'.$index, 'data' => $task];
		}

		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Bezwaar parkeerboete']]],
			'flows'   => [['key' => 'bezwaar', 'data' => self::bezwaar(runs: $runs)]],
			'tasks'   => $rows,
		]);
	}//end fragment()

	/**
	 * The run is written as a live user-task wait, the task fills its slot,
	 * the claim lands in the task's audit, and remove takes task, run and flow.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function testAWaitingRunIsWrittenAsALiveWaitAndItsTaskFillsTheSlot(): void {
		$this->fragment(runs: [self::waitingRun()], tasks: [self::hearingTask()]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->flowRuns, message: 'the check writes no run');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));

		$flow = array_values($this->flows)[0];
		$this->assertCount(expectedCount: 1, haystack: $this->flowRuns);
		$run = array_values($this->flowRuns)[0];
		$this->assertSame(expected: FlowRun::STATUS_SUSPENDED, actual: $run->getStatus());
		$this->assertSame(expected: $flow->getUuid(), actual: $run->getFlowId());
		$this->assertSame(expected: ['hoorzitting' => 1], actual: $run->getMarking());
		$this->assertCount(expectedCount: 1, haystack: (array) $run->getPlaceItems()['hoorzitting']);
		$this->assertCount(expectedCount: 1, haystack: (array) $run->getItems());
		$this->assertSame(expected: 'esra.demir', actual: $run->getTriggeredBy());

		$task = array_values($this->engineTasks)[0];
		$this->assertSame(expected: $run->getUuid(), actual: $task->getRunUuid());
		$this->assertSame(expected: 'hoorzitting', actual: $task->getNodeId());

		$context = (array) $run->getContext();
		$this->assertSame(expected: $run->getUuid(), actual: $context['runUuid']);
		$this->assertSame(expected: $flow->getUuid(), actual: $context['flowId']);
		$this->assertSame(expected: $task->getUuid(), actual: $context['resumeState']['hoorzitting']['taskUuid']);
		$this->assertNotNull(actual: $run->getResumeAt(), message: 'a filled wait has a heartbeat, so the reaper never takes it');
		$this->assertGreaterThan(expected: new \DateTime(), actual: $run->getResumeAt());

		$this->assertCount(expectedCount: 1, haystack: $this->taskAudits);
		$claim = $this->taskAudits[0];
		$this->assertSame(expected: 'claim', actual: $claim->getAction());
		$this->assertSame(expected: 'esra.demir', actual: $claim->getActor());
		$this->assertSame(expected: (int) $task->getId(), actual: $claim->getTaskId());
		$this->assertSame(expected: '2026-10-12 09:05', actual: $this->local(moment: $claim->getCreated()));

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $again['ok'], message: (string) json_encode($again));
		$this->assertCount(expectedCount: 1, haystack: $this->flowRuns, message: 'a kept flow writes its run once');
		$this->assertCount(expectedCount: 1, haystack: $this->taskAudits, message: 'a kept task is claimed once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->engineTasks);
		$this->assertSame(expected: [], actual: $this->flowRuns);
		$this->assertSame(expected: [], actual: $this->flows);
	}//end testAWaitingRunIsWrittenAsALiveWaitAndItsTaskFillsTheSlot()

	/**
	 * A refresh keeps the waiting run and the task keeps naming it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-waiting-run-and-claim/specs/example-sets/spec.md#requirement-a-fragments-flow-may-carry-a-run-that-waits-on-a-task
	 */
	public function testARefreshKeepsTheWaitingRun(): void {
		$this->fragment(runs: [self::waitingRun()], tasks: [self::hearingTask()]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$run = array_key_first($this->flowRuns);

		$report = $this->installer->install(plan: ($this->catalogue->gather(set: 'demo') + ['refresh' => true]), today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: [$run], actual: array_keys($this->flowRuns));
		$this->assertSame(expected: $run, actual: array_values($this->engineTasks)[0]->getRunUuid());
	}//end testARefreshKeepsTheWaitingRun()

	/**
	 * A faulty wait or claim is refused at check time and nothing is written.
	 *
	 * @param array<int, array<string, mixed>> $runs   The flow's runs.
	 * @param array<int, array<string, mixed>> $tasks  The tasks.
	 * @param string                           $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedWaits
	 */
	public function testACheckRefusesAFaultyWaitAndWritesNothing(array $runs, array $tasks, string $reason): void {
		$this->fragment(runs: $runs, tasks: $tasks);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertNotSame(expected: [], actual: $refusals);
		$this->assertStringContainsString(needle: $reason, haystack: implode("\n", $refusals));
		$this->assertSame(expected: [], actual: $this->flowRuns);
		$this->assertSame(expected: [], actual: $this->engineTasks);
		$this->assertSame(expected: [], actual: $this->taskAudits);
	}//end testACheckRefusesAFaultyWaitAndWritesNothing()

	/**
	 * The faulty waits and claims, and a part of their refusal.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: string}>
	 */
	public static function refusedWaits(): array {
		$plain = self::hearingTask(extra: ['runUuid' => null, 'nodeId' => null]);
		unset($plain['runUuid'], $plain['nodeId']);

		$wait  = [self::waitingRun()];
		$plain = [$plain];

		return [
			'a waiting run no task names'     => [$wait, $plain, 'no task of its fragment names it'],
			'a wait on an app step'           => [
				[self::waitingRun(extra: ['node' => 'bericht'])],
				[self::hearingTask(extra: ['nodeId' => 'bericht'])],
				'openregister.user-task',
			],
			'a wait on no step'               => [[self::waitingRun(extra: ['node' => 'ghost'])], [self::hearingTask()], 'openregister.user-task'],
			'a waiting run with no key'       => [[self::waitingRun(extra: ['key' => null])], $plain, 'a waiting run needs a key'],
			'a queued run'                    => [[self::waitingRun(extra: ['status' => 'queued'])], $plain, 'a run is finished'],
			'an unknown run'                  => [$wait, [self::hearingTask(extra: ['runUuid' => '{{flow:bezwaar.runs.nope}}'])], 'cannot resolve'],
			'a run and no step'               => [$wait, [self::hearingTask(extra: ['nodeId' => null])], 'runUuid and nodeId go together'],
			'a step the run does not wait on' => [$wait, [self::hearingTask(extra: ['nodeId' => 'bericht'])], 'is not the step run'],
			'a claim by someone else'         => [$wait, [self::hearingTask(extra: ['claimedBy' => '{{cast:sanne.uid}}'])], 'claimedBy is the assignee'],
			'a claim in the future'           => [
				$wait,
				[self::hearingTask(extra: ['claimedAt' => date('c', strtotime('+10 years'))])],
				'claimedAt lies in the future',
			],
			'a claim that is no date'         => [$wait, [self::hearingTask(extra: ['claimedAt' => 'gisteren'])], 'claimedAt "gisteren" is not a date'],
		];
	}//end refusedWaits()
}//end class
