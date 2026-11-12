<?php

/**
 * GeoResponseCrs: every GeoJSON geometry shape in RD New, and what stays as it was.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  Unit\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/geo-metadata-kaart/spec.md REQ-GEO-015
 */

declare(strict_types=1);

namespace Unit\Service\Geo;

use OCA\OpenRegister\Search\Service\Geo\GeoResponseCrs;
use OCA\OpenRegister\Search\Service\Geo\RdCrsTransformer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Search\Service\Geo\GeoResponseCrs
 * @uses   \OCA\OpenRegister\Search\Service\Geo\RdCrsTransformer
 */
class GeoResponseCrsTest extends TestCase {

	private const POINT = [4.9003, 52.3791];

	/**
	 * A collection of one geometry, in RD New.
	 *
	 * @param array<string, mixed>|null $geometry The geometry.
	 *
	 * @return array<string, mixed>|null The geometry after the transform.
	 */
	private function inRd(?array $geometry): ?array {
		$collection = ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'geometry' => $geometry, 'properties' => ['area' => 5]]]];

		return (new GeoResponseCrs(accept: 'EPSG:28992'))->applyTo(collection: $collection)['features'][0]['geometry'];
	}//end inRd()

	/**
	 * The RD position of POINT.
	 *
	 * @return array{0: float, 1: float} The position.
	 */
	private function rd(): array {
		return (new RdCrsTransformer())->wgs84ToRd(longitude: self::POINT[0], latitude: self::POINT[1]);
	}//end rd()

	public function testAPoint(): void {
		$this->assertSame($this->rd(), $this->inRd(['type' => 'Point', 'coordinates' => self::POINT])['coordinates']);
	}//end testAPoint()

	public function testAnAltitudeIsKept(): void {
		$position = $this->inRd(['type' => 'Point', 'coordinates' => [4.9003, 52.3791, 12.5]])['coordinates'];

		$this->assertSame([...$this->rd(), 12.5], $position);
	}//end testAnAltitudeIsKept()

	public function testALineAndAPolygonAndAMultiPolygon(): void {
		$rd = $this->rd();

		$this->assertSame([$rd, $rd], $this->inRd(['type' => 'LineString', 'coordinates' => [self::POINT, self::POINT]])['coordinates']);
		$this->assertSame([[$rd]], $this->inRd(['type' => 'Polygon', 'coordinates' => [[self::POINT]]])['coordinates']);
		$this->assertSame([[[$rd]]], $this->inRd(['type' => 'MultiPolygon', 'coordinates' => [[[self::POINT]]]])['coordinates']);
	}//end testALineAndAPolygonAndAMultiPolygon()

	public function testAGeometryCollection(): void {
		$geometry = $this->inRd(['type' => 'GeometryCollection', 'geometries' => [['type' => 'Point', 'coordinates' => self::POINT]]]);

		$this->assertSame($this->rd(), $geometry['geometries'][0]['coordinates']);
	}//end testAGeometryCollection()

	public function testAFeatureWithoutAGeometryStaysNull(): void {
		$this->assertNull($this->inRd(null));
	}//end testAFeatureWithoutAGeometryStaysNull()

	public function testEverythingBesideTheGeometryIsKept(): void {
		$collection = ['type' => 'FeatureCollection', 'numberReturned' => 1, 'features' => [['type' => 'Feature', 'id' => 'a', 'geometry' => null, 'properties' => ['area' => 5]]]];

		$this->assertSame($collection, (new GeoResponseCrs(accept: 'EPSG:28992'))->applyTo(collection: $collection));
	}//end testEverythingBesideTheGeometryIsKept()

	public function testAnEmptyCollectionHasNoFeatures(): void {
		$this->assertSame(['features' => []], (new GeoResponseCrs(accept: 'EPSG:28992'))->applyTo(collection: []));
	}//end testAnEmptyCollectionHasNoFeatures()

	public function testNoRequestAndWgs84LeaveTheCollectionAlone(): void {
		$collection = ['features' => [['geometry' => ['type' => 'Point', 'coordinates' => self::POINT]]]];

		$this->assertSame($collection, (new GeoResponseCrs(accept: ''))->applyTo(collection: $collection));
		$this->assertSame($collection, (new GeoResponseCrs(accept: 'EPSG:4326'))->applyTo(collection: $collection));
	}//end testNoRequestAndWgs84LeaveTheCollectionAlone()

	public function testWhatIsServable(): void {
		$this->assertTrue((new GeoResponseCrs(accept: ''))->isServable());
		$this->assertTrue((new GeoResponseCrs(accept: '  epsg:28992 '))->isServable());
		$this->assertFalse((new GeoResponseCrs(accept: 'EPSG:3857'))->isServable());
	}//end testWhatIsServable()

	public function testTheHeaderNamesWhatWasAskedForAndNothingElse(): void {
		$this->assertNull((new GeoResponseCrs(accept: ''))->contentCrs());
		$this->assertSame('EPSG:28992', (new GeoResponseCrs(accept: ' epsg:28992'))->contentCrs());
	}//end testTheHeaderNamesWhatWasAskedForAndNothingElse()
}//end class
