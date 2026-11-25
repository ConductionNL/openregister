<?php

/**
 * AggregateChartData — the chart shape of an aggregation report.
 *
 * Turns the reporting API's `{results: [{<groupField>: ..., <metric>: ...}]}`
 * rows into the labels-plus-series arrays Chart.js and Apache ECharts read
 * directly: one series per metric for bar and line charts, and for a pie
 * chart the first metric's values as a flat series with each slice's share
 * in percent. It holds no state and reads nothing: the rows come from the
 * report, which the runner has already scoped to the caller.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use InvalidArgumentException;

/**
 * Reshapes report rows into chart labels and series.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
 */
final class AggregateChartData {

	/**
	 * The chart types the chart endpoint answers.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = ['bar', 'line', 'area', 'pie', 'doughnut'];

	/**
	 * The types drawn as slices of one whole, which carry percentages.
	 *
	 * @var array<int, string>
	 */
	public const SLICE_TYPES = ['pie', 'doughnut'];

	/**
	 * The chart type named by a request, `bar` when none is named.
	 *
	 * @param mixed $raw The `type` parameter.
	 *
	 * @return string The lower-cased type.
	 *
	 * @throws InvalidArgumentException When the type is not one of TYPES.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
	 */
	public static function type(mixed $raw): string {
		if ($raw === null || $raw === '') {
			return 'bar';
		}

		$type = '';
		if (is_string($raw) === true) {
			$type = strtolower(trim($raw));
		}

		if (in_array($type, self::TYPES, true) === false) {
			throw new InvalidArgumentException('type must be one of: '.implode(', ', self::TYPES));
		}

		return $type;
	}//end type()

	/**
	 * The chart data of a set of report rows.
	 *
	 * @param string                           $type          One of TYPES.
	 * @param array<int, array<string, mixed>> $rows          The report rows, in order.
	 * @param string                           $labelColumn   The row column the labels come from.
	 * @param array<int, string>               $metricColumns The row columns holding the metrics, in order.
	 *
	 * @return array<string, mixed> `{labels, series}`, plus `percentages` for a slice type.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
	 */
	public static function fromRows(string $type, array $rows, string $labelColumn, array $metricColumns): array {
		$labels = [];
		foreach ($rows as $row) {
			$labels[] = self::label(value: ($row[$labelColumn] ?? null));
		}

		if (in_array($type, self::SLICE_TYPES, true) === true) {
			$data = self::column(rows: $rows, column: (string)($metricColumns[0] ?? ''));

			return [
				'labels' => $labels,
				'series' => $data,
				'percentages' => self::percentages(values: $data),
			];
		}

		$series = [];
		foreach ($metricColumns as $column) {
			$series[] = ['name' => $column, 'data' => self::column(rows: $rows, column: $column)];
		}

		return ['labels' => $labels, 'series' => $series];
	}//end fromRows()

	/**
	 * One metric column of the rows, a missing or non-numeric value as 0.
	 *
	 * @param array<int, array<string, mixed>> $rows   The report rows.
	 * @param string                           $column The metric column.
	 *
	 * @return array<int, int|float> The values, in row order.
	 */
	private static function column(array $rows, string $column): array {
		$values = [];
		foreach ($rows as $row) {
			$value = ($row[$column] ?? null);
			if (is_int($value) === true || is_float($value) === true) {
				$values[] = $value;
				continue;
			}

			if (is_numeric($value) === true) {
				$values[] = ($value + 0);
				continue;
			}

			$values[] = 0;
		}

		return $values;
	}//end column()

	/**
	 * Each value's share of the sum, in percent with one decimal; all 0.0 when the sum is 0.
	 *
	 * @param array<int, int|float> $values The slice values.
	 *
	 * @return array<int, float> The percentages, in order.
	 */
	private static function percentages(array $values): array {
		$sum = array_sum($values);
		$shares = [];
		foreach ($values as $value) {
			if ((float)$sum === 0.0) {
				$shares[] = 0.0;
				continue;
			}

			$shares[] = round(($value / $sum) * 100, 1);
		}

		return $shares;
	}//end percentages()

	/**
	 * A label as a string: a null group as an empty label, a boolean as true/false.
	 *
	 * @param mixed $value The row's group value.
	 *
	 * @return string The label.
	 */
	private static function label(mixed $value): string {
		if ($value === null) {
			return '';
		}

		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		if (is_scalar($value) === true) {
			return (string)$value;
		}

		return (string)json_encode($value);
	}//end label()
}//end class
