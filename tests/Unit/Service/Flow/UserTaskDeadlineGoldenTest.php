<?php

/**
 * Characterisation test for the timer a user-task step arms.
 *
 * Pins which SLA shapes arm a timer at all, the exact timer config (only the
 * optional keys the author set are forwarded) and the acting identity it is
 * armed as, so the arming can move into smaller units without changing the
 * deadline a task gets.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowTimer;
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
 * Golden timer configs for a user-task deadline.
 */
class UserTaskDeadlineGoldenTest extends TestCase {

	/**
	 * Every arm() call: [config, actor].
	 *
	 * @var array<int, array{0: array<string, mixed>, 1: string|null}>
	 */
	private array $armed = [];

	/**
	 * Fire a fresh user-task step with the given config and context extras.
	 *
	 * @param array<string, mixed> $config The step config.
	 * @param array<string, mixed> $extra  Extra context keys.
	 *
	 * @return void
	 */
	private function fire(array $config, array $extra=[]): void {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$task   = new Task();
		$task->setUuid('t-1');
		$task->setState(Task::STATE_ACTIVE);
		$task->setIsTerminal(false);
		$task->setPerformerType(Task::PERFORMER_USER);
		$task->setAssignee('alice');
		$bridge->method('createTask')->willReturn($task);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));
		$timers = $this->createMock(FlowTimerService::class);
		$timers->method('arm')->willReturnCallback(
			function (array $config, ?string $actor=null): FlowTimer {
				$this->armed[] = [$config, $actor];
				return new FlowTimer();
			}
		);

		$node  = new UserTaskNode(new UserTaskServices($bridge, $forms, $timers), new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class)));
		$state = new FlowResumeState();
		try {
			$node->execute(new FlowNodeCall(
				items: [FlowItems::item(json: ['name' => 'Case 7'])],
				config: array_merge(['title' => 'Approve', 'assignee' => 'alice'], $config),
				context: array_merge(
					[
						FlowResumeState::CONTEXT_KEY => $state,
						FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'),
						FlowRunContext::CONTEXT_RUN => 'run-1',
						'runUuid' => 'run-1',
					],
					$extra
				)
			));
			$this->fail('a fresh user task suspends');
		} catch (FlowSuspension $suspension) {
			// The normal outcome of a fresh task.
		}

	}//end fire()

	/**
	 * A missing, null, empty-string or empty-array SLA arms nothing.
	 *
	 * @return void
	 */
	public function testAnAbsentSlaArmsNothing(): void {
		$this->fire(config: []);
		$this->fire(config: ['sla' => null]);
		$this->fire(config: ['sla' => '']);
		$this->fire(config: ['sla' => []]);

		$this->assertSame([], $this->armed);

	}//end testAnAbsentSlaArmsNothing()

	/**
	 * Only the optional keys the author set (not null, not '') reach the timer.
	 *
	 * @return void
	 */
	public function testOnlySetOptionalKeysAreForwarded(): void {
		$this->fire(
			config: [
				'title' => '  Review  ',
				'sla' => 'P5D',
				'calendar' => null,
				'ladder' => '',
				'escalationRules' => [],
				'purpose' => 'case-handling',
				'legalEffect' => false,
				'onExpiry' => 'escalate',
				'organisation' => 0,
				'unrelated' => 'x',
			],
			extra: ['runAs' => 'owner']
		);

		$this->assertSame(
			[
				[
					[
						'subjectType' => 'task',
						'subjectUuid' => 't-1',
						'runUuid' => 'run-1',
						'nodeId' => 'ask',
						'appId' => 'openregister',
						'title' => 'Review',
						'sla' => 'P5D',
						'escalationRules' => [],
						'purpose' => 'case-handling',
						'legalEffect' => false,
						'onExpiry' => 'escalate',
						'organisation' => 0,
					],
					'owner',
				],
			],
			$this->armed
		);

	}//end testOnlySetOptionalKeysAreForwarded()

	/**
	 * A zero SLA is a declared SLA; with no acting identity the actor is null.
	 *
	 * @return void
	 */
	public function testAZeroSlaArmsWithoutAnActor(): void {
		$this->fire(config: ['sla' => 0]);

		$this->assertCount(1, $this->armed);
		$this->assertSame(0, $this->armed[0][0]['sla']);
		$this->assertNull($this->armed[0][1]);

	}//end testAZeroSlaArmsWithoutAnActor()
}//end class
