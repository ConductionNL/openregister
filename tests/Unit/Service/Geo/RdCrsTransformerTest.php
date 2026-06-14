<?php

/**
 * Unit tests for RdCrsTransformer — WGS84 <-> RD New (EPSG:28992).
 *
 * Closes geo-metadata-kaart REQ-GEO-015: coordinate transformation and
 * supported-CRS gating.
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

use OCA\OpenRegister\Service\Geo\RdCrsTransformer;
use PHPUnit\Framework\TestCase;

class RdCrsTransformerTest extends TestCase
{

    private RdCrsTransformer $transformer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transformer = new RdCrsTransformer();
    }//end setUp()

    public function testSupportedCrsGate(): void
    {
        $this->assertTrue($this->transformer->isSupportedCrs('EPSG:4326'));
        $this->assertTrue($this->transformer->isSupportedCrs('epsg:28992'));
        $this->assertFalse($this->transformer->isSupportedCrs('EPSG:3857'));
        $this->assertSame(['EPSG:4326', 'EPSG:28992'], $this->transformer->supportedCrs());
    }//end testSupportedCrsGate()

    public function testRdToWgs84Amsterdam(): void
    {
        // Amsterdam Centraal in RD ~ [121687, 487484] -> ~[4.9003, 52.3791].
        [$lon, $lat] = $this->transformer->rdToWgs84(121687.0, 487484.0);
        $this->assertEqualsWithDelta(4.9003, $lon, 0.01);
        $this->assertEqualsWithDelta(52.3791, $lat, 0.01);
    }//end testRdToWgs84Amsterdam()

    public function testWgs84ToRdAmersfoortOrigin(): void
    {
        // The RD origin (Amersfoort tower) ~ [5.3872, 52.1552] -> [155000, 463000].
        [$x, $y] = $this->transformer->wgs84ToRd(5.38720621, 52.15517440);
        $this->assertEqualsWithDelta(155000.0, $x, 1.0);
        $this->assertEqualsWithDelta(463000.0, $y, 1.0);
    }//end testWgs84ToRdAmersfoortOrigin()

    public function testRoundTripIsStable(): void
    {
        [$lon, $lat] = $this->transformer->rdToWgs84(121687.0, 487484.0);
        [$x, $y]     = $this->transformer->wgs84ToRd($lon, $lat);
        $this->assertEqualsWithDelta(121687.0, $x, 2.0);
        $this->assertEqualsWithDelta(487484.0, $y, 2.0);
    }//end testRoundTripIsStable()
}//end class
