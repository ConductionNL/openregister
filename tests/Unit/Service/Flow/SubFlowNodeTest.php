<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\Nodes\SubFlowNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\WorkflowEngine\IManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

class SubFlowNodeTest extends TestCase {

	private FlowLocator $resolvers;

	private FlowRunService $runs;

	private SubFlowNode $node;

	protected function setUp(): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->resolvers = $this->createMock(FlowLocator::class);
		$this->runs = $this->createMock(FlowRunService::class);

		$this->node = new SubFlowNode(
			new \OCA\OpenRegister\Service\Flow\FlowExecution($this->resolvers, $this->runs, new \Psr\Log\NullLogger()),
			new \OCA\OpenRegister\Service\Flow\Nodes\FlowNodePresentation($l, $this->createMock(IURLGenerator::class))
		);
	}//end setUp()

	private function finishedRun(string $status, array $items = []): FlowRun {
		$run = new FlowRun();
		$run->setStatus($status);
		$run->setItems($items);
		return $run;
	}//end finishedRun()

	public function testWaitRunsTheSubFlowAndReturnsItsItems(): void {
		$this->resolvers->method('resolveFlow')->with('child')->willReturn(['id' => 'child', 'edges' => []]);

		$produced = [FlowItems::item(json: ['answer' => 42])];
		$this->runs->method('queueRun')->willReturn($this->finishedRun(FlowRun::STATUS_QUEUED));
		$this->runs->expects($this->once())->method('executePass')
			->willReturn($this->finishedRun(FlowRun::STATUS_COMPLETED, $produced));

		$out = $this->node->execute(new FlowNodeCall(items: [FlowItems::item(json: ['in' => 1])], config: ['flowId' => 'child'], context: []));

		$this->assertCount(1, $out);
		$this->assertSame(42, $out[0]['json']['answer']);
	}//end testWaitRunsTheSubFlowAndReturnsItsItems()

	public function testFireAndForgetQueuesAndPassesItemsThrough(): void {
		$input = [FlowItems::item(json: ['in' => 1])];

		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'child']);
		$this->runs->expects($this->once())->method('queueRun')->willReturn($this->finishedRun(FlowRun::STATUS_QUEUED));
		$this->runs->expects($this->never())->method('executePass');

		$out = $this->node->execute(new FlowNodeCall(items: $input, config: ['flowId' => 'child', 'wait' => false], context: []));

		// The parent's items are untouched — a fired sub-flow does not feed back.
		$this->assertSame($input, $out);
	}//end testFireAndForgetQueuesAndPassesItemsThrough()

	public function testAnUnknownSubFlowIsRefused(): void {
		$this->resolvers->method('resolveFlow')->willReturn(null);

		$this->expectException(UnexpectedValueException::class);
		$this->node->execute(new FlowNodeCall(items: [], config: ['flowId' => 'nope'], context: []));
	}//end testAnUnknownSubFlowIsRefused()

	public function testAFlowCannotCallItself(): void {
		// 'child' is already on the stack the run is inside.
		$this->expectException(UnexpectedValueException::class);
		$this->node->execute(new FlowNodeCall(items: [], config: ['flowId' => 'child'], context: ['flowStack' => ['parent', 'child']]));
	}//end testAFlowCannotCallItself()

	public function testNestingTooDeepIsRefused(): void {
		$deep = array_map(static fn (int $n): string => 'f' . $n, range(1, 16));

		$this->expectException(UnexpectedValueException::class);
		$this->node->execute(new FlowNodeCall(items: [], config: ['flowId' => 'one-more'], context: ['flowStack' => $deep]));
	}//end testNestingTooDeepIsRefused()

	public function testAWaitedSubRunThatDidNotCompleteRaises(): void {
		$this->resolvers->method('resolveFlow')->willReturn(['id' => 'child']);
		$this->runs->method('queueRun')->willReturn($this->finishedRun(FlowRun::STATUS_QUEUED));
		$this->runs->method('executePass')->willReturn($this->finishedRun(FlowRun::STATUS_FAILED));

		$this->expectException(RuntimeException::class);
		$this->node->execute(new FlowNodeCall(items: [], config: ['flowId' => 'child'], context: []));
	}//end testAWaitedSubRunThatDidNotCompleteRaises()

	public function testASubFlowStepNeedsAFlow(): void {
		$this->expectException(UnexpectedValueException::class);
		$this->node->validateConfig([]);
	}//end testASubFlowStepNeedsAFlow()

	public function testItIsAvailableInBothScopes(): void {
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_ADMIN));
		$this->assertTrue($this->node->isAvailableForScope(IManager::SCOPE_USER));
	}//end testItIsAvailableInBothScopes()
}//end class
