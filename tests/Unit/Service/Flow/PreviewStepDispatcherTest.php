<?php

/**
 * A flow dry-run performs only the steps that declare they have no side
 * effect, and describes every other step instead of performing it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodePreview;
use OCA\OpenRegister\Service\Flow\IFlowSideEffectFree;
use OCA\OpenRegister\Service\Flow\PreviewStepDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * A node that counts its runs.
 */
class DryRunCountingNode implements IFlowNode {
	public int $runs = 0;

	public function __construct(
		private readonly string $type,
	) {
	}

	public function getId(): string {
		return $this->type;
	}

	public function getDisplayName(): string {
		return 'Counting ' . $this->type;
	}

	public function getDescription(): string {
		return 'Counts.';
	}

	public function getIcon(): string {
		return '';
	}

	public function isAvailableForScope(int $scope): bool {
		return true;
	}

	public function validateConfig(array $config): void {
	}

	public function execute(array $items, array $config, array $context): array {
		$this->runs++;
		return array_map(
			static fn (array $item): array => FlowItems::item(json: array_merge((array)($item['json'] ?? []), ['touched' => true])),
			$items
		);
	}
}

/**
 * A node without side effects.
 */
class DryRunPureNode extends DryRunCountingNode implements IFlowSideEffectFree {
}

/**
 * A node that says what it would do.
 */
class DryRunPreviewingNode extends DryRunCountingNode implements IFlowNodePreview {
	public function preview(array $items, array $config, array $context): array {
		return ['summary' => 'Would write', 'changes' => [['field' => 'status', 'value' => (string)($config['status'] ?? '')]]];
	}
}

/**
 * Tests for PreviewStepDispatcher.
 */
class PreviewStepDispatcherTest extends TestCase {
	/**
	 * A registry answering the given nodes by type.
	 *
	 * @param array<string, IFlowNode> $nodes The nodes.
	 *
	 * @return FlowNodeRegistry
	 */
	private function registry(array $nodes): FlowNodeRegistry {
		$registry = $this->createMock(FlowNodeRegistry::class);
		$registry->method('get')->willReturnCallback(static fn (string $type): IFlowNode => $nodes[$type]);
		return $registry;
	}

	public function testPerformsAStepWithoutSideEffects(): void {
		$pure = new DryRunPureNode('pure');
		$dispatcher = new PreviewStepDispatcher(registry: $this->registry(['pure' => $pure]));

		$out = $dispatcher->dispatch(step: ['id' => 'n1', 'type' => 'pure'], items: [FlowItems::item(json: ['a' => 1])], context: []);

		self::assertSame(1, $pure->runs);
		self::assertTrue($out[0]['json']['touched']);
		self::assertSame(['performed' => true, 'summary' => 'Counting pure'], $dispatcher->previews()['n1']);
	}

	public function testDescribesAStepWithSideEffectsAndDoesNotPerformIt(): void {
		$writer = new DryRunCountingNode('write');
		$dispatcher = new PreviewStepDispatcher(registry: $this->registry(['write' => $writer]));
		$items = [FlowItems::item(json: ['a' => 1])];

		$out = $dispatcher->dispatch(step: ['id' => 'n2', 'type' => 'write'], items: $items, context: []);

		self::assertSame(0, $writer->runs);
		self::assertSame($items, $out);
		self::assertSame(['performed' => false, 'summary' => 'Counting write would run'], $dispatcher->previews()['n2']);
	}

	public function testAsksANodeThatPreviewsWhatItWouldDo(): void {
		$node = new DryRunPreviewingNode('object-write');
		$dispatcher = new PreviewStepDispatcher(registry: $this->registry(['object-write' => $node]));

		$dispatcher->dispatch(step: ['id' => 'n3', 'type' => 'object-write', 'config' => ['status' => 'closed']], items: [], context: []);

		self::assertSame(0, $node->runs);
		self::assertSame(
			['performed' => false, 'summary' => 'Would write', 'changes' => [['field' => 'status', 'value' => 'closed']]],
			$dispatcher->previews()['n3']
		);
	}

	public function testPassesARoutingEdgeThrough(): void {
		$dispatcher = new PreviewStepDispatcher(registry: $this->registry([]));
		$items = [FlowItems::item(json: ['a' => 1])];

		self::assertSame($items, $dispatcher->dispatch(step: ['id' => 'r'], items: $items, context: []));
		self::assertSame([], $dispatcher->previews());
	}
}
