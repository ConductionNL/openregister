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
			'another field'    => [['title' => 'T', 'objectUuid' => $on, 'requester' => 'x'], 'field "requester"'],
			'an unknown object' => [['title' => 'T', 'objectUuid' => '{{object:nope.id}}'], 'cannot resolve'],
		];
	}

	public function testATaskKeyUsedTwiceRefusesTheFragment(): void {
		$task = ['key' => 'same', 'data' => ['title' => 'T', 'objectUuid' => '{{object:case-1.id}}']];
		$this->caseWithTasks([$task, $task]);

		$this->expectExceptionMessage('task key "same" is used twice');
		$this->catalogue->gather(set: 'demo');
	}
}//end class
