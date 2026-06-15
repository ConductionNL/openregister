<?php

/**
 * Unit tests for GeoFeatureCollectionBuilder — WFS / GeoJSON feature output.
 *
 * Closes geo-metadata-kaart REQ-GEO-008: objects -> GeoJSON
 * FeatureCollection, field selection, WFS GetFeature envelope with
 * maxFeatures cap, and geodesic `_area_m2`.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Geo;

use OCA\OpenRegister\Service\Geo\GeoFeatureCollectionBuilder;
use PHPUnit\Framework\TestCase;

class GeoFeatureCollectionBuilderTest extends TestCase
{

    private GeoFeatureCollectionBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new GeoFeatureCollectionBuilder();
    }//end setUp()

    private function pointRows(): array
    {
        return [
            ['id' => 1, 'title' => 'A', 'status' => 'open', 'locatie' => ['type' => 'Point', 'coordinates' => [5.1, 52.0]]],
            ['id' => 2, 'title' => 'B', 'status' => 'done', 'locatie' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]]],
            ['id' => 3, 'title' => 'C', 'status' => 'open'],
        ];
    }//end pointRows()

    public function testBuildsFeatureCollection(): void
    {
        $fc = $this->builder->buildFeatureCollection($this->pointRows());
        $this->assertSame('FeatureCollection', $fc['type']);
        // Row 3 has no geometry -> skipped.
        $this->assertCount(2, $fc['features']);
        $this->assertSame('Feature', $fc['features'][0]['type']);
        $this->assertSame([5.1, 52.0], $fc['features'][0]['geometry']['coordinates']);
        $this->assertSame(1, $fc['features'][0]['id']);
        // Geometry property is not duplicated into properties.
        $this->assertArrayNotHasKey('locatie', $fc['features'][0]['properties']);
        $this->assertSame('A', $fc['features'][0]['properties']['title']);
    }//end testBuildsFeatureCollection()

    public function testFieldAllowListKeepsGeometry(): void
    {
        $fc = $this->builder->buildFeatureCollection($this->pointRows(), null, ['title']);
        $props = $fc['features'][0]['properties'];
        $this->assertArrayHasKey('title', $props);
        $this->assertArrayNotHasKey('status', $props);
        // Geometry always retained regardless of _fields.
        $this->assertArrayHasKey('geometry', $fc['features'][0]);
    }//end testFieldAllowListKeepsGeometry()

    public function testWfsResponseHasNumberReturned(): void
    {
        $resp = $this->builder->buildWfsResponse($this->pointRows());
        $this->assertSame('FeatureCollection', $resp['type']);
        $this->assertSame(2, $resp['numberReturned']);
    }//end testWfsResponseHasNumberReturned()

    public function testWfsMaxFeaturesCap(): void
    {
        $resp = $this->builder->buildWfsResponse($this->pointRows(), null, 1);
        $this->assertCount(1, $resp['features']);
        $this->assertSame(1, $resp['numberReturned']);
    }//end testWfsMaxFeaturesCap()

    public function testPolygonAreaIncluded(): void
    {
        // ~1km x ~1km square near Amsterdam.
        $rows = [
            [
                'id'      => 10,
                'title'   => 'Gebied',
                'grenzen' => [
                    'type'        => 'Polygon',
                    'coordinates' => [[[4.90, 52.36], [4.9147, 52.36], [4.9147, 52.369], [4.90, 52.369], [4.90, 52.36]]],
                ],
            ],
        ];

        $fc   = $this->builder->buildFeatureCollection($rows, null, null, true);
        $area = $fc['features'][0]['properties']['_area_m2'];
        $this->assertIsFloat($area);
        // Roughly a square kilometre — within an order of magnitude.
        $this->assertGreaterThan(500000, $area);
        $this->assertLessThan(2000000, $area);
    }//end testPolygonAreaIncluded()

    public function testGeodesicAreaNullForNonPolygon(): void
    {
        $this->assertNull($this->builder->geodesicAreaM2(['type' => 'Point', 'coordinates' => [5, 52]]));
    }//end testGeodesicAreaNullForNonPolygon()

    public function testEmptyRowsYieldEmptyCollection(): void
    {
        $fc = $this->builder->buildFeatureCollection([]);
        $this->assertSame([], $fc['features']);
    }//end testEmptyRowsYieldEmptyCollection()
}//end class
