<?php

/**
 * A user-task step whose performer is a field of the item.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/platform-hr-workflows
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerAct;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowRunContext;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowTaskBridge;
use OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskServices;
use OCA\OpenRegister\Service\Flow\Timer\FlowTimerService;
use OCA\OpenRegister\Service\Task\TaskForm;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The task and the resume slot are given the performer the item names.
 */
class UserTaskItemPerformerNodeTest extends TestCase {

	/**
	 * The data each createTask() call was given.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $created = [];

	/**
	 * Enter a fresh user-task step for one onboarding case.
	 *
	 * @param array<string, mixed> $config The step config.
	 *
	 * @return FlowResumeState The run state after the step suspended.
	 */
	private function enter(array $config): FlowResumeState {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$task   = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('hanneke');
		$bridge->method('createTask')->willReturnCallback(
			function (array $data) use ($task): Task {
				$this->created[] = $data;
				return $task;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));

		$node  = new UserTaskNode(
			new UserTaskServices($bridge, $forms, $this->createMock(FlowTimerService::class)),
			new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class))
		);
		$state = new FlowResumeState();
		try {
			$node->execute(new FlowNodeCall(
				items: [FlowItems::item(json: ['id' => 'onb-1', 'employee' => [['managerUserId' => 'hanneke']]])],
				config: $config,
				context: [
					FlowResumeState::CONTEXT_KEY => $state,
					FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'manager-workplace'),
					FlowRunContext::CONTEXT_RUN => 'run-1',
					'runUuid' => 'run-1',
				]
			));
			$this->fail('a fresh user task suspends');
		} catch (FlowSuspension $suspension) {
			// The normal outcome of a fresh task.
		}

		return $state;
	}//end enter()

	/**
	 * The manager named through the item gets the task, and the slot remembers the same person.
	 *
	 * @return void
	 */
	public function testTheManagerOnTheItemGetsTheTask(): void {
		$state = $this->enter(config: ['title' => 'Werkplek en buddy regelen', 'assignee' => '{{ employee.0.managerUserId }}']);

		$this->assertCount(1, $this->created);
		$this->assertSame('hanneke', $this->created[0]['assignee']);
		$this->assertSame(Task::STATE_ACTIVE, $this->created[0]['state']);
		$this->assertSame('hanneke', $state->forNode(nodeId: 'manager-workplace')->get(key: 'assignee'));
	}//end testTheManagerOnTheItemGetsTheTask()

	/**
	 * A fixed assignee is given as before.
	 *
	 * @return void
	 */
	public function testAFixedAssigneeIsUnchanged(): void {
		$this->enter(config: ['title' => 'Approve', 'assignee' => 'alice']);

		$this->assertSame('alice', $this->created[0]['assignee']);
	}//end testAFixedAssigneeIsUnchanged()
}//end class
