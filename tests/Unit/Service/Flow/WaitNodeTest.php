<?php

/**
 * The wait node's suspend decision.
 *
 * Suspending is a RUN-level act, not a branch-level one: `FlowSuspension`
 * stops the whole run and stores its marking. So the question this node has to
 * get right is not only WHEN to wait, but whether there is anything to wait
 * for at all.
 *
 * A transition can fire with no items — a gate sent every item down another
 * branch, or that branch simply had no work this pass. Suspending on that
 * pauses every other branch with it, and in a flow whose branches are
 * PRIORITIES rather than alternatives it loses the work outright: hydra's
 * sequencer routes an in-flight stage to a collect branch and leaves the
 * dispatch branch empty, and the empty branch's wait suspended the run before
 * the branch carrying the item could advance. On resume the marking had moved
 * on, every remaining transition fired empty, and the log read as a clean pass
 * — forty transitions, all `completed`, all `in=0 out=0`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\Nodes\WaitNode;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Flow\Nodes\WaitNode
 *
 * @uses \OCA\OpenRegister\Service\Flow\FlowNodeResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowResumeState
 * @uses \OCA\OpenRegister\Service\Flow\FlowSuspension
 */
final class WaitNodeTest extends TestCase {

	/**
	 * The node under test.
	 *
	 * @var WaitNode
	 */
	private WaitNode $node;

	/**
	 * Build the node with stub collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$this->node = new WaitNode($l10n, $this->createMock(originalClassName: IURLGenerator::class));

	}//end setUp()

	/**
	 * THE POSITIVE CONTROL: a wait that carries work still suspends.
	 *
	 * Without this, the empty-firing test below would pass just as happily
	 * against a node that had stopped suspending altogether — which would make
	 * every wait in every flow a no-op and look, from the log, like success.
	 *
	 * @return void
	 */
	public function testAWaitCarryingItemsStillSuspends(): void {
		$this->expectException(exception: FlowSuspension::class);

		$this->node->execute([['json' => ['a' => 1]]], ['for' => '60 seconds'], []);

	}//end testAWaitCarryingItemsStillSuspends()

	/**
	 * An EMPTY firing does not suspend, because there is nothing to wait for.
	 *
	 * Suspending stops the RUN, so an empty branch that suspends takes every
	 * other branch down with it.
	 *
	 * @return void
	 */
	public function testAnEmptyFiringDoesNotSuspendTheRun(): void {
		$this->assertSame(
			expected: [],
			actual: $this->node->execute([], ['for' => '60 seconds'], []),
			message: 'a branch with no work must not pause every other branch of the run'
		);

	}//end testAnEmptyFiringDoesNotSuspendTheRun()

	/**
	 * Nothing is skipped: a later pass that DOES carry items suspends then.
	 *
	 * This is what makes the early return safe rather than a way of disabling
	 * the wait — the same node, same config, suspends as soon as there is
	 * something to delay.
	 *
	 * @return void
	 */
	public function testTheSameNodeStillSuspendsOnceItemsArrive(): void {
		$this->assertSame(expected: [], actual: $this->node->execute([], ['for' => '60 seconds'], []));

		$this->expectException(exception: FlowSuspension::class);
		$this->node->execute([['json' => []]], ['for' => '60 seconds'], []);

	}//end testTheSameNodeStillSuspendsOnceItemsArrive()

	/**
	 * On the way back in, items pass straight through.
	 *
	 * The marking is not advanced while suspended, so the node runs a SECOND
	 * time on resume. `context.resuming` is what stops that becoming an
	 * infinite wait — and, as a consequence, what makes this node useless as a
	 * loop body.
	 *
	 * @return void
	 */
	public function testResumingPassesItemsThrough(): void {
		$items = [['json' => ['a' => 1]]];

		$this->assertSame(
			expected: $items,
			actual: $this->node->execute($items, ['for' => '60 seconds'], ['resuming' => true])
		);

	}//end testResumingPassesItemsThrough()

	/**
	 * A SECOND wait node in a resumed walk still waits.
	 *
	 * `context.resuming` is true for every node of a resumed walk, so a wait
	 * the walk has only just reached must not read it as "my wait is over".
	 * The node that suspended holds a resume slot; this one holds none, and an
	 * empty slot means the waiting has not started yet.
	 *
	 * @return void
	 */
	public function testASecondWaitInAResumedWalkStillWaits(): void {
		$slot = (new FlowResumeState())->forNode(nodeId: 'second-wait');
		$context = [
			'resuming' => true,
			FlowNodeResumeState::CONTEXT_KEY => $slot,
		];

		try {
			$this->node->execute([['json' => ['a' => 1]]], ['for' => '60 seconds'], $context);
			$this->fail('a wait that never waited must suspend, resumed walk or not');
		} catch (FlowSuspension $suspension) {
			$this->assertNotNull(
				actual: $suspension->getResumeAt(),
				message: 'the second wait suspends on its OWN clock'
			);
		}

		$this->assertTrue(
			condition: $slot->has(key: 'waitingUntil'),
			message: 'the suspension marks this node as the one now waiting'
		);

	}//end testASecondWaitInAResumedWalkStillWaits()

	/**
	 * The node whose own slot is held is the one whose wait is over.
	 *
	 * It wrote the slot when it suspended, and the run only became eligible
	 * again once its `resumeAt` had passed — so a held slot is the per-node
	 * fact the run-wide `resuming` flag only pretends to be.
	 *
	 * @return void
	 */
	public function testAWaitWhoseOwnSlotIsHeldPassesThrough(): void {
		$state = new FlowResumeState();
		$state->forNode(nodeId: 'the-wait')->set(key: 'waitingUntil', value: '2026-01-01T00:00:00+00:00');

		$items = [['json' => ['a' => 1]]];
		$context = [
			'resuming' => true,
			FlowNodeResumeState::CONTEXT_KEY => $state->forNode(nodeId: 'the-wait'),
		];

		$this->assertSame(
			expected: $items,
			actual: $this->node->execute($items, ['for' => '60 seconds'], $context),
			message: 'the wait that suspended is over once the run is woken'
		);

	}//end testAWaitWhoseOwnSlotIsHeldPassesThrough()
}//end class
