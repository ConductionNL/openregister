<?php

/**
 * OpenRegister SearchTrailStatistics
 *
 * Derives the calculated metrics, percentages, ratings and distributions that the
 * search trail analytics add on top of the figures the search trail mapper returns.
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
// applied to the figures it got from the mapper.

namespace OCA\OpenRegister\Service;

use DateTime;

/**
 * Pure calculations over search trail statistics; no lookups of its own.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class SearchTrailStatistics {

	/**
	 * Performance tiers, best first: minimum average results and maximum average response time.
	 */
	private const PERFORMANCE_TIERS = [
		'excellent' => ['minResults' => 10, 'maxResponseTime' => 100],
		'good' => ['minResults' => 5, 'maxResponseTime' => 200],
		'average' => ['minResults' => 1, 'maxResponseTime' => 500],
	];

	/**
	 * Add searches with/without results and the success rate to the mapper's base statistics.
	 *
	 * @param array $stats Base statistics with total_searches and non_empty_searches
	 *
	 * @return array The statistics with the outcome rates added
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function withOutcomeRates(array $stats): array {
		$stats['searches_with_results'] = $stats['non_empty_searches'];
		$stats['searches_without_results'] = $stats['total_searches'] - $stats['non_empty_searches'];
		$stats['success_rate'] = $this->percentage(count: $stats['non_empty_searches'], total: $stats['total_searches']);

		return $stats;
	}//end withOutcomeRates()

	/**
	 * Add organisations, query complexity, the period and (for a period) the daily averages.
	 *
	 * @param array         $stats Statistics with total_searches and total_results
	 * @param DateTime|null $from  Start date of the period
	 * @param DateTime|null $to    End date of the period
	 *
	 * @return array The statistics with the derived metrics added
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function withDerivedMetrics(array $stats, ?DateTime $from, ?DateTime $to): array {
		// Unique organizations and query complexity are placeholders for now.
		$stats['unique_organizations'] = 0;
		$stats['query_complexity'] = $this->queryComplexity(totalSearches: $stats['total_searches']);

		$days = $this->days(from: $from, to: $to);
		$stats['period'] = $this->period(from: $from, to: $to) + ['days' => $days];
		if ($days !== null && $days > 0) {
			$stats['daily_averages'] = [
				'searches_per_day' => round($stats['total_searches'] / $days, 2),
				'results_per_day' => round($stats['total_results'] / $days, 2),
			];
		}

		return $stats;
	}//end withDerivedMetrics()

	/**
	 * Popular search terms with their share and effectiveness.
	 *
	 * @param array         $terms Terms with count and avg_results
	 * @param DateTime|null $from  Start date filter
	 * @param DateTime|null $to    End date filter
	 *
	 * @return array Popular search terms data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function popularTerms(array $terms, ?DateTime $from, ?DateTime $to): array {
		$totalSearches = array_sum(array_column($terms, 'count'));
		$enhancedTerms = array_map(fn ($term) => $this->rateTerm(term: $term, totalSearches: $totalSearches), $terms);

		return [
			'terms' => $enhancedTerms,
			'total_unique_terms' => count($enhancedTerms),
			'total_searches' => $totalSearches,
			'period' => $this->period(from: $from, to: $to),
		];
	}//end popularTerms()

	/**
	 * Register/schema combinations with their share and performance rating, most used first.
	 *
	 * @param array         $stats Combinations with count, avg_results and avg_response_time
	 * @param DateTime|null $from  Start date filter
	 * @param DateTime|null $to    End date filter
	 *
	 * @return array Register/schema statistics data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function registerSchemaUsage(array $stats, ?DateTime $from, ?DateTime $to): array {
		$totalSearches = array_sum(array_column($stats, 'count'));
		$enhancedStats = array_map(fn ($stat) => $this->rateCombination(stat: $stat, totalSearches: $totalSearches), $stats);
		usort($enhancedStats, fn ($a, $b) => $b['percentage'] <=> $a['percentage']);

		return [
			'statistics' => $enhancedStats,
			'total_combinations' => count($enhancedStats),
			'total_searches' => $totalSearches,
			'period' => $this->period(from: $from, to: $to),
		];
	}//end registerSchemaUsage()

	/**
	 * User agents with their browser distribution.
	 *
	 * @param array         $userAgentStats User agent statistics, each with browser_info and count
	 * @param DateTime|null $from           Start date filter
	 * @param DateTime|null $to             End date filter
	 *
	 * @return array User agent statistics data
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function userAgentUsage(array $userAgentStats, ?DateTime $from, ?DateTime $to): array {
		return [
			'user_agents' => $userAgentStats,
			'browser_distribution' => $this->browserDistribution(userAgentStats: $userAgentStats),
			'total_user_agents' => count($userAgentStats),
			'period' => $this->period(from: $from, to: $to),
		];
	}//end userAgentUsage()

	/**
	 * The from/to of a period as strings.
	 *
	 * @param DateTime|null $from Start date
	 * @param DateTime|null $to   End date
	 *
	 * @return array{from: string|null, to: string|null}
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function period(?DateTime $from, ?DateTime $to): array {
		return [
			'from' => $from?->format('Y-m-d H:i:s'),
			'to' => $to?->format('Y-m-d H:i:s'),
		];
	}//end period()

	/**
	 * A count as a percentage of a total, rounded to two decimals; 0 without a total.
	 *
	 * @param mixed $count Part
	 * @param mixed $total Whole
	 *
	 * @return int|float
	 */
	private function percentage(mixed $count, mixed $total): int|float {
		if ($total > 0) {
			return round(($count / $total) * 100, 2);
		}

		return 0;
	}//end percentage()

	/**
	 * Query complexity split (placeholder implementation).
	 *
	 * @param mixed $totalSearches Total number of searches
	 *
	 * @return array{simple: int|float, medium: int|float, complex: int|float}
	 */
	private function queryComplexity(mixed $totalSearches): array {
		if ($totalSearches > 0) {
			return [
				'simple' => round($totalSearches * 0.6),
				'medium' => round($totalSearches * 0.3),
				'complex' => round($totalSearches * 0.1),
			];
		}

		return ['simple' => 0, 'medium' => 0, 'complex' => 0];
	}//end queryComplexity()

	/**
	 * Number of calendar days a period spans, both ends included; null without both ends.
	 *
	 * @param DateTime|null $from Start date
	 * @param DateTime|null $to   End date
	 *
	 * @return int|null
	 */
	private function days(DateTime|null $from, DateTime|null $to): ?int {
		if ($from === null || $to === null) {
			return null;
		}

		return $from->diff($to)->days + 1;
	}//end days()

	/**
	 * Add share and effectiveness to one search term.
	 *
	 * @param array $term          Term with count and avg_results
	 * @param mixed $totalSearches Total searches over all terms
	 *
	 * @return array The term with percentage and effectiveness
	 */
	private function rateTerm(array $term, mixed $totalSearches): array {
		$term['percentage'] = $this->percentage(count: $term['count'], total: $totalSearches);
		$term['effectiveness'] = 'low';
		if ($term['avg_results'] > 0) {
			$term['effectiveness'] = 'high';
		}

		return $term;
	}//end rateTerm()

	/**
	 * Add share and performance rating to one register/schema combination.
	 *
	 * @param array $stat          Combination with count, avg_results and avg_response_time
	 * @param mixed $totalSearches Total searches over all combinations
	 *
	 * @return array The combination with percentage and performance_rating
	 */
	private function rateCombination(array $stat, mixed $totalSearches): array {
		$stat['percentage'] = $this->percentage(count: $stat['count'], total: $totalSearches);
		$stat['performance_rating'] = $this->performanceRating(stat: $stat);

		return $stat;
	}//end rateCombination()

	/**
	 * Calculate performance rating from average results and response time.
	 *
	 * @param array $stat Statistics with avg_results and avg_response_time
	 *
	 * @return string Performance rating ('excellent', 'good', 'average', 'poor')
	 */
	private function performanceRating(array $stat): string {
		$avgResults = $stat['avg_results'];
		$avgResponseTime = $stat['avg_response_time'];

		foreach (self::PERFORMANCE_TIERS as $rating => $tier) {
			if ($avgResults >= $tier['minResults'] && $avgResponseTime <= $tier['maxResponseTime']) {
				return $rating;
			}
		}

		return 'poor';
	}//end performanceRating()

	/**
	 * Aggregate user agent statistics by browser type, most used first.
	 *
	 * @param array $userAgentStats User agent statistics with browser_info and count
	 *
	 * @return array Browser distribution statistics
	 *
	 * @psalm-return list<array{browser: array-key, count: mixed, percentage: int|float}>
	 */
	private function browserDistribution(array $userAgentStats): array {
		$browserCounts = $this->countsByBrowser(userAgentStats: $userAgentStats);
		$total = array_sum($browserCounts);

		$distribution = [];
		foreach ($browserCounts as $browser => $count) {
			$distribution[] = [
				'browser' => $browser,
				'count' => $count,
				'percentage' => $this->percentage(count: $count, total: $total),
			];
		}

		return $distribution;
	}//end browserDistribution()

	/**
	 * Sum the counts per browser, highest first.
	 *
	 * @param array $userAgentStats User agent statistics with browser_info and count
	 *
	 * @return array Counts by browser name
	 */
	private function countsByBrowser(array $userAgentStats): array {
		$browserCounts = [];
		foreach ($userAgentStats as $stat) {
			$browser = $stat['browser_info']['browser'];
			if (isset($browserCounts[$browser]) === false) {
				$browserCounts[$browser] = 0;
			}

			$browserCounts[$browser] += $stat['count'];
		}

		arsort($browserCounts);
		return $browserCounts;
	}//end countsByBrowser()
}//end class
