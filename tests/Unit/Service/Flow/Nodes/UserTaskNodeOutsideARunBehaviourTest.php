<?php

/**
 * UserTaskNode: an ask outside a persisted run is refused before any task is created.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
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
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\TaskConfigCommon
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskConfig
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskHooks
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskPerformers
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskServices
 * @uses \OCA\OpenRegister\Service\Flow\Nodes\UserTaskStep
 * @uses \OCA\OpenRegister\Service\Task\TaskForm
 * @uses \OCA\OpenRegister\Service\Task\TaskFormFields
 */
class UserTaskNodeOutsideARunBehaviourTest extends TestCase {

	public function testAnAskOutsideAPersistedRunCreatesNothing(): void {
		$bridge = $this->createMock(FlowTaskBridge::class);
		$bridge->expects(self::never())->method('createTask');
		$forms = $this->createMock(TaskFormReader::class);
		$forms->method('fromConfig')->willReturn(new TaskForm(kind: null));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$node = new UserTaskNode(
			new UserTaskServices($bridge, $forms, $this->createMock(FlowTimerService::class)),
			new FlowNodePresentation($l10n, $this->createMock(IURLGenerator::class))
		);
		$state = new FlowResumeState();

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('openregister.user-task cannot create a task outside a persisted run');

		$node->execute(new FlowNodeCall(
			items: [FlowItems::item(json: ['name' => 'Case 7'])],
			config: ['title' => 'Approve {{ name }}', 'assignee' => 'alice'],
			context: [FlowResumeState::CONTEXT_KEY => $state, FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'ask'), 'runUuid' => ' ', 'runAs' => 'owner']
		));
	}//end testAnAskOutsideAPersistedRunCreatesNothing()
}//end class
