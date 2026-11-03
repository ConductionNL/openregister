<?php

/**
 * TimeseriesRequestValidator
 *
 * Validates the query-parameter shape posted to the ad-hoc timeseries
 * aggregation endpoint (REST) and the equivalent GraphQL `groupBy`
 * argument. Returns a normalised array ready to feed into
 * AggregationQuery::create(), or throws InvalidArgumentException with a
 * client-safe message that maps to HTTP 400.
 *
 * Pulled into its own class so:
 *  - REST controller and GraphQL resolver share one allow-list /
 *    error vocabulary,
 *  - the validator is unit-testable in isolation (no controller boot,
 *    no GraphQL closure trampoline),
 *  - the validation rules are documented once in the spec and tested
 *    once at this layer.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Aggregation
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;

/**
 * Validates the input shape of an ad-hoc timeseries aggregation request.
 */
class TimeseriesRequestValidator {

	/**
	 * Magic-table metadata columns that are allowed as bucketing /
	 * groupBy fields even though they are not declared properties on
	 * the schema. Mirrors the metadata prefix in MagicMapper.
	 *
	 * @var string[]
	 */
	public const METADATA_FIELDS = ['_created', '_updated', '_deleted_at'];

	/**
	 * Validate and normalise a request shape into an AggregationQuery.
	 *
	 * Input shape (all values strings as received from HTTP query
	 * params; GraphQL resolver passes typed values but the shape is the
	 * same):
	 *  - field        (string, required)
	 *  - interval     (string, optional — see TimeseriesDateBucketValidator)
	 *  - from         (string, required when interval set)
	 *  - to           (string, required when interval set)
	 *  - metric       (string, optional, default 'count')
	 *  - metricField  (string, required when metric != count)
	 *  - filter       (array<string, mixed>, optional)
	 *  - cumulative   (bool, optional, default false — REQ-AGG-103; only
	 *                  valid when `interval` is set)
	 *
	 * @param array<string, mixed> $input The raw request shape.
	 * @param Schema $schema The schema being aggregated (for field allow-listing).
	 *
	 * @return AggregationQuery The validated query value object.
	 *
	 * @throws InvalidArgumentException When any input rule is violated.
	 *                                  The message is client-safe and
	 *                                  maps directly to a 400 body.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *   AggregationQuery::create() is the canonical, validated factory
	 *   for the value object.
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function validate(array $input, Schema $schema): AggregationQuery {
		$allowed = $this->allowedFields(schema: $schema);
		$this->assertFieldAllowed(input: $input, allowed: $allowed);
		$metricField = $this->validatedMetricField(input: $input, allowed: $allowed);
		$dateBucket = (new TimeseriesDateBucketValidator())->validate(input: $input, schema: $schema);

		// Categorical groupBy applies only without an interval: for
		// interval-bucket requests dateBucket carries the field, and
		// AggregationQuery::create() rejects the two together.
		$groupBy = null;
		if ($dateBucket === null) {
			$groupBy = $this->validatedGroupBy(input: $input, allowed: $allowed);
		}

		// MULTI-METRIC. Validated by the SAME class the annotation path uses,
		// so an ad-hoc request and a declared aggregation cannot drift in what
		// they accept — a validator and an executor each owning a copy of the
		// grammar is how this engine acquired specs it could not run.
		$metrics = $this->validatedMetrics(
			raw: ($input['metrics'] ?? null),
			allowed: $allowed
		);

		return AggregationQuery::create(
			metric: $this->requestedMetric(input: $input),
			field: $metricField,
			filter: (array) ($input['filter'] ?? []),
			groupBy: $groupBy,
			dateBucket: $dateBucket,
			metrics: $metrics,
			cumulative: $this->validatedCumulative(input: $input, dateBucket: $dateBucket)
		);
	}//end validate()

	/**
	 * The requested `field`, as a string.
	 *
	 * @param array<string, mixed> $input The raw request shape.
	 *
	 * @return string
	 */
	private function requestedField(array $input): string {
		return (string) ($input['field'] ?? '');
	}//end requestedField()

	/**
	 * The requested metric, lower-cased; `count` when absent.
	 *
	 * @param array<string, mixed> $input The raw request shape.
	 *
	 * @return string
	 */
	private function requestedMetric(array $input): string {
		return strtolower((string) ($input['metric'] ?? 'count'));
	}//end requestedMetric()

	/**
	 * The `field` must be present and a declared property (or metadata column).
	 *
	 * @param array<string, mixed> $input   The raw request shape.
	 * @param array<int, string>   $allowed Allow-listed field names.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the field is missing or not declared.
	 */
	private function assertFieldAllowed(array $input, array $allowed): void {
		$field = $this->requestedField(input: $input);
		if ($field === '') {
			throw new InvalidArgumentException('`field` is required');
		}

		if (in_array($field, $allowed, true) === false) {
			throw new InvalidArgumentException(
				sprintf('`field` "%s" is not a declared property of the schema', $field)
			);
		}
	}//end assertFieldAllowed()

	/**
	 * The field a non-count metric runs over; null for count, which is
	 * record-level rather than field-level.
	 *
	 * @param array<string, mixed> $input   The raw request shape.
	 * @param array<int, string>   $allowed Allow-listed field names.
	 *
	 * @return mixed The `metricField` value, or null for count.
	 *
	 * @throws InvalidArgumentException When a non-count metric has no declared metricField.
	 */
	private function validatedMetricField(array $input, array $allowed): mixed {
		$metric = $this->requestedMetric(input: $input);
		if ($metric === 'count') {
			return null;
		}

		$metricField = ($input['metricField'] ?? null);
		if ($metricField === null || $metricField === '') {
			throw new InvalidArgumentException(
				sprintf('`metricField` is required when metric is "%s"', $metric)
			);
		}

		if (in_array((string) $metricField, $allowed, true) === false) {
			throw new InvalidArgumentException(
				sprintf('`metricField` "%s" is not a declared property of the schema', $metricField)
			);
		}

		return $metricField;
	}//end validatedMetricField()

	/**
	 * The categorical grouping: the `field`, or a composite `fields` list.
	 *
	 * `field` stays required even with `fields`, which keeps the
	 * single-field spelling the canonical one; each bucket of a composite
	 * grouping carries `keys` instead of `key`.
	 *
	 * @param array<string, mixed> $input   The raw request shape.
	 * @param array<int, string>   $allowed Allow-listed field names.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When a `fields` member is not declared.
	 */
	private function validatedGroupBy(array $input, array $allowed): array {
		$extraFields = ($input['fields'] ?? null);
		if (is_array($extraFields) === false || $extraFields === []) {
			return ['field' => $this->requestedField(input: $input)];
		}

		return ['fields' => $this->validatedFields(fields: $extraFields, allowed: $allowed)];
	}//end validatedGroupBy()

	/**
	 * The `cumulative` flag; it needs an interval to accumulate over.
	 *
	 * @param array<string, mixed>                                              $input      The raw request shape.
	 * @param array{field: string, start: string, end: string, gap: string}|null $dateBucket The time bucket, if any.
	 *
	 * @return bool
	 *
	 * @throws InvalidArgumentException When cumulative is set without an interval.
	 */
	private function validatedCumulative(array $input, ?array $dateBucket): bool {
		$cumulative = $this->toBool(raw: $input['cumulative'] ?? false);
		if ($cumulative === true && $dateBucket === null) {
			throw new InvalidArgumentException(
				'`cumulative` requires `interval` (and `from`/`to`) to be set — it orders and accumulates over time buckets'
			);
		}

		return $cumulative;
	}//end validatedCumulative()

	/**
	 * Validate a composite `fields` list against the schema.
	 *
	 * Every member must be a declared property. A field the schema does not
	 * declare is not a runtime error in this stack — it groups everything into
	 * a single null bucket, or filters to nothing — so it is refused here,
	 * where the caller can still be told which one was wrong.
	 *
	 * @param array<mixed>       $fields  The requested group fields.
	 * @param array<int, string> $allowed Property names the schema declares.
	 *
	 * @return array<int, string> The validated field list.
	 *
	 * @throws InvalidArgumentException When a member is not a declared property.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validatedFields(array $fields, array $allowed): array {
		$out = [];
		foreach ($fields as $candidate) {
			if (is_string($candidate) === false || $candidate === '') {
				throw new InvalidArgumentException('`fields` members MUST be non-empty strings');
			}

			if (in_array($candidate, $allowed, true) === false) {
				throw new InvalidArgumentException(
					sprintf('`fields` member "%s" is not a declared property of the schema', $candidate)
				);
			}

			if (in_array($candidate, $out, true) === false) {
				$out[] = $candidate;
			}
		}

		if ($out === []) {
			throw new InvalidArgumentException('`fields` MUST name at least one property');
		}

		return $out;
	}//end validatedFields()

	/**
	 * Validate an ad-hoc `metrics` list, or null when none was requested.
	 *
	 * Delegates to {@see AggregationMetricsAnnotationValidator} — the SAME class
	 * the annotation path uses — so an ad-hoc request and a declared aggregation
	 * cannot diverge in what they accept. Two copies of one grammar drifting
	 * apart is how this engine ended up with declarations it could not execute.
	 *
	 * @param mixed              $raw     The requested metrics list.
	 * @param array<int, string> $allowed Property names the schema declares.
	 *
	 * @return array<int, array<string, mixed>>|null The metrics list, or null.
	 *
	 * @throws InvalidArgumentException When an entry is unusable.
	 *
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	private function validatedMetrics(mixed $raw, array $allowed): ?array {
		if (is_array($raw) === false || $raw === []) {
			return null;
		}

		$normalised = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				throw new InvalidArgumentException('`metrics` entries MUST be objects');
			}

			// GraphQL's enum arrives upper-case (SUM); the engine's vocabulary
			// is lower-case. Normalise before validating so the error message
			// names the metric the caller wrote.
			$entry['metric'] = strtolower((string)($entry['metric'] ?? ''));
			$normalised[] = $entry;
		}

		$errors = (new AggregationMetricsAnnotationValidator())->validate(
			name: 'ad-hoc',
			metrics: $normalised,
			propKeys: $allowed
		);

		if ($errors !== []) {
			throw new InvalidArgumentException((string)$errors[0]['message']);
		}

		return $normalised;
	}//end validatedMetrics()

	/**
	 * Normalise a raw `cumulative` query-param value to a strict bool.
	 *
	 * HTTP query params arrive as strings (`'true'`, `'1'`, `'false'`,
	 * `'0'`, `''`); the GraphQL resolver may pass an actual bool. Only
	 * `true`/`'true'`/`'1'`/`1` are truthy — everything else (including
	 * absent/`null`) is false, so an unrecognised value fails closed
	 * rather than silently turning cumulative on.
	 *
	 * @param mixed $raw The raw `cumulative` input value.
	 *
	 * @return bool The normalised flag.
	 */
	private function toBool(mixed $raw): bool {
		if (is_bool($raw) === true) {
			return $raw;
		}

		if (is_string($raw) === true) {
			return in_array(strtolower($raw), ['true', '1'], true);
		}

		if (is_int($raw) === true) {
			return ($raw === 1);
		}

		return false;
	}//end toBool()

	/**
	 * Compute the allow-list of fields the request may reference: every
	 * declared schema property + the magic-table metadata columns.
	 *
	 * @param Schema $schema The schema being aggregated.
	 *
	 * @return string[] Allow-listed field names.
	 */
	private function allowedFields(Schema $schema): array {
		$properties = ($schema->getProperties() ?? []);
		$declared = array_keys($properties);
		return array_merge($declared, self::METADATA_FIELDS);
	}//end allowedFields()

}//end class
