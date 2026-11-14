<?php

/**
 * Activity insights report the peak, low, average and trend of a period series.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\SearchTrailActivityInsights;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\SearchTrailActivityInsights
 */
class SearchTrailActivityInsightsTest extends TestCase {

	public function testNoActivityIsTheNoDataShape(): void {
		$this->assertSame(
			['peak_period' => null, 'low_period' => null, 'trend' => 'no_data', 'average_searches_per_period' => 0],
			(new SearchTrailActivityInsights())->insights([])
		);
	}//end testNoActivityIsTheNoDataShape()

	public function testPeakLowAverageAndRisingTrend(): void {
		$insights = (new SearchTrailActivityInsights())->insights(
			[
				['period' => '2026-01', 'count' => 2],
				['period' => '2026-02', 'count' => 5],
				['period' => '2026-03', 'count' => 11],
			]
		);

		$this->assertSame('2026-03', $insights['peak_period']);
		$this->assertSame(11, $insights['peak_count']);
		$this->assertSame('2026-01', $insights['low_period']);
		$this->assertSame(2, $insights['low_count']);
		$this->assertSame(6.0, $insights['average_searches_per_period']);
		$this->assertSame(3, $insights['total_periods']);
		$this->assertSame('increasing', $insights['trend']);
	}//end testPeakLowAverageAndRisingTrend()

	public function testAFallingSeriesDecreasesAndAFlatSeriesIsStable(): void {
		$service = new SearchTrailActivityInsights();

		$this->assertSame('decreasing', $service->insights([['period' => 'a', 'count' => 10], ['period' => 'b', 'count' => 1]])['trend']);
		$this->assertSame('stable', $service->insights([['period' => 'a', 'count' => 4], ['period' => 'b', 'count' => 4]])['trend']);
	}//end testAFallingSeriesDecreasesAndAFlatSeriesIsStable()

	public function testASinglePeriodIsStable(): void {
		$this->assertSame('stable', (new SearchTrailActivityInsights())->insights([['period' => 'a', 'count' => 7]])['trend']);
	}//end testASinglePeriodIsStable()
}//end class
