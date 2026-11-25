<?php

/**
 * The `dateField`, `from` and `to` of a categorical count: a half-open period on a named date field.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Aggregation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/aggregations-a-shipped-report-may-rely-on/specs/aggregation-api/spec.md#requirement-a-shipped-report-shall-be-able-to-rely-on-a-stable-count-by-aggregation
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;

/**
 * Turns `dateField` + `from`/`to` into `{dateField: {gte: from, lt: to}}` on the filter.
 *
 * Without an `interval`, a timeseries request is a categorical count and
 * `from`/`to` used to be read by nothing: a report asking for "cases per
 * district in March" got every case and could not tell. Naming the date
 * field the period applies to makes the bound explicit. The period is
 * half-open, `[from, to)`, so two adjacent periods never count an object
 * twice, and it reuses the `gte`/`lt` operators both the native and the
 * PHP path already implement, so the two paths cannot disagree about it.
 * An object with no value in the date field lies in no period.
 *
 * Without `dateField` nothing changes, so no existing request is refused.
 *
 * @spec openspec/changes/aggregations-a-shipped-report-may-rely-on/specs/aggregation-api/spec.md#requirement-a-shipped-report-shall-be-able-to-rely-on-a-stable-count-by-aggregation
 */
class TimeseriesDateRangeFilter {

	/**
	 * The formats a period may be counted on.
	 *
	 * @var string[]
	 */
	private const DATE_FORMATS = ['date', 'date-time'];

	/**
	 * The filter with the period added, or unchanged when no `dateField` is named.
	 *
	 * @param array<string, mixed> $input  The raw request shape (`dateField`, `from`, `to`, `interval`).
	 * @param Schema               $schema The schema being aggregated.
	 * @param array<string, mixed> $filter The request's filter, in the `findAll()` vocabulary.
	 *
	 * @return array<string, mixed> The filter.
	 *
	 * @throws InvalidArgumentException When the field, the period or its combination with the filter is refused.
	 *
	 * @spec openspec/changes/aggregations-a-shipped-report-may-rely-on/specs/aggregation-api/spec.md#requirement-a-shipped-report-shall-be-able-to-rely-on-a-stable-count-by-aggregation
	 */
	public function apply(array $input, Schema $schema, array $filter): array {
		$dateField = trim((string) ($input['dateField'] ?? ''));
		if ($dateField === '') {
			return $filter;
		}

		$interval = ($input['interval'] ?? null);
		if ($interval !== null && $interval !== '') {
			throw new InvalidArgumentException(
				'`dateField` bounds a count that is not bucketed; with `interval` the period applies to `field` itself'
			);
		}

		$this->assertDateField(schema: $schema, field: $dateField);
		$period = $this->period(input: $input);

		return $this->withPeriod(filter: $filter, field: $dateField, period: $period);
	}//end apply()

	/**
	 * The field must be a declared date or date-time property, or a metadata date column.
	 *
	 * @param Schema $schema The schema.
	 * @param string $field  The named date field.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When it is not.
	 */
	private function assertDateField(Schema $schema, string $field): void {
		if (in_array($field, TimeseriesRequestValidator::METADATA_FIELDS, true) === true) {
			return;
		}

		$property = (($schema->getProperties() ?? [])[$field] ?? null);
		if (is_array($property) === false) {
			throw new InvalidArgumentException(sprintf('`dateField` "%s" is not a declared property of the schema', $field));
		}

		$format = ($property['format'] ?? null);
		if (in_array($format, self::DATE_FORMATS, true) === false) {
			throw new InvalidArgumentException(
				sprintf('`dateField` "%s" must have format date or date-time; it has "%s"', $field, (string) ($format ?? 'unspecified'))
			);
		}
	}//end assertDateField()

	/**
	 * The `from`/`to` pair: both present, both dates, `from` before `to`.
	 *
	 * @param array<string, mixed> $input The raw request shape.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @throws InvalidArgumentException When either bound is missing or unparseable, or the period is empty.
	 */
	private function period(array $input): array {
		$from = trim((string) ($input['from'] ?? ''));
		$to = trim((string) ($input['to'] ?? ''));
		if ($from === '' || $to === '') {
			throw new InvalidArgumentException('`from` and `to` are required when `dateField` is set');
		}

		$fromStamp = strtotime($from);
		$toStamp = strtotime($to);
		if ($fromStamp === false || $toStamp === false) {
			throw new InvalidArgumentException('`from` and `to` MUST be parseable ISO-8601 datetimes');
		}

		if ($fromStamp >= $toStamp) {
			throw new InvalidArgumentException('`from` must lie before `to`: the period is [from, to)');
		}

		return ['from' => $from, 'to' => $to];
	}//end period()

	/**
	 * Add `gte`/`lt` on the field, beside any other operator the filter already puts on it.
	 *
	 * A filter that already bounds the field from the same side, or matches it
	 * by value, is refused rather than silently overwritten: two answers to
	 * "which period" in one request is a caller error, not a choice to make here.
	 *
	 * @param array<string, mixed>             $filter The request's filter.
	 * @param string                           $field  The date field.
	 * @param array{from: string, to: string}  $period The period.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When the filter already constrains the field in a way the period would override.
	 */
	private function withPeriod(array $filter, string $field, array $period): array {
		$existing = ($filter[$field] ?? []);
		$isOperatorMap = (is_array($existing) === true && ($existing === [] || array_is_list($existing) === false));
		if ($isOperatorMap === false) {
			throw new InvalidArgumentException(sprintf('`dateField` "%s" is also matched by value in `filter`; use one of the two', $field));
		}

		foreach (['gt', 'gte', 'lt', 'lte'] as $operator) {
			if (array_key_exists($operator, $existing) === true) {
				throw new InvalidArgumentException(
					sprintf('`dateField` "%s" is already bounded by `filter[%s][%s]`; use one of the two', $field, $field, $operator)
				);
			}
		}

		$filter[$field] = $existing + ['gte' => $period['from'], 'lt' => $period['to']];

		return $filter;
	}//end withPeriod()
}//end class
