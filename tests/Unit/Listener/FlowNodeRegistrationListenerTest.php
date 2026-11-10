<?php

/**
 * The node registration listener: every built-in reaches the registry, the
 * portal-task node among them, and a foreign event registers nothing.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\FlowNodeRegistrationListener;
use OCA\OpenRegister\Listener\FlowNodes\ActionFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\BoundaryFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CaseFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CoreFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\DataShapingFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\HumanTaskFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\LockFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\MessagingFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ObjectFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ReshapingFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\RoutingFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\TriggerFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\WaitAndBranchFlowNodes;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNode;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNode;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNode;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FlowNodeRegistrationListener}.
 *
 * @covers \OCA\OpenRegister\Listener\FlowNodeRegistrationListener
 * @covers \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent
 */
class FlowNodeRegistrationListenerTest extends TestCase {

	/**
	 * The listener over one mock per built-in node.
	 *
	 * @param array<int, IFlowNode> $registered Filled with what reaches the registry.
	 *
	 * @return FlowNodeRegistrationListener The listener.
	 */
	private function listener(array &$registered): FlowNodeRegistrationListener {
		$mock = fn (string $class) => $this->createMock($class);
		$listener = new FlowNodeRegistrationListener(
			core: new CoreFlowNodes(
				dataShaping: new DataShapingFlowNodes(
					setFields: $mock(SetFieldsNode::class),
					actionInputs: $mock(ActionInputsNode::class),
					explode: $mock(ExplodeNode::class),
					filter: $mock(FilterNode::class),
				),
				waitAndBranch: new WaitAndBranchFlowNodes(
					wait: $mock(WaitNode::class),
					awaitSignal: $mock(AwaitSignalNode::class),
					switch: $mock(SwitchNode::class),
					end: $mock(EndNode::class),
				),
				routing: new RoutingFlowNodes(
					merge: $mock(MergeNode::class),
					loop: $mock(LoopNode::class),
					subFlow: $mock(SubFlowNode::class),
					router: $mock(RouterNode::class),
				),
				objects: new ObjectFlowNodes(
					objectWrite: $mock(ObjectWriteNode::class),
					transition: $mock(TransitionNode::class),
					linkObject: $mock(LinkObjectNode::class),
					objectRead: $mock(ObjectReadNode::class),
				),
			),
			actions: new ActionFlowNodes(
				reshaping: new ReshapingFlowNodes(
					flowState: $mock(FlowStateNode::class),
					map: $mock(MapNode::class),
					iterate: $mock(IterateNode::class),
					decisionTable: $mock(DecisionTableNode::class),
				),
				messaging: new MessagingFlowNodes(
					sendNotification: $mock(SendNotificationNode::class),
					sendEmail: $mock(SendEmailNode::class),
					sendTalkMessage: $mock(SendTalkMessageNode::class),
				),
				humanTasks: new HumanTaskFlowNodes(
					userTask: $mock(UserTaskNode::class),
					portalTask: $mock(PortalTaskNode::class),
				),
				locks: new LockFlowNodes(
					lockObject: $mock(LockObjectNode::class),
					unlockObject: $mock(UnlockObjectNode::class),
				),
			),
			boundary: new BoundaryFlowNodes(
				cases: new CaseFlowNodes(
					caseOpen: $mock(CaseOpenNode::class),
					caseAdvance: $mock(CaseAdvanceNode::class),
				),
				triggers: new TriggerFlowNodes(
					triggerObject: $mock(TriggerObjectNode::class),
					triggerSchedule: $mock(TriggerScheduleNode::class),
					triggerManual: $mock(TriggerManualNode::class),
				),
			),
		);

		return $listener;
	}//end listener()

	/**
	 * Handling the registration event hands EVERY built-in to the registry,
	 * the three waiters among them.
	 *
	 * @return void
	 */
	public function testEveryBuiltInReachesTheRegistryPortalTaskIncluded(): void {
		$registered = [];
		$listener = $this->listener($registered);

		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('register')->willReturnCallback(
			static function (IFlowNode $node) use (&$registered): void {
				$registered[] = $node;
			}
		);

		$listener->handle(new RegisterFlowNodesEvent(registry: $registry));

		$this->assertCount(32, $registered, 'all thirty-two built-ins are registered');
		$classes = array_map(static fn (IFlowNode $node): string => get_parent_class($node) ?: get_class($node), $registered);
		foreach ([PortalTaskNode::class, UserTaskNode::class, AwaitSignalNode::class, CaseOpenNode::class, CaseAdvanceNode::class, ActionInputsNode::class, TransitionNode::class, LinkObjectNode::class] as $waiter) {
			$this->assertContains($waiter, $classes, "$waiter is registered");
		}
	}//end testEveryBuiltInReachesTheRegistryPortalTaskIncluded()

	/**
	 * A foreign event registers nothing.
	 *
	 * @return void
	 */
	public function testAForeignEventRegistersNothing(): void {
		$registered = [];
		$listener = $this->listener($registered);
		$listener->handle(new Event());
		$this->assertSame([], $registered);
	}//end testAForeignEventRegistersNothing()
}//end class
