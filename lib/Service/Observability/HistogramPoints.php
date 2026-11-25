<?php

/**
 * The exposition points of one histogram series set.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Observability
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Observability;

/**
 * Builds `_bucket` / `_sum` / `_count` points from millisecond aggregates.
 *
 * @spec openspec/changes/production-observability
 */
final class HistogramPoints {

	/**
	 * The points of one label set: a bucket per bound in seconds, `+Inf`, the sum in seconds and the count.
	 *
	 * @param array<string, string> $labels  The labels every point carries.
	 * @param array<int, int>       $buckets Cumulative counts keyed by upper bound in milliseconds.
	 * @param int                   $count   The number of observations (the `+Inf` bucket).
	 * @param float                 $sumMs   The summed observations, in milliseconds.
	 *
	 * @return array<int, array{labels: array<string, string>, value: float|int, suffix: string}>
	 *
	 * @spec openspec/changes/production-observability
	 */
	public static function points(array $labels, array $buckets, int $count, float $sumMs): array {
		$points = [];
		foreach ($buckets as $boundMs => $bucketCount) {
			$points[] = ['labels' => [...$labels, 'le' => self::seconds(milliseconds: (int) $boundMs)], 'value' => $bucketCount, 'suffix' => '_bucket'];
		}

		$points[] = ['labels' => [...$labels, 'le' => '+Inf'], 'value' => $count, 'suffix' => '_bucket'];
		$points[] = ['labels' => $labels, 'value' => $sumMs / 1000, 'suffix' => '_sum'];
		$points[] = ['labels' => $labels, 'value' => $count, 'suffix' => '_count'];

		return $points;
	}//end points()

	/**
	 * A millisecond bound as a seconds `le` label: 50 → "0.05", 1000 → "1.0", 30000 → "30.0".
	 *
	 * @param int $milliseconds The bound.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/production-observability
	 */
	public static function seconds(int $milliseconds): string {
		$label = rtrim(rtrim(number_format($milliseconds / 1000, 3, '.', ''), '0'), '.');
		if (str_contains($label, '.') === false) {
			$label .= '.0';
		}

		return $label;
	}//end seconds()
}//end class
