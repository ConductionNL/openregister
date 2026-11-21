<?php

/**
 * RdCrsTransformer — WGS84 <-> RD New (EPSG:28992) coordinate transform.
 *
 * Implements the CRS negotiation of REQ-GEO-015:
 *
 *   - rdToWgs84()      — RD New (EPSG:28992) -> WGS84 (EPSG:4326).
 *   - wgs84ToRd()      — WGS84 (EPSG:4326)  -> RD New (EPSG:28992).
 *   - isSupportedCrs() — only EPSG:4326 and EPSG:28992 are accepted;
 *                        anything else (e.g. EPSG:3857) is rejected so
 *                        the controller can return a 406.
 *
 * Uses the well-known approximate RD <-> WGS84 polynomial (Schreutelkamp
 * & Strang van Hees), accurate to ~0.25 m within the Netherlands — fine
 * for storage/display. Precision-critical geodetic work uses the full
 * RDNAPTRANS2018 grid, out of scope here.
 *
 * Pure data-shaping: no I/O, no DB, no framework. Stored geometry is
 * always WGS84 internally (REQ-GEO-002 / REQ-GEO-015).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Transforms coordinates between WGS84 and RD New (EPSG:28992).
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *   `$x` / `$y` / `$p` / `$q` are the canonical variable names from the
 *   Schreutelkamp & Strang van Hees RD-transformation reference.
 */
class RdCrsTransformer {

	public const CRS_WGS84 = 'EPSG:4326';

	public const CRS_RD = 'EPSG:28992';

	/**
	 * Reference point: Onze Lieve Vrouwetoren, Amersfoort.
	 */
	private const X0 = 155000.0;

	private const Y0 = 463000.0;

	private const PHI0 = 52.15517440;

	private const LAM0 = 5.38720621;

	/**
	 * The RD → WGS84 latitude series, in arc seconds.
	 *
	 * Each term is [coefficient, power of dX, power of dY], summed in this order.
	 *
	 * @var list<array{0: float, 1: int, 2: int}>
	 */
	private const LATITUDE_TERMS = [
		[3235.65389, 0, 1],
		[-32.58297, 2, 0],
		[-0.24750, 0, 2],
		[-0.84978, 2, 1],
		[-0.06550, 0, 3],
		[-0.01709, 2, 2],
		[-0.00738, 1, 0],
		[0.00530, 4, 0],
		[-0.00039, 2, 3],
		[0.00033, 4, 1],
		[-0.00012, 1, 1],
	];

	/**
	 * The RD → WGS84 longitude series, in arc seconds.
	 *
	 * Each term is [coefficient, power of dX, power of dY], summed in this order.
	 *
	 * @var list<array{0: float, 1: int, 2: int}>
	 */
	private const LONGITUDE_TERMS = [
		[5260.52916, 1, 0],
		[105.94684, 1, 1],
		[2.45656, 1, 2],
		[-0.81885, 3, 0],
		[0.05594, 1, 3],
		[-0.05607, 3, 1],
		[0.01199, 0, 1],
		[-0.00256, 3, 2],
		[0.00128, 1, 4],
		[0.00022, 0, 2],
		[-0.00022, 2, 0],
		[0.00026, 5, 0],
	];

	/**
	 * The WGS84 → RD x series, in meters.
	 *
	 * Each term is [coefficient, power of dPhi, power of dLam], summed in this order.
	 *
	 * @var list<array{0: float, 1: int, 2: int}>
	 */
	private const X_TERMS = [
		[190094.945, 0, 1],
		[-11832.228, 1, 1],
		[-114.221, 1, 3],
		[-32.391, 0, 3],
		[-0.705, 1, 0],
		[-2.340, 3, 1],
		[-0.608, 1, 5],
		[-0.008, 0, 2],
		[0.148, 2, 3],
	];

	/**
	 * The WGS84 → RD y series, in meters.
	 *
	 * Each term is [coefficient, power of dPhi, power of dLam], summed in this order.
	 *
	 * @var list<array{0: float, 1: int, 2: int}>
	 */
	private const Y_TERMS = [
		[309056.544, 1, 0],
		[3638.893, 0, 2],
		[73.077, 2, 0],
		[-157.984, 1, 2],
		[59.788, 3, 0],
		[0.433, 0, 1],
		[-6.439, 2, 2],
		[-0.032, 1, 1],
		[0.092, 0, 4],
		[-0.054, 1, 4],
	];

	/**
	 * Whether a CRS string is supported (REQ-GEO-015).
	 *
	 * @param string $crs The CRS identifier (e.g. `EPSG:28992`).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function isSupportedCrs(string $crs): bool {
		return in_array(strtoupper($crs), [self::CRS_WGS84, self::CRS_RD], true);
	}//end isSupportedCrs()

	/**
	 * The supported CRS identifiers (for 406 error messages).
	 *
	 * @return string[]
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function supportedCrs(): array {
		return [self::CRS_WGS84, self::CRS_RD];
	}//end supportedCrs()

	/**
	 * Transform RD New (x, y in meters) to WGS84 (lon, lat in degrees).
	 *
	 * @param float $x RD x-coordinate (Easting) in meters.
	 * @param float $y RD y-coordinate (Northing) in meters.
	 *
	 * @return array{0: float, 1: float} `[longitude, latitude]` in WGS84.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function rdToWgs84(float $x, float $y): array {
		$dX = (($x - self::X0) * 1e-5);
		$dY = (($y - self::Y0) * 1e-5);

		$sumPhi = self::series(terms: self::LATITUDE_TERMS, point: [$dX, $dY]);

		$latitude = (self::PHI0 + ($sumPhi / 3600.0));

		$sumLam = self::series(terms: self::LONGITUDE_TERMS, point: [$dX, $dY]);

		$longitude = (self::LAM0 + ($sumLam / 3600.0));

		return [round($longitude, 7), round($latitude, 7)];
	}//end rdToWgs84()

	/**
	 * Transform WGS84 (lon, lat in degrees) to RD New (x, y in meters).
	 *
	 * @param float $longitude WGS84 longitude in degrees.
	 * @param float $latitude WGS84 latitude in degrees.
	 *
	 * @return array{0: float, 1: float} `[x, y]` in RD New meters.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function wgs84ToRd(float $longitude, float $latitude): array {
		$dPhi = (0.36 * ($latitude - self::PHI0));
		$dLam = (0.36 * ($longitude - self::LAM0));

		$sumX = self::series(terms: self::X_TERMS, point: [$dPhi, $dLam]);

		$x = (self::X0 + $sumX);

		$sumY = self::series(terms: self::Y_TERMS, point: [$dPhi, $dLam]);

		$y = (self::Y0 + $sumY);

		return [round($x, 3), round($y, 3)];
	}//end wgs84ToRd()

	/**
	 * Sum a two-variable polynomial series at a point, term by term in order.
	 *
	 * Each term is `coefficient * a^p * b^q`, multiplied left to right, so the
	 * result is the same float as writing the series out by hand.
	 *
	 * @param list<array{0: float, 1: int, 2: int}> $terms The series.
	 * @param array{0: float, 1: float}              $point The two variables (a, b).
	 *
	 * @return float
	 */
	private static function series(array $terms, array $point): float {
		$sum = 0.0;
		foreach ($terms as [$coefficient, $powerA, $powerB]) {
			$sum += $coefficient * ($point[0] ** $powerA) * ($point[1] ** $powerB);
		}

		return $sum;
	}//end series()
}//end class
