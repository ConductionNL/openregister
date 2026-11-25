<?php

/**
 * AggregateReportPeriods — the time side of a report.
 *
 * The bucket starts, labels and default range of a time-series report,
 * its zero-filled gaps, the created-date range a report filters on, and
 * the change between two periods or two buckets. Pure: no state, no reads.
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
 * Buckets, labels, ranges and changes of time in a report.
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
 */
final class AggregateReportPeriods {

	/**
	 * The most buckets a gap fill writes; a wider range is left unfilled.
	 */
	public const MAX_FILLED_BUCKETS = 1000;

	/**
	 * How many buckets a time series covers when the caller sends no range.
	 */
	public const DEFAULT_BUCKETS = 12;

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
	 * The `_created` operator map a report's `from`/`to` range filters on.
	 *
	 * A date-only `to` (`2026-03-31`) covers that whole day, so it becomes
	 * `lt` the next day; a moment is kept as an inclusive `lte`. A blank
	 * bound is left out; both blank gives an empty map.
	 *
	 * @param string $from The range start, inclusive.
	 * @param string $to   The range end, inclusive.
	 *
	 * @return array<string, string> The operator map.
	 *
	 * @throws InvalidArgumentException When a bound does not parse.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
	 */
	public static function createdRange(string $from, string $to): array {
		$range = [];
		if ($from !== '') {
			if (self::parse(value: $from) === null) {
				throw new InvalidArgumentException('`from` MUST be an ISO-8601 date or datetime');
			}

			$range['gte'] = $from;
		}

		if ($to !== '') {
			$end = self::parse(value: $to);
			if ($end === null) {
				throw new InvalidArgumentException('`to` MUST be an ISO-8601 date or datetime');
			}

			$range['lte'] = $to;
			if (self::isDateOnly(value: $to) === true) {
				unset($range['lte']);
				$range['lt'] = $end->modify('+1 day')->format('Y-m-d');
			}
		}

		return $range;
	}//end createdRange()

	/**
	 * A period's label: `Q1 2026` for a whole quarter, `2026-01` for a whole
	 * month, `2026` for a whole year, else the ISO 8601 interval `from/to`.
	 *
	 * @param string $from The period start, a date.
	 * @param string $to   The period end, a date, inclusive.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
	 */
	public static function periodLabel(string $from, string $to): string {
		$start = self::parse(value: $from);
		$end = self::parse(value: $to);
		if ($start === null || $end === null || self::isDateOnly(value: $from) === false || self::isDateOnly(value: $to) === false) {
			return $from . '/' . $to;
		}

		$next = $end->modify('+1 day');
		foreach (['year', 'quarter', 'month'] as $gap) {
			$isStart = (self::bucketStart(moment: $start, gap: $gap)->format('c') === $start->format('c'));
			if ($isStart === true && self::step(start: $start, gap: $gap, direction: 1)->format('c') === $next->format('c')) {
				if ($gap === 'quarter') {
					return 'Q' . (string)(intdiv((int)$start->format('n') - 1, 3) + 1) . ' ' . $start->format('Y');
				}

				return self::label(key: $start->format('Y-m-d\TH:i:s\Z'), gap: $gap);
			}
		}

		return $from . '/' . $to;
	}//end periodLabel()

	/**
	 * The change from an earlier figure to a later one: the difference, and
	 * the percentage of the earlier figure rounded to two decimals (null
	 * when the earlier figure is zero or either is missing).
	 *
	 * @param mixed $current  The later figure.
	 * @param mixed $previous The earlier figure.
	 *
	 * @return array{absolute: int|float|null, percentage: float|null}
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
	 */
	public static function change(mixed $current, mixed $previous): array {
		if (is_numeric($current) === false || is_numeric($previous) === false) {
			return ['absolute' => null, 'percentage' => null];
		}

		$absolute = (($current + 0) - ($previous + 0));
		$percentage = null;
		if ((float)$previous !== 0.0) {
			$percentage = round(($absolute / ($previous + 0)) * 100, 2);
		}

		return ['absolute' => $absolute, 'percentage' => $percentage];
	}//end change()

	/**
	 * A time series with each row's previous figure and its percentage
	 * change: `previousCount` and `change` for a count series. The first row
	 * has no previous bucket, so both are null.
	 *
	 * @param array<int, array<string, mixed>> $rows   The series rows, in time order.
	 * @param string                           $column The metric column.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
	 */
	public static function withTrend(array $rows, string $column): array {
		$previousKey = 'previous' . ucfirst($column);
		$previous = null;
		foreach ($rows as $index => $row) {
			$current = ($row[$column] ?? null);
			$rows[$index][$previousKey] = $previous;
			$rows[$index]['change'] = null;
			if ($index > 0) {
				$rows[$index]['change'] = self::change(current: $current, previous: $previous)['percentage'];
			}

			$previous = $current;
		}

		return $rows;
	}//end withTrend()

	/**
	 * Whether the text is a bare `Y-m-d` date.
	 *
	 * @param string $value The text.
	 *
	 * @return bool
	 */
	private static function isDateOnly(string $value): bool {
		return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
	}//end isDateOnly()

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
