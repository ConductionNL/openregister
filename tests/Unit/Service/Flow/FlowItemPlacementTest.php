<?php

/**
 * A firing that produced nothing must not erase what another branch placed.
 *
 * Every node in a flow fires on every pass, and most fire with no items — the
 * `in 0 out 0` heartbeat that fills a run log. `advanceItems()` used to run its
 * output loop for those firings too, ASSIGNING an empty list to each of the
 * node's output places. Where two branches of one pass shared an output place,
 * whichever fired last won, and the consumer found nothing.
 *
 * It is worth being explicit about why this needed a test rather than a review.
 * Nothing reports it. The run finishes `completed`, the routing node reports
 * `in 1 out 1`, and the starved consumer reports `completed` as well, because a
 * node with no items still fires. In hydra's sequencer it stranded a lock and a
 * slot and left an issue queued forever, and the only trace anywhere was an
 * `in 0` on a node nobody was looking at (openregister#2488).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowItemPlacement;
use OCA\OpenRegister\Service\Flow\FlowItems;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Transition;

/**
 * @covers \OCA\OpenRegister\Service\Flow\FlowItemPlacement
 */
final class FlowItemPlacementTest extends TestCase {

	/**
	 * The collaborator under test.
	 *
	 * @var FlowItemPlacement
	 */
	private FlowItemPlacement $placement;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->placement = new FlowItemPlacement();

	}//end setUp()

	/**
	 * The measured defect, as a test: a heartbeat in one branch used to wipe
	 * the place another branch had just filled.
	 *
	 * This mirrors hydra's sequencer exactly. `built-gate` routes an item to
	 * `build-blocked`; `verdict-gate` sits in another branch of the same pass,
	 * fires with nothing, and also lists `build-blocked` among its outputs.
	 * Before the guard the second firing replaced the first's item with an
	 * empty list and the consumer starved.
	 *
	 * @return void
	 */
	public function testAnEmptyFiringDoesNotEraseAnotherBranchesItems(): void {
		$item = FlowItems::item(json: ['issue' => 1549], output: 'build-blocked');

		// The routing node fires with work and tags the item for one exit.
		$placeItems = $this->placement->advanceItems(
			transition: new Transition('built-gate', ['built-gate'], ['run-stage', 'nothing-built', 'build-blocked']),
			placeItems: ['built-gate' => [$item]],
			items: [$item],
			taken: ['run-stage', 'nothing-built', 'build-blocked']
		);

		$this->assertCount(
			expectedCount: 1,
			haystack: $placeItems['build-blocked'],
			message: 'the routed item should land on the place its output tag names'
		);

		// A DIFFERENT branch of the same pass now fires with nothing, and it
		// happens to share that output place.
		$placeItems = $this->placement->advanceItems(
			transition: new Transition('verdict-gate', ['verdict-gate'], ['advance', 'run-fix', 'build-blocked']),
			placeItems: $placeItems,
			items: [],
			taken: ['advance', 'run-fix', 'build-blocked']
		);

		$this->assertCount(
			expectedCount: 1,
			haystack: ($placeItems['build-blocked'] ?? []),
			message: 'a firing that produced no items must not erase a shared output place'
		);

	}//end testAnEmptyFiringDoesNotEraseAnotherBranchesItems()

	/**
	 * The guard must not stop a firing from consuming what it read.
	 *
	 * Leaving items on an input place is how a loop re-reads a stale copy
	 * instead of fresh work, so the early return still clears the froms.
	 *
	 * @return void
	 */
	public function testAnEmptyFiringStillClearsItsOwnInputPlaces(): void {
		$stale = FlowItems::item(json: ['stale' => true]);

		$placeItems = $this->placement->advanceItems(
			transition: new Transition('gate', ['gate'], ['next']),
			placeItems: ['gate' => [$stale]],
			items: [],
			taken: ['next']
		);

		$this->assertArrayNotHasKey(
			key: 'gate',
			array: $placeItems,
			message: 'the input place a firing consumed must be cleared even when it produced nothing'
		);

	}//end testAnEmptyFiringStillClearsItsOwnInputPlaces()

	/**
	 * A firing that DID produce items still clears the branches it did not take.
	 *
	 * This is the behaviour the guard must not weaken: an exit the token never
	 * reached must not keep items from an earlier pass, or a later firing picks
	 * them up as if they were fresh.
	 *
	 * @return void
	 */
	public function testAProductiveFiringStillClearsTheBranchesItDidNotTake(): void {
		$old = FlowItems::item(json: ['from' => 'an earlier pass']);
		$item = FlowItems::item(json: ['issue' => 1549], output: 'taken');

		$placeItems = $this->placement->advanceItems(
			transition: new Transition('gate', ['gate'], ['taken', 'not-taken']),
			placeItems: ['gate' => [$item], 'not-taken' => [$old]],
			items: [$item],
			taken: ['taken']
		);

		$this->assertArrayNotHasKey(
			key: 'not-taken',
			array: $placeItems,
			message: 'an exit the token did not reach must not keep stale items'
		);
		$this->assertCount(expectedCount: 1, haystack: $placeItems['taken']);

	}//end testAProductiveFiringStillClearsTheBranchesItDidNotTake()

	/**
	 * An untagged item is still broadcast to every taken output.
	 *
	 * The ordinary case, and what a parallel split relies on. Included so the
	 * guard cannot be "fixed" later by returning early on anything other than
	 * a genuinely empty produce.
	 *
	 * @return void
	 */
	public function testAnUntaggedItemStillReachesEveryTakenOutput(): void {
		$item = FlowItems::item(json: ['issue' => 1549]);

		$placeItems = $this->placement->advanceItems(
			transition: new Transition('split', ['split'], ['left', 'right']),
			placeItems: ['split' => [$item]],
			items: [$item],
			taken: ['left', 'right']
		);

		$this->assertCount(expectedCount: 1, haystack: $placeItems['left']);
		$this->assertCount(expectedCount: 1, haystack: $placeItems['right']);

	}//end testAnUntaggedItemStillReachesEveryTakenOutput()

}//end class
