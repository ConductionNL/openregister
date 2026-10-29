<?php

/**
 * A fragment may put tasks on its objects: checked before anything is
 * written, written once through the engine's trusted path, proved, and
 * deleted on remove before the objects they are on.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/example-set-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-put-tasks-on-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetTasks
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetExternalTask
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCatalogue
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRecord
 * @uses \OCA\OpenRegister\Db\Task
 * @uses \OCA\OpenRegister\Service\Task\TaskState
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetChanges
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppConfig
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstance
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetOrder
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPaths
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPeople
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetPrerequisites
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetProof
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetUserPreferences
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetValues
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineRows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetViews
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAppointments
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetArchival
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetAuditEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetCollectivesPages
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetDeckCards
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetEmailLinks
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetFlows
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetLock
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetNotes
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetParties
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetReadStates
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetRewriter
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTalkConversations
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTaskChecklist
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetTimelineEntries
 * @uses \OCA\OpenRegister\Service\ExampleSet\ExampleSetWatchers
 */
class ExampleSetTasksTest extends ExampleSetTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * A fragment with a case and the given tasks on it.
	 *
	 * @param array<int, array<string, mixed>> $tasks The tasks.
	 *
	 * @return void
	 */
	private function caseWithTasks(array $tasks): void {
		$this->app(app: 'alpha', fragment: [
			'objects' => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'tasks'   => $tasks,
		]);
	}

	public function testOpenAndFinishedTasksAreWrittenOnceOnTheirObjectAndRemovedFirst(): void {
		$this->caseWithTasks([
			['key' => 'beoordelen', 'data' => [
				'title' => 'Documenten beoordelen', 'objectUuid' => '{{object:case-1.id}}', 'state' => 'active',
				'assignee' => '{{cast:esra.uid}}', 'dueAt' => '{{date:5}}', 'priority' => 'high',
			]],
			['key' => 'ontvangst', 'data' => [
				'title' => 'Ontvangst bevestigen', 'objectUuid' => '{{object:case-1.id}}', 'state' => 'completed',
				'assignee' => '{{cast:esra.uid}}', 'completedAt' => '{{datetime:-13 10:00}}', 'completedBy' => '{{cast:esra.uid}}',
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame([], $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame([], $this->engineTasks, 'the check writes no task');

		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame(['alpha/beoordelen' => 'created', 'alpha/ontvangst' => 'created'], $report['tasks']);
		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$this->assertCount(2, $this->engineTasks);
		foreach ($this->engineTasks as $task) {
			$this->assertSame($caseId, $task->getObjectUuid());
			$this->assertSame('esra.demir', $task->getAssignee());
		}

		$this->assertSame('example-set:alpha/beoordelen', $this->taskImports[0]['data']['key']);
		$this->assertSame('alpha', $this->taskImports[0]['data']['appId']);
		$this->assertArrayNotHasKey('completedAt', $this->taskImports[1]['data']);
		$done = $this->engineTasks['task-2'];
		$this->assertSame('completed', $done->getState());
		$this->assertSame('2026-09-30 10:00', $done->getCompletedAt()->format('Y-m-d H:i'));
		$this->assertSame('esra.demir', $done->getCompletedBy());

		$again = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertSame(['alpha/beoordelen' => 'kept', 'alpha/ontvangst' => 'kept'], $again['tasks']);
		$this->assertCount(2, $this->taskImports, 'a second install writes nothing');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame([], $removed['failed']);
		$this->assertSame(2, $removed['removed']['tasks']);
		$this->assertSame([], $this->engineTasks);
		$this->assertSame([], $this->store->ids(register: 'cases', schema: 'case'));
	}

	/**
	 * An external task reaches the engine addressed to its party, with its
	 * upload rules as metadata.upload, raised by the fragment's app.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-external-party-tasks/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-resident-a-portal-task
	 */
	public function testAnExternalTaskIsWrittenForItsPartyWithItsUploadRules(): void {
		$this->caseWithTasks(tasks: [
			['key' => 'id-kopie', 'data' => [
				'title' => 'Stuur een kopie van uw ID-bewijs', 'objectUuid' => '{{object:case-1.id}}',
				'performerType' => 'external', 'assignee' => 'party:{{cast:sanne.uid}}', 'dueAt' => '{{date:7}}',
				'uploadRules' => ['required' => true, 'maxFiles' => 2],
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$import = $this->taskImports[0];
		$this->assertSame(expected: 'external', actual: $import['data']['performerType']);
		$this->assertSame(expected: 'party:sanne.devries', actual: $import['data']['assignee']);
		$this->assertSame(expected: ['upload' => ['required' => true, 'maxFiles' => 2]], actual: $import['data']['metadata']);
		$this->assertArrayNotHasKey(key: 'uploadRules', array: $import['data']);
		$this->assertSame(expected: 'alpha', actual: $import['actor'], message: 'the app raises the task, not the party');
	}

	/**
	 * A task's checklist reaches the engine as it is declared, ticked items included.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-checklist/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-checklist
	 */
	public function testATaskIsWrittenWithItsChecklist(): void {
		$checklist = [
			['id' => 'datum', 'label' => 'Datum afstemmen', 'checked' => true],
			['id' => 'zaal', 'label' => 'Zaal reserveren', 'checked' => false],
		];
		$this->caseWithTasks(tasks: [
			['key' => 'hoorzitting', 'data' => [
				'title' => 'Hoorzitting inplannen', 'objectUuid' => '{{object:case-1.id}}', 'priority' => 'high',
				'checklist' => $checklist,
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: $checklist, actual: $this->taskImports[0]['data']['checklist']);
		$this->assertSame(expected: 'high', actual: $this->taskImports[0]['data']['priority']);
	}

	/**
	 * A task may carry the engine key and organisation an app reads it by, such as a tenant's onboarding step.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-key-and-organisation/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-engine-key-and-organisation
	 */
	public function testATaskKeepsTheKeyAndOrganisationItDeclares(): void {
		$this->caseWithTasks(tasks: [
			['key' => 'stap-contract', 'data' => [
				'title' => 'Contract', 'objectUuid' => '{{object:case-1.id}}', 'kind' => 'dossiq.onboarding',
				'taskKey' => 'contract', 'organisation' => '{{object:case-1.id}}', 'state' => 'completed',
			]],
			['key' => 'gewoon', 'data' => ['title' => 'Gewoon', 'objectUuid' => '{{object:case-1.id}}']],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->installer->install(plan: $plan, today: $this->today());

		$caseId = $this->store->ids(register: 'cases', schema: 'case')[0];
		$this->assertSame(expected: 'contract', actual: $this->taskImports[0]['data']['key']);
		$this->assertSame(expected: $caseId, actual: $this->taskImports[0]['data']['organisation']);
		$this->assertArrayNotHasKey(key: 'taskKey', array: $this->taskImports[0]['data']);
		$this->assertSame(expected: 'example-set:alpha/gewoon', actual: $this->taskImports[1]['data']['key'], message: 'without taskKey the set names it');
	}//end testATaskKeepsTheKeyAndOrganisationItDeclares()

	/**
	 * A task may name who asked for it and when it expires: the requester is one of the set's people, the expiry a
	 * date the loader resolves from the install day.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/example-set-task-requester-and-expiry/specs/example-sets/spec.md#requirement-a-fragment-may-give-a-task-its-requester-and-expiry
	 */
	public function testATaskKeepsItsRequesterAndExpiry(): void {
		$this->caseWithTasks(tasks: [
			['key' => 'hoorzitting', 'data' => [
				'title' => 'Hoorzitting inplannen', 'objectUuid' => '{{object:case-1.id}}', 'assignee' => '{{cast:esra.uid}}',
				'requester' => '{{cast:sanne.uid}}', 'dueAt' => '{{date:5}}', 'expiresAt' => '{{datetime:9 17:00}}',
			]],
		]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$import = $this->taskImports[0]['data'];
		$this->assertSame(expected: 'sanne.devries', actual: $import['requester']);
		$this->assertSame(expected: '2026-10-22 17:00', actual: (new \DateTime($import['expiresAt']))->format('Y-m-d H:i'));
	}//end testATaskKeepsItsRequesterAndExpiry()

	public function testATaskThatDisappearedIsReportedMissing(): void {
		$this->caseWithTasks([['key' => 't', 'data' => ['title' => 'Taak', 'objectUuid' => '{{object:case-1.id}}']]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());

		$this->engineTasks = [];
		$report = $this->installer->install(plan: $plan, today: $this->today());

		$this->assertContains('task alpha/t', $report['missing']);
		$this->assertFalse($report['ok']);
	}

	/**
	 * A refresh that finds the recorded task gone but a task with the same key in the engine adopts that task and
	 * points the record at it, instead of writing a second task with the key.
	 *
	 * @return void
	 */
	public function testARefreshAdoptsTheTaskThatHoldsTheKey(): void {
		$this->caseWithTasks(tasks: [['key' => 't', 'data' => ['title' => 'Taak', 'objectUuid' => '{{object:case-1.id}}']]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$task = array_values($this->engineTasks)[0];
		$task->setUuid('task-elsewhere');
		$this->engineTasks = ['task-elsewhere' => $task];

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['task-elsewhere'], actual: array_keys($this->engineTasks), message: 'no second task with the key');
		$this->assertCount(expectedCount: 1, haystack: $this->taskImports);
		$this->assertNotContains(needle: 'task alpha/t', haystack: $report['missing']);
	}//end testARefreshAdoptsTheTaskThatHoldsTheKey()

	/**
	 * @dataProvider refusedTasks
	 *
	 * @param array<string, mixed> $data   The task's fields.
	 * @param string               $reason A part of the refusal.
	 */
	public function testACheckRefusesAFaultyTaskAndWritesNothing(array $data, string $reason): void {
		$this->caseWithTasks([['key' => 'bad', 'data' => $data]]);

		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(1, $refusals);
		$this->assertStringContainsString('task "bad"', $refusals[0]);
		$this->assertStringContainsString($reason, $refusals[0]);
		$this->assertSame([], $this->engineTasks);
	}

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedTasks(): array {
		$on = '{{object:case-1.id}}';
		return [
			'no title'         => [['objectUuid' => $on], 'needs a title'],
			'no object'        => [['title' => 'T'], 'needs the object'],
			'a stranger'       => [['title' => 'T', 'objectUuid' => $on, 'assignee' => 'somebody.else'], 'assignee "somebody.else"'],
			'an unknown state' => [['title' => 'T', 'objectUuid' => $on, 'state' => 'half-done'], "Unmapped task status 'half-done'"],
			'not a date'       => [['title' => 'T', 'objectUuid' => $on, 'dueAt' => 'soon'], 'dueAt "soon" is not a date'],
			'another field'    => [['title' => 'T', 'objectUuid' => $on, 'runUuid' => 'x'], 'field "runUuid"'],
			'a stranger asked for it' => [['title' => 'T', 'objectUuid' => $on, 'requester' => 'somebody.else'], 'requester "somebody.else"'],
			'an unknown requester' => [['title' => 'T', 'objectUuid' => $on, 'requester' => '{{cast:nobody.uid}}'], 'cannot resolve'],
			'an expiry that is not a date' => [['title' => 'T', 'objectUuid' => $on, 'expiresAt' => 'later'], 'expiresAt "later" is not a date'],
			'a malformed expiry token' => [['title' => 'T', 'objectUuid' => $on, 'expiresAt' => '{{datetime:9}}'], 'cannot resolve'],
			'an expiry before the due date' => [
				['title' => 'T', 'objectUuid' => $on, 'dueAt' => '{{date:9}}', 'expiresAt' => '{{date:5}}'],
				'expiresAt lies before dueAt',
			],
			'an unknown object' => [['title' => 'T', 'objectUuid' => '{{object:nope.id}}'], 'cannot resolve'],
			'an external task for an account' => [
				['title' => 'T', 'objectUuid' => $on, 'performerType' => 'external', 'assignee' => '{{cast:sanne.uid}}'],
				'assigned to a party',
			],
			'an external task marked done' => [
				['title' => 'T', 'objectUuid' => $on, 'performerType' => 'external', 'assignee' => 'party:x', 'completedBy' => '{{cast:esra.uid}}'],
				'completed by its party',
			],
			'upload rules on an internal task' => [
				['title' => 'T', 'objectUuid' => $on, 'uploadRules' => ['maxFiles' => 1]],
				'external task only',
			],
			'a checklist that is not a list' => [['title' => 'T', 'objectUuid' => $on, 'checklist' => 'bel de commissie'], 'checklist must be a list'],
			'a checklist item without a label' => [['title' => 'T', 'objectUuid' => $on, 'checklist' => [['id' => 'a']]], 'an id and a label'],
			'a checklist item id used twice' => [
				['title' => 'T', 'objectUuid' => $on, 'checklist' => [['id' => 'a', 'label' => 'A'], ['id' => 'a', 'label' => 'B']]],
				'id "a" is used twice',
			],
			'a checklist item checked as text' => [
				['title' => 'T', 'objectUuid' => $on, 'checklist' => [['id' => 'a', 'label' => 'A', 'checked' => 'yes']]],
				'checked is true or false',
			],
			'an unknown upload rule' => [
				['title' => 'T', 'objectUuid' => $on, 'performerType' => 'external', 'assignee' => 'party:x', 'uploadRules' => ['virusScan' => true]],
				'upload rule "virusScan"',
			],
		];
	}

	public function testATaskKeyUsedTwiceRefusesTheFragment(): void {
		$task = ['key' => 'same', 'data' => ['title' => 'T', 'objectUuid' => '{{object:case-1.id}}']];
		$this->caseWithTasks([$task, $task]);

		$this->expectExceptionMessage('task key "same" is used twice');
		$this->catalogue->gather(set: 'demo');
	}
}//end class
