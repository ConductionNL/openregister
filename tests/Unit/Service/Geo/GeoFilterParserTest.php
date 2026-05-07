<?php

/**
 * Unit tests for GeoFilterParser — wire-format adapter.
 *
 * Closes geo-metadata-kaart REQ-GEO-004 query-param + POST body parsing.
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
use OCA\OpenRegister\Service\Geo\GeoFilterParser;
use PHPUnit\Framework\TestCase;

class GeoFilterParserTest extends TestCase
{

    private GeoFilterParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new GeoFilterParser();
    }//end setUp()

    public function testQueryWithoutGeoParamsReturnsEmpty(): void
    {
        $this->assertSame([], $this->parser->fromQueryParams(['_limit' => 10]));
    }//end testQueryWithoutGeoParamsReturnsEmpty()

    public function testQueryParsesBbox(): void
    {
        $filters = $this->parser->fromQueryParams(['geo.bbox' => '5.10,52.05,5.15,52.10']);
        $this->assertCount(1, $filters);
        $this->assertSame(GeoFilter::TYPE_BBOX, $filters[0]->type);
        $this->assertSame(5.10, $filters[0]->payload['west']);
        $this->assertSame(52.10, $filters[0]->payload['north']);
    }//end testQueryParsesBbox()

    public function testQueryParsesNearAndRadius(): void
    {
        $filters = $this->parser->fromQueryParams([
            'geo.near'   => '5.12,52.09',
            'geo.radius' => '500',
        ]);
        $this->assertCount(1, $filters);
        $this->assertSame(GeoFilter::TYPE_NEAR, $filters[0]->type);
        $this->assertSame(5.12, $filters[0]->payload['lon']);
        $this->assertSame(500.0, $filters[0]->payload['radius']);
    }//end testQueryParsesNearAndRadius()

    public function testQueryRejectsNearWithoutRadius(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MUST be used together');
        $this->parser->fromQueryParams(['geo.near' => '5.12,52.09']);
    }//end testQueryRejectsNearWithoutRadius()

    public function testQueryRejectsRadiusWithoutNear(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->fromQueryParams(['geo.radius' => '500']);
    }//end testQueryRejectsRadiusWithoutNear()

    public function testQueryComposesBboxAndNear(): void
    {
        $filters = $this->parser->fromQueryParams([
            'geo.bbox'   => '5.10,52.05,5.15,52.10',
            'geo.near'   => '5.12,52.09',
            'geo.radius' => '500',
        ]);
        $this->assertCount(2, $filters);
        $this->assertSame(GeoFilter::TYPE_BBOX, $filters[0]->type);
        $this->assertSame(GeoFilter::TYPE_NEAR, $filters[1]->type);
    }//end testQueryComposesBboxAndNear()

    public function testQueryHonoursPropertyHint(): void
    {
        $filters = $this->parser->fromQueryParams([
            'geo.bbox'     => '5.10,52.05,5.15,52.10',
            'geo.property' => 'locatie',
        ]);
        $this->assertSame('locatie', $filters[0]->property);
    }//end testQueryHonoursPropertyHint()

    public function testGeoSearchBodyParsesWithinPolygon(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.8, 52.4], [4.8, 52.3]]],
        ];
        $filters = $this->parser->fromGeoSearchBody([
            'geometry' => ['within' => $polygon],
        ]);
        $this->assertCount(1, $filters);
        $this->assertSame(GeoFilter::TYPE_WITHIN, $filters[0]->type);
        $this->assertSame($polygon, $filters[0]->payload['geometry']);
    }//end testGeoSearchBodyParsesWithinPolygon()

    public function testGeoSearchBodyParsesIntersects(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.8, 52.4], [4.8, 52.3]]],
        ];
        $filters = $this->parser->fromGeoSearchBody([
            'geometry' => ['intersects' => $polygon],
            'property' => 'boundary',
        ]);
        $this->assertCount(1, $filters);
        $this->assertSame(GeoFilter::TYPE_INTERSECTS, $filters[0]->type);
        $this->assertSame('boundary', $filters[0]->property);
    }//end testGeoSearchBodyParsesIntersects()

    public function testGeoSearchBodyComposesWithinAndIntersects(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.8, 52.4], [4.8, 52.3]]],
        ];
        $filters = $this->parser->fromGeoSearchBody([
            'geometry' => [
                'within'     => $polygon,
                'intersects' => $polygon,
            ],
        ]);
        $this->assertCount(2, $filters);
    }//end testGeoSearchBodyComposesWithinAndIntersects()

    public function testGeoSearchBodyRejectsMissingGeometry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->parser->fromGeoSearchBody([]);
    }//end testGeoSearchBodyRejectsMissingGeometry()

    public function testGeoSearchBodyRejectsEmptyGeometry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one of');
        $this->parser->fromGeoSearchBody(['geometry' => []]);
    }//end testGeoSearchBodyRejectsEmptyGeometry()
}//end class
