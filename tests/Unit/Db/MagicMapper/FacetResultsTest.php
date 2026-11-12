<?php

/**
 * FacetResultsTest: collecting computed facets, @self facets and their timings.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\FacetResults;
use PHPUnit\Framework\TestCase;

/**
 * Timings come from the clock, so they are checked for presence and lower
 * bounds only, never pinned.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\FacetResults
 */
class FacetResultsTest extends TestCase {

	public function testAComputedFacetIsKeptWithItsTitleAndTiming(): void {
		$results = new FacetResults(microtime(true), []);

		$results->putField('status', ['facet' => ['buckets' => [['key' => 'open']]], 'config' => ['title' => 'Status'], 'start' => microtime(true) - 0.5]);

		$this->assertSame(['status' => ['buckets' => [['key' => 'open']], 'title' => 'Status']], $results->facets);
		$this->assertGreaterThanOrEqual(500.0, $results->times['status']);
	}//end testAComputedFacetIsKeptWithItsTitleAndTiming()

	public function testAFacetThatComputedNothingIsLeftOutButStillTimed(): void {
		$results = new FacetResults(microtime(true), []);

		$results->putField('owner', ['facet' => null, 'config' => [], 'start' => microtime(true)]);

		$this->assertSame([], $results->facets);
		$this->assertArrayHasKey('owner', $results->times);
	}//end testAFacetThatComputedNothingIsLeftOutButStillTimed()

	public function testATitleWithoutAFacetStillNamesTheField(): void {
		$results = new FacetResults(microtime(true), []);

		$results->putField(3, ['facet' => null, 'config' => ['title' => 'Jaar'], 'start' => microtime(true)]);

		$this->assertSame([3 => ['title' => 'Jaar']], $results->facets);
	}//end testATitleWithoutAFacetStillNamesTheField()

	public function testWithoutSelfConfigNoSelfFacetsAreComputed(): void {
		$results = new FacetResults(microtime(true), ['@self' => 'not-a-list']);
		$called = 0;

		$results->addSelfFacets(
			static function () use (&$called) {
				$called++;
				return [];
			}
		);

		$this->assertSame(0, $called);
		$this->assertArrayNotHasKey('@self', $results->facets);
	}//end testWithoutSelfConfigNoSelfFacetsAreComputed()

	public function testSelfFacetsAreComputedPerFieldAndEmptyOnesLeftOut(): void {
		$results = new FacetResults(microtime(true), ['@self' => ['register' => ['type' => 'terms'], 'owner' => ['type' => 'terms']]]);
		$asked = [];

		$results->addSelfFacets(
			static function (string $field, array $config) use (&$asked): ?array {
				$asked[] = [$field, $config['type']];
				return ($field === 'register' ? ['buckets' => [['key' => 1]]] : null);
			}
		);

		$this->assertSame([['register', 'terms'], ['owner', 'terms']], $asked);
		$this->assertSame(['@self' => ['register' => ['buckets' => [['key' => 1]]]]], $results->facets);
		$this->assertSame(['@self.register', '@self.owner'], array_keys($results->times));
	}//end testSelfFacetsAreComputedPerFieldAndEmptyOnesLeftOut()

	public function testWithMetricsAddsTotalsAfterTheFacets(): void {
		$results = new FacetResults(microtime(true) - 1.0, []);
		$results->putField('status', ['facet' => ['buckets' => []], 'config' => [], 'start' => microtime(true)]);

		$facets = $results->withMetrics(['queries' => 2], ['hits' => 5]);

		$this->assertSame(['status', '_metrics'], array_keys($facets));
		$this->assertSame(['total_ms', 'queries', 'per_facet_ms', 'label_cache'], array_keys($facets['_metrics']));
		$this->assertGreaterThanOrEqual(1000.0, $facets['_metrics']['total_ms']);
		$this->assertSame(2, $facets['_metrics']['queries']);
		$this->assertSame(['status'], array_keys($facets['_metrics']['per_facet_ms']));
		$this->assertSame(['hits' => 5], $facets['_metrics']['label_cache']);
		$this->assertArrayNotHasKey('_metrics', $results->facets);
	}//end testWithMetricsAddsTotalsAfterTheFacets()
}//end class
