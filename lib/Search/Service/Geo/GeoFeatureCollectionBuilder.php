<?php

/**
 * GeoFeatureCollectionBuilder — shape OR objects into GeoJSON output.
 *
 * Implements the export/feature-output side of REQ-GEO-008:
 *
 *   - buildFeatureCollection() — objects -> GeoJSON FeatureCollection
 *     (one Feature per object, geometry pulled from the geo property,
 *     remaining properties become Feature properties; honours an
 *     optional field allow-list, geometry always retained).
 *   - buildWfsResponse()       — WFS GetFeature-compatible envelope
 *     (FeatureCollection + numberReturned + optional maxFeatures cap).
 *   - geodesicAreaM2()         — geodesic polygon area in square meters
 *     for the `_area_m2` Feature property.
 *
 * Pure data-shaping: no I/O, no DB, no framework. The controller feeds
 * it the already-RBAC-scoped listing rows so this class never widens
 * access (no IDOR surface here).
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
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Builds GeoJSON FeatureCollections and WFS-compatible envelopes.
 */
class GeoFeatureCollectionBuilder implements GeoFeatureCollectionBuilderInterface {

	/**
	 * GeoJSON geometry types recognised when scanning a row.
	 *
	 * @var string[]
	 */
	private const GEOJSON_TYPES = ['Point', 'Polygon', 'MultiPolygon', 'LineString'];

	/**
	 * WGS84 mean earth radius in meters (geodesic area approximation).
	 */
	private const EARTH_RADIUS_M = 6371008.8;

	/**
	 * Build a GeoJSON FeatureCollection from a list of object rows.
	 *
	 * Rows without a recognisable geometry are skipped (a marker-less
	 * row has nothing to plot). When `$fields` is non-null the Feature
	 * `properties` are restricted to that allow-list; the geometry is
	 * always included regardless of the allow-list (REQ-GEO-008).
	 *
	 * @param array $rows Object rows (assoc arrays).
	 * @param ?string $geoProperty Geo property name; null = auto-detect.
	 * @param string[]|null $fields Property allow-list, or null for all.
	 * @param bool $includeArea Add `_area_m2` for polygonal geometries.
	 *
	 * @return array A GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function buildFeatureCollection(array $rows, ?string $geoProperty = null, ?array $fields = null, bool $includeArea = false): array {
		return $this->collect(
			rows: $rows,
			shape: new FeatureShape(geoProperty: $geoProperty, fields: $fields, includeArea: $includeArea)
		);
	}//end buildFeatureCollection()

	/**
	 * Build a GeoJSON FeatureCollection from a list of object rows in one shape.
	 *
	 * @param array        $rows  Object rows (assoc arrays); a non-array row is skipped.
	 * @param FeatureShape $shape The geo property, the allow-list and whether to add the area.
	 *
	 * @return array A GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function collect(array $rows, FeatureShape $shape): array {
		$rows = array_filter($rows, static fn ($row): bool => is_array($row));
		$features = array_map(fn (array $row): ?array => $this->feature(row: $row, shape: $shape), $rows);

		return [
			'type' => 'FeatureCollection',
			'features' => array_values(array_filter($features, static fn (?array $feature): bool => $feature !== null)),
		];
	}//end collect()

	/**
	 * Build a single GeoJSON Feature from an object row in one shape.
	 *
	 * @param array        $row   The object row.
	 * @param FeatureShape $shape The geo property, the allow-list and whether to add the area.
	 *
	 * @return ?array The Feature, or null when no geometry is present.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function feature(array $row, FeatureShape $shape): ?array {
		[$geomKey, $geometry] = $this->locateGeometry(row: $row, geoProperty: $shape->geoProperty);
		if ($geometry === null) {
			return null;
		}

		$properties = $this->allowedProperties(properties: $this->withoutKey(row: $row, key: $geomKey), fields: $shape->fields);
		if ($shape->includeArea === true) {
			$properties = $this->withArea(properties: $properties, geometry: $geometry);
		}

		return $this->withId(feature: ['type' => 'Feature', 'geometry' => $geometry, 'properties' => $properties], row: $row);
	}//end feature()

	/**
	 * Add the row's id (or `@self.id`) to the Feature, when it has one.
	 *
	 * @param array $feature The Feature.
	 * @param array $row     The object row.
	 *
	 * @return array
	 */
	private function withId(array $feature, array $row): array {
		$id = ($row['id'] ?? ($row['@self']['id'] ?? null));
		if ($id !== null) {
			$feature['id'] = $id;
		}

		return $feature;
	}//end withId()

	/**
	 * Add `_area_m2` to the properties when the geometry is polygonal.
	 *
	 * @param array $properties The Feature properties.
	 * @param array $geometry   The Feature geometry.
	 *
	 * @return array
	 */
	private function withArea(array $properties, array $geometry): array {
		$area = $this->geodesicAreaM2(geometry: $geometry);
		if ($area !== null) {
			$properties['_area_m2'] = $area;
		}

		return $properties;
	}//end withArea()

	/**
	 * Build a WFS GetFeature-compatible response envelope.
	 *
	 * The body is a GeoJSON FeatureCollection (the format WFS 2.0 returns
	 * for `outputFormat=application/json`) plus the `numberReturned`
	 * count. `$maxFeatures` caps the feature list (WFS `count`/`maxFeatures`).
	 *
	 * @param array $rows Object rows (assoc arrays).
	 * @param ?string $geoProperty Geo property name; null = auto-detect.
	 * @param int|null $maxFeatures Optional cap on returned features.
	 *
	 * @return array A WFS-compatible GeoJSON FeatureCollection.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function buildWfsResponse(array $rows, ?string $geoProperty = null, ?int $maxFeatures = null): array {
		$collection = $this->collect(rows: $rows, shape: new FeatureShape(geoProperty: $geoProperty));
		return $this->capped(collection: $collection, maxFeatures: $maxFeatures);
	}//end buildWfsResponse()

	/**
	 * A FeatureCollection cut to at most maxFeatures features, with its `numberReturned`.
	 *
	 * @param array    $collection  A GeoJSON FeatureCollection.
	 * @param int|null $maxFeatures Optional cap on returned features; a negative cap is ignored.
	 *
	 * @return array
	 */
	private function capped(array $collection, ?int $maxFeatures): array {
		if ($maxFeatures !== null && $maxFeatures >= 0) {
			$collection['features'] = array_slice($collection['features'], 0, $maxFeatures);
		}

		$collection['numberReturned'] = count($collection['features']);

		return $collection;
	}//end capped()

	/**
	 * Locate a geometry value within a row.
	 *
	 * @param array $row The object row.
	 * @param ?string $geoProperty Explicit property name, or null to auto-detect.
	 *
	 * @return array{0: ?string, 1: ?array} Tuple of [property key, geometry].
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function locateGeometry(array $row, ?string $geoProperty): array {
		if ($geoProperty !== null) {
			$geometry = $this->coerceGeometry(value: ($row[$geoProperty] ?? null));
			return [$geoProperty, $geometry];
		}

		foreach ($row as $key => $value) {
			$geometry = $this->coerceGeometry(value: $value);
			if ($geometry !== null) {
				return [(string)$key, $geometry];
			}
		}

		return [null, null];
	}//end locateGeometry()

	/**
	 * The row without the key holding the geometry.
	 *
	 * Keys are compared strictly, as the located key is always a string.
	 *
	 * @param array   $row The object row.
	 * @param ?string $key The key holding the geometry (excluded).
	 *
	 * @return array
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function withoutKey(array $row, ?string $key): array {
		return array_filter($row, static fn ($rowKey): bool => $rowKey !== $key, ARRAY_FILTER_USE_KEY);
	}//end withoutKey()

	/**
	 * Restrict the properties to an allow-list, when one is given.
	 *
	 * @param array         $properties The properties.
	 * @param string[]|null $fields     Allow-list, or null for all.
	 *
	 * @return array The Feature properties.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function allowedProperties(array $properties, ?array $fields): array {
		if ($fields === null) {
			return $properties;
		}

		return array_filter($properties, static fn ($key): bool => in_array($key, $fields, true), ARRAY_FILTER_USE_KEY);
	}//end allowedProperties()

	/**
	 * Coerce a raw value to a GeoJSON geometry array.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return ?array The geometry when shape matches, null otherwise.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function coerceGeometry(mixed $value): ?array {
		if ($this->isGeometry(value: $value) === false) {
			return null;
		}

		return $value;
	}//end coerceGeometry()

	/**
	 * Whether a value has the shape of a recognised GeoJSON geometry.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return bool True for an array with a recognised `type` and an array of `coordinates`.
	 */
	private function isGeometry(mixed $value): bool {
		return is_array($value) === true
			&& in_array(($value['type'] ?? null), self::GEOJSON_TYPES, true) === true
			&& is_array($value['coordinates'] ?? null) === true;
	}//end isGeometry()

	/**
	 * Compute the geodesic area of a Polygon or MultiPolygon in m^2.
	 *
	 * Uses the spherical-excess shoelace formula over the outer rings
	 * (holes are subtracted). Sufficient for the `_area_m2` display
	 * metadata; precision-critical paths use PostGIS `ST_Area(geography)`.
	 *
	 * @param array $geometry GeoJSON Polygon or MultiPolygon.
	 *
	 * @return ?float The geodesic area in square meters, or null.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	public function geodesicAreaM2(array $geometry): ?float {
		$type = ($geometry['type'] ?? null);
		if ($type !== 'Polygon' && $type !== 'MultiPolygon') {
			return null;
		}

		$total = 0.0;
		foreach ($this->polygonsOf(geometry: $geometry) as $rings) {
			$total += $this->polygonArea(rings: $rings);
		}

		return round($total, 2);
	}//end geodesicAreaM2()

	/**
	 * The polygons of a Polygon or MultiPolygon: a Polygon is one polygon.
	 *
	 * @param array $geometry GeoJSON Polygon or MultiPolygon.
	 *
	 * @return mixed The polygons, as the geometry holds them (not checked to be an array).
	 */
	private function polygonsOf(array $geometry): mixed {
		$polygons = ($geometry['coordinates'] ?? []);
		if ($geometry['type'] === 'Polygon') {
			return [$polygons];
		}

		return $polygons;
	}//end polygonsOf()

	/**
	 * Area of one polygon: its outer ring less its holes; 0.0 for an empty or malformed polygon.
	 *
	 * @param mixed $rings The polygon's rings, outer ring first.
	 *
	 * @return float
	 */
	private function polygonArea(mixed $rings): float {
		if (is_array($rings) === false || count($rings) === 0) {
			return 0.0;
		}

		$outer = abs($this->ringArea(ring: ($rings[0] ?? [])));

		return ($outer - $this->holesArea(rings: $rings));
	}//end polygonArea()

	/**
	 * Summed area of a polygon's holes: every ring after the first.
	 *
	 * @param array $rings The polygon's rings.
	 *
	 * @return float
	 */
	private function holesArea(array $rings): float {
		$holes = 0.0;
		$ringCount = count($rings);
		for ($i = 1; $i < $ringCount; $i++) {
			$holes += abs($this->ringArea(ring: $rings[$i]));
		}

		return $holes;
	}//end holesArea()

	/**
	 * Spherical-excess area of a single ring in square meters.
	 *
	 * @param array $ring The ring vertices ([lon, lat] positions).
	 *
	 * @return float Signed area in square meters.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-008
	 */
	private function ringArea(array $ring): float {
		$count = count($ring);
		if ($count < 4) {
			return 0.0;
		}

		$area = 0.0;
		for ($i = 0; $i < $count; $i++) {
			$area += ($this->edgeTerm(edge: [$ring[$i], $ring[(($i + 1) % $count)]]) ?? 0.0);
		}

		return ($area * self::EARTH_RADIUS_M * self::EARTH_RADIUS_M / 2.0);
	}//end ringArea()

	/**
	 * One edge's term of the spherical-excess sum, or null when a vertex is not a position.
	 *
	 * @param array{0: mixed, 1: mixed} $edge The edge's start and end vertex.
	 *
	 * @return float|null
	 */
	private function edgeTerm(array $edge): ?float {
		[$from, $to] = $edge;
		if ($this->isPosition(vertex: $from) === false || $this->isPosition(vertex: $to) === false) {
			return null;
		}

		$lat1 = deg2rad((float)$from[1]);
		$lat2 = deg2rad((float)$to[1]);

		return (deg2rad((float)$to[0]) - deg2rad((float)$from[0])) * (2 + sin($lat1) + sin($lat2));
	}//end edgeTerm()

	/**
	 * Whether a vertex is an array with a numeric longitude.
	 *
	 * @param mixed $vertex The vertex.
	 *
	 * @return bool
	 */
	private function isPosition(mixed $vertex): bool {
		return is_array($vertex) === true && is_numeric($vertex[0] ?? null) === true;
	}//end isPosition()
}//end class
