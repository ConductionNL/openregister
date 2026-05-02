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

use InvalidArgumentException;

/**
 * Wire-format adapter that turns HTTP query params and POST bodies
 * into GeoFilter value objects.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) GeoFilter is an immutable value object built via the canonical static-factory pattern; PHPMD's StaticAccess warning is a false positive for that pattern.
 */
class GeoFilterParser
{
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
     */
    public function fromQueryParams(array $params): array
    {
        $filters  = [];
        $property = null;
        if (isset($params['geo.property']) === true) {
            $property = (string) $params['geo.property'];
        }

        if (isset($params['geo.bbox']) === true && $params['geo.bbox'] !== '') {
            $filters[] = GeoFilter::fromBbox(bbox: (string) $params['geo.bbox'], property: $property);
        }

        $hasNear   = (isset($params['geo.near']) === true && $params['geo.near'] !== '');
        $hasRadius = (isset($params['geo.radius']) === true && $params['geo.radius'] !== '');

        if (($hasNear xor $hasRadius) === true) {
            throw new InvalidArgumentException(
                'geo.near and geo.radius MUST be used together — neither is meaningful alone'
            );
        }

        if ($hasNear === true) {
            $coords = array_map('trim', explode(',', (string) $params['geo.near']));
            if (count($coords) !== 2) {
                throw new InvalidArgumentException('geo.near MUST be `lon,lat` — exactly two values');
            }

            $filters[] = GeoFilter::fromNearAndRadius(
                lon: $coords[0],
                lat: $coords[1],
                radius: (string) $params['geo.radius'],
                property: $property
            );
        }

        return $filters;

    }//end fromQueryParams()

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
     */
    public function fromGeoSearchBody(array $body): array
    {
        $geometry = ($body['geometry'] ?? null);
        if (is_array($geometry) === false) {
            throw new InvalidArgumentException('geo-search body MUST include a geometry object');
        }

        $property = null;
        if (isset($body['property']) === true) {
            $property = (string) $body['property'];
        }

        $filters = [];

        if (isset($geometry['within']) === true) {
            if (is_array($geometry['within']) === false) {
                throw new InvalidArgumentException('geometry.within MUST be a GeoJSON geometry object');
            }

            $filters[] = GeoFilter::fromWithinGeometry(geometry: $geometry['within'], property: $property);
        }

        if (isset($geometry['intersects']) === true) {
            if (is_array($geometry['intersects']) === false) {
                throw new InvalidArgumentException('geometry.intersects MUST be a GeoJSON geometry object');
            }

            $filters[] = GeoFilter::fromIntersectsGeometry(geometry: $geometry['intersects'], property: $property);
        }

        if (count($filters) === 0) {
            throw new InvalidArgumentException(
                'geo-search body MUST include at least one of: geometry.within, geometry.intersects'
            );
        }

        return $filters;

    }//end fromGeoSearchBody()
}//end class
