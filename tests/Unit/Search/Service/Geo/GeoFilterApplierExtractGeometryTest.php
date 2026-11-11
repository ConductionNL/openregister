<?php

/**
 * Characterisation of how a row's geometry is found.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Service\Geo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Search\Service\Geo;

use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Search\Service\Geo\GeoSpatialEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * extractGeometry with a named property and without one.
 *
 * @covers \OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier
 */
class GeoFilterApplierExtractGeometryTest extends TestCase {

	/**
	 * A named property is read alone; without one the first GeoJSON value wins.
	 *
	 * @return void
	 */
	public function testExtractGeometry(): void {
		$applier = new GeoFilterApplier(new GeoSpatialEvaluator());
		$point   = ['type' => 'Point', 'coordinates' => [5.1, 52.1]];
		$line    = ['type' => 'LineString', 'coordinates' => [[0, 0], [1, 1]]];
		$row     = ['title' => 'x', 'bad' => ['type' => 'Point'], 'first' => $point, 'second' => $line];

		$this->assertSame($line, $applier->extractGeometry($row, 'second'));
		$this->assertNull($applier->extractGeometry($row, 'bad'));
		$this->assertNull($applier->extractGeometry($row, 'missing'));
		$this->assertSame($point, $applier->extractGeometry($row, null));
		$this->assertNull($applier->extractGeometry(['title' => 'x', 'n' => 3], null));
		$this->assertNull($applier->extractGeometry([], null));
	}//end testExtractGeometry()
}//end class
