<?php

/**
 * Golden test for GeoJsonGeometryValidator.
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

use OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case validates one value for one geo type, or reads the geo type a
 * property declares, and records the exact result. Compared with
 * fixtures/geo-json-geometry-validator-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator
 */
class GeoJsonGeometryValidatorGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/geo-json-geometry-validator-golden.json';

	/**
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	public static function cases(): array {
		return [
			'bag ok' => ['geo:bag', '0363200000123456'],
			'bag short' => ['geo:bag', '123'],
			'bag int' => ['geo:bag', 12345],
			'bag upper type' => ['GEO:BAG', 'x'],
			'non array' => ['geo:point', 'nope'],
			'unknown type' => ['geo:circle', ['type' => 'Point']],
			'point ok' => ['geo:point', ['type' => 'Point', 'coordinates' => [5, 52]]],
			'point wrong type' => ['geo:point', ['type' => 'Polygon', 'coordinates' => [5, 52]]],
			'point no type' => ['geo:point', ['coordinates' => [5, 52]]],
			'point short coords' => ['geo:point', ['type' => 'Point', 'coordinates' => [5]]],
			'point coords string' => ['geo:point', ['type' => 'Point', 'coordinates' => 'x']],
			'point non numeric' => ['geo:point', ['type' => 'Point', 'coordinates' => ['a', 52]]],
			'point non numeric lat' => ['geo:point', ['type' => 'Point', 'coordinates' => [5, 'b']]],
			'point numeric strings' => ['geo:point', ['type' => 'Point', 'coordinates' => ['5.1', '52']]],
			'point lon out' => ['geo:point', ['type' => 'Point', 'coordinates' => [181, 52]]],
			'point lon low' => ['geo:point', ['type' => 'Point', 'coordinates' => [-180.5, 0]]],
			'point lat out' => ['geo:point', ['type' => 'Point', 'coordinates' => [5, 91]]],
			'point both out' => ['geo:point', ['type' => 'Point', 'coordinates' => [-200, -95]]],
			'point edges' => ['geo:point', ['type' => 'Point', 'coordinates' => [180, -90]]],
			'point assoc coords' => ['geo:point', ['type' => 'Point', 'coordinates' => ['a' => 1, 'b' => 2]]],
			'line ok' => ['geo:linestring', ['type' => 'LineString', 'coordinates' => [[5, 52], [6, 53]]]],
			'line wrong type' => ['geo:linestring', ['type' => 'Point', 'coordinates' => [[5, 52], [6, 53]]]],
			'line one pos' => ['geo:linestring', ['type' => 'LineString', 'coordinates' => [[5, 52]]]],
			'line not array' => ['geo:linestring', ['type' => 'LineString', 'coordinates' => 7]],
			'line bad positions' => ['geo:linestring', ['type' => 'LineString', 'coordinates' => [5, [200, 52], ['x', 1], [1, 100]]]],
			'polygon ok' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]]],
			'polygon wrong type' => ['geo:polygon', ['type' => 'MultiPolygon', 'coordinates' => []]],
			'polygon no rings' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => []]],
			'polygon rings scalar' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => 'x']],
			'polygon unclosed' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 1]]]]],
			'polygon short ring' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [0, 0]]]]],
			'polygon scalar ring' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => ['r', [[0, 0], [1, 0], [1, 1], [0, 0]]]]],
			'polygon bad positions' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => [[[0, 0], 'p', [300, 1], ['q', 1], [0, 0]]]]],
			'polygon int vs float closure' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0.0, 0]]]]],
			'polygon two rings' => ['geo:polygon', ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]], [[0, 0], [1, 0], [1, 1], [0, 2], [0, 3]]]]],
			'multi ok' => ['geo:multipolygon', ['type' => 'MultiPolygon', 'coordinates' => [[[[0, 0], [1, 0], [1, 1], [0, 0]]]]]],
			'multi wrong type' => ['geo:multipolygon', ['type' => 'Polygon', 'coordinates' => [[[[0, 0]]]]]],
			'multi empty' => ['geo:multipolygon', ['type' => 'MultiPolygon', 'coordinates' => []]],
			'multi scalar' => ['geo:multipolygon', ['type' => 'MultiPolygon']],
			'multi bad polygons' => ['geo:multipolygon', ['type' => 'MultiPolygon', 'coordinates' => ['k' => 'x', 1 => [], 2 => [[[0, 0], [1, 0], [1, 1], [0, 1]]], 3 => [[[0, 0], [200, 0], [0, 0]]]]]],
			'geometry point' => ['geo:geometry', ['type' => 'Point', 'coordinates' => [5, 52]]],
			'geometry polygon bad' => ['geo:geometry', ['type' => 'Polygon', 'coordinates' => []]],
			'geometry multi bad' => ['geo:geometry', ['type' => 'MultiPolygon', 'coordinates' => []]],
			'geometry line bad' => ['geo:geometry', ['type' => 'LineString', 'coordinates' => []]],
			'geometry unknown' => ['geo:geometry', ['type' => 'Sphere']],
			'geometry no type' => ['geo:geometry', ['coordinates' => [1, 2]]],
			'geometry lowercase point' => ['geo:geometry', ['type' => 'point', 'coordinates' => [1, 2]]],
			'geometry upper type' => ['GEO:Geometry', ['type' => 'Point', 'coordinates' => [1, 2]]],
			'geometry non array' => ['geo:geometry', null],
		];
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function properties(): array {
		return [
			'declared null'            => [null],
			'declared string'          => ['x'],
			'declared empty'           => [[]],
			'declared plain type'      => [['type' => 'string']],
			'declared format mixed'    => [['format' => 'geo:Point']],
			'declared type upper'      => [['type' => 'GEO:BAG']],
			'declared format not geo'  => [['format' => 'date', 'type' => 'geo:polygon']],
			'declared format wins'     => [['format' => 'geo:point', 'type' => 'geo:polygon']],
			'declared format int'      => [['format' => 5, 'type' => 'geo:linestring']],
			'declared type array'      => [['type' => ['geo:point']]],
		];
	}

	#[DataProvider('cases')]
	public function testValidate(string $type, mixed $value): void {
		$this->assertGolden((new GeoJsonGeometryValidator())->validate($type, $value));
	}

	#[DataProvider('properties')]
	public function testDeclaredGeoType(mixed $property): void {
		$this->assertGolden((new GeoJsonGeometryValidator())->declaredGeoType($property));
	}

	private function assertGolden(mixed $actual): void {
		$case = (string) $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$case] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($case, $golden, 'No golden entry; record with UPDATE_GOLDEN=1.');
		$this->assertSame($golden[$case], $actual);
	}
}
