<?php

/**
 * PerformanceOptimizationHandler::getPerformanceRecommendations() recommends exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler;
use OCA\OpenRegister\Service\OrganisationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden record of the recommendations at, just below and just above every threshold, for each
 * timing and for the `_extend` count. Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\PerformanceOptimizationHandler
 */
class PerformanceRecommendationsGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/performance-recommendations-golden.json';

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$scenarios = [
			'fast, nothing else'        => [12.5, [], []],
			'at 500'                    => [500.0, [], []],
			'just over 500'             => [500.5, [], []],
			'at 2000'                   => [2000.0, [], []],
			'over 2000'                 => [2500.0, [], []],
			'db at 200'                 => [10.0, ['database_query' => 200], []],
			'db over 200'               => [10.0, ['database_query' => 250.75], []],
			'relations at 1000'         => [10.0, ['relationship_loading' => 1000], []],
			'relations over 1000'       => [10.0, ['relationship_loading' => 1001], []],
			'json at 100'               => [10.0, ['json_processing' => 100], []],
			'json over 100'             => [10.0, ['json_processing' => 101], []],
			'extend string'             => [10.0, [], ['_extend' => 'a,b,c,d,e']],
			'extend three'              => [10.0, [], ['_extend' => ['a', 'b', 'c']]],
			'extend four'               => [10.0, [], ['_extend' => ['a', 'b', 'c', 'd']]],
			'extend empty'              => [10.0, [], ['_extend' => []]],
			'everything at once'        => [3000.0, ['database_query' => 300, 'relationship_loading' => 2000, 'json_processing' => 150], ['_extend' => ['a', 'b', 'c', 'd', 'e']]],
			'slow with only json'       => [800.0, ['json_processing' => 120], []],
			'fast with only json'       => [100.0, ['json_processing' => 120], []],
			'non-numeric timing'        => [10.0, ['database_query' => null], []],
		];

		$handler = new PerformanceOptimizationHandler(
			$this->createMock(OrganisationService::class),
			$this->createMock(LoggerInterface::class)
		);

		$actual = [];
		foreach ($scenarios as $name => [$totalTime, $timings, $query]) {
			$actual[$name] = $handler->getPerformanceRecommendations(totalTime: $totalTime, perfTimings: $timings, query: $query);
		}

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(expected: $expected, actual: json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()
}//end class
