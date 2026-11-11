<?php

/**
 * OpenRegister SearchTrailActivityInsights
 *
 * Derives peak, low, average and trend from search activity per time period.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */

// No strict_types on purpose: the arithmetic keeps the coercion SearchTrailService
// applied to the counts it got from the mapper.

namespace OCA\OpenRegister\Service;

/**
 * Activity insights for search trail activity data.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchTrailActivityInsights {

	/**
	 * Insights when there is no activity at all.
	 */
	private const NO_DATA = [
		'peak_period' => null,
		'low_period' => null,
		'trend' => 'no_data',
		'average_searches_per_period' => 0,
	];

	/**
	 * A slope beyond plus or minus this counts as a trend.
	 */
	private const TREND_THRESHOLD = 0.1;

	/**
	 * Calculate activity insights from search activity data
	 *
	 * @param array $activity Search activity data, each period with a `count`
	 *
	 * @return array Activity insights
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function insights(array $activity): array {
		if ($activity === []) {
			return self::NO_DATA;
		}

		$counts = array_column($activity, 'count');
		return [
			'peak_period' => $activity[array_search(max($counts), $counts)]['period'] ?? null,
			'peak_count' => max($counts),
			'low_period' => $activity[array_search(min($counts), $counts)]['period'] ?? null,
			'low_count' => min($counts),
			'trend' => $this->trend(counts: $counts),
			'average_searches_per_period' => round(array_sum($counts) / count($counts), 2),
			'total_periods' => count($activity),
		];
	}//end insights()

	/**
	 * Calculate trend direction from count data (simple linear regression).
	 *
	 * @param array $counts Count values in period order
	 *
	 * @return string Trend direction
	 *
	 * @psalm-return 'decreasing'|'increasing'|'stable'
	 */
	private function trend(array $counts): string {
		if (count($counts) < 2) {
			return 'stable';
		}

		$slope = $this->slope(counts: $counts);
		if ($slope > self::TREND_THRESHOLD) {
			return 'increasing';
		}

		if ($slope < -self::TREND_THRESHOLD) {
			return 'decreasing';
		}

		return 'stable';
	}//end trend()

	/**
	 * Least-squares slope of the counts against their position.
	 *
	 * @param array $counts At least two count values in period order
	 *
	 * @return int|float
	 */
	private function slope(array $counts): int|float {
		$n = count($counts);
		$sumX = array_sum(range(0, $n - 1));
		$sumY = array_sum($counts);
		$sumXY = 0;
		$sumX2 = 0;

		for ($i = 0; $i < $n; $i++) {
			$sumXY += $i * $counts[$i];
			$sumX2 += $i * $i;
		}

		return ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
	}//end slope()
}//end class
