<?php

/**
 * Unit tests for GeoJsonGeometryValidator — geo-metadata shaping.
 *
 * Closes geo-metadata-kaart REQ-GEO-001: RFC 7946 validation of the
 * geo:point / geo:polygon / geo:multipolygon / geo:linestring /
 * geo:geometry / geo:bag property types.
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

use OCA\OpenRegister\Service\Geo\GeoJsonGeometryValidator;
use PHPUnit\Framework\TestCase;

class GeoJsonGeometryValidatorTest extends TestCase
{

    private GeoJsonGeometryValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new GeoJsonGeometryValidator();
    }//end setUp()

    public function testRecognisesGeoTypes(): void
    {
        $this->assertTrue($this->validator->isGeoType('geo:point'));
        $this->assertTrue($this->validator->isGeoType('GEO:BAG'));
        $this->assertFalse($this->validator->isGeoType('string'));
    }//end testRecognisesGeoTypes()

    public function testValidPointPasses(): void
    {
        $errors = $this->validator->validate('geo:point', ['type' => 'Point', 'coordinates' => [5.1214, 52.0907]]);
        $this->assertSame([], $errors);
    }//end testValidPointPasses()

    public function testPointOutOfRangeRejected(): void
    {
        $errors = $this->validator->validate('geo:point', ['type' => 'Point', 'coordinates' => [999, 999]]);
        $this->assertNotEmpty($errors);
    }//end testPointOutOfRangeRejected()

    public function testPointLongitudeIsFirstElement(): void
    {
        // 200 longitude is invalid; 80 latitude is fine — exactly one error.
        $errors = $this->validator->validate('geo:point', ['type' => 'Point', 'coordinates' => [200, 80]]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('longitude', $errors[0]);
    }//end testPointLongitudeIsFirstElement()

    public function testNonNumericCoordinatesRejected(): void
    {
        $errors = $this->validator->validate('geo:point', ['type' => 'Point', 'coordinates' => ['a', 'b']]);
        $this->assertNotEmpty($errors);
    }//end testNonNumericCoordinatesRejected()

    public function testValidClosedPolygonPasses(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.8, 52.3]]],
        ];
        $this->assertSame([], $this->validator->validate('geo:polygon', $polygon));
    }//end testValidClosedPolygonPasses()

    public function testUnclosedPolygonRejected(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.9, 52.35]]],
        ];
        $errors = $this->validator->validate('geo:polygon', $polygon);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('unclosed', implode(' ', $errors));
    }//end testUnclosedPolygonRejected()

    public function testPolygonRingTooFewPositionsRejected(): void
    {
        $polygon = [
            'type'        => 'Polygon',
            'coordinates' => [[[4.8, 52.3], [5.0, 52.3], [4.8, 52.3]]],
        ];
        $errors = $this->validator->validate('geo:polygon', $polygon);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('at least 4', implode(' ', $errors));
    }//end testPolygonRingTooFewPositionsRejected()

    public function testMultiPolygonValidatesEachPolygon(): void
    {
        $multi = [
            'type'        => 'MultiPolygon',
            'coordinates' => [
                [[[4.8, 52.3], [5.0, 52.3], [5.0, 52.4], [4.8, 52.3]]],
                [[[3.0, 51.0], [3.2, 51.0], [3.2, 51.2], [3.1, 51.05]]],
            ],
        ];
        $errors = $this->validator->validate('geo:multipolygon', $multi);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('polygon #1', implode(' ', $errors));
    }//end testMultiPolygonValidatesEachPolygon()

    public function testLineStringRequiresTwoPositions(): void
    {
        $bad = ['type' => 'LineString', 'coordinates' => [[5.1, 52.0]]];
        $this->assertNotEmpty($this->validator->validate('geo:linestring', $bad));

        $good = ['type' => 'LineString', 'coordinates' => [[5.1, 52.0], [5.2, 52.1]]];
        $this->assertSame([], $this->validator->validate('geo:linestring', $good));
    }//end testLineStringRequiresTwoPositions()

    public function testGeometryTypeAcceptsAnySupported(): void
    {
        $this->assertSame([], $this->validator->validate('geo:geometry', ['type' => 'Point', 'coordinates' => [5, 52]]));
        $this->assertNotEmpty($this->validator->validate('geo:geometry', ['type' => 'Sphere', 'coordinates' => []]));
    }//end testGeometryTypeAcceptsAnySupported()

    public function testValidBagReferencePasses(): void
    {
        $this->assertSame([], $this->validator->validate('geo:bag', '0363200000123456'));
    }//end testValidBagReferencePasses()

    public function testInvalidBagReferenceRejected(): void
    {
        $this->assertNotEmpty($this->validator->validate('geo:bag', '123'));
        $this->assertNotEmpty($this->validator->validate('geo:bag', 'abcd200000123456'));
        $this->assertNotEmpty($this->validator->validate('geo:bag', 12345));
    }//end testInvalidBagReferenceRejected()

    public function testNonArrayGeometryRejected(): void
    {
        $this->assertNotEmpty($this->validator->validate('geo:point', 'not-a-geometry'));
    }//end testNonArrayGeometryRejected()
}//end class
