<?php

/**
 * Characterisation of ObjectRelationsReachability::reachableFromRoot(): a refused object
 * disappears with every edge touching it, and so does everything only reachable through it;
 * malformed rows are dropped and the rest of the graph is kept as it was.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller\Support;

use OCA\OpenRegister\Controller\Support\ObjectRelationsReachability;
use PHPUnit\Framework\TestCase;

/**
 * Golden pruned relation graphs.
 *
 * @covers \OCA\OpenRegister\Controller\Support\ObjectRelationsReachability
 */
final class ObjectRelationsReachabilityGoldenTest extends TestCase {

	/**
	 * A graph: root r, r-a, a-b, b-c, r-d, and an island x-y; nodes carry a label.
	 *
	 * @return array<string, mixed>
	 */
	private static function graph(): array {
		return [
			'root' => 'r',
			'depth' => 3,
			'nodes' => [
				['uuid' => 'r', 'label' => 'root'],
				['uuid' => 'a', 'label' => 'A'],
				['uuid' => 'b', 'label' => 'B'],
				['uuid' => 'c', 'label' => 'C'],
				['uuid' => 'd', 'label' => 'D'],
				['uuid' => 'x', 'label' => 'X'],
				'not a row',
				['label' => 'no uuid'],
			],
			'edges' => [
				['from' => 'r', 'to' => 'a', 'type' => 'has'],
				['from' => 'b', 'to' => 'a', 'type' => 'back'],
				['from' => 'b', 'to' => 'c', 'type' => 'has'],
				['from' => 'r', 'to' => 'd', 'type' => 'has'],
				['from' => 'x', 'to' => 'y', 'type' => 'island'],
				'not a row',
			],
		];
	}//end graph()

	/**
	 * The graphs pruned for each refused set.
	 *
	 * @return array<string, array{0: array<string, true>, 1: list<string>, 2: list<string>}>
	 */
	public static function cases(): array {
		return [
			'nothing refused' => [[], ['r', 'a', 'b', 'c', 'd'], ['r>a', 'b>a', 'b>c', 'r>d']],
			'a refused cuts b and c off' => [['a' => true], ['r', 'd'], ['r>d']],
			'c refused' => [['c' => true], ['r', 'a', 'b', 'd'], ['r>a', 'b>a', 'r>d']],
			'root refused' => [['r' => true], [], []],
			'unknown refused' => [['zz' => true], ['r', 'a', 'b', 'c', 'd'], ['r>a', 'b>a', 'b>c', 'r>d']],
		];
	}//end cases()

	/**
	 * The graph keeps what the root still reaches without passing a refused object.
	 *
	 * @param array<string, true> $refused The refused uuids.
	 * @param list<string>        $nodes   The kept node uuids, in order.
	 * @param list<string>        $edges   The kept edges, in order.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testPrunedGraph(array $refused, array $nodes, array $edges): void {
		$pruned = (new ObjectRelationsReachability())->reachableFromRoot(self::graph(), $refused);

		$this->assertSame(['root', 'depth', 'nodes', 'edges'], array_keys($pruned));
		$this->assertSame(3, $pruned['depth']);
		$this->assertSame($nodes, array_map(static fn (array $node): string => $node['uuid'], $pruned['nodes']));
		$this->assertSame($edges, array_map(static fn (array $edge): string => $edge['from'] . '>' . $edge['to'], $pruned['edges']));
		$this->assertTrue(array_is_list($pruned['nodes']));
		$this->assertTrue(array_is_list($pruned['edges']));
		foreach ($pruned['nodes'] as $node) {
			$this->assertArrayHasKey('label', $node);
		}
	}//end testPrunedGraph()

	/**
	 * A graph without nodes or edges stays empty.
	 *
	 * @return void
	 */
	public function testEmptyGraph(): void {
		$this->assertSame(
			['nodes' => [], 'edges' => []],
			(new ObjectRelationsReachability())->reachableFromRoot([], [])
		);
	}//end testEmptyGraph()
}//end class
