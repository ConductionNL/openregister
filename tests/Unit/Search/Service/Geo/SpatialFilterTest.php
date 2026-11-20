<?php

/**
 * The spatial-filter port: Search's answer parses and applies through the geo primitives.
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

use InvalidArgumentException;
use OCA\OpenRegister\Kernel\Contracts\Core\SpatialFilterInterface;
use OCA\OpenRegister\Search\SearchWiring;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplierInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParser;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParserInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoSpatialEvaluator;
use OCA\OpenRegister\Search\Service\Geo\SpatialFilter;
use PHPUnit\Framework\TestCase;

/**
 * Delegation to the parser and the post-filter, the real primitives end to end, and the binding.
 *
 * @covers \OCA\OpenRegister\Search\Service\Geo\SpatialFilter
 */
class SpatialFilterTest extends TestCase {

	/**
	 * Query params go to the parser unchanged and its filters come back unchanged.
	 *
	 * @return void
	 */
	public function testFromQueryParamsAsksTheParser(): void {
		$filters = [new \stdClass()];
		$parser  = $this->createMock(GeoFilterParserInterface::class);
		$parser->expects($this->once())->method('fromQueryParams')->with(['geo.bbox' => '1,2,3,4'])->willReturn($filters);
		$parser->expects($this->never())->method('fromGeoSearchBody');

		$filter = new SpatialFilter(parser: $parser, applier: $this->createMock(GeoFilterApplierInterface::class));

		$this->assertSame($filters, $filter->fromQueryParams(params: ['geo.bbox' => '1,2,3,4']));
	}//end testFromQueryParamsAsksTheParser()

	/**
	 * A geo-search body goes to the parser unchanged and its filters come back unchanged.
	 *
	 * @return void
	 */
	public function testFromGeoSearchBodyAsksTheParser(): void {
		$filters = [new \stdClass()];
		$body    = ['geometry' => ['within' => ['type' => 'Polygon']]];
		$parser  = $this->createMock(GeoFilterParserInterface::class);
		$parser->expects($this->once())->method('fromGeoSearchBody')->with($body)->willReturn($filters);

		$filter = new SpatialFilter(parser: $parser, applier: $this->createMock(GeoFilterApplierInterface::class));

		$this->assertSame($filters, $filter->fromGeoSearchBody(body: $body));
	}//end testFromGeoSearchBodyAsksTheParser()

	/**
	 * A parser refusal reaches the caller as the same exception.
	 *
	 * @return void
	 */
	public function testAParserRefusalPassesThrough(): void {
		$parser = $this->createMock(GeoFilterParserInterface::class);
		$parser->method('fromQueryParams')->willThrowException(new InvalidArgumentException('bad geo'));

		$filter = new SpatialFilter(parser: $parser, applier: $this->createMock(GeoFilterApplierInterface::class));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('bad geo');
		$filter->fromQueryParams(params: ['geo.near' => '5,52']);
	}//end testAParserRefusalPassesThrough()

	/**
	 * Rows and filters go to the post-filter unchanged and its rows come back unchanged.
	 *
	 * @return void
	 */
	public function testApplyAllAsksThePostFilter(): void {
		$rows    = [['id' => 1], ['id' => 2]];
		$filters = [new \stdClass()];
		$applier = $this->createMock(GeoFilterApplierInterface::class);
		$applier->expects($this->once())->method('applyAll')->with($rows, $filters)->willReturn([['id' => 2]]);

		$filter = new SpatialFilter(parser: $this->createMock(GeoFilterParserInterface::class), applier: $applier);

		$this->assertSame([['id' => 2]], $filter->applyAll(rows: $rows, filters: $filters));
	}//end testApplyAllAsksThePostFilter()

	/**
	 * With the real primitives a bounding box keeps the rows inside it, in order.
	 *
	 * @return void
	 */
	public function testTheRealPrimitivesNarrowByABoundingBox(): void {
		$filter = new SpatialFilter(parser: new GeoFilterParser(), applier: new GeoFilterApplier(new GeoSpatialEvaluator()));
		$inside = ['id' => 'in', 'location' => ['type' => 'Point', 'coordinates' => [5.1, 52.1]]];
		$away   = ['id' => 'out', 'location' => ['type' => 'Point', 'coordinates' => [10.0, 40.0]]];

		$filters = $filter->fromQueryParams(params: ['geo.bbox' => '5,52,6,53']);

		$this->assertCount(1, $filters);
		$this->assertSame([$inside], $filter->applyAll(rows: [$inside, $away], filters: $filters));
	}//end testTheRealPrimitivesNarrowByABoundingBox()

	/**
	 * Search binds the Core port to this class in its own wiring, and the class implements it.
	 *
	 * @return void
	 */
	public function testSearchBindsTheCorePort(): void {
		$this->assertSame(SpatialFilter::class, SearchWiring::BINDINGS[SpatialFilterInterface::class] ?? null);
		$this->assertContains(SpatialFilterInterface::class, class_implements(SpatialFilter::class));
	}//end testSearchBindsTheCorePort()
}//end class
