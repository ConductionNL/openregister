<?php

/**
 * Golden pins for FlowGraphDiff's comparison and summary.
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
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-semantic-versions/specs/flow-semantic-versions/spec.md#requirement-taking-something-away-is-major-everything-else-is-minor
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow;

use OCA\OpenRegister\Service\Flow\FlowGraphDiff;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the exact verdict, lists and sentence for awkward graphs.
 */
class FlowGraphDiffGoldenTest extends TestCase {

	/**
	 * Published graph, candidate graph, expected comparison, expected summary.
	 *
	 * @return array<string, array{0: array, 1: array, 2: array, 3: string}> The cases.
	 */
	public static function cases(): array {
		$none = ['removedNodes' => [], 'removedEdges' => [], 'removedKeys' => []];
		$graph = ['nodes' => [['id' => 'a', 'config' => ['x' => 1]]], 'edges' => [['from' => 'a', 'to' => 'b']]];

		return [
			'identical' => [$graph, $graph, ['verdict' => 'minor'] + $none, ''],
			'empty' => [[], [], ['verdict' => 'minor'] + $none, ''],
			'everything removed, messy input' => [
				[
					'nodes' => [
						['id' => ' b '],
						['id' => 'a', 'config' => ['k1' => 1, 'k2' => 2, 'z' => 3]],
						['id' => 'c', 'config' => 'bogus'],
						['id' => ''],
						['config' => ['q' => 1]],
						'junk',
						['id' => 'gone', 'config' => ['lost' => 1]],
					],
					'edges' => [
						['from' => 'a', 'to' => ['b', 'c']],
						['from' => ['a', ' c '], 'to' => 'b'],
						['from' => 'a', 'to' => 'b'],
						['from' => null, 'to' => 'b'],
						['from' => '', 'to' => 'x'],
						'junk',
						['from' => 'b', 'to' => 'gone'],
					],
				],
				[
					'nodes' => [['id' => 'a', 'config' => ['k2' => 2]], ['id' => 'b', 'config' => 'x'], ['id' => 'c']],
					'edges' => [['from' => ['a'], 'to' => 'c'], ['from' => 'a', 'to' => 'b']],
				],
				[
					'verdict' => 'major',
					'removedNodes' => ['gone'],
					'removedEdges' => ['b->gone', 'c->b'],
					'removedKeys' => ['a.k1', 'a.z'],
				],
				'This removes steps gone; connections b->gone, c->b; settings a.k1, a.z.',
			],
			'only edges' => [
				['edges' => [['from' => 'x', 'to' => 'y'], ['from' => 'y', 'to' => 'x']]],
				['edges' => [['from' => 'y', 'to' => 'x']]],
				['verdict' => 'major', 'removedNodes' => [], 'removedEdges' => ['x->y'], 'removedKeys' => []],
				'This removes connections x->y.',
			],
			'only keys, sorted' => [
				['nodes' => [['id' => 'n', 'config' => ['b' => 1, 'a' => 2]]]],
				['nodes' => [['id' => 'n', 'config' => []]]],
				['verdict' => 'major', 'removedNodes' => [], 'removedEdges' => [], 'removedKeys' => ['n.a', 'n.b']],
				'This removes settings n.a, n.b.',
			],
			'additions only' => [
				['nodes' => []],
				['nodes' => [['id' => 'new']], 'edges' => [['from' => 'new', 'to' => 'new']]],
				['verdict' => 'minor'] + $none,
				'',
			],
		];
	}//end cases()

	/**
	 * The comparison and its summary are exactly as pinned.
	 *
	 * @param array  $published The published graph.
	 * @param array  $candidate The candidate graph.
	 * @param array  $expected  The comparison.
	 * @param string $summary   The summary.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testCompareAndSummarise(array $published, array $candidate, array $expected, string $summary): void {
		$diff = new FlowGraphDiff();
		$result = $diff->compare(published: $published, candidate: $candidate);

		$this->assertSame($expected, $result);
		$this->assertSame($summary, $diff->summarise(diff: $result));
	}//end testCompareAndSummarise()

	/**
	 * A partial diff summarises only what it carries.
	 *
	 * @return void
	 */
	public function testSummariseToleratesMissingLists(): void {
		$diff = new FlowGraphDiff();

		$this->assertSame('', $diff->summarise(diff: []));
		$this->assertSame('This removes settings x.y.', $diff->summarise(diff: ['removedKeys' => ['x.y']]));
	}//end testSummariseToleratesMissingLists()
}//end class
