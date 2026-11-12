<?php

/**
 * Golden test for GeoFeatureCollectionBuilder.
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

use OCA\OpenRegister\Search\Service\Geo\FeatureShape;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Search\Service\Geo\GetFeatureRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case calls one public builder method and records its result plus any
 * PHP warnings raised. Compared with fixtures/geo-feature-collection-golden.json;
 * set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder
 */
class GeoFeatureCollectionBuilderGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/geo-feature-collection-golden.json';

	/**
	 * @return array<string, array{0: string, 1: list<mixed>}>
	 */
	public static function cases(): array {
		$square = ['type' => 'Polygon', 'coordinates' => [[[4.0, 52.0], [4.01, 52.0], [4.01, 52.01], [4.0, 52.01], [4.0, 52.0]]]];
		$withHole = ['type' => 'Polygon', 'coordinates' => [
			[[4.0, 52.0], [4.01, 52.0], [4.01, 52.01], [4.0, 52.01], [4.0, 52.0]],
			[[4.002, 52.002], [4.004, 52.002], [4.004, 52.004], [4.002, 52.004], [4.002, 52.002]],
		]];
		$multi = ['type' => 'MultiPolygon', 'coordinates' => [$square['coordinates'], [], 'x', $withHole['coordinates']]];
		$rows = [
			['id' => 1, 'name' => 'a', 'location' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]], 'kind' => 'x'],
			['@self' => ['id' => 'u-2'], 'area' => $square, 'name' => 'b'],
			['name' => 'no geometry', 'location' => ['type' => 'Point']],
			'not a row',
			['name' => 'bad type', 'location' => ['type' => 'Circle', 'coordinates' => [1, 2]]],
			['id' => 4, 'location' => ['type' => 'Point', 'coordinates' => 'x'], 'shape' => $withHole],
			[0 => ['type' => 'Point', 'coordinates' => [1, 2]], 'name' => 'numeric key'],
		];
		return [
			'collection auto-detect'          => ['collection', [$rows]],
			'collection by property'          => ['collection', [$rows, 'location']],
			'collection fields and area'      => ['collection', [$rows, null, ['name', 'id'], true]],
			'collection empty fields'         => ['collection', [$rows, 'area', [], true]],
			'feature multipolygon area'       => ['feature', [['id' => 9, 'geo' => $multi], 'geo', null, true]],
			'feature linestring area'         => ['feature', [['geo' => ['type' => 'LineString', 'coordinates' => [[1, 2], [3, 4]]]], null, null, true]],
			'feature none'                    => ['feature', [['x' => 1]]],
			'wfs all'                         => ['wfs', [$rows]],
			'wfs capped'                      => ['wfs', [$rows, null, 2]],
			'wfs zero'                        => ['wfs', [$rows, null, 0]],
			'wfs negative cap'                => ['wfs', [$rows, null, -1]],
			'area polygon'                    => ['area', [$square]],
			'area with hole'                  => ['area', [$withHole]],
			'area multipolygon'               => ['area', [$multi]],
			'area point'                      => ['area', [['type' => 'Point', 'coordinates' => [1, 2]]]],
			'area no type'                    => ['area', [['coordinates' => []]]],
			'area polygon no coordinates'     => ['area', [['type' => 'Polygon']]],
			'area multipolygon scalar coords' => ['area', [['type' => 'MultiPolygon', 'coordinates' => 'x']]],
			'area short ring'                 => ['area', [['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [0, 0]]]]]],
			'area ring with bad vertices'     => [
				'area',
				[['type' => 'Polygon', 'coordinates' => [[[0, 0], 'x', [1, 0], ['a', 1], [1, 1], [0, 0]]]]],
			],
			'area vertex without latitude'    => ['area', [['type' => 'Polygon', 'coordinates' => [[[0, 0], [1], [1, 1], [0, 1], [0, 0]]]]]],
			'area assoc ring'                 => [
				'area',
				[['type' => 'Polygon', 'coordinates' => [['a' => [0, 0], 'b' => [1, 0], 'c' => [1, 1], 'd' => [0, 0]]]]],
			],
		];
	}

	/**
	 * @param string      $call What to call.
	 * @param list<mixed> $args The arguments.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testBuild(string $call, array $args): void {
		$builder = new GeoFeatureCollectionBuilder();
		$warnings = [];
		set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
			$warnings[] = $message;
			return true;
		});
		try {
			$result = match ($call) {
				'collection' => $builder->buildFeatureCollection($args[0], new FeatureShape(...array_slice($args, 1))),
				'feature' => $builder->feature($args[0], new FeatureShape(...array_slice($args, 1))),
				'wfs' => $builder->buildWfsResponse($args[0], new GetFeatureRequest(...array_slice($args, 1))),
				default => $builder->geodesicAreaM2(...$args),
			};
		} finally {
			restore_error_handler();
		}

		$this->assertGolden((string) $this->dataName(), ['result' => $result, 'warnings' => $warnings]);
	}

	/**
	 * @param string $case   The case name.
	 * @param mixed  $actual The recorded value.
	 *
	 * @return void
	 */
	private function assertGolden(string $case, mixed $actual): void {
		$actual = json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
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
