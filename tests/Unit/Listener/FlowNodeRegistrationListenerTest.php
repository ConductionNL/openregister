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
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNode;
use OCA\OpenRegister\Service\Flow\Nodes\EndNode;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNode;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNode;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNode;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNode;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNode;
use OCA\OpenRegister\Service\Flow\Nodes\MapNode;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNode;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNode;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNode;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNode;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNode;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNode;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNode;
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
			setFields: $mock(SetFieldsNode::class),
			explode: $mock(ExplodeNode::class),
			filter: $mock(FilterNode::class),
			wait: $mock(WaitNode::class),
			awaitSignal: $mock(AwaitSignalNode::class),
			switch: $mock(SwitchNode::class),
			end: $mock(EndNode::class),
			merge: $mock(MergeNode::class),
			loop: $mock(LoopNode::class),
			subFlow: $mock(SubFlowNode::class),
			router: $mock(RouterNode::class),
			objectWrite: $mock(ObjectWriteNode::class),
			objectRead: $mock(ObjectReadNode::class),
			flowState: $mock(FlowStateNode::class),
			map: $mock(MapNode::class),
			iterate: $mock(IterateNode::class),
			sendNotification: $mock(SendNotificationNode::class),
			sendEmail: $mock(SendEmailNode::class),
			sendTalkMessage: $mock(SendTalkMessageNode::class),
			triggerObject: $mock(TriggerObjectNode::class),
			triggerSchedule: $mock(TriggerScheduleNode::class),
			triggerManual: $mock(TriggerManualNode::class),
			userTask: $mock(UserTaskNode::class),
			portalTask: $mock(PortalTaskNode::class),
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

		$this->assertCount(24, $registered, 'all twenty-four built-ins are registered');
		$classes = array_map(static fn (IFlowNode $node): string => get_parent_class($node) ?: get_class($node), $registered);
		foreach ([PortalTaskNode::class, UserTaskNode::class, AwaitSignalNode::class] as $waiter) {
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
