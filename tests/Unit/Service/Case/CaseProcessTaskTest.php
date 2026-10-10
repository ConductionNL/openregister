<?php

/**
 * One engine, two model types: a plan item's states are the task's, and a
 * process task starts a flow.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Case
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-a-process-task-starts-a-flow
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Case;

use OCA\OpenRegister\Db\CaseItem;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCA\OpenRegister\Exception\CaseValidationException;
use OCA\OpenRegister\Service\Case\CasePlanDefinition;
use OCA\OpenRegister\Service\Case\CasePlanTransitions;
use OCA\OpenRegister\Service\Case\CaseRealisationService;
use OCA\OpenRegister\Service\Case\CaseSentryEvaluator;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Task\TaskService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Real transition table, real definition validator, real realisation
 * service over mocked task and run stores.
 */
class CaseProcessTaskTest extends TestCase {

	/**
	 * The plan item's states ARE the task's states: one declaration.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-engine-carries-both-model-types-on-a-shared-core
	 *
	 * @return void
	 */
	public function testPlanItemsAndTasksShareOneListOfStates(): void {
		$this->assertSame(Task::STATES, CaseItem::STATES);
		$this->assertSame(Task::TERMINAL_STATES, CaseItem::TERMINAL_STATES);
		$this->assertSame(['available', 'enabled', 'active', 'completed', 'terminated', 'disabled'], array_values(CaseItem::STATES));
	}//end testPlanItemsAndTasksShareOneListOfStates()

	/**
	 * A process task follows the work-item lifecycle.
	 *
	 * @return void
	 */
	public function testAProcessTaskHasTheWorkItemLifecycle(): void {
		$this->assertContains(CaseItem::TYPE_PROCESS_TASK, CaseItem::TYPES);
		$table = new CasePlanTransitions();
		foreach (CaseItem::STATES as $from) {
			$this->assertSame(
				$table->targetsFor(type: CaseItem::TYPE_HUMAN_TASK, from: $from),
				$table->targetsFor(type: CaseItem::TYPE_PROCESS_TASK, from: $from),
				$from
			);
		}
	}//end testAProcessTaskHasTheWorkItemLifecycle()

	/**
	 * A process task names its flow, which lands in the plan settings like a
	 * stage's; without a flow, or with children, it is refused.
	 *
	 * @return void
	 */
	public function testTheDefinitionBindsAProcessTaskToItsFlow(): void {
		$definitions = new CasePlanDefinition(sentries: new CaseSentryEvaluator(catalog: new EventCatalogService()));
		$normalised = $definitions->validate(
			definition: ['items' => [['key' => 'advies', 'type' => CaseItem::TYPE_PROCESS_TASK, 'name' => 'Advies', 'flow' => 'advice-flow']]]
		);
		$this->assertSame(['advies' => 'advice-flow'], $normalised['settings']['flows']);

		$refusals = [
			"'advies' is a process task and names no flow" => ['key' => 'advies', 'type' => CaseItem::TYPE_PROCESS_TASK],
			"'advies' is a processTask and cannot contain children" => [
				'key' => 'advies',
				'type' => CaseItem::TYPE_PROCESS_TASK,
				'flow' => 'advice-flow',
				'children' => [['key' => 'x', 'type' => CaseItem::TYPE_HUMAN_TASK]],
			],
		];
		foreach ($refusals as $expected => $node) {
			try {
				$definitions->validate(definition: ['items' => [$node]]);
				$this->fail('refused: ' . $expected);
			} catch (CaseValidationException $refusal) {
				$this->assertStringContainsString($expected, $refusal->getMessage());
			}
		}
	}//end testTheDefinitionBindsAProcessTaskToItsFlow()

	/**
	 * Entering active queues the bound flow on the plan's subject and records
	 * the run as the realisation, exactly as for a flow-bound stage.
	 *
	 * @return void
	 */
	public function testAProcessTaskQueuesItsFlow(): void {
		$runs = $this->createMock(FlowRunService::class);
		$run = new FlowRun();
		$run->setUuid('run-9');
		$runs->expects($this->once())->method('queue')->with(
			'advice-flow',
			['uuid' => CaseFixtures::OBJECT, 'register' => '1', 'schema' => '1'],
			CaseRealisationService::RUN_TRIGGER,
			['caseItem' => 'item-4'],
			'alice'
		)->willReturn($run);
		$tasks = $this->createMock(TaskService::class);
		$tasks->expects($this->never())->method('import');

		$item = CaseFixtures::row(id: 4, key: 'advies', type: CaseItem::TYPE_PROCESS_TASK, state: CaseItem::STATE_ENABLED);
		$item->setPlanSettings(['flows' => ['advies' => 'advice-flow']]);
		$service = new CaseRealisationService(
			tasks: $tasks,
			taskRows: $this->createMock(TaskMapper::class),
			runs: $runs,
			runRows: $this->createMock(FlowRunMapper::class),
			logger: new NullLogger()
		);
		$service->realise(item: $item, actor: 'alice');

		$this->assertSame(CaseItem::REALISATION_RUN, $item->getRealisationKind());
		$this->assertSame('run-9', $item->getRealisationUuid());
	}//end testAProcessTaskQueuesItsFlow()
}//end class
