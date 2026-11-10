<?php

/**
 * NamedConditionGraph: finding the first reference cycle and the first named
 * condition nested deeper than NamedConditionLibrary::MAX_DEPTH.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Rules\NamedConditionGraph;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rules\NamedConditionGraph
 */
class NamedConditionGraphTest extends TestCase {

	/**
	 * A straight chain c0 -> c1 -> ... -> c$length.
	 *
	 * @param int $length The number of edges.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function chain(int $length): array {
		$edges = [];
		for ($i = 0; $i < $length; $i++) {
			$edges["c{$i}"] = ['c' . ($i + 1)];
		}

		return $edges;
	}

	public function testAnAcyclicGraphHasNoCycle(): void {
		$graph = new NamedConditionGraph(['a' => ['b', 'external'], 'b' => [], 'c' => ['b']]);

		$this->assertNull($graph->firstCycle());
	}

	public function testAnEmptyGraphHasNoCycleAndNothingTooDeep(): void {
		$graph = new NamedConditionGraph([]);

		$this->assertNull($graph->firstCycle());
		$this->assertNull($graph->firstTooDeep());
	}

	public function testASelfReferenceIsACycle(): void {
		$this->assertSame(['a', 'a'], (new NamedConditionGraph(['a' => ['a']]))->firstCycle());
	}

	public function testTheCycleIsReportedAsThePathThatClosesIt(): void {
		$graph = new NamedConditionGraph(['start' => ['x'], 'x' => ['y'], 'y' => ['missing', 'x']]);

		$this->assertSame(['start', 'x', 'y', 'x'], $graph->firstCycle());
	}

	public function testAChainExactlyAtTheLimitIsNotTooDeep(): void {
		$this->assertNull((new NamedConditionGraph(self::chain(NamedConditionLibrary::MAX_DEPTH)))->firstTooDeep());
	}

	public function testAChainOneBeyondTheLimitNamesItsRoot(): void {
		$this->assertSame('c0', (new NamedConditionGraph(self::chain(NamedConditionLibrary::MAX_DEPTH + 1)))->firstTooDeep());
	}

	public function testTheDeepestBranchDecides(): void {
		$edges = self::chain(NamedConditionLibrary::MAX_DEPTH + 1);
		$edges = ['shallowRoot' => ['leaf'], 'root' => ['leaf', 'c0']] + $edges;

		$this->assertSame('root', (new NamedConditionGraph($edges))->firstTooDeep());
	}

	public function testACycleIsReportedTooDeepRatherThanRecursingForever(): void {
		$this->assertSame('a', (new NamedConditionGraph(['a' => ['b'], 'b' => ['a']]))->firstTooDeep());
	}
}
