<?php

/**
 * Several steps converging on one node WITHOUT a join must deliver every
 * firing's items to it.
 *
 * Measured on the Rotterdam stack (opencatalogi publiccode harvest, run
 * 5f222a8b): 24 shard pages converged on one `hits` node, and the tail read
 * 3 of them, one item each. `FlowItemPlacement::advanceItems()` ASSIGNED a
 * firing's items to each output place, so a predecessor that fired before the
 * consumer had run replaced the items the previous predecessor left there.
 * The token count added up; the items did not. The run said `completed`.
 *
 * These tests drive the real FlowEngine, FlowDefinitionBuilder and Symfony
 * marking store, so the order in which the walk fires the branches is the
 * engine's own, not one the test chose.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/flow-or-merge-keeps-every-firing/specs/flow-merge/spec.md#requirement-an-or-merge-place-keeps-every-firings-items
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowDefinitionBuilder;
use OCA\OpenRegister\Service\Flow\FlowEngine;
use OCA\OpenRegister\Service\Flow\FlowItemPlacement;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowStepDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MethodMarkingStore;
use Symfony\Component\Workflow\Transition;

/** Subject carrying the marking. */
class OrMergeSubject {
	public array $marking = [];
}

/**
 * Records EVERY firing's input per step type (not only the last one), and
 * stamps `shard=<value>` for a `shard:<value>` step.
 */
class AccumulatingDispatcher implements FlowStepDispatcher {
	/** @var array<string, array<int, array>> Every item each step type received, across all its firings. */
	public array $received = [];

	public function dispatch(array $step, array $items, array $context): array {
		$type = (string)($step['type'] ?? '');
		foreach ($items as $item) {
			$this->received[$type][] = $item;
		}

		if (str_starts_with($type, 'shard:') === true) {
			$out = [];
			foreach ($items as $i => $item) {
				$json = (array)($item['json'] ?? []);
				$json['shard'] = substr($type, 6);
				$out[] = FlowItems::item(json: $json, binary: [], fromItemIndex: $i);
			}

			return $out;
		}

		return $items;
	}
}

class FlowOrMergeTest extends TestCase {
	/**
	 * Three parallel shards converge on one node with no `join`. The node
	 * must receive all three shards' items, whatever order the walk fires them.
	 */
	public function testEveryShardReachesTheConvergingNode(): void {
		$flow = [
			'id' => 'or-merge',
			'nodes' => [
				['id' => 'trigger', 'type' => 'passthrough'],
				['id' => 's1', 'type' => 'shard:1'],
				['id' => 's2', 'type' => 'shard:2'],
				['id' => 's3', 'type' => 'shard:3'],
				['id' => 'hits', 'type' => 'sink'],
			],
			'edges' => [
				['id' => 't-1', 'from' => 'trigger', 'to' => 's1'],
				['id' => 't-2', 'from' => 'trigger', 'to' => 's2'],
				['id' => 't-3', 'from' => 'trigger', 'to' => 's3'],
				['id' => '1-h', 'from' => 's1', 'to' => 'hits'],
				['id' => '2-h', 'from' => 's2', 'to' => 'hits'],
				['id' => '3-h', 'from' => 's3', 'to' => 'hits'],
			],
		];

		$dispatcher = new AccumulatingDispatcher();
		$engine = new FlowEngine(new FlowDefinitionBuilder(), $this->createMock(LoggerInterface::class));
		$engine->run(
			flow: $flow,
			store: new MethodMarkingStore(false, 'marking'),
			subject: new OrMergeSubject(),
			dispatcher: $dispatcher,
			context: [],
			items: [FlowItems::item(json: ['page' => 1])]
		);

		// Each shard fired once with the trigger's item.
		foreach (['shard:1', 'shard:2', 'shard:3'] as $shard) {
			$this->assertCount(1, ($dispatcher->received[$shard] ?? []), $shard . ' should fire once');
		}

		$shards = array_column(array_column(($dispatcher->received['sink'] ?? []), 'json'), 'shard');
		sort($shards);
		$this->assertSame(
			['1', '2', '3'],
			$shards,
			'the converging node must receive every shard exactly once, none overwritten and none twice'
		);
	}

	/**
	 * The placement rule itself: a second firing onto a place that still holds
	 * the first firing's items adds to them.
	 */
	public function testASecondFiringOntoAnOccupiedPlaceAppends(): void {
		$placement = new FlowItemPlacement();
		$first = FlowItems::item(json: ['shard' => 1]);
		$second = FlowItems::item(json: ['shard' => 2]);

		$placeItems = $placement->advanceItems(
			transition: new Transition('s1', ['s1'], ['hits']),
			placeItems: ['s1' => [$first], 's2' => [$second]],
			items: [$first],
			taken: ['hits']
		);
		$placeItems = $placement->advanceItems(
			transition: new Transition('s2', ['s2'], ['hits']),
			placeItems: $placeItems,
			items: [$second],
			taken: ['hits']
		);

		$this->assertSame([1, 2], array_column(array_column($placeItems['hits'], 'json'), 'shard'));
		$this->assertArrayNotHasKey('s1', $placeItems);
		$this->assertArrayNotHasKey('s2', $placeItems);
	}
}
