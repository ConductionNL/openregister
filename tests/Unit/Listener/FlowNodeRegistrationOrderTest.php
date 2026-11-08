<?php

/**
 * Golden pin: the built-in flow nodes reach the registry in exactly this order.
 *
 * The listener is assembled by walking its constructor the way the container
 * does, so the pin holds whatever shape the constructor takes.
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
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * @covers \OCA\OpenRegister\Listener\FlowNodeRegistrationListener
 */
class FlowNodeRegistrationOrderTest extends TestCase {

	/**
	 * The declared type of every node mock, keyed by object id.
	 *
	 * @var array<int, string>
	 */
	private array $typeOf = [];

	/**
	 * Build a class by autowiring its constructor; node types become mocks.
	 *
	 * @param string $class The class or interface to supply.
	 *
	 * @return object The instance.
	 */
	private function build(string $class): object {
		if (is_a($class, IFlowNode::class, true) === true) {
			$mock = $this->createMock($class);
			$this->typeOf[spl_object_id($mock)] = $class;
			return $mock;
		}

		$ctor = (new ReflectionClass($class))->getConstructor();
		$args = [];
		foreach ($ctor?->getParameters() ?? [] as $param) {
			$type = $param->getType();
			$this->assertInstanceOf(ReflectionNamedType::class, $type);
			$args[] = $this->build($type->getName());
		}

		return (new ReflectionClass($class))->newInstanceArgs($args);
	}//end build()

	/**
	 * The registration order, node type by node type.
	 *
	 * @return void
	 */
	public function testBuiltInsRegisterInThisExactOrder(): void {
		$listener = $this->build(FlowNodeRegistrationListener::class);

		$registered = [];
		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('register')->willReturnCallback(
			function (IFlowNode $node) use (&$registered): void {
				$registered[] = $this->typeOf[spl_object_id($node)];
			}
		);

		$listener->handle(new RegisterFlowNodesEvent(registry: $registry));

		$ns = 'OCA\\OpenRegister\\Service\\Flow\\Nodes\\';
		$this->assertSame(
			[
				$ns . 'SetFieldsNodeInterface',
				$ns . 'ActionInputsNodeInterface',
				$ns . 'ExplodeNodeInterface',
				$ns . 'FilterNodeInterface',
				$ns . 'WaitNodeInterface',
				$ns . 'AwaitSignalNodeInterface',
				$ns . 'SwitchNodeInterface',
				$ns . 'EndNodeInterface',
				$ns . 'MergeNodeInterface',
				$ns . 'LoopNodeInterface',
				$ns . 'SubFlowNodeInterface',
				$ns . 'RouterNodeInterface',
				$ns . 'ObjectWriteNodeInterface',
				$ns . 'TransitionNodeInterface',
				$ns . 'LinkObjectNodeInterface',
				$ns . 'ObjectReadNodeInterface',
				$ns . 'FlowStateNodeInterface',
				$ns . 'MapNodeInterface',
				$ns . 'IterateNodeInterface',
				$ns . 'DecisionTableNodeInterface',
				$ns . 'SendNotificationNodeInterface',
				$ns . 'SendEmailNodeInterface',
				$ns . 'SendTalkMessageNodeInterface',
				$ns . 'UserTaskNodeInterface',
				$ns . 'PortalTaskNodeInterface',
				$ns . 'LockObjectNode',
				$ns . 'UnlockObjectNodeInterface',
				'OCA\\OpenRegister\\Service\\Case\\Nodes\\CaseOpenNodeInterface',
				'OCA\\OpenRegister\\Service\\Case\\Nodes\\CaseAdvanceNodeInterface',
				$ns . 'TriggerObjectNodeInterface',
				$ns . 'TriggerScheduleNodeInterface',
				$ns . 'TriggerManualNodeInterface',
			],
			$registered
		);
	}//end testBuiltInsRegisterInThisExactOrder()
}//end class
