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
 * @category Service
 * @package  OCA\OpenRegister\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

/**
 * Pure-PHP spatial-filter matcher.
 */
class GeoSpatialEvaluator
{

    /**
     * Earth radius in meters (mean radius — sufficient for listing-API
     * filtering; precision-critical paths use PostGIS).
     */
    private const EARTH_RADIUS_M = 6371008.8;

    /**
     * Test whether a row geometry matches the filter.
     *
     * @param array|null $rowGeometry The GeoJSON geometry from the row.
     * @param GeoFilter  $filter      The filter to apply.
     *
     * @return bool True when the row passes the filter; null geometries always fail.
     */
    public function matches(?array $rowGeometry, GeoFilter $filter): bool
    {
        if ($rowGeometry === null) {
            return false;
        }

        switch ($filter->type) {
            case GeoFilter::TYPE_BBOX:
                return $this->matchesBbox(geometry: $rowGeometry, bbox: $filter->payload);
            case GeoFilter::TYPE_NEAR:
                return $this->matchesNear(geometry: $rowGeometry, payload: $filter->payload);
            case GeoFilter::TYPE_WITHIN:
                return $this->matchesWithin(rowGeometry: $rowGeometry, predicateGeometry: $filter->payload['geometry']);
            case GeoFilter::TYPE_INTERSECTS:
                return $this->matchesIntersects(
                rowGeometry: $rowGeometry,
                predicateGeometry: $filter->payload['geometry']
            );
        }

        return false;

    }//end matches()

    /**
     * Compute the great-circle distance in meters between two lon/lat pairs.
     *
     * @param float $lon1 Longitude of point A.
     * @param float $lat1 Latitude of point A.
     * @param float $lon2 Longitude of point B.
     * @param float $lat2 Latitude of point B.
     *
     * @return float Distance in meters.
     */
    public function haversineMeters(float $lon1, float $lat1, float $lon2, float $lat2): float
    {
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
     * @param array $bbox     west/south/east/north.
     *
     * @return bool
     */
    private function matchesBbox(array $geometry, array $bbox): bool
    {
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
     * @param array $payload  lon/lat/radius.
     *
     * @return bool
     */
    private function matchesNear(array $geometry, array $payload): bool
    {
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
     * @param array $rowGeometry       Row geometry (Point uses centroid for Polygon rows).
     * @param array $predicateGeometry The polygon predicate.
     *
     * @return bool
     */
    private function matchesWithin(array $rowGeometry, array $predicateGeometry): bool
    {
        $point = $this->extractRepresentativePoint(geometry: $rowGeometry);
        if ($point === null) {
            return false;
        }

        return $this->pointInPolygonGeometry(point: $point, geometry: $predicateGeometry);

    }//end matchesWithin()

    /**
     * Intersects match.
     *
     * @param array $rowGeometry       Row geometry.
     * @param array $predicateGeometry The polygon predicate.
     *
     * @return bool
     */
    private function matchesIntersects(array $rowGeometry, array $predicateGeometry): bool
    {
        if (($rowGeometry['type'] ?? null) === 'Point') {
            return $this->matchesWithin(rowGeometry: $rowGeometry, predicateGeometry: $predicateGeometry);
        }

        $rowPolygons       = $this->extractPolygons(geometry: $rowGeometry);
        $predicatePolygons = $this->extractPolygons(geometry: $predicateGeometry);

        foreach ($rowPolygons as $row) {
            foreach ($predicatePolygons as $pred) {
                foreach (($row[0] ?? []) as $vertex) {
                    if ($this->pointInRing(point: $vertex, ring: $pred[0]) === true) {
                        return true;
                    }
                }

                foreach (($pred[0] ?? []) as $vertex) {
                    if ($this->pointInRing(point: $vertex, ring: $row[0]) === true) {
                        return true;
                    }
                }
            }
        }

        return false;

    }//end matchesIntersects()

    /**
     * Extract a representative `[lon, lat]` from a GeoJSON geometry.
     *
     * @param array $geometry The geometry.
     *
     * @return ?array `[lon, lat]` or null when the shape is unsupported.
     */
    private function extractRepresentativePoint(array $geometry): ?array
    {
        $type   = ($geometry['type'] ?? null);
        $coords = ($geometry['coordinates'] ?? null);
        if (is_array($coords) === false) {
            return null;
        }

        if ($type === 'Point') {
            if (count($coords) >= 2 && is_numeric($coords[0]) === true && is_numeric($coords[1]) === true) {
                return [(float) $coords[0], (float) $coords[1]];
            }

            return null;
        }

        if ($type === 'Polygon') {
            $ring = ($coords[0] ?? null);
            return $this->ringCentroid(ring: (is_array($ring) === true ? $ring : []));
        }

        if ($type === 'MultiPolygon') {
            $ring = ($coords[0][0] ?? null);
            return $this->ringCentroid(ring: (is_array($ring) === true ? $ring : []));
        }

        if ($type === 'LineString'
            && isset($coords[0]) === true
            && is_array($coords[0]) === true
            && count($coords[0]) >= 2
            && is_numeric($coords[0][0]) === true
            && is_numeric($coords[0][1]) === true
        ) {
            return [(float) $coords[0][0], (float) $coords[0][1]];
        }

        return null;

    }//end extractRepresentativePoint()

    /**
     * Compute the arithmetic centroid of a ring.
     *
     * @param array $ring The ring vertices.
     *
     * @return ?array `[lon, lat]` centroid, or null when the ring is empty.
     */
    private function ringCentroid(array $ring): ?array
    {
        if (count($ring) === 0) {
            return null;
        }

        $sumLon = 0.0;
        $sumLat = 0.0;
        $n      = 0;
        foreach ($ring as $pt) {
            if (is_array($pt) === false || count($pt) < 2) {
                continue;
            }

            if (is_numeric($pt[0]) === false || is_numeric($pt[1]) === false) {
                continue;
            }

            $sumLon += (float) $pt[0];
            $sumLat += (float) $pt[1];
            $n++;
        }

        if ($n === 0) {
            return null;
        }

        return [($sumLon / $n), ($sumLat / $n)];

    }//end ringCentroid()

    /**
     * Test whether a `[lon, lat]` point is inside a Polygon / MultiPolygon.
     *
     * @param array $point    The point.
     * @param array $geometry GeoJSON Polygon or MultiPolygon.
     *
     * @return bool
     */
    private function pointInPolygonGeometry(array $point, array $geometry): bool
    {
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
     * @param array $ring  The ring vertices.
     *
     * @return bool
     */
    private function pointInRing(array $point, array $ring): bool
    {
        if (count($ring) < 3) {
            return false;
        }

        $x      = $point[0];
        $y      = $point[1];
        $inside = false;
        $n      = count($ring);

        for ($i = 0, $j = ($n - 1); $i < $n; $j = $i++) {
            $xi = ($ring[$i][0] ?? null);
            $yi = ($ring[$i][1] ?? null);
            $xj = ($ring[$j][0] ?? null);
            $yj = ($ring[$j][1] ?? null);

            if ($xi === null || $yi === null || $xj === null || $yj === null) {
                continue;
            }

            $denom     = ((($yj - $yi) === 0.0) ? 1e-12 : ($yj - $yi));
            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < (($xj - $xi) * (($y - $yi) / $denom) + $xi));
            if ($intersect === true) {
                $inside = !$inside;
            }
        }

        return $inside;

    }//end pointInRing()

    /**
     * Normalise a Polygon or MultiPolygon to a list of polygons.
     *
     * @param array $geometry The geometry.
     *
     * @return array<int, array<int, array<int, array<int, float>>>>
     */
    private function extractPolygons(array $geometry): array
    {
        $type   = ($geometry['type'] ?? null);
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
