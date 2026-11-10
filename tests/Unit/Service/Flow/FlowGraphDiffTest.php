<?php

/**
 * What a publish takes away, and therefore whether it breaks anybody.
 *
 * The rule is about REMOVAL. A consumer of a flow can depend on three things:
 * that a step exists, that a path connects, and that a step still reads the key
 * it read. Each is broken by taking something away, and none by adding.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowGraphDiff;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see FlowGraphDiff}.
 *
 * @covers \OCA\OpenRegister\Service\Flow\FlowGraphDiff
 */
class FlowGraphDiffTest extends TestCase {

	/**
	 * The subject.
	 *
	 * @var FlowGraphDiff
	 */
	private FlowGraphDiff $diff;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->diff = new FlowGraphDiff();

	}//end setUp()

	/**
	 * A graph of three chained steps, the shape most flows have.
	 *
	 * @return array<string, mixed> The graph.
	 */
	private function published(): array {
		return [
			'nodes' => [
				['id' => 'a', 'type' => 'openregister.trigger-manual', 'config' => []],
				['id' => 'b', 'type' => 'openregister.user-task', 'config' => ['title' => 'Ask', 'assignee' => 'admin']],
				['id' => 'c', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e1', 'from' => 'a', 'to' => 'b'],
				['id' => 'e2', 'from' => 'b', 'to' => 'c'],
			],
		];
	}//end published()

	/**
	 * Removing a step is major, and the step is named.
	 *
	 * @return void
	 */
	public function testARemovedStepIsMajorAndNamed(): void {
		$after = $this->published();
		$after['nodes'] = [$after['nodes'][0], $after['nodes'][2]];

		$result = $this->diff->compare(published: $this->published(), candidate: $after);

		$this->assertSame(FlowGraphDiff::MAJOR, $result['verdict']);
		$this->assertSame(['b'], $result['removedNodes']);
	}//end testARemovedStepIsMajorAndNamed()

	/**
	 * Removing an edge is major even when every node survives.
	 *
	 * @return void
	 */
	public function testARemovedEdgeIsMajorWithEveryNodeIntact(): void {
		$after = $this->published();
		$after['edges'] = [$after['edges'][0]];

		$result = $this->diff->compare(published: $this->published(), candidate: $after);

		$this->assertSame(FlowGraphDiff::MAJOR, $result['verdict']);
		$this->assertSame([], $result['removedNodes'], 'no node went');
		$this->assertSame(['b->c'], $result['removedEdges']);
	}//end testARemovedEdgeIsMajorWithEveryNodeIntact()

	/**
	 * Removing a config key from a SURVIVING node is major.
	 *
	 * @return void
	 */
	public function testARemovedConfigKeyOnASurvivingNodeIsMajor(): void {
		$after = $this->published();
		$after['nodes'][1]['config'] = ['title' => 'Ask'];

		$result = $this->diff->compare(published: $this->published(), candidate: $after);

		$this->assertSame(FlowGraphDiff::MAJOR, $result['verdict']);
		$this->assertSame(['b.assignee'], $result['removedKeys']);
	}//end testARemovedConfigKeyOnASurvivingNodeIsMajor()

	/**
	 * 🔴 A key that went WITH its node is one change, not two.
	 *
	 * Counting both inflates the list the author reads before publishing, and
	 * that list is the part that makes the verdict credible.
	 *
	 * @return void
	 */
	public function testAKeyThatWentWithItsNodeIsCountedOnce(): void {
		$after = $this->published();
		$after['nodes'] = [$after['nodes'][0], $after['nodes'][2]];

		$result = $this->diff->compare(published: $this->published(), candidate: $after);

		$this->assertSame(['b'], $result['removedNodes']);
		$this->assertSame([], $result['removedKeys'], 'the node is the change; its keys are not a second one');
	}//end testAKeyThatWentWithItsNodeIsCountedOnce()

	/**
	 * Adding anything is minor.
	 *
	 * @return void
	 */
	public function testAddingIsMinor(): void {
		$after = $this->published();
		$after['nodes'][] = ['id' => 'd', 'type' => 'openregister.filter', 'config' => ['when' => 'x']];
		$after['nodes'][1]['config']['priority'] = 'high';
		$after['edges'][] = ['id' => 'e3', 'from' => 'c', 'to' => 'd'];

		$result = $this->diff->compare(published: $this->published(), candidate: $after);

		$this->assertSame(FlowGraphDiff::MINOR, $result['verdict']);
	}//end testAddingIsMinor()

	/**
	 * Changing a VALUE is minor: it is not detectable as breaking from the
	 * graph, which is what the author's override exists for.
	 *
	 * @return void
	 */
	public function testChangingAValueIsMinor(): void {
		$after = $this->published();
		$after['nodes'][1]['config']['assignee'] = 'somebody-else';

		$result = $this->diff->compare(published: $this->published(), candidate: $after);

		$this->assertSame(FlowGraphDiff::MINOR, $result['verdict']);
	}//end testChangingAValueIsMinor()

	/**
	 * An identical republish is minor, and never a refusal.
	 *
	 * @return void
	 */
	public function testAnIdenticalRepublishIsMinor(): void {
		$result = $this->diff->compare(published: $this->published(), candidate: $this->published());

		$this->assertSame(FlowGraphDiff::MINOR, $result['verdict']);
		$this->assertSame([], $result['removedNodes']);
		$this->assertSame([], $result['removedEdges']);
		$this->assertSame([], $result['removedKeys']);
	}//end testAnIdenticalRepublishIsMinor()

	/**
	 * The same connection written as a string and as a list is ONE edge.
	 *
	 * The editor writes `from: 'a'` and the dossiq projections write
	 * `from: ['a']`. Reading those as different would report a removal and an
	 * addition on a graph nobody touched.
	 *
	 * @return void
	 */
	public function testTheTwoEdgeDialectsAreTheSameEdge(): void {
		$before = $this->published();
		$after = $this->published();
		$after['edges'] = [
			['id' => 'e1', 'from' => ['a'], 'to' => ['b']],
			['id' => 'e2', 'from' => ['b'], 'to' => ['c']],
		];

		$result = $this->diff->compare(published: $before, candidate: $after);

		$this->assertSame(FlowGraphDiff::MINOR, $result['verdict']);
		$this->assertSame([], $result['removedEdges']);
	}//end testTheTwoEdgeDialectsAreTheSameEdge()

	/**
	 * An omitted config and an empty one both mean "no keys".
	 *
	 * @return void
	 */
	public function testAnOmittedConfigIsNotARemoval(): void {
		$before = ['nodes' => [['id' => 'a', 'config' => []]], 'edges' => []];
		$after = ['nodes' => [['id' => 'a']], 'edges' => []];

		$this->assertSame(FlowGraphDiff::MINOR, $this->diff->compare(published: $before, candidate: $after)['verdict']);
	}//end testAnOmittedConfigIsNotARemoval()

	/**
	 * The summary names what went, and says nothing when nothing did.
	 *
	 * @return void
	 */
	public function testTheSummaryNamesWhatWentAndIsEmptyOtherwise(): void {
		$after = $this->published();
		$after['nodes'] = [$after['nodes'][0]];
		$after['edges'] = [];

		$diff = $this->diff->compare(published: $this->published(), candidate: $after);
		$summary = $this->diff->summarise(diff: $diff);

		$this->assertStringContainsString('b', $summary);
		$this->assertStringContainsString('a->b', $summary);
		$this->assertSame('', $this->diff->summarise(diff: $this->diff->compare(published: $this->published(), candidate: $this->published())));
	}//end testTheSummaryNamesWhatWentAndIsEmptyOtherwise()
}//end class
