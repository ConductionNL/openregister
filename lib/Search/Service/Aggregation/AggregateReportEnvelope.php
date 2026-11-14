<?php

/**
 * AggregateReportEnvelope — the reporting shape of an aggregation result.
 *
 * Turns the runner's `{groups: [{key|keys, value|values}]}` envelope into
 * the reporting API's `{results: [{<groupField>: ..., <metric>: ...}], total}`
 * rows, fills empty time buckets with zero, and applies the `having`
 * post-aggregation filter. It holds no state and reads nothing: the runner
 * has already applied the caller's access rules to the figures it gets.
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
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Aggregation;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Reshapes, gap-fills and post-filters an aggregation result for reporting.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
 */
final class AggregateReportEnvelope {

	/**
	 * The `having` operators and what each compares.
	 *
	 * @var array<int, string>
	 */
	public const HAVING_OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte'];

	/**
	 * The most buckets a gap fill writes; a wider range is left unfilled.
	 */
	public const MAX_FILLED_BUCKETS = 1000;

	/**
	 * How many buckets a time series covers when the caller sends no range.
	 */
	public const DEFAULT_BUCKETS = 12;

	/**
	 * The report's metadata group fields and the column each reads.
	 *
	 * @var array<string, string>
	 */
	public const SELF_FIELDS = [
		'@self.created' => '_created',
		'@self.updated' => '_updated',
	];

	/**
	 * The column a report's group field reads: `@self.created` is `_created`.
	 *
	 * @param string $field The group field as the caller wrote it.
	 *
	 * @return string The column the runner groups on.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function column(string $field): string {
		return (self::SELF_FIELDS[$field] ?? $field);
	}//end column()

	/**
	 * The range a time series covers when the caller sent none: the bucket
	 * holding `$now` and the eleven before it, `to` being the start of the
	 * next bucket (exclusive, as the runner reads it).
	 *
	 * @param string            $gap The bucket width (minute ... year).
	 * @param DateTimeImmutable $now The moment the report is asked for.
	 *
	 * @return array{from: string, to: string} ISO-8601 UTC bounds.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function defaultRange(string $gap, DateTimeImmutable $now): array {
		$current = self::bucketStart(moment: $now, gap: $gap);
		$from = $current;
		for ($i = 1; $i < self::DEFAULT_BUCKETS; $i++) {
			$from = self::step(start: $from, gap: $gap, direction: -1);
		}

		return [
			'from' => $from->format('Y-m-d\TH:i:s\Z'),
			'to' => self::step(start: $current, gap: $gap, direction: 1)->format('Y-m-d\TH:i:s\Z'),
		];
	}//end defaultRange()

	/**
	 * The time series with every bucket of the range present: a bucket the
	 * runner left out (no rows) gets 0 for count and sum, null otherwise.
	 *
	 * A range of more than MAX_FILLED_BUCKETS buckets is returned as given.
	 *
	 * @param array<int, array<string, mixed>> $groups The runner's `{key, value}` buckets.
	 * @param string                           $gap    The bucket width.
	 * @param array{from: string, to: string}  $range  The requested range, `to` exclusive.
	 * @param string                           $metric The metric the buckets carry.
	 *
	 * @return array<int, array<string, mixed>> The buckets in time order.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function fillGaps(array $groups, string $gap, array $range, string $metric): array {
		$starts = self::bucketStarts(gap: $gap, range: $range);
		if ($starts === null) {
			return $groups;
		}

		$byKey = [];
		foreach ($groups as $group) {
			$byKey[(string)($group['key'] ?? '')] = $group;
		}

		$empty = null;
		if ($metric === AggregationQuery::METRIC_COUNT || $metric === 'sum') {
			$empty = 0;
		}

		$filled = [];
		foreach ($starts as $start) {
			$filled[] = ($byKey[$start] ?? ['key' => $start, 'value' => $empty]);
			unset($byKey[$start]);
		}

		// A bucket the runner returned outside the computed starts (a
		// backend that labels differently) is kept, never dropped.
		foreach ($byKey as $group) {
			$filled[] = $group;
		}

		return $filled;
	}//end fillGaps()

	/**
	 * The label a bucket start carries in a report: `2026-01` for a month,
	 * `2026-01-05` for a day, `2026-W02` for an ISO week, `2026-Q1` for a
	 * quarter, `2026` for a year, `2026-01-05T09:00` for an hour or minute.
	 *
	 * @param string $key The bucket start, `Y-m-d\TH:i:s\Z`.
	 * @param string $gap The bucket width.
	 *
	 * @return string The ISO 8601 label, or the key unchanged when it does not parse.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function label(string $key, string $gap): string {
		$moment = self::parse(value: $key);
		if ($moment === null) {
			return $key;
		}

		return match ($gap) {
			'minute' => $moment->format('Y-m-d\TH:i'),
			'hour' => $moment->format('Y-m-d\TH:00'),
			'week' => $moment->format('o-\WW'),
			'month' => $moment->format('Y-m'),
			'quarter' => $moment->format('Y') . '-Q' . (string)(intdiv((int)$moment->format('n') - 1, 3) + 1),
			'year' => $moment->format('Y'),
			default => $moment->format('Y-m-d'),
		};
	}//end label()

	/**
	 * The runner's groups as report rows, one column per group field and
	 * one per metric.
	 *
	 * A metric column is named by its metric (`sum`) when only one entry
	 * uses that metric, else by the runner's own key (`sum_bedrag`).
	 *
	 * @param array<int, array<string, mixed>>                 $groups      The runner's groups.
	 * @param array<int, string>                               $groupFields The group fields, as the caller wrote them.
	 * @param array<int, array{metric: string, field: mixed}> $metrics     The metric entries, primary first.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function rows(array $groups, array $groupFields, array $metrics): array {
		$names = self::metricNames(metrics: $metrics);
		$rows = [];
		foreach ($groups as $group) {
			$row = self::keyColumns(group: $group, groupFields: $groupFields);
			if (isset($group['values']) === true && is_array($group['values']) === true) {
				foreach ($group['values'] as $runnerKey => $value) {
					$row[($names[$runnerKey] ?? (string)$runnerKey)] = $value;
				}
			} else {
				$row[reset($names)] = ($group['value'] ?? null);
			}

			$rows[] = $row;
		}

		return $rows;
	}//end rows()

	/**
	 * The report's total: the sum of the count column when count is one of
	 * the metrics, else the number of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows, before `having`.
	 *
	 * @return int|float The total.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function total(array $rows): int|float {
		$total = 0;
		$hasCount = false;
		foreach ($rows as $row) {
			if (array_key_exists(AggregationQuery::METRIC_COUNT, $row) === true) {
				$hasCount = true;
				if (is_numeric($row[AggregationQuery::METRIC_COUNT]) === true) {
					$total += ($row[AggregationQuery::METRIC_COUNT] + 0);
				}
			}
		}

		if ($hasCount === false) {
			return count($rows);
		}

		return $total;
	}//end total()

	/**
	 * Parse the `having` param: `having[count][gt]=10`, or `having[count]=10`
	 * for equality.
	 *
	 * @param mixed              $raw     The raw `having` param.
	 * @param array<int, string> $columns The metric columns the rows carry.
	 *
	 * @return array<string, array<string, float>> Column => operator => operand.
	 *
	 * @throws InvalidArgumentException When it names an unknown column or operator, or an operand is not a number.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function parseHaving(mixed $raw, array $columns): array {
		if ($raw === null || $raw === '' || $raw === []) {
			return [];
		}

		if (is_array($raw) === false) {
			throw new InvalidArgumentException('`having` MUST name a metric, e.g. having[count][gt]=10');
		}

		$having = [];
		foreach ($raw as $column => $condition) {
			if (in_array((string)$column, $columns, true) === false) {
				throw new InvalidArgumentException(
					sprintf('`having` names "%s", which is not one of the metrics (%s)', (string)$column, implode(', ', $columns))
				);
			}

			if (is_array($condition) === false) {
				$condition = ['eq' => $condition];
			}

			foreach ($condition as $operator => $operand) {
				if (in_array((string)$operator, self::HAVING_OPERATORS, true) === false) {
					throw new InvalidArgumentException(
						sprintf('`having` operator "%s" MUST be one of %s', (string)$operator, implode(', ', self::HAVING_OPERATORS))
					);
				}

				if (is_numeric($operand) === false) {
					throw new InvalidArgumentException(sprintf('`having[%s][%s]` MUST be a number', (string)$column, (string)$operator));
				}

				$having[(string)$column][(string)$operator] = (float)$operand;
			}
		}//end foreach

		return $having;
	}//end parseHaving()

	/**
	 * The rows every `having` condition holds for; a null figure holds for none.
	 *
	 * @param array<int, array<string, mixed>>    $rows   The rows.
	 * @param array<string, array<string, float>> $having The parsed conditions.
	 *
	 * @return array<int, array<string, mixed>> The kept rows, in order.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function applyHaving(array $rows, array $having): array {
		if ($having === []) {
			return $rows;
		}

		$kept = [];
		foreach ($rows as $row) {
			if (self::holds(row: $row, having: $having) === true) {
				$kept[] = $row;
			}
		}

		return $kept;
	}//end applyHaving()

	/**
	 * The metric column names, keyed by the runner's key for each entry.
	 *
	 * @param array<int, array{metric: string, field: mixed}> $metrics The metric entries.
	 *
	 * @return array<string, string> Runner key => column name.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public static function metricNames(array $metrics): array {
		$uses = array_count_values(array_map(static fn (array $entry): string => $entry['metric'], $metrics));
		$names = [];
		foreach ($metrics as $entry) {
			$field = null;
			if (is_string($entry['field'] ?? null) === true) {
				$field = $entry['field'];
			}

			$runnerKey = AggregationQuery::metricResponseKey(metric: $entry['metric'], field: $field);
			$names[$runnerKey] = $runnerKey;
			if ($uses[$entry['metric']] === 1) {
				$names[$runnerKey] = $entry['metric'];
			}
		}

		return $names;
	}//end metricNames()

	/**
	 * Whether every condition holds for one row.
	 *
	 * @param array<string, mixed>                $row    The row.
	 * @param array<string, array<string, float>> $having The conditions.
	 *
	 * @return bool
	 */
	private static function holds(array $row, array $having): bool {
		foreach ($having as $column => $conditions) {
			$figure = ($row[$column] ?? null);
			if (is_numeric($figure) === false) {
				return false;
			}

			$figure = (float)$figure;
			foreach ($conditions as $operator => $operand) {
				$holds = match ($operator) {
					'eq' => $figure === $operand,
					'ne' => $figure !== $operand,
					'gt' => $figure > $operand,
					'gte' => $figure >= $operand,
					'lt' => $figure < $operand,
					default => $figure <= $operand,
				};
				if ($holds === false) {
					return false;
				}
			}
		}

		return true;
	}//end holds()

	/**
	 * A group's key as report columns, named by the group fields.
	 *
	 * @param array<string, mixed> $group       The runner's group.
	 * @param array<int, string>   $groupFields The group fields as the caller wrote them.
	 *
	 * @return array<string, mixed>
	 */
	private static function keyColumns(array $group, array $groupFields): array {
		if (isset($group['keys']) === true && is_array($group['keys']) === true) {
			$columns = [];
			foreach ($groupFields as $field) {
				$columns[$field] = ($group['keys'][self::column(field: $field)] ?? ($group['keys'][$field] ?? null));
			}

			return $columns;
		}

		return [($groupFields[0] ?? 'key') => ($group['key'] ?? null)];
	}//end keyColumns()

	/**
	 * Every bucket start in the range, or null when the range does not
	 * parse or holds more than MAX_FILLED_BUCKETS buckets.
	 *
	 * @param string                          $gap   The bucket width.
	 * @param array{from: string, to: string} $range The range, `to` exclusive.
	 *
	 * @return array<int, string>|null
	 */
	private static function bucketStarts(string $gap, array $range): ?array {
		$from = self::parse(value: $range['from']);
		$to = self::parse(value: $range['to']);
		if ($from === null || $to === null) {
			return null;
		}

		$starts = [];
		$start = self::bucketStart(moment: $from, gap: $gap);
		while ($start < $to) {
			if (count($starts) >= self::MAX_FILLED_BUCKETS) {
				return null;
			}

			$starts[] = $start->format('Y-m-d\TH:i:s\Z');
			$start = self::step(start: $start, gap: $gap, direction: 1);
		}

		return $starts;
	}//end bucketStarts()

	/**
	 * The start of the bucket holding a moment, in UTC, as the runner keys it.
	 *
	 * @param DateTimeImmutable $moment The moment.
	 * @param string            $gap    The bucket width.
	 *
	 * @return DateTimeImmutable
	 */
	private static function bucketStart(DateTimeImmutable $moment, string $gap): DateTimeImmutable {
		$utc = $moment->setTimezone(new DateTimeZone('UTC'));
		$year = (int)$utc->format('Y');
		$month = (int)$utc->format('n');
		$day = (int)$utc->format('j');

		return match ($gap) {
			'minute' => $utc->setTime((int)$utc->format('G'), (int)$utc->format('i')),
			'hour' => $utc->setTime((int)$utc->format('G'), 0),
			'week' => $utc->setTime(0, 0)->modify('-' . ((int)$utc->format('N') - 1) . ' days'),
			'month' => $utc->setDate($year, $month, 1)->setTime(0, 0),
			'quarter' => $utc->setDate($year, (intdiv($month - 1, 3) * 3) + 1, 1)->setTime(0, 0),
			'year' => $utc->setDate($year, 1, 1)->setTime(0, 0),
			default => $utc->setDate($year, $month, $day)->setTime(0, 0),
		};
	}//end bucketStart()

	/**
	 * The bucket start one bucket later (direction 1) or earlier (-1).
	 *
	 * @param DateTimeImmutable $start     A bucket start.
	 * @param string            $gap       The bucket width.
	 * @param int               $direction 1 or -1.
	 *
	 * @return DateTimeImmutable
	 */
	private static function step(DateTimeImmutable $start, string $gap, int $direction): DateTimeImmutable {
		$amount = match ($gap) {
			'minute' => '1 minute',
			'hour' => '1 hour',
			'week' => '7 days',
			'month' => '1 month',
			'quarter' => '3 months',
			'year' => '1 year',
			default => '1 day',
		};

		$sign = '+';
		if ($direction < 0) {
			$sign = '-';
		}

		return $start->modify($sign . $amount);
	}//end step()

	/**
	 * Parse an ISO-8601 moment, reading one without an offset as UTC.
	 *
	 * @param string $value The text.
	 *
	 * @return DateTimeImmutable|null
	 */
	private static function parse(string $value): ?DateTimeImmutable {
		if ($value === '' || strtotime($value) === false) {
			return null;
		}

		return new DateTimeImmutable($value, new DateTimeZone('UTC'));
	}//end parse()
}//end class
