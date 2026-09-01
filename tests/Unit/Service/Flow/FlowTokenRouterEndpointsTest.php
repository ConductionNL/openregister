<?php

/**
 * The router's reading of an edge's endpoints.
 *
 * An endpoint is a LIST — `{"from": ["a"], "to": ["b"]}` is the shape the flow
 * canvas saves and `FlowDefinitionBuilder` normalises to. The router read `to`
 * as a list and `from` as a scalar, in the same method, and `(string)["a"]` is
 * the literal `"Array"` rather than an error. So no edge ever matched its
 * source node: a token that fired had nowhere to go, and a guarded exit
 * resolved against an empty step and read as unconditional — the token took a
 * branch whose condition was false and the run still reported success.
 *
 * These tests pin the list form, the scalar form (which must keep working),
 * and the fan-out form, on both public entry points. Each has a negative
 * control, because a router that matched EVERYTHING would satisfy the positive
 * cases just as well.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowTokenRouter;
use PHPUnit\Framework\TestCase;

/**
 * Covers endpoint reading in placesForExit() and conditionReaching().
 */
class FlowTokenRouterEndpointsTest extends TestCase {
	/**
	 * The router under test.
	 *
	 * @var FlowTokenRouter
	 */
	private FlowTokenRouter $router;

	/**
	 * Build the router.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->router = new FlowTokenRouter();

	}//end setUp()

	/**
	 * A flow whose branching node guards one exit and defaults the other.
	 *
	 * @param mixed $from The `from` endpoint, in whichever shape a test needs.
	 *
	 * @return array The flow document.
	 */
	private function branchingFlow(mixed $from): array {
		return [
			'nodes' => [
				[
					'id' => 'split',
					'type' => 'openregister.router',
					'exits' => [
						['id' => 'high', 'condition' => ['>' => [['var' => 'n'], 10]]],
						['id' => 'low'],
					],
				],
				['id' => 'hi', 'type' => 'openregister.set-fields'],
				['id' => 'lo', 'type' => 'openregister.set-fields'],
			],
			'edges' => [
				['id' => 'toHigh', 'from' => $from, 'fromExit' => 'high', 'to' => ['hi']],
				['id' => 'toLow', 'from' => $from, 'fromExit' => 'low', 'to' => ['lo']],
			],
		];
	}//end branchingFlow()

	/**
	 * A LIST source resolves the places an exit reaches.
	 *
	 * @return void
	 */
	public function testPlacesForExitReadsAListSource(): void {
		$places = $this->router->placesForExit(
			flow: $this->branchingFlow(from: ['split']),
			nodeId: 'split',
			exitId: 'high',
			candidates: ['hi', 'lo']
		);

		$this->assertSame(['hi'], $places);

	}//end testPlacesForExitReadsAListSource()

	/**
	 * The scalar form keeps working — this is a widening, not a swap.
	 *
	 * @return void
	 */
	public function testPlacesForExitStillReadsAScalarSource(): void {
		$places = $this->router->placesForExit(
			flow: $this->branchingFlow(from: 'split'),
			nodeId: 'split',
			exitId: 'high',
			candidates: ['hi', 'lo']
		);

		$this->assertSame(['hi'], $places);

	}//end testPlacesForExitStillReadsAScalarSource()

	/**
	 * The negative control: the OTHER exit reaches the other place, not both.
	 *
	 * Without this, the tests above would pass for a router that ignored
	 * `fromExit` and returned every candidate it saw.
	 *
	 * @return void
	 */
	public function testPlacesForExitDoesNotReturnTheOtherBranch(): void {
		$flow = $this->branchingFlow(from: ['split']);

		$this->assertSame(['lo'], $this->router->placesForExit(flow: $flow, nodeId: 'split', exitId: 'low', candidates: ['hi', 'lo']));
		$this->assertSame([], $this->router->placesForExit(flow: $flow, nodeId: 'split', exitId: 'nosuch', candidates: ['hi', 'lo']));

	}//end testPlacesForExitDoesNotReturnTheOtherBranch()

	/**
	 * A node that is not the edge's source reaches nothing through it.
	 *
	 * @return void
	 */
	public function testPlacesForExitIgnoresAnotherNodesEdge(): void {
		$places = $this->router->placesForExit(
			flow: $this->branchingFlow(from: ['split']),
			nodeId: 'someone-else',
			exitId: 'high',
			candidates: ['hi', 'lo']
		);

		$this->assertSame([], $places);

	}//end testPlacesForExitIgnoresAnotherNodesEdge()

	/**
	 * A fan-out source counts for EVERY node it names, not just the first.
	 *
	 * @return void
	 */
	public function testPlacesForExitHonoursEveryFanOutSource(): void {
		$flow = [
			'nodes' => [['id' => 'a'], ['id' => 'b'], ['id' => 'end']],
			'edges' => [['id' => 'both', 'from' => ['a', 'b'], 'fromExit' => 'out', 'to' => ['end']]],
		];

		$this->assertSame(['end'], $this->router->placesForExit(flow: $flow, nodeId: 'a', exitId: 'out', candidates: ['end']));
		$this->assertSame(['end'], $this->router->placesForExit(flow: $flow, nodeId: 'b', exitId: 'out', candidates: ['end']));

	}//end testPlacesForExitHonoursEveryFanOutSource()

	/**
	 * A candidate list the target is absent from filters it out.
	 *
	 * @return void
	 */
	public function testPlacesForExitRespectsTheCandidateList(): void {
		$places = $this->router->placesForExit(
			flow: $this->branchingFlow(from: ['split']),
			nodeId: 'split',
			exitId: 'high',
			candidates: ['lo']
		);

		$this->assertSame([], $places);

	}//end testPlacesForExitRespectsTheCandidateList()

	/**
	 * ⚠️ THE GUARD ON A BRANCH SURVIVES A LIST SOURCE.
	 *
	 * This is the quiet half of the defect. `exitCondition()` resolved the
	 * edge's source with `(string)$edge['from']`, which named no node, which
	 * returned an empty step, which has no `exits` — so the guard came back
	 * null and the branch read as the unconditional default. A token then took
	 * a path whose condition was false, and the run reported success.
	 *
	 * @return void
	 */
	public function testConditionReachingResolvesAGuardThroughAListSource(): void {
		$condition = $this->router->conditionReaching(
			flow: $this->branchingFlow(from: ['split']),
			nodeId: 'hi'
		);

		$this->assertSame(['>' => [['var' => 'n'], 10]], $condition);

	}//end testConditionReachingResolvesAGuardThroughAListSource()

	/**
	 * The negative control: an exit that declares no condition IS the default.
	 *
	 * Null here must mean "this branch is unconditional", not "the lookup
	 * failed" — the two were indistinguishable before, which is what let the
	 * bug hide.
	 *
	 * @return void
	 */
	public function testConditionReachingReturnsNullForTheDefaultBranch(): void {
		$this->assertNull(
			$this->router->conditionReaching(
				flow: $this->branchingFlow(from: ['split']),
				nodeId: 'lo'
			)
		);

	}//end testConditionReachingReturnsNullForTheDefaultBranch()

	/**
	 * An unbranched edge is unconditional whichever shape its endpoints take.
	 *
	 * @return void
	 */
	public function testConditionReachingIsNullForAnUnbranchedListEdge(): void {
		$flow = [
			'nodes' => [['id' => 'a'], ['id' => 'b']],
			'edges' => [['id' => 'e1', 'from' => ['a'], 'to' => ['b']]],
		];

		$this->assertNull($this->router->conditionReaching(flow: $flow, nodeId: 'b'));

	}//end testConditionReachingIsNullForAnUnbranchedListEdge()

	/**
	 * A node nothing points at has no guard to find.
	 *
	 * @return void
	 */
	public function testConditionReachingIsNullForAnUnreachedNode(): void {
		$this->assertNull(
			$this->router->conditionReaching(
				flow: $this->branchingFlow(from: ['split']),
				nodeId: 'orphan'
			)
		);

	}//end testConditionReachingIsNullForAnUnreachedNode()

	/**
	 * A malformed edge is skipped rather than throwing.
	 *
	 * @return void
	 */
	public function testMalformedEdgesAreSkipped(): void {
		$flow = [
			'nodes' => [['id' => 'a'], ['id' => 'b']],
			'edges' => ['not-an-edge', ['id' => 'ok', 'from' => ['a'], 'fromExit' => 'x', 'to' => ['b']]],
		];

		$this->assertSame(['b'], $this->router->placesForExit(flow: $flow, nodeId: 'a', exitId: 'x', candidates: ['b']));

	}//end testMalformedEdgesAreSkipped()
}//end class
