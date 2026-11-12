<?php

/**
 * The core flow-node bundles hand out their nodes in registration order.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener\FlowNodes
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

namespace OCA\OpenRegister\Tests\Unit\Listener\FlowNodes;

use OCA\OpenRegister\Listener\FlowNodes\ActionFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\BoundaryFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CaseFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\CoreFlowNodes;
use OCA\OpenRegister\Listener\FlowNodes\WaitFlowNodes;
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
use PHPUnit\Framework\TestCase;

/**
 * Each bundle returns exactly its nodes, once each, in the documented order.
 */
class CoreFlowNodeBundleOrderTest extends TestCase {
	/**
	 * Named node doubles, keyed by the constructor parameter they fill.
	 *
	 * @var array<string, IFlowNode>
	 */
	private array $nodes = [];

	/**
	 * A distinct node double for the given interface, remembered under its name.
	 *
	 * @param string $name      The constructor parameter name.
	 * @param string $interface The node interface to double.
	 *
	 * @return IFlowNode The double.
	 */
	private function node(string $name, string $interface): IFlowNode {
		$this->nodes[$name] = $this->createMock($interface);
		return $this->nodes[$name];
	}

	/**
	 * The nodes registered under the given names, in that order.
	 *
	 * @param list<string> $names The parameter names.
	 *
	 * @return list<IFlowNode> The doubles.
	 */
	private function pick(array $names): array {
		return array_map(fn (string $name): IFlowNode => $this->nodes[$name], $names);
	}

	private function dataShaping(): DataShapingFlowNodes {
		return new DataShapingFlowNodes(
			new FieldFlowNodes($this->node('setFields', SetFieldsNodeInterface::class), $this->node('actionInputs', ActionInputsNodeInterface::class)),
			new ItemListFlowNodes($this->node('explode', ExplodeNodeInterface::class), $this->node('filter', FilterNodeInterface::class)),
		);
	}

	private function waitAndBranch(): WaitAndBranchFlowNodes {
		return new WaitAndBranchFlowNodes(
			new WaitFlowNodes($this->node('wait', WaitNodeInterface::class), $this->node('awaitSignal', AwaitSignalNodeInterface::class)),
			new BranchFlowNodes($this->node('switch', SwitchNodeInterface::class), $this->node('end', EndNodeInterface::class)),
		);
	}

	private function routing(): RoutingFlowNodes {
		return new RoutingFlowNodes(
			new ItemLoopFlowNodes($this->node('merge', MergeNodeInterface::class), $this->node('loop', LoopNodeInterface::class)),
			new HandOffFlowNodes($this->node('subFlow', SubFlowNodeInterface::class), $this->node('router', RouterNodeInterface::class)),
		);
	}

	private function objects(): ObjectFlowNodes {
		return new ObjectFlowNodes(
			new ObjectChangeFlowNodes($this->node('objectWrite', ObjectWriteNodeInterface::class), $this->node('transition', TransitionNodeInterface::class)),
			new ObjectLookupFlowNodes($this->node('linkObject', LinkObjectNodeInterface::class), $this->node('objectRead', ObjectReadNodeInterface::class)),
		);
	}

	private function reshaping(): ReshapingFlowNodes {
		return new ReshapingFlowNodes(
			new DataMappingFlowNodes($this->node('flowState', FlowStateNodeInterface::class), $this->node('map', MapNodeInterface::class)),
			new EvaluationFlowNodes($this->node('iterate', IterateNodeInterface::class), $this->node('decisionTable', DecisionTableNodeInterface::class)),
		);
	}

	private function messaging(): MessagingFlowNodes {
		return new MessagingFlowNodes(
			$this->node('sendNotification', SendNotificationNodeInterface::class),
			$this->node('sendEmail', SendEmailNodeInterface::class),
			$this->node('sendTalkMessage', SendTalkMessageNodeInterface::class),
		);
	}

	private function humanTasks(): HumanTaskFlowNodes {
		return new HumanTaskFlowNodes(
			$this->node('userTask', UserTaskNodeInterface::class),
			$this->node('portalTask', PortalTaskNodeInterface::class),
		);
	}

	private function locks(): LockFlowNodes {
		return new LockFlowNodes(
			$this->node('lockObject', LockObjectNodeInterface::class),
			$this->node('unlockObject', UnlockObjectNodeInterface::class),
		);
	}

	private function cases(): CaseFlowNodes {
		return new CaseFlowNodes(
			$this->node('caseOpen', CaseOpenNodeInterface::class),
			$this->node('caseAdvance', CaseAdvanceNodeInterface::class),
		);
	}

	private function triggers(): TriggerFlowNodes {
		return new TriggerFlowNodes(
			$this->node('triggerObject', TriggerObjectNodeInterface::class),
			$this->node('triggerSchedule', TriggerScheduleNodeInterface::class),
			$this->node('triggerManual', TriggerManualNodeInterface::class),
		);
	}

	public function testDataShapingListsSetFieldsActionInputsExplodeFilter(): void {
		$nodes = $this->dataShaping()->nodes();
		$this->assertSame($this->pick(['setFields', 'actionInputs', 'explode', 'filter']), $nodes);
	}

	public function testWaitAndBranchListsWaitAwaitSignalSwitchEnd(): void {
		$nodes = $this->waitAndBranch()->nodes();
		$this->assertSame($this->pick(['wait', 'awaitSignal', 'switch', 'end']), $nodes);
	}

	public function testRoutingListsMergeLoopSubFlowRouter(): void {
		$nodes = $this->routing()->nodes();
		$this->assertSame($this->pick(['merge', 'loop', 'subFlow', 'router']), $nodes);
	}

	public function testObjectsListWriteTransitionLinkRead(): void {
		$nodes = $this->objects()->nodes();
		$this->assertSame($this->pick(['objectWrite', 'transition', 'linkObject', 'objectRead']), $nodes);
	}

	public function testCoreFlattensShapingBranchingRoutingThenObjects(): void {
		$core = new CoreFlowNodes(
			new ItemHandlingFlowNodes($this->dataShaping(), $this->waitAndBranch()),
			new DestinationFlowNodes($this->routing(), $this->objects()),
		);

		$this->assertSame(
			$this->pick([
				'setFields', 'actionInputs', 'explode', 'filter',
				'wait', 'awaitSignal', 'switch', 'end',
				'merge', 'loop', 'subFlow', 'router',
				'objectWrite', 'transition', 'linkObject', 'objectRead',
			]),
			$core->nodes()
		);
	}
}
