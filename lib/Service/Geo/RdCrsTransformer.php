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

namespace OCA\OpenRegister\Service\Geo;

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

		$sumPhi = 3235.65389 * $dY;
		$sumPhi += -32.58297 * ($dX ** 2);
		$sumPhi += -0.24750 * ($dY ** 2);
		$sumPhi += -0.84978 * ($dX ** 2) * $dY;
		$sumPhi += -0.06550 * ($dY ** 3);
		$sumPhi += -0.01709 * ($dX ** 2) * ($dY ** 2);
		$sumPhi += -0.00738 * $dX;
		$sumPhi += 0.00530 * ($dX ** 4);
		$sumPhi += -0.00039 * ($dX ** 2) * ($dY ** 3);
		$sumPhi += 0.00033 * ($dX ** 4) * $dY;
		$sumPhi += -0.00012 * $dX * $dY;

		$latitude = (self::PHI0 + ($sumPhi / 3600.0));

		$sumLam = 5260.52916 * $dX;
		$sumLam += 105.94684 * $dX * $dY;
		$sumLam += 2.45656 * $dX * ($dY ** 2);
		$sumLam += -0.81885 * ($dX ** 3);
		$sumLam += 0.05594 * $dX * ($dY ** 3);
		$sumLam += -0.05607 * ($dX ** 3) * $dY;
		$sumLam += 0.01199 * $dY;
		$sumLam += -0.00256 * ($dX ** 3) * ($dY ** 2);
		$sumLam += 0.00128 * $dX * ($dY ** 4);
		$sumLam += 0.00022 * ($dY ** 2);
		$sumLam += -0.00022 * ($dX ** 2);
		$sumLam += 0.00026 * ($dX ** 5);

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

		$sumX = 190094.945 * $dLam;
		$sumX += -11832.228 * $dPhi * $dLam;
		$sumX += -114.221 * $dPhi * ($dLam ** 3);
		$sumX += -32.391 * ($dLam ** 3);
		$sumX += -0.705 * $dPhi;
		$sumX += -2.340 * ($dPhi ** 3) * $dLam;
		$sumX += -0.608 * $dPhi * ($dLam ** 5);
		$sumX += -0.008 * ($dLam ** 2);
		$sumX += 0.148 * ($dPhi ** 2) * ($dLam ** 3);

		$x = (self::X0 + $sumX);

		$sumY = 309056.544 * $dPhi;
		$sumY += 3638.893 * ($dLam ** 2);
		$sumY += 73.077 * ($dPhi ** 2);
		$sumY += -157.984 * $dPhi * ($dLam ** 2);
		$sumY += 59.788 * ($dPhi ** 3);
		$sumY += 0.433 * $dLam;
		$sumY += -6.439 * ($dPhi ** 2) * ($dLam ** 2);
		$sumY += -0.032 * $dPhi * $dLam;
		$sumY += 0.092 * ($dLam ** 4);
		$sumY += -0.054 * $dPhi * ($dLam ** 4);

		$y = (self::Y0 + $sumY);

		return [round($x, 3), round($y, 3)];
	}//end wgs84ToRd()
}//end class
