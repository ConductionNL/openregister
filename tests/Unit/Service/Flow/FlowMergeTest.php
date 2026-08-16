<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;

/** Subject carrying the marking. */
class MergeSubject {
	public array $marking = [];
}

/**
 * Dispatcher that, per step type, stamps a field so a test can see which
 * branch's items reached the merge. Type `merge` applies MergeNode-append,
 * `passthrough` returns items unchanged, and `stamp:<field>=<value>` sets a
 * field on every item.
 */
class BranchDispatcher implements FlowStepDispatcher {
	/** @var array<string, array> Items seen per step type. */
	public array $seen = [];

	public function dispatch(array $step, array $items, array $context): array {
		$type = (string)($step['type'] ?? '');
		$this->seen[$type] = $items;

		if (str_starts_with($type, 'stamp:')) {
			[$field, $value] = explode('=', substr($type, 6));
			$out = [];
			foreach ($items as $i => $item) {
				$json = (array)($item['json'] ?? []);
				$json[$field] = $value;
				$out[] = FlowItems::item(json: $json, binary: [], fromItemIndex: $i);
			}
			return $out;
		}

		return $items;
	}
}

class FlowMergeTest extends TestCase {
	private FlowEngine $engine;

	protected function setUp(): void {
		$this->engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));
	}

	private function walk(array $flow, array $items, FlowStepDispatcher $d): array {
		return $this->engine->run(
			flow: $flow,
			store: new MethodMarkingStore(false, 'marking'),
			subject: new MergeSubject(),
			dispatcher: $d,
			context: [],
			items: $items
		);
	}

	/**
	 * The property the per-place item buffers exist for: two parallel branches
	 * each carry their own items from the split, and the join reads BOTH.
	 */
	public function testAJoinReadsTheItemsFromEveryBranch(): void {
		// The split node has NO conditioned exits, so it takes every output —
		// that is what keeps a genuine parallel split parallel now that a
		// branching node takes exactly one exit. The merge declares
		// `join: true` so it waits for both branches rather than firing on
		// whichever arrives first.
		$flow = [
			'id' => 'split-join',
			'nodes' => [
				['id' => 'split', 'type' => 'passthrough'],
				['id' => 'ea', 'type' => 'stamp:branch=A'],
				['id' => 'eb', 'type' => 'stamp:branch=B'],
				['id' => 'merge', 'type' => 'merge', 'join' => true],
			],
			'edges' => [
				['id' => 'split-a', 'from' => 'split', 'to' => 'ea'],
				['id' => 'split-b', 'from' => 'split', 'to' => 'eb'],
				['id' => 'a-merge', 'from' => 'ea', 'to' => 'merge'],
				['id' => 'b-merge', 'from' => 'eb', 'to' => 'merge'],
			],
		];

		$d = new BranchDispatcher();
		$this->walk($flow, [FlowItems::item(json: ['n' => 1])], $d);

		// The merge saw both branches' items — one from A, one from B.
		$branches = array_column(array_column($d->seen['merge'], 'json'), 'branch');
		sort($branches);
		$this->assertSame(['A', 'B'], $branches);
	}

	/**
	 * A parallel branch must NOT see the other branch's items — each carries
	 * its own from the split. This is the bug the single global list had.
	 */
	public function testParallelBranchesDoNotSeeEachOther(): void {
		$flow = [
			'id' => 'parallel',
			'nodes' => [
				['id' => 'split', 'type' => 'passthrough'],
				['id' => 'ea', 'type' => 'stamp:x=fromA'],
				['id' => 'eb', 'type' => 'stamp:x=fromB'],
			],
			'edges' => [
				['id' => 'split-a', 'from' => 'split', 'to' => 'ea'],
				['id' => 'split-b', 'from' => 'split', 'to' => 'eb'],
			],
		];

		$d = new BranchDispatcher();
		$this->walk($flow, [FlowItems::item(json: ['seed' => true])], $d);

		// Each branch received the seed item (one item), not the other branch's.
		$this->assertCount(1, $d->seen['stamp:x=fromA']);
		$this->assertCount(1, $d->seen['stamp:x=fromB']);
		$this->assertTrue($d->seen['stamp:x=fromA'][0]['json']['seed']);
		$this->assertTrue($d->seen['stamp:x=fromB'][0]['json']['seed']);
	}
}
