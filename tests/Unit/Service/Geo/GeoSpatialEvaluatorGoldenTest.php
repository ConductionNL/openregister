<?php

/**
 * Golden test for GeoSpatialEvaluator.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Geo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Geo;

use OCA\OpenRegister\Search\Service\Geo\GeoFilter;
use OCA\OpenRegister\Search\Service\Geo\GeoSpatialEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Runs every filter against every row geometry and records the match matrix
 * as one string of 1/0 per filter (E where the evaluator throws a TypeError),
 * with any PHP warnings raised on the way. Compared with
 * fixtures/geo-spatial-evaluator-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Geo\GeoSpatialEvaluator
 */
class GeoSpatialEvaluatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/geo-spatial-evaluator-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public static function rows(): array {
		$square = [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]];
		return [
			'null'                       => [null],
			'point inside'               => [['type' => 'Point', 'coordinates' => [5, 5]]],
			'point outside'              => [['type' => 'Point', 'coordinates' => [15, 5]]],
			'point on west edge'         => [['type' => 'Point', 'coordinates' => [0, 5]]],
			'point numeric strings'      => [['type' => 'Point', 'coordinates' => ['5.5', '4']]],
			'point non-numeric'          => [['type' => 'Point', 'coordinates' => ['a', 4]]],
			'point one coordinate'       => [['type' => 'Point', 'coordinates' => [5]]],
			'point coordinates scalar'   => [['type' => 'Point', 'coordinates' => 5]],
			'point no type'              => [['coordinates' => [5, 5]]],
			'polygon inside'             => [['type' => 'Polygon', 'coordinates' => [[[2, 2], [4, 2], [4, 4], [2, 4], [2, 2]]]]],
			'polygon overlapping'        => [['type' => 'Polygon', 'coordinates' => [[[8, 8], [12, 8], [12, 12], [8, 12], [8, 8]]]]],
			'polygon around predicate'   => [['type' => 'Polygon', 'coordinates' => [[[-5, -5], [15, -5], [15, 15], [-5, 15], [-5, -5]]]]],
			'polygon crossing no vertex' => [['type' => 'Polygon', 'coordinates' => [[[-5, 4], [15, 4], [15, 6], [-5, 6], [-5, 4]]]]],
			'polygon far away'           => [['type' => 'Polygon', 'coordinates' => [[[50, 50], [52, 50], [52, 52], [50, 50]]]]],
			'polygon empty ring'         => [['type' => 'Polygon', 'coordinates' => [[]]]],
			'polygon ring not array'     => [['type' => 'Polygon', 'coordinates' => ['x']]],
			'polygon bad vertices'       => [['type' => 'Polygon', 'coordinates' => [[[1, 1], 'x', [2], ['a', 3], [3, 3], [1, 3]]]]],
			'polygon only bad vertices'  => [['type' => 'Polygon', 'coordinates' => [[['a', 'b'], [1]]]]],
			'multipolygon second inside' => [['type' => 'MultiPolygon', 'coordinates' => [
				[[[20, 20], [22, 20], [22, 22], [20, 20]]],
				[[[3, 3], [4, 3], [4, 4], [3, 3]]],
			]]],
			'multipolygon first inside'  => [['type' => 'MultiPolygon', 'coordinates' => [[$square]]]],
			'multipolygon empty'         => [['type' => 'MultiPolygon', 'coordinates' => []]],
			'linestring'                 => [['type' => 'LineString', 'coordinates' => [[6, 6], [30, 30]]]],
			'linestring bad first'       => [['type' => 'LineString', 'coordinates' => [[6], [30, 30]]]],
			'linestring scalar first'    => [['type' => 'LineString', 'coordinates' => [6, 30]]],
			'unknown type'               => [['type' => 'Curve', 'coordinates' => [5, 5]]],
			'triangle two vertices'      => [['type' => 'Polygon', 'coordinates' => [[[1, 1], [2, 2]]]]],
			'flat ring'                  => [['type' => 'Polygon', 'coordinates' => [[[1, 5], [9, 5], [9, 5], [1, 5]]]]],
		];
	}

	/**
	 * @return list<GeoFilter>
	 */
	private static function filters(): array {
		$square = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]]];
		$withNull = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [null, 10], [10, 10], [0, 10]]]];
		$horizontal = ['type' => 'Polygon', 'coordinates' => [[[0.0, 0.0], [10.0, 0.0], [10.0, 10.0], [0.0, 10.0]]]];
		$multi = ['type' => 'MultiPolygon', 'coordinates' => [
			[[[100, 100], [101, 100], [101, 101], [100, 100]]],
			[[[0, 0], [6, 0], [6, 6], [0, 6], [0, 0]]],
		]];
		return [
			GeoFilter::fromBbox('0,0,10,10'),
			GeoFilter::fromBbox('4,4,6,6'),
			GeoFilter::fromBbox('-180,-90,180,90'),
			GeoFilter::fromNearAndRadius(5, 5, 1000),
			GeoFilter::fromNearAndRadius(5, 5, 600000),
			GeoFilter::fromNearAndRadius('0', '5', '1'),
			GeoFilter::fromWithinGeometry($square),
			GeoFilter::fromWithinGeometry($multi),
			GeoFilter::fromWithinGeometry($withNull),
			GeoFilter::fromWithinGeometry($horizontal),
			GeoFilter::fromIntersectsGeometry($square),
			GeoFilter::fromIntersectsGeometry($multi),
			GeoFilter::fromIntersectsGeometry(['type' => 'Polygon', 'coordinates' => [[[3, 3], [7, 3], [7, 7], [3, 7], [3, 3]]]]),
		];
	}

	/**
	 * @param array<string, mixed>|null $row The row geometry.
	 *
	 * @return void
	 */
	#[DataProvider('rows')]
	public function testMatchMatrix(?array $row): void {
		$evaluator = new GeoSpatialEvaluator();
		$bits = '';
		$warnings = [];
		set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		});
		try {
			foreach (self::filters() as $filter) {
				$bits .= $this->matchBit($evaluator, $row, $filter);
			}
		} finally {
			restore_error_handler();
		}

		$recorded = $bits;
		if ($warnings !== []) {
			$recorded = ['bits' => $bits, 'warnings' => $warnings];
		}

		$this->assertGolden((string) $this->dataName(), $recorded);
	}

	/**
	 * @param GeoSpatialEvaluator       $evaluator The evaluator.
	 * @param array<string, mixed>|null $row       The row geometry.
	 * @param GeoFilter                 $filter    The filter.
	 *
	 * @return string 1 or 0, or E where the evaluator throws a TypeError.
	 */
	private function matchBit(GeoSpatialEvaluator $evaluator, ?array $row, GeoFilter $filter): string {
		try {
			return (string) (int) $evaluator->matches($row, $filter);
		} catch (TypeError $e) {
			// Malformed rings reach a typed parameter on the intersects path; pinned as found.
			return 'E';
		}
	}

	public function testHaversine(): void {
		$evaluator = new GeoSpatialEvaluator();
		$distances = [
			round($evaluator->haversineMeters(4.9, 52.37, 4.48, 51.92), 6),
			round($evaluator->haversineMeters(0.0, 0.0, 0.0, 0.0), 6),
			round($evaluator->haversineMeters(-179.5, 10.0, 179.5, -10.0), 6),
		];
		$this->assertGolden('haversine', $distances);
	}

	/**
	 * @param string $case   The case name.
	 * @param mixed  $actual The recorded value.
	 *
	 * @return void
	 */
	private function assertGolden(string $case, mixed $actual): void {
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
