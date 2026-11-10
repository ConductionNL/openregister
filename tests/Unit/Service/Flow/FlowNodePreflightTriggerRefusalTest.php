<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use UnexpectedValueException;

/**
 * A node's refusal must block the save whichever SPL exception it chose.
 *
 * The preflight used to catch `UnexpectedValueException` alone, on the stated
 * grounds that it was "what every implementation in-tree raises". It was not:
 * TriggerScheduleNode, TriggerObjectNode and FlowStateNode all raise
 * `InvalidArgumentException`, so their refusals were logged as "failed for its
 * own reasons" and the flow saved clean.
 *
 * Measured live before the fix: `cron: "@hourly"` and a trigger naming a
 * register that does not exist both returned `valid: true`. The first flow
 * never fires; the second subscribes to nothing.
 */
class FlowNodePreflightTriggerRefusalTest extends TestCase {

	/**
	 * A preflight whose single known node refuses with the given exception.
	 *
	 * @param \Throwable $refusal What the node throws from validateConfig().
	 *
	 * @return FlowNodePreflight
	 */
	private function preflightRefusingWith(\Throwable $refusal): FlowNodePreflight {
		$node = $this->createMock(IFlowNode::class);
		$node->method('validateConfig')->willThrowException($refusal);

		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturn($node);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(true);

		return new FlowNodePreflight($registry, $appManager, $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return array<string, mixed> The flow document.
	 */
	private function flow(): array {
		return [
			'name' => 'trigger-flow',
			'nodes' => [['id' => 'a', 'type' => 'openregister.trigger-schedule'], ['id' => 'b']],
			'edges' => [['id' => 'e1', 'from' => 'a', 'to' => 'b']],
		];
	}

	/**
	 * The regression: an InvalidArgumentException refusal must BLOCK.
	 *
	 * @return void
	 */
	public function testAnInvalidArgumentRefusalBlocksTheSave(): void {
		$preflight = $this->preflightRefusingWith(
			new InvalidArgumentException('A cron expression has five fields.')
		);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/five fields/');
		$preflight->assertRunnable(flow: $this->flow());

	}//end testAnInvalidArgumentRefusalBlocksTheSave()

	/**
	 * The behaviour that already worked keeps working.
	 *
	 * @return void
	 */
	public function testAnUnexpectedValueRefusalStillBlocksTheSave(): void {
		$preflight = $this->preflightRefusingWith(
			new UnexpectedValueException('This step needs a register.')
		);

		$this->expectException(UnexpectedValueException::class);
		$this->expectExceptionMessageMatches('/needs a register/');
		$preflight->assertRunnable(flow: $this->flow());

	}//end testAnUnexpectedValueRefusalStillBlocksTheSave()

	/**
	 * A node that BROKE — as opposed to refusing — must not make the instance
	 * unsavable. This is the distinction the change deliberately preserves.
	 *
	 * @return void
	 */
	public function testANodeThatBreaksForItsOwnReasonsDoesNotBlock(): void {
		$preflight = $this->preflightRefusingWith(
			new RuntimeException('A collaborator this node needs is missing.')
		);

		$preflight->assertRunnable(flow: $this->flow());
		$this->addToAssertionCount(1);

	}//end testANodeThatBreaksForItsOwnReasonsDoesNotBlock()
}//end class
