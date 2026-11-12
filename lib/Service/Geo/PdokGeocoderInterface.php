<?php

/**
 * PdokGeocoderInterface: the part of PdokGeocoder that the objects controller uses through its geo bundle.
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
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

/**
 * The methods of PdokGeocoder that callers outside its component use.
 * Callers depend on this interface, not on the class.
 */
interface PdokGeocoderInterface {

	/**
	 * Whether geocoding is currently available (OpenConnector enabled).
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function isAvailable(): bool;

	/**
	 * Forward geocoding: free-text address query -> up to N suggestions.
	 *
	 * Each suggestion is `{ display, type, lon, lat, bagId }`. On any
	 * failure (OpenConnector down, upstream error, malformed payload)
	 * an empty list is returned — geocoding is non-blocking (REQ-GEO-005).
	 *
	 * @param string              $query   The free-text address.
	 * @param GeocodeOptions|null $options How many suggestions, and whether only BAG addresses count; defaults when null.
	 *
	 * @return array<int, array<string, mixed>> Suggestions (possibly empty).
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-006
	 */
	public function geocodeFree(string $query, ?GeocodeOptions $options = null): array;

	/**
	 * Reverse geocoding: coordinates -> nearest address (or null).
	 *
	 * @param float $longitude WGS84 longitude.
	 * @param float $latitude WGS84 latitude.
	 *
	 * @return array<string, mixed>|null The nearest address, or null.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-005
	 */
	public function reverseGeocode(float $longitude, float $latitude): ?array;
}//end interface
