<?php

/**
 * OpenRegister TimeseriesDateBucketValidator
 *
 * The `interval`, `from` and `to` rules of an ad-hoc timeseries request.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/aggregations-backend-native/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;

/**
 * Turns the `interval`, `from` and `to` of a timeseries request into a
 * time bucket, kept apart from TimeseriesRequestValidator so that class
 * holds the field, metric and grouping rules only.
 */
class TimeseriesDateBucketValidator {

	/**
	 * Allowed interval names on the REST endpoint. Mapped to the
	 * AggregationQuery::DATE_BUCKET_GAPS vocabulary in lower-case.
	 *
	 * @var array<string, string>
	 */
	private const INTERVAL_MAP = [
		'MINUTE' => 'minute',
		'HOUR' => 'hour',
		'DAY' => 'day',
		'WEEK' => 'week',
		'MONTH' => 'month',
		'QUARTER' => 'quarter',
		'YEAR' => 'year',
	];

	/**
	 * Sub-day interval gaps (require `format: date-time` on the field).
	 *
	 * @var string[]
	 */
	private const SUB_DAY_GAPS = ['minute', 'hour'];

	/**
	 * The time bucket an `interval` asks for, or null without an interval.
	 *
	 * @param array<string, mixed> $input  The raw request shape.
	 * @param Schema               $schema The schema being aggregated.
	 *
	 * @return array{field: string, start: string, end: string, gap: string}|null
	 *
	 * @throws InvalidArgumentException When the interval, its field or its range is invalid.
	 *
	 * @spec openspec/specs/aggregations-backend-native/spec.md
	 */
	public function validate(array $input, Schema $schema): ?array {
		$rawInterval = ($input['interval'] ?? null);
		if ($rawInterval === null || $rawInterval === '') {
			return null;
		}

		$gap = $this->intervalGap(rawInterval: $rawInterval);
		$this->assertSubDayIntervalField(input: $input, schema: $schema);
		$range = $this->validatedRange(input: $input);

		return [
			'field' => (string) ($input['field'] ?? ''),
			'start' => $range['from'],
			'end' => $range['to'],
			'gap' => $gap,
		];
	}//end validate()

	/**
	 * The bucket gap for an interval name, case-insensitive.
	 *
	 * @param mixed $rawInterval The requested interval.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the interval is not one of INTERVAL_MAP.
	 */
	private function intervalGap(mixed $rawInterval): string {
		$intervalKey = strtoupper((string) $rawInterval);
		if (isset(self::INTERVAL_MAP[$intervalKey]) === false) {
			throw new InvalidArgumentException(
				sprintf(
					'`interval` MUST be one of %s (got: %s)',
					implode(', ', array_keys(self::INTERVAL_MAP)),
					(string) $rawInterval
				)
			);
		}

		return self::INTERVAL_MAP[$intervalKey];
	}//end intervalGap()

	/**
	 * A sub-day interval needs a `date-time` field.
	 *
	 * @param array<string, mixed> $input  The raw request shape (with a known interval).
	 * @param Schema               $schema The schema being aggregated.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When a sub-day interval targets a field of another format.
	 */
	private function assertSubDayIntervalField(array $input, Schema $schema): void {
		$intervalKey = strtoupper((string) $input['interval']);
		if (in_array(self::INTERVAL_MAP[$intervalKey], self::SUB_DAY_GAPS, true) === false) {
			return;
		}

		$field = (string) ($input['field'] ?? '');
		$format = $this->fieldFormat(schema: $schema, field: $field);
		if ($format !== 'date-time') {
			throw new InvalidArgumentException(
				sprintf(
					'sub-day interval "%s" requires a `date-time` field; "%s" has format "%s"',
					$intervalKey,
					$field,
					$format ?? 'unspecified'
				)
			);
		}
	}//end assertSubDayIntervalField()

	/**
	 * The `from`/`to` range an interval request must carry.
	 *
	 * @param array<string, mixed> $input The raw request shape.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @throws InvalidArgumentException When either bound is missing or unparseable.
	 */
	private function validatedRange(array $input): array {
		$from = (string) ($input['from'] ?? '');
		$to = (string) ($input['to'] ?? '');
		if ($from === '' || $to === '') {
			throw new InvalidArgumentException(
				'`from` and `to` are required when `interval` is set'
			);
		}

		if (strtotime($from) === false || strtotime($to) === false) {
			throw new InvalidArgumentException(
				'`from` and `to` MUST be parseable ISO-8601 datetimes'
			);
		}

		return ['from' => $from, 'to' => $to];
	}//end validatedRange()

	/**
	 * Look up the JSON-Schema `format` for a declared property.
	 *
	 * @param Schema $schema The schema.
	 * @param string $field The property name.
	 *
	 * @return string|null The format string, or null when the property
	 *                     is a metadata column or has no format declared.
	 */
	private function fieldFormat(Schema $schema, string $field): ?string {
		// Magic metadata cols are date-time-shaped by convention.
		if (in_array($field, TimeseriesRequestValidator::METADATA_FIELDS, true) === true) {
			return 'date-time';
		}

		$properties = ($schema->getProperties() ?? []);
		$property = ($properties[$field] ?? null);
		if (is_array($property) === false) {
			return null;
		}

		$format = ($property['format'] ?? null);
		if (is_string($format) === true) {
			return $format;
		}

		return null;
	}//end fieldFormat()
}//end class
