<?php

/**
 * GeoSpatialEvaluator — pure-PHP fallback for spatial-filter matching.
 *
 * Decides whether a single GeoJSON geometry (read from a row) satisfies
 * a GeoFilter. No database calls — purely arithmetic over GeoJSON
 * coordinates.
 *
 * This is the cross-platform fallback for backends without native
 * spatial indexing (MariaDB / MySQL / SQLite). On PostgreSQL with
 * PostGIS the spec says spatial filtering SHOULD be pushed into SQL
 * (`ST_Within`, `ST_Intersects`); that optimisation is tracked in the
 * `geo-spatial-queries` follow-up change.
 *
 * Algorithms used:
 *   - Bounding-box test for bbox + Polygon AABB pre-check.
 *   - Haversine formula for near+radius distance in meters.
 *   - Ray-casting point-in-polygon for within / intersects on Point
 *     geometries against a Polygon predicate.
 *   - Polygon-polygon overlap is approximated as "any vertex of A is in
 *     B, OR any vertex of B is in A".
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
 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Geo;

/**
 * Pure-PHP spatial-filter matcher.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 *   Spatial-filter algorithms (ray-casting point-in-polygon, Haversine
 *   distance, Polygon/MultiPolygon coordinate extraction) are inherently
 *   branchy. Splitting across classes would obscure the algorithmic flow
 *   without reducing real complexity.
 *
 * @SuppressWarnings(PHPMD.ShortVariable)
 *   `$x` / `$y` are mathematical convention for ray-casting; `$xi` / `$yi`
 *   / `$xj` / `$yj` are the standard ring-vertex names from Bevis &
 *   Chatelain's algorithm.
 */
class GeoSpatialEvaluator {

	/**
	 * Earth radius in meters (mean radius — sufficient for listing-API
	 * filtering; precision-critical paths use PostGIS).
	 */
	private const EARTH_RADIUS_M = 6371008.8;

	/**
	 * Test whether a row geometry matches the filter.
	 *
	 * @param array|null $rowGeometry The GeoJSON geometry from the row.
	 * @param GeoFilter $filter The filter to apply.
	 *
	 * @return bool True when the row passes the filter; null geometries always fail.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	public function matches(?array $rowGeometry, GeoFilter $filter): bool {
		if ($rowGeometry === null) {
			return false;
		}

		return match ($filter->type) {
			GeoFilter::TYPE_BBOX => $this->matchesBbox(geometry: $rowGeometry, bbox: $filter->payload),
			GeoFilter::TYPE_NEAR => $this->matchesNear(geometry: $rowGeometry, payload: $filter->payload),
			default => $this->matchesPredicate(rowGeometry: $rowGeometry, filter: $filter),
		};
	}//end matches()

	/**
	 * Match against the polygon predicate of a within or intersects filter.
	 *
	 * @param array     $rowGeometry The GeoJSON geometry from the row.
	 * @param GeoFilter $filter      The filter to apply.
	 *
	 * @return bool False for any other filter type.
	 */
	private function matchesPredicate(array $rowGeometry, GeoFilter $filter): bool {
		return match ($filter->type) {
			GeoFilter::TYPE_WITHIN => $this->matchesWithin(rowGeometry: $rowGeometry, predicateGeometry: $filter->payload['geometry']),
			GeoFilter::TYPE_INTERSECTS => $this->matchesIntersects(rowGeometry: $rowGeometry, predicateGeometry: $filter->payload['geometry']),
			default => false,
		};
	}//end matchesPredicate()

	/**
	 * Compute the great-circle distance in meters between two lon/lat pairs.
	 *
	 * @param float $lon1 Longitude of point A.
	 * @param float $lat1 Latitude of point A.
	 * @param float $lon2 Longitude of point B.
	 * @param float $lat2 Latitude of point B.
	 *
	 * @return float Distance in meters.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	public function haversineMeters(float $lon1, float $lat1, float $lon2, float $lat2): float {
		$phi1 = deg2rad($lat1);
		$phi2 = deg2rad($lat2);
		$dPhi = deg2rad(($lat2 - $lat1));
		$dLam = deg2rad(($lon2 - $lon1));

		$a = (sin($dPhi / 2) ** 2) + cos($phi1) * cos($phi2) * (sin($dLam / 2) ** 2);
		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
		return self::EARTH_RADIUS_M * $c;
	}//end haversineMeters()

	/**
	 * Bbox match — uses a representative point from the geometry.
	 *
	 * @param array $geometry The row geometry.
	 * @param array $bbox west/south/east/north.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function matchesBbox(array $geometry, array $bbox): bool {
		$point = $this->extractRepresentativePoint(geometry: $geometry);
		if ($point === null) {
			return false;
		}

		[$lon, $lat] = $point;

		return ($lon >= $bbox['west']
			&& $lon <= $bbox['east']
			&& $lat >= $bbox['south']
			&& $lat <= $bbox['north']);

	}//end matchesBbox()

	/**
	 * Near + radius match — Haversine distance test.
	 *
	 * @param array $geometry The row geometry.
	 * @param array $payload lon/lat/radius.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-004-spatial-queries-in-the-api
	 */
	private function matchesNear(array $geometry, array $payload): bool {
		$point = $this->extractRepresentativePoint(geometry: $geometry);
		if ($point === null) {
			return false;
		}

		$distance = $this->haversineMeters(
			lon1: $payload['lon'],
			lat1: $payload['lat'],
			lon2: $point[0],
			lat2: $point[1]
		);
		return $distance <= $payload['radius'];
	}//end matchesNear()

	/**
	 * Within-polygon match.
	 *
	 * @param array $rowGeometry Row geometry (Point uses centroid for Polygon rows).
	 * @param array $predicateGeometry The polygon predicate.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function matchesWithin(array $rowGeometry, array $predicateGeometry): bool {
		$point = $this->extractRepresentativePoint(geometry: $rowGeometry);
		if ($point === null) {
			return false;
		}

		return $this->pointInPolygonGeometry(point: $point, geometry: $predicateGeometry);
	}//end matchesWithin()

	/**
	 * Intersects match.
	 *
	 * @param array $rowGeometry Row geometry.
	 * @param array $predicateGeometry The polygon predicate.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function matchesIntersects(array $rowGeometry, array $predicateGeometry): bool {
		if (($rowGeometry['type'] ?? null) === 'Point') {
			return $this->matchesWithin(rowGeometry: $rowGeometry, predicateGeometry: $predicateGeometry);
		}

		$rowPolygons = $this->extractPolygons(geometry: $rowGeometry);
		$predicatePolygons = $this->extractPolygons(geometry: $predicateGeometry);
		foreach ($rowPolygons as $row) {
			if ($this->touchesAny(row: $row, predicatePolygons: $predicatePolygons) === true) {
				return true;
			}
		}

		return false;
	}//end matchesIntersects()

	/**
	 * Whether a row polygon and any predicate polygon have a vertex inside the other's outer ring.
	 *
	 * @param mixed        $row               One row polygon.
	 * @param array<mixed> $predicatePolygons The predicate polygons.
	 *
	 * @return bool
	 */
	private function touchesAny(mixed $row, array $predicatePolygons): bool {
		foreach ($predicatePolygons as $pred) {
			if ($this->touches(row: $row, pred: $pred) === true) {
				return true;
			}
		}

		return false;
	}//end touchesAny()

	/**
	 * Whether a row polygon and a predicate polygon have a vertex inside the other's outer ring.
	 *
	 * The row's vertices are tried first, so the predicate is only read when they all miss.
	 *
	 * @param mixed $row  One row polygon.
	 * @param mixed $pred One predicate polygon.
	 *
	 * @return bool
	 */
	private function touches(mixed $row, mixed $pred): bool {
		return $this->anyVertexInside(vertices: ($row[0] ?? []), polygon: $pred) === true
			|| $this->anyVertexInside(vertices: ($pred[0] ?? []), polygon: $row) === true;
	}//end touches()

	/**
	 * Whether any of the vertices lies inside the polygon's outer ring.
	 *
	 * The ring is read per vertex, so a polygon is not touched when there are no vertices.
	 *
	 * @param mixed $vertices The vertices.
	 * @param mixed $polygon  The polygon.
	 *
	 * @return bool
	 */
	private function anyVertexInside(mixed $vertices, mixed $polygon): bool {
		foreach ($vertices as $vertex) {
			if ($this->pointInRing(point: $vertex, ring: $polygon[0]) === true) {
				return true;
			}
		}

		return false;
	}//end anyVertexInside()

	/**
	 * Extract a representative `[lon, lat]` from a GeoJSON geometry.
	 *
	 * @param array $geometry The geometry.
	 *
	 * Point: its coordinates. Polygon: the centroid of the outer ring.
	 * MultiPolygon: the centroid of the first polygon's outer ring.
	 * LineString: its first vertex.
	 *
	 * @return ?array `[lon, lat]` or null when the shape is unsupported.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function extractRepresentativePoint(array $geometry): ?array {
		$coords = ($geometry['coordinates'] ?? null);
		if (is_array($coords) === false) {
			return null;
		}

		return $this->pointOf(type: ($geometry['type'] ?? null), coords: $coords);
	}//end extractRepresentativePoint()

	/**
	 * The representative `[lon, lat]` of a geometry of a given type.
	 *
	 * @param mixed $type   The geometry type.
	 * @param array $coords The geometry's coordinates.
	 *
	 * @return ?array `[lon, lat]` or null when the shape is unsupported.
	 */
	private function pointOf(mixed $type, array $coords): ?array {
		if ($type === 'Point') {
			return $this->numericPair(pair: $coords);
		}

		if ($type === 'LineString') {
			return $this->numericPair(pair: ($coords[0] ?? null));
		}

		return $this->outerRingCentroid(type: $type, coords: $coords);
	}//end pointOf()

	/**
	 * The centroid of the outer ring of a Polygon, or of a MultiPolygon's first polygon.
	 *
	 * @param mixed $type   The geometry type.
	 * @param array $coords The geometry's coordinates.
	 *
	 * @return ?array `[lon, lat]`, or null for another type or a ring with no usable vertex.
	 */
	private function outerRingCentroid(mixed $type, array $coords): ?array {
		return match ($type) {
			'Polygon' => $this->ringCentroid(ring: $this->arrayOrEmpty(value: ($coords[0] ?? null))),
			'MultiPolygon' => $this->ringCentroid(ring: $this->arrayOrEmpty(value: ($coords[0][0] ?? null))),
			default => null,
		};
	}//end outerRingCentroid()

	/**
	 * Read `[lon, lat]` floats from a vertex with at least two numeric leading values.
	 *
	 * @param mixed $pair The vertex.
	 *
	 * @return ?array `[lon, lat]`, or null when the vertex is not a numeric pair.
	 */
	private function numericPair(mixed $pair): ?array {
		if (is_array($pair) === false || count($pair) < 2 || is_numeric($pair[0]) === false || is_numeric($pair[1]) === false) {
			return null;
		}

		return [(float) $pair[0], (float) $pair[1]];
	}//end numericPair()

	/**
	 * The value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed>
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOrEmpty()

	/**
	 * Compute the arithmetic centroid of a ring.
	 *
	 * @param array $ring The ring vertices.
	 *
	 * Vertices that are not numeric pairs are skipped.
	 *
	 * @return ?array `[lon, lat]` centroid, or null when the ring has no usable vertex.
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function ringCentroid(array $ring): ?array {
		$points = array_values(array_filter(array_map(fn (mixed $vertex): ?array => $this->numericPair(pair: $vertex), $ring)));
		$count = count($points);
		if ($count === 0) {
			return null;
		}

		return [(array_sum(array_column($points, 0)) / $count), (array_sum(array_column($points, 1)) / $count)];
	}//end ringCentroid()

	/**
	 * Test whether a `[lon, lat]` point is inside a Polygon / MultiPolygon.
	 *
	 * @param array $point The point.
	 * @param array $geometry GeoJSON Polygon or MultiPolygon.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function pointInPolygonGeometry(array $point, array $geometry): bool {
		foreach ($this->extractPolygons(geometry: $geometry) as $polygon) {
			$ring = ($polygon[0] ?? null);
			if (is_array($ring) === true && $this->pointInRing(point: $point, ring: $ring) === true) {
				return true;
			}
		}

		return false;
	}//end pointInPolygonGeometry()

	/**
	 * Ray-casting point-in-polygon test on a single ring.
	 *
	 * @param array $point The point.
	 * @param array $ring The ring vertices.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/geo-metadata-kaart/spec.md#requirement-req-geo-012-solr-and-elasticsearch-spatial-query-support
	 */
	private function pointInRing(array $point, array $ring): bool {
		$n = count($ring);
		if ($n < 3) {
			return false;
		}

		$ray = [$point[0], $point[1]];
		$inside = false;
		for ($i = 0, $j = ($n - 1); $i < $n; $j = $i++) {
			// Each crossing flips the answer.
			$inside = ($inside !== $this->edgeCrossesRay(ray: $ray, edge: $this->edgeOf(ring: $ring, ends: [$i, $j])));
		}

		return $inside;
	}//end pointInRing()

	/**
	 * The two vertices of a ring edge, null where the ring has no vertex at that position.
	 *
	 * @param array                $ring The ring vertices.
	 * @param array{0: int, 1: int} $ends The positions of the edge's two vertices.
	 *
	 * @return array{0: mixed, 1: mixed}
	 */
	private function edgeOf(array $ring, array $ends): array {
		return [($ring[$ends[0]] ?? null), ($ring[$ends[1]] ?? null)];
	}//end edgeOf()

	/**
	 * Whether a ring edge crosses the horizontal ray cast east from a point.
	 *
	 * @param array{0: mixed, 1: mixed} $ray  The point `[x, y]` the ray starts at.
	 * @param array{0: mixed, 1: mixed} $edge The edge's two vertices.
	 *
	 * @return bool False for an edge with a missing coordinate.
	 */
	private function edgeCrossesRay(array $ray, array $edge): bool {
		$vertices = $this->edgeCoordinates(edge: $edge);
		if ($vertices === null) {
			return false;
		}

		[$x, $y] = $ray;
		[$xi, $yi, $xj, $yj] = $vertices;
		$denom = ($yj - $yi);
		if ($denom === 0.0) {
			$denom = 1e-12;
		}

		return (($yi > $y) !== ($yj > $y)) && ($x < (($xj - $xi) * (($y - $yi) / $denom) + $xi));
	}//end edgeCrossesRay()

	/**
	 * The `[xi, yi, xj, yj]` coordinates of an edge, or null when one is missing.
	 *
	 * @param array{0: mixed, 1: mixed} $edge The edge's two vertices.
	 *
	 * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}|null
	 */
	private function edgeCoordinates(array $edge): ?array {
		$coordinates = array_merge($this->pairOf(vertex: $edge[0]), $this->pairOf(vertex: $edge[1]));
		if (in_array(null, $coordinates, true) === true) {
			return null;
		}

		return $coordinates;
	}//end edgeCoordinates()

	/**
	 * The first two values of a vertex, null where it has none.
	 *
	 * @param mixed $vertex The vertex.
	 *
	 * @return array{0: mixed, 1: mixed}
	 */
	private function pairOf(mixed $vertex): array {
		return [($vertex[0] ?? null), ($vertex[1] ?? null)];
	}//end pairOf()

	/**
	 * Normalise a Polygon or MultiPolygon to a list of polygons.
	 *
	 * @param array $geometry The geometry.
	 *
	 * @return array<int, array<int, array<int, array<int, float>>>>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-geo-metadata-kaart/tasks.md#task-2
	 */
	private function extractPolygons(array $geometry): array {
		$type = ($geometry['type'] ?? null);
		$coords = ($geometry['coordinates'] ?? []);
		if ($type === 'Polygon') {
			return [$coords];
		}

		if ($type === 'MultiPolygon') {
			return $coords;
		}

		return [];
	}//end extractPolygons()
}//end class
