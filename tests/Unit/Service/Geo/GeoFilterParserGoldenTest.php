<?php

/**
 * Golden test for GeoFilterParser.
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

use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Geo\GeoFilter;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each case parses query params or a geo-search body and records the filters
 * (type, payload, property) or the error. Compared with
 * fixtures/geo-filter-parser-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\Search\Service\Geo\GeoFilterParser
 */
class GeoFilterParserGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/geo-filter-parser-golden.json';

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function cases(): array {
		$square = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]]]];
		return [
			'params empty'                 => ['params', []],
			'params unrelated'             => ['params', ['q' => 'x', 'geo.other' => 1]],
			'params bbox'                  => ['params', ['geo.bbox' => '4.1, 52.0,5,53']],
			'params bbox empty'            => ['params', ['geo.bbox' => '']],
			'params bbox null'             => ['params', ['geo.bbox' => null]],
			'params bbox bad'              => ['params', ['geo.bbox' => '1,2,3']],
			'params bbox int property'     => ['params', ['geo.bbox' => '0,0,1,1', 'geo.property' => 12]],
			'params near and radius'       => ['params', ['geo.near' => ' 4.9 , 52.3 ', 'geo.radius' => 250, 'geo.property' => 'location']],
			'params near without radius'   => ['params', ['geo.near' => '4.9,52.3']],
			'params radius without near'   => ['params', ['geo.radius' => '10']],
			'params near empty radius'     => ['params', ['geo.near' => '4.9,52.3', 'geo.radius' => '']],
			'params near three values'     => ['params', ['geo.near' => '1,2,3', 'geo.radius' => '5']],
			'params near non-numeric'      => ['params', ['geo.near' => 'a,2', 'geo.radius' => '5']],
			'params zero radius'           => ['params', ['geo.near' => '1,2', 'geo.radius' => '0']],
			'params bbox and near'         => ['params', ['geo.bbox' => '0,0,1,1', 'geo.near' => '1,2', 'geo.radius' => '5', 'geo.property' => 'p']],
			'params bad bbox before pair'  => ['params', ['geo.bbox' => 'x', 'geo.near' => '1,2']],
			'params pair error before near' => ['params', ['geo.bbox' => '0,0,1,1', 'geo.near' => '1']],
			'body no geometry'             => ['body', []],
			'body geometry scalar'         => ['body', ['geometry' => 'x']],
			'body empty geometry'          => ['body', ['geometry' => []]],
			'body within'                  => ['body', ['geometry' => ['within' => $square], 'property' => 'area']],
			'body intersects'              => ['body', ['geometry' => ['intersects' => $square], 'property' => 5]],
			'body both'                    => ['body', ['geometry' => ['intersects' => $square, 'within' => $square]]],
			'body within scalar'           => ['body', ['geometry' => ['within' => 'x', 'intersects' => $square]]],
			'body intersects scalar'       => ['body', ['geometry' => ['within' => $square, 'intersects' => 3]]],
			'body within null'             => ['body', ['geometry' => ['within' => null]]],
			'body within point'            => ['body', ['geometry' => ['within' => ['type' => 'Point', 'coordinates' => [1, 2]]]]],
			'body property null'           => ['body', ['geometry' => ['within' => $square], 'property' => null]],
		];
	}

	/**
	 * @param string               $kind  params or body.
	 * @param array<string, mixed> $input The input.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testParse(string $kind, array $input): void {
		$parser = new GeoFilterParser();
		try {
			$filters = match ($kind) {
				'params' => $parser->fromQueryParams($input),
				default => $parser->fromGeoSearchBody($input),
			};
			$actual = array_map(
				static fn (GeoFilter $filter): array => ['type' => $filter->type, 'payload' => $filter->payload, 'property' => $filter->property],
				$filters
			);
		} catch (InvalidArgumentException $e) {
			$actual = ['error' => $e->getMessage()];
		}

		$actual = json_decode((string) json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		$case = (string) $this->dataName();
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
