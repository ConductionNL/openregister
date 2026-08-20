<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\RegistryStepDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The dispatcher's half of the resume contract.
 *
 * The state class can be correct on its own and the feature still be broken, so
 * these test the two claims that actually carry it: a node is handed a slot
 * scoped to itself, and that slot survives exactly one suspension.
 */
class RegistryStepDispatcherResumeTest extends TestCase {

	/**
	 * A node that records the context it was called with, and optionally
	 * suspends the way a real awaiting node does.
	 *
	 * @param callable $onExecute What the node does when called.
	 *
	 * @return IFlowNode The stub.
	 */
	private function node(callable $onExecute): IFlowNode {
		$node = $this->createMock(IFlowNode::class);
		$node->method('execute')->willReturnCallback($onExecute);

		return $node;
	}

	/**
	 * @param IFlowNode $node The node the registry should resolve to.
	 *
	 * @return RegistryStepDispatcher The dispatcher under test.
	 */
	private function dispatcher(IFlowNode $node): RegistryStepDispatcher {
		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturn($node);

		return new RegistryStepDispatcher(registry: $registry);
	}

	/**
	 * The node reads its progress without ever naming itself — the dispatcher
	 * scopes the slot from the step's id. This is what keeps two nodes of the
	 * same type in one flow from sharing a cursor.
	 */
	public function testTheNodeIsHandedASlotScopedToItself(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'crawl-b')->set(key: 'page', value: 47);

		$seen = null;
		$dispatcher = $this->dispatcher(
			$this->node(function (array $items, array $config, array $context) use (&$seen): array {
				$seen = $context[FlowNodeResumeState::CONTEXT_KEY]->get(key: 'page');

				return $items;
			})
		);

		$dispatcher->dispatch(
			['id' => 'crawl-b', 'type' => 'test.node'],
			[],
			[FlowResumeState::CONTEXT_KEY => $state]
		);

		$this->assertSame(47, $seen);
	}

	/**
	 * ...and it is the RIGHT slot. Handing a node the wrong node's progress is
	 * worse than handing it none, because it resumes confidently from a
	 * position that was never its own.
	 */
	public function testItIsNotHandedAnotherNodesSlot(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'crawl-a')->set(key: 'page', value: 47);

		$seen = 'unset';
		$dispatcher = $this->dispatcher(
			$this->node(function (array $items, array $config, array $context) use (&$seen): array {
				$seen = $context[FlowNodeResumeState::CONTEXT_KEY]->get(key: 'page', default: null);

				return $items;
			})
		);

		$dispatcher->dispatch(
			['id' => 'crawl-b', 'type' => 'test.node'],
			[],
			[FlowResumeState::CONTEXT_KEY => $state]
		);

		$this->assertNull($seen);
	}

	/**
	 * THE contract. A node that suspends keeps its progress — that is the whole
	 * point of the mechanism, and the assertion whose failure would make resume
	 * silently equivalent to restart.
	 */
	public function testProgressSurvivesASuspension(): void {
		$state = new FlowResumeState();
		$dispatcher = $this->dispatcher(
			$this->node(static function (array $items, array $config, array $context): array {
				$context[FlowNodeResumeState::CONTEXT_KEY]->set(key: 'page', value: 47);

				throw new FlowSuspension(reason: 'rate limited');
			})
		);

		try {
			$dispatcher->dispatch(['id' => 'crawl', 'type' => 'test.node'], [], [FlowResumeState::CONTEXT_KEY => $state]);
			$this->fail('Expected the node to suspend.');
		} catch (FlowSuspension $suspension) {
			// Expected.
		}

		$this->assertSame(47, $state->forNode(nodeId: 'crawl')->get(key: 'page'));
	}

	/**
	 * The other half, and the one that is easy to leave out: a node that
	 * FINISHED has nothing to remember. Left behind, its cursor would be handed
	 * back on the next pass through the same node — inside a loop, or on the
	 * next scheduled tick — and it would resume from a position it had already
	 * finished with.
	 */
	public function testProgressIsClearedWhenTheNodeReturnsNormally(): void {
		$state = new FlowResumeState();
		$dispatcher = $this->dispatcher(
			$this->node(static function (array $items, array $config, array $context): array {
				$context[FlowNodeResumeState::CONTEXT_KEY]->set(key: 'page', value: 47);

				return $items;
			})
		);

		$dispatcher->dispatch(['id' => 'crawl', 'type' => 'test.node'], [], [FlowResumeState::CONTEXT_KEY => $state]);

		$this->assertTrue($state->isEmpty());
	}

	/**
	 * A dispatcher walking a context with no run behind it — the flow tester,
	 * node unit tests — must still dispatch. The scoping is an enhancement, not
	 * a precondition.
	 */
	public function testItDispatchesWithNoResumeStateInContext(): void {
		$dispatcher = $this->dispatcher(
			$this->node(static function (array $items, array $config, array $context): array {
				return [['ok' => true]];
			})
		);

		$this->assertSame(
			[['ok' => true]],
			$dispatcher->dispatch(['id' => 'n', 'type' => 'test.node'], [], [])
		);
	}

	/**
	 * A step with no id has no slot to key, and must not take one keyed on the
	 * empty string — every unidentified node would then share one.
	 */
	public function testAStepWithNoIdGetsNoSlot(): void {
		$state = new FlowResumeState();

		$seen = 'unset';
		$dispatcher = $this->dispatcher(
			$this->node(function (array $items, array $config, array $context) use (&$seen): array {
				$seen = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);

				return $items;
			})
		);

		$dispatcher->dispatch(['type' => 'test.node'], [], [FlowResumeState::CONTEXT_KEY => $state]);

		$this->assertNull($seen);
	}
}
