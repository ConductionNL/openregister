<?php

/**
 * A fragment may put an adaptive case plan on its objects: checked before
 * anything is written, brought in as the fragment's app once, proved, and the
 * items it created taken away on remove.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/specs/example-sets/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\Task;

/**
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetCasePlans
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetEngineKinds
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetInstaller
 * @covers \OCA\OpenRegister\Service\ExampleSet\ExampleSetRemover
 */
class ExampleSetCasePlansTest extends ExampleSetTestCase {
	/**
	 * A case register and the cast.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store->offer(register: 'cases', schema: 'case');
		$this->app(app: 'openregister', fragment: $this->castFile());
	}

	/**
	 * One case and the plans given.
	 *
	 * @param array<int, array<string, mixed>> $rows The fragment's plan rows.
	 *
	 * @return void
	 */
	private function caseWithPlans(array $rows): void {
		$this->app(app: 'alpha', fragment: [
			'objects'   => [['key' => 'case-1', 'register' => 'cases', 'schema' => 'case', 'data' => ['title' => 'Zaak']]],
			'casePlans' => $rows,
		]);
	}//end caseWithPlans()

	/**
	 * A Woo plan: a reached milestone, an active task, an optional one and one waiting.
	 *
	 * @return array<string, mixed>
	 */
	private static function plan(): array {
		return [
			'object'     => '{{object:case-1.id}}',
			'definition' => [
				'items' => [[
					'key' => 'behandeling', 'type' => 'stage', 'state' => 'active', 'children' => [
						['key' => 'verzoek-compleet', 'type' => 'milestone', 'state' => 'completed'],
						['key' => 'documenten-beoordelen', 'type' => 'humanTask', 'state' => 'active'],
						['key' => 'zienswijze-derde', 'type' => 'humanTask', 'state' => 'available', 'discretionary' => true],
						['key' => 'besluit-opstellen', 'type' => 'humanTask', 'state' => 'available'],
					],
				]],
			],
			'history'    => [['item' => 'verzoek-compleet', 'to' => 'completed', 'at' => '2026-10-04T10:00:00+02:00']],
		];
	}//end plan()

	/**
	 * The plan is brought in as the app once, and its items go on remove.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function testAPlanIsBroughtInOnceAndTakenAway(): void {
		$this->caseWithPlans(rows: [['key' => 'woo-plan', 'data' => self::plan()]]);
		$plan = $this->catalogue->gather(set: 'demo');

		$this->assertSame(expected: [], actual: $this->installer->check(plan: $plan, today: $this->today())['refusals']);
		$this->assertSame(expected: [], actual: $this->caseItems, message: 'the check writes no item');

		$report = $this->installer->install(plan: $plan, today: $this->today());
		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertSame(expected: ['alpha'], actual: $this->casePlanActors);
		$states = array_map(static fn (CaseItem $item): string => (string) $item->getState(), array_values($this->caseItems));
		$this->assertSame(expected: ['active', 'completed', 'active', 'available', 'available'], actual: $states);

		$this->installer->install(plan: $plan, today: $this->today());
		$this->assertCount(expectedCount: 5, haystack: $this->caseItems, message: 'a kept plan is brought in once');

		$removed = $this->remover->remove(set: 'demo');
		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->caseItems);
	}//end testAPlanIsBroughtInOnceAndTakenAway()

	/**
	 * A refresh brings the plan's items back to the states the declaration gives them: an item that moved on since
	 * (a cascade that activated it) is put back.
	 *
	 * @return void
	 */
	public function testARefreshPutsThePlanBackInItsDeclaredStates(): void {
		$this->caseWithPlans(rows: [['key' => 'woo-plan', 'data' => self::plan()]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		foreach ($this->caseItems as $item) {
			if ($item->getItemKey() === 'besluit-opstellen') {
				$item->setState('active');
			}
		}

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue($report['ok'], (string) json_encode($report));
		$this->assertSame('refreshed', $report['casePlans']['alpha/woo-plan']);
		$states = [];
		foreach ($this->caseItems as $item) {
			$states[$item->getItemKey()] = $item->getState();
		}

		$this->assertSame('available', $states['besluit-opstellen']);
		$this->assertCount(5, $this->caseItems);
	}//end testARefreshPutsThePlanBackInItsDeclaredStates()

	/**
	 * A refresh takes away the task an item was realised by when it puts the item back: a plan item the set
	 * returns to `available` must not leave its old task in the case's task list (710d44a4 "Besluit opstellen",
	 * whose entry criterion is still unmet).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function testARefreshTakesAwayTheTaskOfAnItemItPutsBack(): void {
		$this->caseWithPlans(rows: [['key' => 'woo-plan', 'data' => self::plan()]]);
		$plan = $this->catalogue->gather(set: 'demo');
		$this->installer->install(plan: $plan, today: $this->today());
		$this->realiseAsTask(itemKey: 'besluit-opstellen', taskUuid: 'task-besluit');

		$report = $this->installer->install(plan: ($plan + ['refresh' => true]), today: $this->today());

		$this->assertTrue(condition: $report['ok'], message: (string) json_encode($report));
		$this->assertArrayNotHasKey(key: 'task-besluit', array: $this->engineTasks, message: 'the realisation of the item put back is gone');
	}//end testARefreshTakesAwayTheTaskOfAnItemItPutsBack()

	/**
	 * Removing the set takes away the tasks its plan items were realised by.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-fragment-may-put-a-case-plan-on-its-objects
	 */
	public function testRemovingTheSetTakesAwayTheTasksItsItemsMade(): void {
		$this->caseWithPlans(rows: [['key' => 'woo-plan', 'data' => self::plan()]]);
		$this->installer->install(plan: $this->catalogue->gather(set: 'demo'), today: $this->today());
		$this->realiseAsTask(itemKey: 'besluit-opstellen', taskUuid: 'task-besluit');

		$removed = $this->remover->remove(set: 'demo');

		$this->assertSame(expected: [], actual: $removed['failed']);
		$this->assertSame(expected: [], actual: $this->engineTasks);
	}//end testRemovingTheSetTakesAwayTheTasksItsItemsMade()

	/**
	 * Activate an item as a cascade would: the item is realised by a task.
	 *
	 * @param string $itemKey  The plan item.
	 * @param string $taskUuid The task's uuid.
	 *
	 * @return void
	 */
	private function realiseAsTask(string $itemKey, string $taskUuid): void {
		$task = new Task();
		$task->setUuid($taskUuid);
		$task->setTitle('Besluit opstellen');
		$this->engineTasks[$taskUuid] = $task;
		foreach ($this->caseItems as $item) {
			if ($item->getItemKey() === $itemKey) {
				$item->setState('active');
				$item->setRealisationKind(CaseItem::REALISATION_TASK);
				$item->setRealisationUuid($taskUuid);
			}
		}
	}//end realiseAsTask()

	/**
	 * A faulty plan is refused at check time and nothing is written.
	 *
	 * @param array<string, mixed> $data   The row's fields.
	 * @param string               $reason A part of the refusal.
	 *
	 * @return void
	 *
	 * @dataProvider refusedPlans
	 */
	public function testACheckRefusesAFaultyPlan(array $data, string $reason): void {
		$this->caseWithPlans(rows: [['key' => 'bad', 'data' => $data]]);
		$refusals = $this->installer->check(plan: $this->catalogue->gather(set: 'demo'), today: $this->today())['refusals'];

		$this->assertCount(expectedCount: 1, haystack: $refusals);
		$this->assertStringContainsString(needle: $reason, haystack: $refusals[0]);
		$this->assertSame(expected: [], actual: $this->caseItems);
	}//end testACheckRefusesAFaultyPlan()

	/**
	 * The faulty plans and a part of their refusal.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function refusedPlans(): array {
		return [
			'no items'         => [['object' => 'c', 'definition' => ['items' => []]], 'definition.items'],
			'no object'        => [['definition' => self::plan()['definition']], 'needs the object'],
			'history not a list' => [[...self::plan(), 'history' => ['a' => 1]], 'history is a list'],
			'an unknown field' => [[...self::plan(), 'flow' => 'x'], 'field "flow"'],
		];
	}//end refusedPlans()
}//end class
