<?php

/**
 * Unit tests for GeoFilterApplier.
 *
 * Verifies AND-composition + property-hint vs first-found extraction.
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

use OCA\OpenRegister\Service\Geo\GeoFilter;
use OCA\OpenRegister\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Service\Geo\GeoSpatialEvaluator;
use PHPUnit\Framework\TestCase;

class GeoFilterApplierTest extends TestCase
{

    private GeoFilterApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new GeoFilterApplier(new GeoSpatialEvaluator());
    }//end setUp()

    private function row(string $title, float $lon, float $lat, string $key='locatie'): array
    {
        return [
            'title' => $title,
            $key    => ['type' => 'Point', 'coordinates' => [$lon, $lat]],
        ];
    }//end row()

    public function testEmptyFilterListPassesEverythingThrough(): void
    {
        $rows = [$this->row('a', 5, 52), $this->row('b', 6, 53)];
        $this->assertSame($rows, $this->applier->applyAll($rows, []));
    }//end testEmptyFilterListPassesEverythingThrough()

    public function testBboxFilterKeepsOnlyMatchingRows(): void
    {
        $rows = [
            $this->row('inside-1', 5.12, 52.07),
            $this->row('outside',  6.50, 52.07),
            $this->row('inside-2', 5.13, 52.08),
        ];
        $filters = [GeoFilter::fromBbox('5.10,52.05,5.15,52.10')];

        $result = $this->applier->applyAll($rows, $filters);
        $titles = array_map(fn($r) => $r['title'], $result);
        $this->assertSame(['inside-1', 'inside-2'], $titles);
    }//end testBboxFilterKeepsOnlyMatchingRows()

    public function testAndComposition_BboxIntersectsNear(): void
    {
        // Inside bbox AND inside 500m of (5.12, 52.09) — only the
        // tightly-clustered point qualifies.
        $rows = [
            $this->row('inside-both',  5.12, 52.091),
            $this->row('inside-bbox-only', 5.149, 52.06),
            $this->row('outside-both', 6.0, 53.0),
        ];
        $filters = [
            GeoFilter::fromBbox('5.10,52.05,5.15,52.10'),
            GeoFilter::fromNearAndRadius(5.12, 52.09, 500),
        ];

        $result = $this->applier->applyAll($rows, $filters);
        $titles = array_map(fn($r) => $r['title'], $result);
        $this->assertSame(['inside-both'], $titles);
    }//end testAndComposition_BboxIntersectsNear()

    public function testRowWithoutGeometryIsExcluded(): void
    {
        $rows = [
            $this->row('has-geo', 5.12, 52.07),
            ['title' => 'no-geo'],
        ];
        $filters = [GeoFilter::fromBbox('5.10,52.05,5.15,52.10')];

        $result = $this->applier->applyAll($rows, $filters);
        $this->assertCount(1, $result);
        $this->assertSame('has-geo', $result[0]['title']);
    }//end testRowWithoutGeometryIsExcluded()

    public function testPropertyHintTargetsNamedProperty(): void
    {
        $row = [
            'title'   => 'two-geo-props',
            'home'    => ['type' => 'Point', 'coordinates' => [4.9, 52.4]],
            'office'  => ['type' => 'Point', 'coordinates' => [5.12, 52.07]],
        ];
        $bbox = '5.10,52.05,5.15,52.10';

        // Without hint: first-found `home` is used → outside bbox.
        $this->assertFalse(
            $this->applier->rowMatchesAll($row, [GeoFilter::fromBbox($bbox)])
        );

        // With hint `office`: that point IS inside the bbox.
        $this->assertTrue(
            $this->applier->rowMatchesAll($row, [GeoFilter::fromBbox($bbox, 'office')])
        );
    }//end testPropertyHintTargetsNamedProperty()

    public function testCoercionRejectsNonGeoJsonShapes(): void
    {
        $row = [
            'title' => 'bogus',
            'meta'  => ['type' => 'Customer', 'coordinates' => [1, 2]],
            // Wrong `type` value — must NOT be picked up as a geometry.
        ];
        $this->assertNull($this->applier->extractGeometry($row, null));
    }//end testCoercionRejectsNonGeoJsonShapes()
}//end class
