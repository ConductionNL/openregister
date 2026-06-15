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
 * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Geo;

/**
 * Builds GeoJSON FeatureCollections and WFS-compatible envelopes.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) None used; documented for parity
 *   with sibling geo services.
 */
class GeoFeatureCollectionBuilder
{

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
     * @param array         $rows         Object rows (assoc arrays).
     * @param ?string       $geoProperty  Geo property name; null = auto-detect.
     * @param string[]|null $fields       Property allow-list, or null for all.
     * @param bool          $includeArea  Add `_area_m2` for polygonal geometries.
     *
     * @return array A GeoJSON FeatureCollection.
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    public function buildFeatureCollection(
        array $rows,
        ?string $geoProperty=null,
        ?array $fields=null,
        bool $includeArea=false
    ): array {
        $features = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $feature = $this->buildFeature(
                row: $row,
                geoProperty: $geoProperty,
                fields: $fields,
                includeArea: $includeArea
            );
            if ($feature !== null) {
                $features[] = $feature;
            }
        }

        return [
            'type'     => 'FeatureCollection',
            'features' => $features,
        ];

    }//end buildFeatureCollection()

    /**
     * Build a single GeoJSON Feature from an object row.
     *
     * @param array         $row         The object row.
     * @param ?string       $geoProperty Geo property name; null = auto-detect.
     * @param string[]|null $fields      Property allow-list, or null for all.
     * @param bool          $includeArea Add `_area_m2` for polygonal geometries.
     *
     * @return ?array The Feature, or null when no geometry is present.
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    public function buildFeature(
        array $row,
        ?string $geoProperty=null,
        ?array $fields=null,
        bool $includeArea=false
    ): ?array {
        [$geomKey, $geometry] = $this->locateGeometry(row: $row, geoProperty: $geoProperty);
        if ($geometry === null) {
            return null;
        }

        $properties = $this->shapeProperties(row: $row, geomKey: $geomKey, fields: $fields);

        if ($includeArea === true) {
            $area = $this->geodesicAreaM2(geometry: $geometry);
            if ($area !== null) {
                $properties['_area_m2'] = $area;
            }
        }

        $feature = [
            'type'       => 'Feature',
            'geometry'   => $geometry,
            'properties' => $properties,
        ];

        $id = ($row['id'] ?? ($row['@self']['id'] ?? null));
        if ($id !== null) {
            $feature['id'] = $id;
        }

        return $feature;

    }//end buildFeature()

    /**
     * Build a WFS GetFeature-compatible response envelope.
     *
     * The body is a GeoJSON FeatureCollection (the format WFS 2.0 returns
     * for `outputFormat=application/json`) plus the `numberReturned`
     * count. `$maxFeatures` caps the feature list (WFS `count`/`maxFeatures`).
     *
     * @param array    $rows        Object rows (assoc arrays).
     * @param ?string  $geoProperty Geo property name; null = auto-detect.
     * @param int|null $maxFeatures Optional cap on returned features.
     *
     * @return array A WFS-compatible GeoJSON FeatureCollection.
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    public function buildWfsResponse(array $rows, ?string $geoProperty=null, ?int $maxFeatures=null): array
    {
        $collection = $this->buildFeatureCollection(rows: $rows, geoProperty: $geoProperty);

        if ($maxFeatures !== null && $maxFeatures >= 0) {
            $collection['features'] = array_slice($collection['features'], 0, $maxFeatures);
        }

        $collection['numberReturned'] = count($collection['features']);

        return $collection;

    }//end buildWfsResponse()

    /**
     * Locate a geometry value within a row.
     *
     * @param array   $row         The object row.
     * @param ?string $geoProperty Explicit property name, or null to auto-detect.
     *
     * @return array{0: ?string, 1: ?array} Tuple of [property key, geometry].
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    private function locateGeometry(array $row, ?string $geoProperty): array
    {
        if ($geoProperty !== null) {
            $geometry = $this->coerceGeometry(value: ($row[$geoProperty] ?? null));
            return [$geoProperty, $geometry];
        }

        foreach ($row as $key => $value) {
            $geometry = $this->coerceGeometry(value: $value);
            if ($geometry !== null) {
                return [(string) $key, $geometry];
            }
        }

        return [null, null];

    }//end locateGeometry()

    /**
     * Shape a row's non-geometry properties into Feature properties.
     *
     * @param array         $row     The object row.
     * @param ?string       $geomKey The key holding the geometry (excluded).
     * @param string[]|null $fields  Allow-list, or null for all.
     *
     * @return array The Feature properties.
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    private function shapeProperties(array $row, ?string $geomKey, ?array $fields): array
    {
        $properties = [];
        foreach ($row as $key => $value) {
            if ($key === $geomKey) {
                continue;
            }

            if ($fields !== null && in_array($key, $fields, true) === false) {
                continue;
            }

            $properties[$key] = $value;
        }

        return $properties;

    }//end shapeProperties()

    /**
     * Coerce a raw value to a GeoJSON geometry array.
     *
     * @param mixed $value The candidate.
     *
     * @return ?array The geometry when shape matches, null otherwise.
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    private function coerceGeometry(mixed $value): ?array
    {
        if (is_array($value) === false) {
            return null;
        }

        if (in_array(($value['type'] ?? null), self::GEOJSON_TYPES, true) === false) {
            return null;
        }

        if (isset($value['coordinates']) === false || is_array($value['coordinates']) === false) {
            return null;
        }

        return $value;

    }//end coerceGeometry()

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
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    public function geodesicAreaM2(array $geometry): ?float
    {
        $type = ($geometry['type'] ?? null);
        if ($type !== 'Polygon' && $type !== 'MultiPolygon') {
            return null;
        }

        $polygons = [];
        if ($type === 'Polygon') {
            $polygons = [($geometry['coordinates'] ?? [])];
        } else {
            $polygons = ($geometry['coordinates'] ?? []);
        }

        $total = 0.0;
        foreach ($polygons as $rings) {
            if (is_array($rings) === false || count($rings) === 0) {
                continue;
            }

            $outer = abs($this->ringArea(ring: ($rings[0] ?? [])));
            $holes = 0.0;
            for ($i = 1; $i < count($rings); $i++) {
                $holes += abs($this->ringArea(ring: $rings[$i]));
            }

            $total += ($outer - $holes);
        }

        return round($total, 2);

    }//end geodesicAreaM2()

    /**
     * Spherical-excess area of a single ring in square meters.
     *
     * @param array $ring The ring vertices ([lon, lat] positions).
     *
     * @return float Signed area in square meters.
     *
     * @spec openspec/changes/geo-metadata-kaart/specs/geo-metadata-kaart/spec.md REQ-GEO-008
     */
    private function ringArea(array $ring): float
    {
        $n = count($ring);
        if ($n < 4) {
            return 0.0;
        }

        $area = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $p1 = $ring[$i];
            $p2 = $ring[(($i + 1) % $n)];
            if (is_array($p1) === false || is_array($p2) === false) {
                continue;
            }

            if (is_numeric($p1[0] ?? null) === false || is_numeric($p2[0] ?? null) === false) {
                continue;
            }

            $lon1 = deg2rad((float) $p1[0]);
            $lat1 = deg2rad((float) $p1[1]);
            $lon2 = deg2rad((float) $p2[0]);
            $lat2 = deg2rad((float) $p2[1]);

            $area += ($lon2 - $lon1) * (2 + sin($lat1) + sin($lat2));
        }

        $area = ($area * self::EARTH_RADIUS_M * self::EARTH_RADIUS_M / 2.0);

        return $area;

    }//end ringArea()
}//end class
