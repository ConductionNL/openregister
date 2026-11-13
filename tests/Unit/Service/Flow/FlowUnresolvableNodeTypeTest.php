<?php

/**
 * An unresolvable flow node type fails its step visibly (flow-engine-unification task 1.8): a bare id such as `step` for the catalogue's `acme.step` ends the run as failed with the reason, and is never skipped as a no-op that reports success.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItemOptions;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunStart;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/**
 * A node that changes observable state: it counts its executions.
 */
class CountingStepNode implements IFlowNode {

	public int $executions = 0;

	public function getId(): string {
		return 'acme.step';
	}

	public function getDisplayName(): string {
		return 'Step';
	}

	public function getDescription(): string {
		return 'Counts its executions.';
	}

	public function getIcon(): string {
		return '';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(FlowNodeCall $call): array {
		$this->executions++;
		$out = [];
		foreach ($call->items as $index => $item) {
			$json = (array)($item['json'] ?? []);
			$json['counted'] = $this->executions;
			$out[] = FlowItems::item(json: $json, options: new FlowItemOptions(binary: [], fromItemIndex: $index));
		}

		return $out;
	}
}

/**
 * Marking subject for the walk.
 */
class UnresolvableNodeSubject {

	public $marking = [];
}

/**
 * @covers \OCA\OpenRegister\Service\Flow\RegistryStepDispatcher
 * @covers \OCA\OpenRegister\Service\Flow\FlowNodeRegistry
 * @covers \OCA\OpenRegister\Service\Flow\FlowEngine
 * @uses \OCA\OpenRegister\Service\Flow\EdgeArrival
 * @uses \OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder
 * @uses \OCA\OpenRegister\Service\Flow\FlowEngineServices
 * @uses \OCA\OpenRegister\Service\Flow\FlowExitChoice
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpression
 * @uses \OCA\OpenRegister\Service\Flow\FlowExpressionDataOptions
 * @uses \OCA\OpenRegister\Service\Flow\FlowGraph
 * @uses \OCA\OpenRegister\Service\Flow\FlowHop
 * @uses \OCA\OpenRegister\Service\Flow\FlowHopAdvance
 * @uses \OCA\OpenRegister\Service\Flow\FlowHopPlan
 * @uses \OCA\OpenRegister\Service\Flow\FlowHopRouting
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemOptions
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemPlacement
 * @uses \OCA\OpenRegister\Service\Flow\FlowItemShape
 * @uses \OCA\OpenRegister\Service\Flow\FlowItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeCall
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeDeclarations
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeExits
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeTaxonomyResolver
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunGuard
 * @uses \OCA\OpenRegister\Service\Flow\FlowRunStart
 * @uses \OCA\OpenRegister\Service\Flow\FlowStepOutput
 * @uses \OCA\OpenRegister\Service\Flow\FlowStepReport
 * @uses \OCA\OpenRegister\Service\Flow\FlowSubjectItems
 * @uses \OCA\OpenRegister\Service\Flow\FlowTokenRouter
 * @uses \OCA\OpenRegister\Service\Flow\FlowWalkHop
 * @uses \OCA\OpenRegister\Service\Flow\FlowWalkPass
 * @uses \OCA\OpenRegister\Service\Flow\ItemFiring
 * @uses \OCA\OpenRegister\Service\Flow\ItemSeed
 * @uses \OCA\OpenRegister\Service\Flow\LoweredFlow
 * @uses \OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent
 * @uses \OCA\OpenRegister\Service\Flow\StepDispatch
 */
class FlowUnresolvableNodeTypeTest extends TestCase {

	private CountingStepNode $node;

	private FlowEngine $engine;

	private RegistryStepDispatcher $dispatcher;

	protected function setUp(): void {
		$this->node = new CountingStepNode();
		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(
			function (RegisterFlowNodesEvent $event): void {
				$event->registerNode($this->node);
			}
		);
		$registry = new FlowNodeRegistry($events, $this->createMock(LoggerInterface::class));
		$this->dispatcher = new RegistryStepDispatcher(registry: $registry);
		$this->engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));
	}

	private function runFlow(string $firstType): array {
		$flow = [
			'id' => 'palette-flow',
			'nodes' => [
				['id' => 'first', 'type' => $firstType],
				['id' => 'second', 'type' => 'acme.step'],
			],
			'edges' => [['id' => 'first-second', 'from' => 'first', 'to' => 'second']],
		];

		return $this->engine->run(
			start: new FlowRunStart(
				flow: $flow,
				store: new MethodMarkingStore(false, 'marking'),
				subject: new UnresolvableNodeSubject(),
				dispatcher: $this->dispatcher
			)
		);
	}

	public function testANamespacedPaletteNodeRunsAndChangesState(): void {
		$result = $this->runFlow('acme.step');

		$this->assertSame(FlowEngine::STATUS_COMPLETED, $result['status']);
		$this->assertSame(2, $this->node->executions);
	}

	public function testABareIdFailsTheRunWithTheReasonInsteadOfBeingSkipped(): void {
		$result = $this->runFlow('step');

		$this->assertSame(FlowEngine::STATUS_FAILED, $result['status']);
		$this->assertStringContainsString('No app provides the flow node type "step"', (string)$result['error']);
		// Nothing ran: the walk stopped at the unresolvable step, it did not skip past it.
		$this->assertSame(0, $this->node->executions);
	}
}
