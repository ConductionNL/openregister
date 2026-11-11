<?php

/**
 * The flow-node registration listener registers every built-in in order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Listener\FlowNodeRegistrationListener;
use OCA\OpenRegister\Listener\FlowNodes\ActionFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\BoundaryFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CaseFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CoreFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\WaitFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\OutputFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ObjectLookupFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ObjectChangeFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ItemLoopFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ItemListFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\ItemHandlingFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\HandOffFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\FieldFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\EvaluationFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\DestinationFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\DataMappingFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CoordinationFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\BranchFlowNodes;
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
use OCA\OpenRegister\Service\Flow\Nodes\ActionInputsNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\AwaitSignalNodeInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseAdvanceNodeInterface;
use OCA\OpenRegister\Service\Case\Nodes\CaseOpenNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\DecisionTableNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\EndNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ExplodeNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FilterNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\FlowStateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\IterateNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LinkObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LockObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\LoopNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\MapNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\MergeNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectReadNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\ObjectWriteNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\PortalTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\RouterNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendEmailNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendNotificationNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SendTalkMessageNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SetFieldsNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\SwitchNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TransitionNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerManualNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\TriggerScheduleNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UnlockObjectNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNodeInterface;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Core first, then actions, then the boundary nodes; foreign events are ignored.
 */
class FlowNodeBuiltInRegistrationTest extends TestCase {
	/**
	 * The node doubles in the order the listener must register them.
	 *
	 * @var list<IFlowNode>
	 */
	private array $expected = [];

	/**
	 * Build a fresh node double and remember it in registration order.
	 *
	 * @param string $interface The node interface.
	 *
	 * @return IFlowNode The double.
	 */
	private function node(string $interface): IFlowNode {
		$node             = $this->createMock($interface);
		$this->expected[] = $node;
		return $node;
	}

	private function listener(): FlowNodeRegistrationListener {
		$core = new CoreFlowNodes(
			new ItemHandlingFlowNodes(new DataShapingFlowNodes(
				new FieldFlowNodes($this->node(SetFieldsNodeInterface::class), $this->node(ActionInputsNodeInterface::class)),
				new ItemListFlowNodes($this->node(ExplodeNodeInterface::class), $this->node(FilterNodeInterface::class)),
			), new WaitAndBranchFlowNodes(
				new WaitFlowNodes($this->node(WaitNodeInterface::class), $this->node(AwaitSignalNodeInterface::class)),
				new BranchFlowNodes($this->node(SwitchNodeInterface::class), $this->node(EndNodeInterface::class)),
			)),
			new DestinationFlowNodes(new RoutingFlowNodes(
				new ItemLoopFlowNodes($this->node(MergeNodeInterface::class), $this->node(LoopNodeInterface::class)),
				new HandOffFlowNodes($this->node(SubFlowNodeInterface::class), $this->node(RouterNodeInterface::class)),
			), new ObjectFlowNodes(
				new ObjectChangeFlowNodes($this->node(ObjectWriteNodeInterface::class), $this->node(TransitionNodeInterface::class)),
				new ObjectLookupFlowNodes($this->node(LinkObjectNodeInterface::class), $this->node(ObjectReadNodeInterface::class)),
			)),
		);
		$actions = new ActionFlowNodes(
			new OutputFlowNodes(new ReshapingFlowNodes(
				new DataMappingFlowNodes($this->node(FlowStateNodeInterface::class), $this->node(MapNodeInterface::class)),
				new EvaluationFlowNodes($this->node(IterateNodeInterface::class), $this->node(DecisionTableNodeInterface::class)),
			), new MessagingFlowNodes(
				$this->node(SendNotificationNodeInterface::class),
				$this->node(SendEmailNodeInterface::class),
				$this->node(SendTalkMessageNodeInterface::class),
			)),
			new CoordinationFlowNodes(new HumanTaskFlowNodes(
				$this->node(UserTaskNodeInterface::class),
				$this->node(PortalTaskNodeInterface::class),
			), new LockFlowNodes(
				$this->node(LockObjectNodeInterface::class),
				$this->node(UnlockObjectNodeInterface::class),
			)),
		);
		$boundary = new BoundaryFlowNodes(
			new CaseFlowNodes(
				$this->node(CaseOpenNodeInterface::class),
				$this->node(CaseAdvanceNodeInterface::class),
			),
			new TriggerFlowNodes(
				$this->node(TriggerObjectNodeInterface::class),
				$this->node(TriggerScheduleNodeInterface::class),
				$this->node(TriggerManualNodeInterface::class),
			),
		);

		return new FlowNodeRegistrationListener($core, $actions, $boundary);
	}

	public function testRegistersEveryBuiltInOnceInCoreActionBoundaryOrder(): void {
		$listener   = $this->listener();
		$registered = [];
		$registry   = $this->createMock(FlowNodeRegistry::class);
		$registry->expects($this->exactly(count($this->expected)))
			->method('register')
			->willReturnCallback(function (IFlowNode $node) use (&$registered): void {
				$registered[] = $node;
			});

		$listener->handle(new RegisterFlowNodesEvent($registry));

		$this->assertCount(32, $registered);
		$this->assertSame($this->expected, $registered);
	}

	public function testIgnoresAnEventThatIsNotTheRegistrationEvent(): void {
		$listener = $this->listener();
		// A look-alike that would accept nodes if the type guard were skipped.
		$foreign = new class extends Event {
			/** @var list<IFlowNode> */
			public array $received = [];

			public function registerNode(IFlowNode $node): void {
				$this->received[] = $node;
			}
		};

		$listener->handle($foreign);

		$this->assertSame([], $foreign->received);
	}
}
