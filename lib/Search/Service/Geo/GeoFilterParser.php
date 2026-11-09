<?php

/**
 * GeoFilterParser — wire-format adapter for spatial filters.
 *
 * Two entry points:
 *
 *   - fromQueryParams(array)    — `?geo.bbox=` and `?geo.near=&geo.radius=`.
 *   - fromGeoSearchBody(array)  — POST `/geo-search` with `geometry.within`
 *                                 or `geometry.intersects`.
 *
 * Either entry returns a list of GeoFilter; the caller AND-composes.
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
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-004
 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

use InvalidArgumentException;

/**
 * Wire-format adapter that turns HTTP query params and POST bodies
 * into GeoFilter value objects.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 *   GeoFilter is an immutable value object built via the canonical
 *   static-factory pattern; PHPMD's StaticAccess warning is a false
 *   positive for that pattern.
 */
class GeoFilterParser implements GeoFilterParserInterface {
	/**
	 * Parse spatial filters out of a query-parameter array.
	 *
	 * Recognised keys:
	 *   - geo.bbox     — `west,south,east,north`
	 *   - geo.near     — `lon,lat` (requires geo.radius)
	 *   - geo.radius   — meters (requires geo.near)
	 *   - geo.property — optional property name to evaluate
	 *
	 * @param array $params HTTP query params.
	 *
	 * @return GeoFilter[]
	 *
	 * @throws InvalidArgumentException When `geo.near`/`geo.radius` are mismatched.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	public function fromQueryParams(array $params): array {
		$property = $this->propertyOf(value: ($params['geo.property'] ?? null));
		$filters = [];
		if ($this->isGiven(value: ($params['geo.bbox'] ?? null)) === true) {
			$filters[] = GeoFilter::fromBbox(bbox: (string)$params['geo.bbox'], property: $property);
		}

		$near = $this->nearFilter(params: $params, property: $property);
		if ($near !== null) {
			$filters[] = $near;
		}

		return $filters;
	}//end fromQueryParams()

	/**
	 * The near + radius filter, or null when neither is given.
	 *
	 * @param array       $params   HTTP query params.
	 * @param string|null $property Optional property name to evaluate.
	 *
	 * @return GeoFilter|null
	 *
	 * @throws InvalidArgumentException When only one of the pair is given, or geo.near is not two values.
	 */
	private function nearFilter(array $params, ?string $property): ?GeoFilter {
		$hasNear = $this->isGiven(value: ($params['geo.near'] ?? null));
		if (($hasNear xor $this->isGiven(value: ($params['geo.radius'] ?? null))) === true) {
			throw new InvalidArgumentException('geo.near and geo.radius MUST be used together — neither is meaningful alone');
		}

		if ($hasNear === false) {
			return null;
		}

		$coords = array_map('trim', explode(',', (string)$params['geo.near']));
		if (count($coords) !== 2) {
			throw new InvalidArgumentException('geo.near MUST be `lon,lat` — exactly two values');
		}

		return GeoFilter::fromNearAndRadius(lon: $coords[0], lat: $coords[1], radius: (string)$params['geo.radius'], property: $property);
	}//end nearFilter()

	/**
	 * Whether a parameter is given: set and not an empty string.
	 *
	 * @param mixed $value The parameter value.
	 *
	 * @return bool
	 */
	private function isGiven(mixed $value): bool {
		return $value !== null && $value !== '';
	}//end isGiven()

	/**
	 * The property to evaluate, as a string, or null when none is given.
	 *
	 * @param mixed $value The property value.
	 *
	 * @return string|null
	 */
	private function propertyOf(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end propertyOf()

	/**
	 * Parse a `POST /geo-search` JSON body into spatial filters.
	 *
	 * Body shape:
	 *   {
	 *     "geometry": { "within": <Geometry>, "intersects": <Geometry> },
	 *     "property": "<name>"
	 *   }
	 *
	 * @param array $body Decoded JSON body.
	 *
	 * @return GeoFilter[]
	 *
	 * @throws InvalidArgumentException When malformed.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	public function fromGeoSearchBody(array $body): array {
		$geometry = ($body['geometry'] ?? null);
		if (is_array($geometry) === false) {
			throw new InvalidArgumentException('geo-search body MUST include a geometry object');
		}

		$filters = $this->geometryFilters(geometry: $geometry, property: $this->propertyOf(value: ($body['property'] ?? null)));
		if (count($filters) === 0) {
			throw new InvalidArgumentException('geo-search body MUST include at least one of: geometry.within, geometry.intersects');
		}

		return $filters;
	}//end fromGeoSearchBody()

	/**
	 * The within filter, then the intersects filter, for the predicates the body gives.
	 *
	 * @param array       $geometry The body's geometry object.
	 * @param string|null $property Optional property name to evaluate.
	 *
	 * @return GeoFilter[]
	 *
	 * @throws InvalidArgumentException When a given predicate is not a GeoJSON geometry.
	 */
	private function geometryFilters(array $geometry, ?string $property): array {
		$filters = [];
		$within = $this->predicate(geometry: $geometry, key: 'within');
		if ($within !== null) {
			$filters[] = GeoFilter::fromWithinGeometry(geometry: $within, property: $property);
		}

		$intersects = $this->predicate(geometry: $geometry, key: 'intersects');
		if ($intersects !== null) {
			$filters[] = GeoFilter::fromIntersectsGeometry(geometry: $intersects, property: $property);
		}

		return $filters;
	}//end geometryFilters()

	/**
	 * One spatial predicate of the body's geometry object, or null when it is not given.
	 *
	 * @param array  $geometry The body's geometry object.
	 * @param string $key      within or intersects.
	 *
	 * @return array|null
	 *
	 * @throws InvalidArgumentException When the predicate is given but is not an object.
	 */
	private function predicate(array $geometry, string $key): ?array {
		$predicate = ($geometry[$key] ?? null);
		if ($predicate === null) {
			return null;
		}

		if (is_array($predicate) === false) {
			throw new InvalidArgumentException('geometry.' . $key . ' MUST be a GeoJSON geometry object');
		}

		return $predicate;
	}//end predicate()
}//end class
