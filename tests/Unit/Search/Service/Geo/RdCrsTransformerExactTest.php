<?php

/**
 * Characterisation test for RdCrsTransformer: exact outputs over a grid.
 *
 * RdCrsTransformerTest checks the transforms against known reference points
 * within a tolerance. This pins the exact float each direction returns for a
 * grid across the Netherlands (and a little beyond), so restructuring the
 * polynomial evaluation cannot move a single bit.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Geo;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- PHPUnit arrange/act/assert conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Search\Service\Geo\RdCrsTransformer;
use PHPUnit\Framework\TestCase;

class RdCrsTransformerExactTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/rd-crs-exact.json';

	/**
	 * Every grid point's output in both directions, as exact float strings.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function outputs(): array {
		$transformer = new RdCrsTransformer();
		$out = [];
		for ($x = -10000.0; $x <= 300000.0; $x += 15500.0) {
			for ($y = 280000.0; $y <= 640000.0; $y += 18000.0) {
				[$lon, $lat] = $transformer->rdToWgs84($x, $y);
				$out['rd:' . $x . ',' . $y] = [var_export($lon, true), var_export($lat, true)];
			}
		}

		for ($lon = 3.0; $lon <= 7.4; $lon += 0.22) {
			for ($lat = 50.6; $lat <= 53.8; $lat += 0.16) {
				[$rx, $ry] = $transformer->wgs84ToRd($lon, $lat);
				$out['wgs:' . $lon . ',' . $lat] = [var_export($rx, true), var_export($ry, true)];
			}
		}

		return $out;
	}

	public function testEveryGridPointIsUnchanged(): void {
		if (getenv('RD_CRS_WRITE_FIXTURE') === '1') {
			@mkdir(dirname(self::FIXTURE));
			file_put_contents(self::FIXTURE, json_encode(self::outputs(), JSON_PRETTY_PRINT) . "\n");
		}

		$this->assertSame(json_decode((string) file_get_contents(self::FIXTURE), true), self::outputs());
	}
}
