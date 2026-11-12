<?php

/**
 * The coordinate reference system a geo response is asked for.
 *
 * Stored geometry is always WGS84. A client that sends `Accept-Crs: EPSG:28992`
 * gets its coordinates in RD New; anything else this instance cannot serve is
 * refused rather than answered in a system the client did not ask for.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Applies a requested CRS to a GeoJSON FeatureCollection.
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
 */
final class GeoResponseCrs implements GeoResponseCrsInterface {

	/**
	 * The requested CRS, uppercased; empty when the client asked for none.
	 *
	 * @var string
	 */
	private readonly string $requested;

	/**
	 * The transform.
	 *
	 * @var RdCrsTransformer
	 */
	private readonly RdCrsTransformer $transformer;

	/**
	 * A negotiation for one request.
	 *
	 * @param string $accept The `Accept-Crs` header; empty when absent.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function __construct(string $accept) {
		$this->requested = strtoupper(trim($accept));
		$this->transformer = new RdCrsTransformer();
	}//end __construct()

	/**
	 * Whether this request can be served.
	 *
	 * @return bool False when the client asked for a CRS this instance does not serve.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function isServable(): bool {
		return $this->requested === '' || $this->transformer->isSupportedCrs(crs: $this->requested) === true;
	}//end isServable()

	/**
	 * The body of a refusal.
	 *
	 * @return array{error: string, supported: string[]} What to answer with a 406.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function refusal(): array {
		$supported = $this->transformer->supportedCrs();

		return [
			'error' => 'Unsupported CRS. Supported values: ' . implode(', ', $supported),
			'supported' => $supported,
		];
	}//end refusal()

	/**
	 * The `Content-Crs` header value, or null when the client asked for none.
	 *
	 * @return string|null The CRS of the response.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function contentCrs(): ?string {
		if ($this->requested === '') {
			return null;
		}

		return $this->requested;
	}//end contentCrs()

	/**
	 * A FeatureCollection in the requested CRS.
	 *
	 * @param array<string, mixed> $collection The collection, in WGS84.
	 *
	 * @return array<string, mixed> The collection to answer with.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
	 */
	public function applyTo(array $collection): array {
		if ($this->requested !== RdCrsTransformer::CRS_RD) {
			return $collection;
		}

		$collection['features'] = array_map(
			fn (array $feature): array => $this->featureInRd(feature: $feature),
			($collection['features'] ?? [])
		);

		return $collection;
	}//end applyTo()

	/**
	 * One feature with its geometry in RD New.
	 *
	 * @param array<string, mixed> $feature The feature.
	 *
	 * @return array<string, mixed> The feature.
	 */
	private function featureInRd(array $feature): array {
		if (is_array($feature['geometry'] ?? null) === true) {
			$feature['geometry'] = $this->geometryInRd(geometry: $feature['geometry']);
		}

		return $feature;
	}//end featureInRd()

	/**
	 * A geometry in RD New, a GeometryCollection included.
	 *
	 * @param array<string, mixed> $geometry The geometry.
	 *
	 * @return array<string, mixed> The geometry.
	 */
	private function geometryInRd(array $geometry): array {
		if (is_array($geometry['geometries'] ?? null) === true) {
			$geometry['geometries'] = array_map(
				fn (array $member): array => $this->geometryInRd(geometry: $member),
				$geometry['geometries']
			);
		}

		if (is_array($geometry['coordinates'] ?? null) === true) {
			$geometry['coordinates'] = $this->coordinatesInRd(coordinates: $geometry['coordinates']);
		}

		return $geometry;
	}//end geometryInRd()

	/**
	 * Coordinates at any nesting depth in RD New; a position keeps its altitude.
	 *
	 * @param array<int, mixed> $coordinates A position, or a list of them.
	 *
	 * @return array<int, mixed> The coordinates.
	 */
	private function coordinatesInRd(array $coordinates): array {
		if (is_numeric($coordinates[0] ?? null) === false) {
			return array_map(fn (array $inner): array => $this->coordinatesInRd(coordinates: $inner), $coordinates);
		}

		[$x, $y] = $this->transformer->wgs84ToRd(longitude: (float)$coordinates[0], latitude: (float)$coordinates[1]);

		return array_merge([$x, $y], array_slice($coordinates, 2));
	}//end coordinatesInRd()
}//end class
