<?php

/**
 * Characterisation test for SearchTrailStatistics' period-bound reports.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use DateTime;
use DateTimeZone;
use OCA\OpenRegister\Service\SearchPeriod;
use OCA\OpenRegister\Service\SearchTrailStatistics;
use PHPUnit\Framework\TestCase;

/**
 * Pins withPeriodMetrics, termsReport, combinationsReport and userAgentReport.
 */
class SearchTrailStatisticsGoldenTest extends TestCase {
	/**
	 * A fixed instant, independent of the clock and the default timezone.
	 *
	 * @param string $at The date and time.
	 *
	 * @return DateTime
	 */
	private function at(string $at): DateTime {
		return new DateTime($at, new DateTimeZone('UTC'));
	}//end at()

	/**
	 * Derived metrics with a bounded period carry the period, its days and the daily averages.
	 *
	 * @return void
	 */
	public function testDerivedMetricsWithPeriod(): void {
		$stats = (new SearchTrailStatistics())->withPeriodMetrics(['total_searches' => 20, 'total_results' => 45], new SearchPeriod($this->at('2026-01-01 08:00:00'), $this->at('2026-01-04 09:30:00')));

		$this->assertSame(
			[
				'total_searches'       => 20,
				'total_results'        => 45,
				'unique_organizations' => 0,
				'query_complexity'     => ['simple' => 12.0, 'medium' => 6.0, 'complex' => 2.0],
				'period'               => ['from' => '2026-01-01 08:00:00', 'to' => '2026-01-04 09:30:00', 'days' => 4],
				'daily_averages'       => ['searches_per_day' => 5.0, 'results_per_day' => 11.25],
			],
			$stats
		);
	}//end testDerivedMetricsWithPeriod()

	/**
	 * Derived metrics without a period carry null bounds and no daily averages.
	 *
	 * @return void
	 */
	public function testDerivedMetricsWithoutPeriod(): void {
		$stats = (new SearchTrailStatistics())->withPeriodMetrics(['total_searches' => 0, 'total_results' => 0], new SearchPeriod());

		$this->assertSame(
			[
				'total_searches'       => 0,
				'total_results'        => 0,
				'unique_organizations' => 0,
				'query_complexity'     => ['simple' => 0, 'medium' => 0, 'complex' => 0],
				'period'               => ['from' => null, 'to' => null, 'days' => null],
			],
			$stats
		);
	}//end testDerivedMetricsWithoutPeriod()

	/**
	 * Popular terms are rated by share and effectiveness.
	 *
	 * @return void
	 */
	public function testPopularTerms(): void {
		$report = (new SearchTrailStatistics())->termsReport([['term' => 'a', 'count' => 3, 'avg_results' => 2], ['term' => 'b', 'count' => 1, 'avg_results' => 0]], new SearchPeriod($this->at('2026-02-01 00:00:00'), null));

		$this->assertSame(
			[
				'terms'              => [
					['term' => 'a', 'count' => 3, 'avg_results' => 2, 'percentage' => 75.0, 'effectiveness' => 'high'],
					['term' => 'b', 'count' => 1, 'avg_results' => 0, 'percentage' => 25.0, 'effectiveness' => 'low'],
				],
				'total_unique_terms' => 2,
				'total_searches'     => 4,
				'period'             => ['from' => '2026-02-01 00:00:00', 'to' => null],
			],
			$report
		);
	}//end testPopularTerms()

	/**
	 * Register/schema combinations are rated and sorted by share.
	 *
	 * @return void
	 */
	public function testRegisterSchemaUsage(): void {
		$report = (new SearchTrailStatistics())->combinationsReport(
			[
				['count' => 1, 'avg_results' => 0, 'avg_response_time' => 900],
				['count' => 3, 'avg_results' => 12, 'avg_response_time' => 50],
			],
			new SearchPeriod(null, $this->at('2026-03-01 12:00:00'))
		);

		$this->assertSame(
			[
				'statistics'         => [
					['count' => 3, 'avg_results' => 12, 'avg_response_time' => 50, 'percentage' => 75.0, 'performance_rating' => 'excellent'],
					['count' => 1, 'avg_results' => 0, 'avg_response_time' => 900, 'percentage' => 25.0, 'performance_rating' => 'poor'],
				],
				'total_combinations' => 2,
				'total_searches'     => 4,
				'period'             => ['from' => null, 'to' => '2026-03-01 12:00:00'],
			],
			$report
		);
	}//end testRegisterSchemaUsage()

	/**
	 * User agents are reported with their browser distribution.
	 *
	 * @return void
	 */
	public function testUserAgentUsage(): void {
		$agents = [
			['count' => 2, 'browser_info' => ['browser' => 'Firefox']],
			['count' => 6, 'browser_info' => ['browser' => 'Chrome']],
		];
		$report = (new SearchTrailStatistics())->userAgentReport($agents, new SearchPeriod());

		$this->assertSame(
			[
				'user_agents'          => $agents,
				'browser_distribution' => [
					['browser' => 'Chrome', 'count' => 6, 'percentage' => 75.0],
					['browser' => 'Firefox', 'count' => 2, 'percentage' => 25.0],
				],
				'total_user_agents'    => 2,
				'period'               => ['from' => null, 'to' => null],
			],
			$report
		);
	}//end testUserAgentUsage()
}//end class
