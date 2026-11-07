<?php

/**
 * Golden test for the GeoFilter factories.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Geo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Geo;

use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Geo\GeoFilter;
use PHPUnit\Framework\TestCase;

/**
 * Pins the filter each factory builds (type, payload, property) or the
 * message it refuses with. Compared with fixtures/geo-filter-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Geo\GeoFilter
 */
class GeoFilterGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/geo-filter-golden.json';

	/**
	 * Case name => factory call.
	 *
	 * @return array<string, callable(): GeoFilter>
	 */
	private static function cases(): array {
		$polygon = ['type' => 'Polygon', 'coordinates' => [[[4.0, 52.0], [5.0, 52.0], [5.0, 53.0], [4.0, 52.0]]]];
		$cases   = [];
		foreach ([
			'4.1,52.0,5.2,53.3', ' 4 , 52 , 5 , 53 ', '5,52,5,52', '-180,-90,180,90', '1e1,2,3e1,4', '0x1A,1,2,3',
			'5,52,4,53', '4,53,5,52', '6,54,5,53', '4,52,5', '4,52,5,53,6', '', 'a,b,c,d', '4,52,,53', '4,52,5,53x',
		] as $bbox) {
			$cases['bbox "' . $bbox . '"'] = static fn (): GeoFilter => GeoFilter::fromBbox($bbox, 'locatie');
		}

		$cases['bbox no property'] = static fn (): GeoFilter => GeoFilter::fromBbox('1,2,3,4');
		foreach ([
			'near floats' => [4.9, 52.37, 500.0],
			'near strings' => ['4.9', ' 52.37', '1e3'],
			'near zero radius' => ['4.9', '52.37', '0'],
			'near negative radius' => [4.9, 52.37, -1.0],
			'near bad lon' => ['east', '52', '10'],
			'near bad radius' => ['4', '52', 'far'],
		] as $name => [$lon, $lat, $radius]) {
			$cases[$name] = static fn (): GeoFilter => GeoFilter::fromNearAndRadius($lon, $lat, $radius, 'punt');
		}

		$geometries = [
			'polygon' => $polygon,
			'multipolygon' => ['type' => 'MultiPolygon', 'coordinates' => [$polygon['coordinates']]],
			'point' => ['type' => 'Point', 'coordinates' => [4, 52]],
			'lower-case type' => ['type' => 'polygon', 'coordinates' => []],
			'no type' => ['coordinates' => []],
			'type array' => ['type' => ['Polygon'], 'coordinates' => []],
			'no coordinates' => ['type' => 'Polygon'],
			'null coordinates' => ['type' => 'Polygon', 'coordinates' => null],
			'string coordinates' => ['type' => 'MultiPolygon', 'coordinates' => '[]'],
			'empty coordinates' => ['type' => 'Polygon', 'coordinates' => []],
			'empty' => [],
		];
		foreach ($geometries as $name => $geometry) {
			$cases['within ' . $name] = static fn (): GeoFilter => GeoFilter::fromWithinGeometry($geometry, 'vlak');
			$cases['intersects ' . $name] = static fn (): GeoFilter => GeoFilter::fromIntersectsGeometry($geometry);
		}

		return $cases;
	}//end cases()

	public function testFactoriesMatchGolden(): void {
		$actual = [];
		foreach (self::cases() as $name => $build) {
			try {
				$filter = $build();
				$actual[$name] = ['type' => $filter->type, 'payload' => $filter->payload, 'property' => $filter->property];
			} catch (InvalidArgumentException $e) {
				$actual[$name] = ['error' => $e->getMessage()];
			}
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame(array_keys($expected), array_keys($actual));
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name], $name);
		}
	}//end testFactoriesMatchGolden()
}//end class
