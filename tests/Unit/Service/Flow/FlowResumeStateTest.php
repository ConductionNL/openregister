<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FlowResumeStateTest extends TestCase {

	/**
	 * The control that discriminates this from a plain bag: two nodes writing
	 * the same key must not see each other's value. A flow holding two
	 * synchronisation nodes is the case that breaks without scoping, and it
	 * breaks on the SECOND node, which is where nobody looks.
	 */
	public function testTwoNodesDoNotShareASlot(): void {
		$state = new FlowResumeState();

		$state->forNode(nodeId: 'sync-a')->set(key: 'page', value: 7);
		$state->forNode(nodeId: 'sync-b')->set(key: 'page', value: 42);

		$this->assertSame(7, $state->forNode(nodeId: 'sync-a')->get(key: 'page'));
		$this->assertSame(42, $state->forNode(nodeId: 'sync-b')->get(key: 'page'));
	}

	/**
	 * A node with no stored slot is not resuming, whatever the RUN is doing.
	 * This is the distinction `$context['resuming']` cannot make: once anything
	 * in the graph suspends, that flag is true for every node downstream of it.
	 */
	public function testIsResumingIsPerNodeNotPerRun(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'sync-a')->set(key: 'page', value: 3);

		$this->assertTrue($state->forNode(nodeId: 'sync-a')->isResuming());
		$this->assertFalse($state->forNode(nodeId: 'sync-b')->isResuming());
	}

	/**
	 * Writes reach the parent immediately rather than being buffered in the
	 * view. A node suspends by THROWING, so there is no return path to flush on
	 * — a buffered write would be lost at exactly the moment it mattered.
	 */
	public function testWritesThroughAViewAreVisibleOnTheParent(): void {
		$state = new FlowResumeState();
		$view = $state->forNode(nodeId: 'n');

		$view->set(key: 'shard', value: 'size:0..499');

		$this->assertSame(['n' => ['shard' => 'size:0..499']], $state->all());
	}

	/**
	 * Two views of the same node are the same slot. The dispatcher builds a
	 * fresh view on every dispatch, so a node re-entered on resume must see
	 * what the earlier view wrote.
	 */
	public function testASecondViewSeesTheFirstViewsWrites(): void {
		$state = new FlowResumeState();

		$state->forNode(nodeId: 'n')->set(key: 'page', value: 12);

		$this->assertSame(12, $state->forNode(nodeId: 'n')->get(key: 'page'));
	}

	/**
	 * Clearing empties the slot rather than leaving an empty bag behind, so
	 * `isResuming()` goes back to false and the state serialises to nothing.
	 */
	public function testClearingRemovesTheSlotEntirely(): void {
		$state = new FlowResumeState();
		$view = $state->forNode(nodeId: 'n');
		$view->set(key: 'page', value: 1);

		$view->clear();

		$this->assertFalse($view->isResuming());
		$this->assertTrue($state->isEmpty());
		$this->assertSame([], $state->jsonSerialize());
	}

	/**
	 * Writing an empty bag is the same as forgetting. Otherwise a node that
	 * emptied its own slot would leave `{"n":{}}` behind, and the run would be
	 * persisted as carrying progress it does not have.
	 */
	public function testWritingAnEmptyBagForgetsTheSlot(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'n')->set(key: 'page', value: 1);

		$state->write(nodeId: 'n', values: []);

		$this->assertTrue($state->isEmpty());
	}

	/**
	 * The round trip the whole feature rests on: a slot written before a
	 * suspension must come back after it, through JSON, unchanged.
	 */
	public function testItSurvivesAJsonRoundTrip(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'crawl')->merge(values: ['page' => 47, 'shard' => 'b', 'written' => 1294]);

		$restored = FlowResumeState::fromArray(
			json_decode(json_encode($state->jsonSerialize()), true)
		);

		$this->assertSame(
			['page' => 47, 'shard' => 'b', 'written' => 1294],
			$restored->forNode(nodeId: 'crawl')->all()
		);
	}

	/**
	 * `fromArray` is total, for the same reason FlowToken's is: a run persisted
	 * before this existed, a corrupted column and a run handed straight back are
	 * all things that must not fail a run.
	 *
	 * @param mixed $stored The stored value.
	 */
	#[DataProvider('unusableStoredValues')]
	public function testFromArrayNeverFails(mixed $stored): void {
		$this->assertTrue(FlowResumeState::fromArray($stored)->isEmpty());
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function unusableStoredValues(): array {
		return [
			'null (run predates the feature)' => [null],
			'scalar (corrupted column)' => ['nonsense'],
			'integer' => [7],
			'a list, not a node map' => [[['page' => 1]]],
			'a slot that is not a bag' => [['n' => 'not-a-bag']],
		];
	}

	/**
	 * An object handed straight back is returned as itself, so rehydrating a
	 * context twice does not silently drop what a node just wrote.
	 */
	public function testFromArrayPassesAnExistingStateThrough(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'n')->set(key: 'page', value: 5);

		$this->assertSame($state, FlowResumeState::fromArray($state));
	}

	/**
	 * Slots survive every pass end the run can still advance from — a pass
	 * that ends `queued` (an in-request advance whose sibling has enabled
	 * work, a claim refused on contention) must not cost a parked node the
	 * uuid of the task it is waiting on. Dropping it there was the heartbeat
	 * wedge: the node asked again, and the original task's completion could
	 * never address the slot again.
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-live-run-keeps-every-parked-nodes-resume-slot
	 */
	public function testSlotsAreStorableWhileTheRunIsLive(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: 'taskUuid', value: 't-1');

		$this->assertSame(['ask' => ['taskUuid' => 't-1']], $state->storableWhen(live: true));
	}

	/**
	 * A terminal run drops its slots: anything still held belongs to a node
	 * the run never came back to, and keeping it would put a stale cursor in
	 * front of anyone reading the finished run.
	 *
	 * @spec openspec/changes/flow-heartbeat-recovery/specs/flow-heartbeat-recovery/spec.md#requirement-a-live-run-keeps-every-parked-nodes-resume-slot
	 */
	public function testATerminalRunDropsItsSlots(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'ask')->set(key: 'taskUuid', value: 't-1');

		$this->assertNull($state->storableWhen(live: false));
	}

	/**
	 * Nothing held is nothing stored, live or not — an empty bag must not
	 * write an empty key into every run's context.
	 */
	public function testAnEmptyStateStoresNothing(): void {
		$this->assertNull((new FlowResumeState())->storableWhen(live: true));
	}

	/**
	 * The scoped view is what a node is handed, and it must not be able to
	 * name another node's slot: there is no API on it that takes a node id.
	 */
	public function testTheScopedViewExposesNoWayToReachAnotherNode(): void {
		$methods = get_class_methods(FlowNodeResumeState::class);

		foreach ($methods as $method) {
			if ($method === '__construct') {
				// The dispatcher names the node exactly once, when it builds the
				// view. That is the point of the type: after construction the
				// node id is not addressable.
				continue;
			}

			$parameters = (new \ReflectionMethod(FlowNodeResumeState::class, $method))->getParameters();
			foreach ($parameters as $parameter) {
				$this->assertNotSame(
					'nodeId',
					$parameter->getName(),
					sprintf('%s() takes a nodeId, which lets a node reach another node.', $method)
				);
			}
		}
	}
}
