<?php

/**
 * AggregationQueryRules — the input rules of an aggregation request.
 *
 * The fail-fast checks AggregationQuery::create() runs before it builds a query.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use InvalidArgumentException;

/**
 * The checks an aggregation request must pass, each throwing on bad input.
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */
final class AggregationQueryRules {

	/**
	 * The metric that needs no field.
	 *
	 * @var string
	 */
	private const METRIC_COUNT = 'count';

	/**
	 * The known metrics, in the order the refusal message lists them
	 * (AggregationQuery::METRIC_*).
	 *
	 * @var string[]
	 */
	private const METRICS = ['count', 'sum', 'avg', 'min', 'max'];

	/**
	 * Allowed gap units for dateBucket. Aligns with Solr's `facet.range.gap`
	 * and ES's `date_histogram.calendar_interval` vocabularies.
	 *
	 * @var string[]
	 */
	private const DATE_BUCKET_GAPS = ['minute', 'hour', 'day', 'week', 'month', 'quarter', 'year'];

	/**
	 * The metric must be known, and every metric but count needs a field.
	 *
	 * @param string      $metric The metric.
	 * @param string|null $field  The field it runs over.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the metric is unknown or lacks a field.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function assertValidMetric(string $metric, ?string $field): void {
		if (in_array($metric, self::METRICS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('aggregation metric MUST be one of: %s (got: %s)', implode(', ', self::METRICS), $metric)
			);
		}

		if (self::lacksRequiredField(metric: $metric, field: $field) === true) {
			throw new InvalidArgumentException(sprintf('aggregation metric "%s" MUST specify a field', $metric));
		}
	}//end assertValidMetric()

	/**
	 * Whether a metric other than count is missing its field.
	 *
	 * @param string $metric The metric.
	 * @param mixed  $field  The field it runs over.
	 *
	 * @return bool
	 */
	private static function lacksRequiredField(string $metric, mixed $field): bool {
		return $metric !== self::METRIC_COUNT && ($field === null || $field === '');
	}//end lacksRequiredField()

	/**
	 * A `metrics` list, when supplied, is non-empty, holds valid entries and
	 * is not combined with a time bucket.
	 *
	 * @param array<int, mixed>|null $metrics      The metrics list, or null.
	 * @param bool                   $timeBucketed Whether a dateBucket is set.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the list is empty, an entry is invalid or a dateBucket is set.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function assertValidMetricsList(?array $metrics, bool $timeBucketed): void {
		if ($metrics === null) {
			return;
		}

		if (count($metrics) === 0) {
			throw new InvalidArgumentException('metrics list, when supplied, MUST NOT be empty');
		}

		array_walk($metrics, static fn (mixed $metricEntry) => self::assertValidMetricsEntry(metricEntry: $metricEntry));

		if ($timeBucketed === true) {
			throw new InvalidArgumentException('a multi-metric `metrics` list MUST NOT be combined with dateBucket (time-bucket) aggregation');
		}
	}//end assertValidMetricsList()

	/**
	 * One `metrics` entry: an array with a known `metric`, and a field for
	 * every metric but count.
	 *
	 * @param mixed $metricEntry The entry.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the entry is malformed.
	 */
	private static function assertValidMetricsEntry(mixed $metricEntry): void {
		if (is_array($metricEntry) === false || array_key_exists('metric', $metricEntry) === false) {
			throw new InvalidArgumentException('each metrics entry MUST be an array with a `metric` key');
		}

		$entryMetric = (string) $metricEntry['metric'];
		if (in_array($entryMetric, self::METRICS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('metrics entry has an invalid metric "%s" (MUST be one of: %s)', $entryMetric, implode(', ', self::METRICS))
			);
		}

		if (self::lacksRequiredField(metric: $entryMetric, field: ($metricEntry['field'] ?? null)) === true) {
			throw new InvalidArgumentException(sprintf('metrics entry "%s" MUST specify a field', $entryMetric));
		}
	}//end assertValidMetricsEntry()

	/**
	 * A groupBy, when supplied, names one or more distinct, non-empty fields.
	 *
	 * @param array<mixed>|null $groupBy The groupBy spec, or null.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the groupBy is malformed.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function assertValidGroupBy(?array $groupBy): void {
		if ($groupBy === null) {
			return;
		}

		$groupFields = AggregationQueryShape::groupByFields(groupBy: $groupBy);
		array_walk($groupFields, static fn (mixed $groupField) => self::assertValidGroupField(groupField: $groupField));

		if (count($groupFields) === 0) {
			throw new InvalidArgumentException('groupBy MUST include a non-empty `field` or a non-empty `fields` list');
		}

		if (count($groupFields) !== count(array_unique($groupFields))) {
			throw new InvalidArgumentException('groupBy fields MUST be distinct (duplicate field in groupBy list)');
		}
	}//end assertValidGroupBy()

	/**
	 * One group field is a non-empty string.
	 *
	 * @param mixed $groupField The group field.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not.
	 */
	private static function assertValidGroupField(mixed $groupField): void {
		if (is_string($groupField) === false || $groupField === '') {
			throw new InvalidArgumentException(
				'groupBy MUST include a non-empty `field`; every group field MUST be a non-empty string'
			);
		}
	}//end assertValidGroupField()

	/**
	 * A dateBucket, when supplied, is well formed and is the only bucketing.
	 *
	 * @param array<string, mixed>|null $dateBucket The dateBucket spec, or null.
	 * @param bool                      $grouped    Whether a groupBy is set.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the dateBucket is malformed or combined with a groupBy.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function assertValidTimeBucketing(?array $dateBucket, bool $grouped): void {
		if ($dateBucket === null) {
			return;
		}

		self::assertValidDateBucket(spec: $dateBucket);
		if ($grouped === true) {
			throw new InvalidArgumentException(
				'groupBy and dateBucket MUST NOT be combined — pick one bucketing strategy'
			);
		}
	}//end assertValidTimeBucketing()

	/**
	 * A running total needs time buckets to accumulate over.
	 *
	 * @param bool $cumulative   The cumulative flag.
	 * @param bool $timeBucketed Whether a dateBucket is set.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When cumulative is set without a dateBucket.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public static function assertCumulativeIsTimeBucketed(bool $cumulative, bool $timeBucketed): void {
		if ($cumulative === true && $timeBucketed === false) {
			throw new InvalidArgumentException(
				'cumulative MUST only be combined with a dateBucket (time-bucket) aggregation'
			);
		}
	}//end assertCumulativeIsTimeBucketed()

	/**
	 * Validate a dateBucket spec.
	 *
	 * @param array<string, mixed> $spec The dateBucket spec.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When malformed.
	 */
	private static function assertValidDateBucket(array $spec): void {
		foreach (['field', 'start', 'end', 'gap'] as $required) {
			if (isset($spec[$required]) === false || $spec[$required] === '') {
				throw new InvalidArgumentException('dateBucket MUST include non-empty `field`, `start`, `end`, `gap`');
			}
		}

		if (in_array($spec['gap'], self::DATE_BUCKET_GAPS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('dateBucket gap MUST be one of: %s (got: %s)', implode(', ', self::DATE_BUCKET_GAPS), (string) $spec['gap'])
			);
		}

	}//end assertValidDateBucket()
}//end class
