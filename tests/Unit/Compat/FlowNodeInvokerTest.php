<?php

/**
 * Flow nodes in the released (items, config, context) form and in the FlowNodeCall form both load and run.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Compat
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Compat;

use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeInvoker;
use OCA\OpenRegister\Service\Flow\ICallFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\ILegacyFlowNode;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\CallFormFlowNode;
use OCA\OpenRegister\Tests\Unit\Compat\Fixtures\ReleasedFormFlowNode;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class FlowNodeInvokerTest extends TestCase {

	private function call(): FlowNodeCall {
		return new FlowNodeCall(items: [['json' => ['n' => 1]]], config: ['c' => 2], context: ['runUuid' => 'r']);
	}

	public function testReleasedFormNodeReceivesThePositionalArguments(): void {
		$node = new ReleasedFormFlowNode();

		$result = (new FlowNodeInvoker())->execute(node: $node, call: $this->call());

		$this->assertSame([[['json' => ['n' => 1]]], ['c' => 2], ['runUuid' => 'r']], $node->received);
		$this->assertSame([['form' => 'released']], $result);
	}

	public function testCallFormNodeReceivesTheCall(): void {
		$node = new CallFormFlowNode();
		$call = $this->call();

		$result = (new FlowNodeInvoker())->execute(node: $node, call: $call);

		$this->assertSame($call, $node->received);
		$this->assertSame([['form' => 'call']], $result);
	}

	public function testExplicitCallInterfaceIsCalledWithTheCall(): void {
		$node = $this->createMock(ICallFlowNode::class);
		$call = $this->call();
		$node->expects($this->once())->method('execute')->with($call)->willReturn([]);

		$this->assertSame([], (new FlowNodeInvoker())->execute(node: $node, call: $call));
	}

	public function testExplicitLegacyInterfaceIsCalledPositionally(): void {
		$node = $this->createMock(ILegacyFlowNode::class);
		$node->expects($this->once())->method('execute')->with([['json' => ['n' => 1]]], ['c' => 2], ['runUuid' => 'r'])->willReturn([]);

		$this->assertSame([], (new FlowNodeInvoker())->execute(node: $node, call: $this->call()));
	}

	public function testNodeWithoutExecuteIsRefusedLoudly(): void {
		$this->expectException(RuntimeException::class);

		(new FlowNodeInvoker())->execute(node: $this->createMock(IFlowNode::class), call: $this->call());
	}
}
