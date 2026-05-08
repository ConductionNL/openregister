<?php

/**
 * GeoFilter value object.
 *
 * Captures a single spatial filter that can be applied to a list of
 * objects with `geo`-typed properties. Four filter types per
 * REQ-GEO-004 (geo-metadata-kaart spec):
 *
 *   - bbox        — `?geo.bbox=west,south,east,north`
 *   - near+radius — `?geo.near=lon,lat&geo.radius=meters`
 *   - within      — POST /geo-search body `{ geometry: { within: <Polygon> } }`
 *   - intersects  — POST /geo-search body `{ geometry: { intersects: <geometry> } }`
 *
 * Filters are immutable and constructed via static factory methods so
 * invalid input fails fast at parse time.
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
 * Immutable spatial filter descriptor.
 */
class GeoFilter
{

    public const TYPE_BBOX = 'bbox';

    public const TYPE_NEAR = 'near';

    public const TYPE_WITHIN = 'within';

    public const TYPE_INTERSECTS = 'intersects';

    /**
     * Constructor — use the static factories.
     *
     * @param string  $type     One of the TYPE_* constants.
     * @param array   $payload  Filter-specific payload.
     * @param ?string $property Property name; null = first geo-typed.
     */
    private function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly ?string $property=null
    ) {

    }//end __construct()

    /**
     * Build a bounding-box filter.
     *
     * @param string  $bbox     Comma-separated `west,south,east,north`.
     * @param ?string $property Property hint.
     *
     * @return self
     *
     * @throws InvalidArgumentException When malformed.
     */
    public static function fromBbox(string $bbox, ?string $property=null): self
    {
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4) {
            throw new InvalidArgumentException('bbox MUST be exactly 4 comma-separated decimal values');
        }

        foreach ($parts as $p) {
            if (is_numeric($p) === false) {
                throw new InvalidArgumentException('bbox values MUST be numeric');
            }
        }

        $west  = (float) $parts[0];
        $south = (float) $parts[1];
        $east  = (float) $parts[2];
        $north = (float) $parts[3];

        if ($west > $east) {
            throw new InvalidArgumentException('bbox west MUST be less than or equal to east');
        }

        if ($south > $north) {
            throw new InvalidArgumentException('bbox south MUST be less than or equal to north');
        }

        return new self(
            self::TYPE_BBOX,
            ['west' => $west, 'south' => $south, 'east' => $east, 'north' => $north],
            $property
        );

    }//end fromBbox()

    /**
     * Build a point + radius filter.
     *
     * @param float|string $lon      Center longitude.
     * @param float|string $lat      Center latitude.
     * @param float|string $radius   Radius in meters.
     * @param ?string      $property Property hint.
     *
     * @return self
     *
     * @throws InvalidArgumentException When malformed.
     */
    public static function fromNearAndRadius(
        float|string $lon,
        float|string $lat,
        float|string $radius,
        ?string $property=null
    ): self {
        if (is_numeric($lon) === false || is_numeric($lat) === false || is_numeric($radius) === false) {
            throw new InvalidArgumentException('near coordinates and radius MUST be numeric');
        }

        $r = (float) $radius;
        if ($r <= 0.0) {
            throw new InvalidArgumentException('radius MUST be greater than 0 meters');
        }

        return new self(
            self::TYPE_NEAR,
            ['lon' => (float) $lon, 'lat' => (float) $lat, 'radius' => $r],
            $property
        );

    }//end fromNearAndRadius()

    /**
     * Build a within-polygon filter from a GeoJSON geometry.
     *
     * @param array   $geometry GeoJSON Polygon or MultiPolygon.
     * @param ?string $property Property hint.
     *
     * @return self
     *
     * @throws InvalidArgumentException When geometry is malformed.
     */
    public static function fromWithinGeometry(array $geometry, ?string $property=null): self
    {
        self::assertGeoJsonGeometry(geometry: $geometry, opName: 'within');
        return new self(self::TYPE_WITHIN, ['geometry' => $geometry], $property);

    }//end fromWithinGeometry()

    /**
     * Build an intersects filter from a GeoJSON geometry.
     *
     * @param array   $geometry GeoJSON Polygon or MultiPolygon.
     * @param ?string $property Property hint.
     *
     * @return self
     *
     * @throws InvalidArgumentException When geometry is malformed.
     */
    public static function fromIntersectsGeometry(array $geometry, ?string $property=null): self
    {
        self::assertGeoJsonGeometry(geometry: $geometry, opName: 'intersects');
        return new self(self::TYPE_INTERSECTS, ['geometry' => $geometry], $property);

    }//end fromIntersectsGeometry()

    /**
     * Validate that a value looks like a GeoJSON geometry.
     *
     * @param mixed  $geometry The candidate.
     * @param string $opName   Operator name for error context.
     *
     * @return void
     *
     * @throws InvalidArgumentException When the geometry is invalid.
     */
    private static function assertGeoJsonGeometry(mixed $geometry, string $opName): void
    {
        if (is_array($geometry) === false) {
            throw new InvalidArgumentException("{$opName} geometry MUST be a GeoJSON object");
        }

        $type = $geometry['type'] ?? null;
        if (in_array($type, ['Polygon', 'MultiPolygon'], true) === false) {
            throw new InvalidArgumentException(
                "{$opName} geometry type MUST be Polygon or MultiPolygon (got: ".var_export($type, true).')'
            );
        }

        if (isset($geometry['coordinates']) === false || is_array($geometry['coordinates']) === false) {
            throw new InvalidArgumentException("{$opName} geometry MUST have a coordinates array");
        }

    }//end assertGeoJsonGeometry()
}//end class
