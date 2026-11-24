<?php

/**
 * ObjectRelationsReachability behaviour tests.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ObjectRelationsReachability;
use PHPUnit\Framework\TestCase;

/**
 * A refused node is cut out with its edges, and whatever was reachable only
 * through it leaves the graph too.
 */
class ObjectRelationsReachabilityTest extends TestCase {

	/**
	 * The uuids of the nodes in a graph.
	 *
	 * @param array $graph The graph.
	 *
	 * @return list<string>
	 */
	private function nodeUuids(array $graph): array {
		return array_map(static fn (array $node): string => $node['uuid'], $graph['nodes']);
	}//end nodeUuids()

	/**
	 * A chain root-a-b with b's only path through a: refusing a drops a and b.
	 *
	 * @return void
	 */
	public function testRefusingAHopDropsWhatLiesBehindIt(): void {
		$graph = [
			'root' => 'r',
			'nodes' => [['uuid' => 'r'], ['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => 'c']],
			'edges' => [
				['from' => 'r', 'to' => 'a'],
				['from' => 'a', 'to' => 'b'],
				['from' => 'c', 'to' => 'r'],
			],
		];

		$result = (new ObjectRelationsReachability())->reachableFromRoot($graph, ['a' => true]);

		$this->assertSame(['r', 'c'], $this->nodeUuids($result));
		$this->assertSame([['from' => 'c', 'to' => 'r']], $result['edges']);
		$this->assertSame('r', $result['root']);
	}//end testRefusingAHopDropsWhatLiesBehindIt()

	/**
	 * Nothing refused keeps every connected node, following edges in both directions.
	 *
	 * @return void
	 */
	public function testNothingRefusedKeepsTheConnectedGraph(): void {
		$graph = [
			'root' => 'r',
			'nodes' => [['uuid' => 'r'], ['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => 'island']],
			'edges' => [
				['from' => 'b', 'to' => 'a'],
				['from' => 'a', 'to' => 'r'],
			],
		];

		$result = (new ObjectRelationsReachability())->reachableFromRoot($graph, []);

		$this->assertSame(['r', 'a', 'b'], $this->nodeUuids($result));
		$this->assertCount(2, $result['edges']);
	}//end testNothingRefusedKeepsTheConnectedGraph()

	/**
	 * A node reachable another way survives the refusal of one of its neighbours.
	 *
	 * @return void
	 */
	public function testANodeWithASecondPathSurvives(): void {
		$graph = [
			'root' => 'r',
			'nodes' => [['uuid' => 'r'], ['uuid' => 'a'], ['uuid' => 'b']],
			'edges' => [
				['from' => 'r', 'to' => 'a'],
				['from' => 'a', 'to' => 'b'],
				['from' => 'r', 'to' => 'b'],
			],
		];

		$result = (new ObjectRelationsReachability())->reachableFromRoot($graph, ['a' => true]);

		$this->assertSame(['r', 'b'], $this->nodeUuids($result));
		$this->assertSame([['from' => 'r', 'to' => 'b']], $result['edges']);
	}//end testANodeWithASecondPathSurvives()

	/**
	 * Malformed nodes and edges (non-arrays, missing lists) are dropped, not fatal.
	 *
	 * @return void
	 */
	public function testMalformedRowsAreIgnored(): void {
		$graph = [
			'root' => 'r',
			'nodes' => [['uuid' => 'r'], 'junk', ['name' => 'no uuid']],
			'edges' => 'not a list',
		];

		$result = (new ObjectRelationsReachability())->reachableFromRoot($graph, []);

		$this->assertSame(['r'], $this->nodeUuids($result));
		$this->assertSame([], $result['edges']);
	}//end testMalformedRowsAreIgnored()

	/**
	 * A graph without nodes, edges or root comes back empty.
	 *
	 * @return void
	 */
	public function testEmptyGraphStaysEmpty(): void {
		$result = (new ObjectRelationsReachability())->reachableFromRoot([], ['x' => true]);

		$this->assertSame(['nodes' => [], 'edges' => []], $result);
	}//end testEmptyGraphStaysEmpty()

	/**
	 * A refused root itself is removed even though it is the starting point.
	 *
	 * @return void
	 */
	public function testARefusedRootIsRemoved(): void {
		$graph = [
			'root' => 'r',
			'nodes' => [['uuid' => 'r'], ['uuid' => 'a']],
			'edges' => [['from' => 'r', 'to' => 'a']],
		];

		$result = (new ObjectRelationsReachability())->reachableFromRoot($graph, ['r' => true]);

		$this->assertSame([], $result['nodes']);
		$this->assertSame([], $result['edges']);
	}//end testARefusedRootIsRemoved()
}//end class
