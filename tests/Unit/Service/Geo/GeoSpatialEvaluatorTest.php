<?php

/**
 * Unit tests for GeoFilter + GeoSpatialEvaluator.
 *
 * Exercises every filter type (bbox, near+radius, within polygon,
 * intersects geometry) against representative GeoJSON fixtures.
 *
 * Closes geo-metadata-kaart REQ-GEO-004 + REQ-GEO-011 implementation.
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

use InvalidArgumentException;
use OCA\OpenRegister\Service\Geo\GeoFilter;
use OCA\OpenRegister\Service\Geo\GeoSpatialEvaluator;
use PHPUnit\Framework\TestCase;

class GeoSpatialEvaluatorTest extends TestCase
{

    private GeoSpatialEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new GeoSpatialEvaluator();
    }//end setUp()

    private function point(float $lon, float $lat): array
    {
        return ['type' => 'Point', 'coordinates' => [$lon, $lat]];
    }//end point()

    // ---- bbox -------------------------------------------------------

    public function testBboxIncludesPointInside(): void
    {
        $filter = GeoFilter::fromBbox('5.10,52.05,5.15,52.10');
        $this->assertTrue($this->evaluator->matches($this->point(5.12, 52.07), $filter));
    }//end testBboxIncludesPointInside()

    public function testBboxExcludesPointOutside(): void
    {
        $filter = GeoFilter::fromBbox('5.10,52.05,5.15,52.10');
        $this->assertFalse($this->evaluator->matches($this->point(6.00, 52.07), $filter));
        $this->assertFalse($this->evaluator->matches($this->point(5.12, 51.00), $filter));
    }//end testBboxExcludesPointOutside()

    public function testBboxIncludesPointOnEdge(): void
    {
        $filter = GeoFilter::fromBbox('5.10,52.05,5.15,52.10');
        // Edge inclusivity: corner + edge points are inside.
        $this->assertTrue($this->evaluator->matches($this->point(5.10, 52.05), $filter));
        $this->assertTrue($this->evaluator->matches($this->point(5.15, 52.10), $filter));
    }//end testBboxIncludesPointOnEdge()

    public function testBboxRejectsMalformed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoFilter::fromBbox('5.10,52.05,5.15');
    }//end testBboxRejectsMalformed()

    public function testBboxRejectsWestGreaterThanEast(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('west MUST be less than or equal to east');
        GeoFilter::fromBbox('5.20,52.05,5.10,52.10');
    }//end testBboxRejectsWestGreaterThanEast()

    public function testBboxOnPolygonUsesCentroid(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[
                [5.10, 52.05],
                [5.15, 52.05],
                [5.15, 52.10],
                [5.10, 52.10],
                [5.10, 52.05],
            ]],
        ];
        $filter = GeoFilter::fromBbox('5.11,52.06,5.14,52.09');
        $this->assertTrue($this->evaluator->matches($polygon, $filter));
    }//end testBboxOnPolygonUsesCentroid()

    public function testBboxNullGeometryFails(): void
    {
        $filter = GeoFilter::fromBbox('5.10,52.05,5.15,52.10');
        $this->assertFalse($this->evaluator->matches(null, $filter));
    }//end testBboxNullGeometryFails()

    // ---- near + radius ----------------------------------------------

    public function testNearIncludesPointInsideRadius(): void
    {
        // Two points ~111m apart at this latitude: 5.12,52.09 and 5.12,52.091
        $filter = GeoFilter::fromNearAndRadius(5.12, 52.09, 500);
        $this->assertTrue($this->evaluator->matches($this->point(5.12, 52.091), $filter));
    }//end testNearIncludesPointInsideRadius()

    public function testNearExcludesPointOutsideRadius(): void
    {
        // 5.12 → 5.13 is ~684m at lat 52, well outside 500m.
        $filter = GeoFilter::fromNearAndRadius(5.12, 52.09, 500);
        $this->assertFalse($this->evaluator->matches($this->point(5.13, 52.09), $filter));
    }//end testNearExcludesPointOutsideRadius()

    public function testNearRejectsZeroOrNegativeRadius(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoFilter::fromNearAndRadius(5.12, 52.09, 0);
    }//end testNearRejectsZeroOrNegativeRadius()

    public function testHaversineKnownDistance(): void
    {
        // Amsterdam → Utrecht is roughly 35-40 km in real geography.
        $d = $this->evaluator->haversineMeters(4.9041, 52.3676, 5.1214, 52.0907);
        $this->assertGreaterThan(30000, $d);
        $this->assertLessThan(45000, $d);
    }//end testHaversineKnownDistance()

    // ---- within polygon ---------------------------------------------

    private function squareAroundUtrecht(): array
    {
        // Closed ring around (5.0, 52.0) → (5.2, 52.2)
        return [
            'type'        => 'Polygon',
            'coordinates' => [[
                [5.0, 52.0],
                [5.2, 52.0],
                [5.2, 52.2],
                [5.0, 52.2],
                [5.0, 52.0],
            ]],
        ];
    }//end squareAroundUtrecht()

    public function testWithinIncludesPointInside(): void
    {
        $filter = GeoFilter::fromWithinGeometry($this->squareAroundUtrecht());
        $this->assertTrue($this->evaluator->matches($this->point(5.1, 52.1), $filter));
    }//end testWithinIncludesPointInside()

    public function testWithinExcludesPointOutside(): void
    {
        $filter = GeoFilter::fromWithinGeometry($this->squareAroundUtrecht());
        $this->assertFalse($this->evaluator->matches($this->point(6.0, 52.1), $filter));
    }//end testWithinExcludesPointOutside()

    public function testWithinRejectsNonPolygonPredicate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoFilter::fromWithinGeometry(['type' => 'Point', 'coordinates' => [5.1, 52.1]]);
    }//end testWithinRejectsNonPolygonPredicate()

    public function testWithinRejectsMissingCoordinates(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GeoFilter::fromWithinGeometry(['type' => 'Polygon']);
    }//end testWithinRejectsMissingCoordinates()

    // ---- intersects geometry ---------------------------------------

    public function testIntersectsPointInsidePredicate(): void
    {
        // Point geometry vs Polygon predicate behaves like within.
        $filter = GeoFilter::fromIntersectsGeometry($this->squareAroundUtrecht());
        $this->assertTrue($this->evaluator->matches($this->point(5.1, 52.1), $filter));
    }//end testIntersectsPointInsidePredicate()

    public function testIntersectsOverlappingPolygons(): void
    {
        // Row polygon overlaps the predicate's south-east corner.
        $rowPolygon = [
            'type'        => 'Polygon',
            'coordinates' => [[
                [5.15, 52.15],
                [5.30, 52.15],
                [5.30, 52.30],
                [5.15, 52.30],
                [5.15, 52.15],
            ]],
        ];
        $filter = GeoFilter::fromIntersectsGeometry($this->squareAroundUtrecht());
        $this->assertTrue($this->evaluator->matches($rowPolygon, $filter));
    }//end testIntersectsOverlappingPolygons()

    public function testIntersectsDisjointPolygons(): void
    {
        $rowPolygon = [
            'type'        => 'Polygon',
            'coordinates' => [[
                [6.0, 52.0],
                [6.2, 52.0],
                [6.2, 52.2],
                [6.0, 52.2],
                [6.0, 52.0],
            ]],
        ];
        $filter = GeoFilter::fromIntersectsGeometry($this->squareAroundUtrecht());
        $this->assertFalse($this->evaluator->matches($rowPolygon, $filter));
    }//end testIntersectsDisjointPolygons()
}//end class
