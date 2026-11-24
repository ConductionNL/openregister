<?php

/**
 * The period a report template is generated over.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\Reporting
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods;

/**
 * A period of whole days, both bounds inclusive.
 */
final class ReportPeriod {
	/**
	 * Constructor; use `fromDates()`.
	 *
	 * @param string $from First day, `Y-m-d`.
	 * @param string $to   Last day, `Y-m-d`.
	 */
	private function __construct(
		public readonly string $from,
		public readonly string $to,
	) {
	}//end __construct()

	/**
	 * A period from two dates, refused when one does not parse or the end precedes the start.
	 *
	 * @param string $from First day, `Y-m-d`.
	 * @param string $to   Last day, `Y-m-d`.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When a bound is not a date or the range is reversed.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public static function fromDates(string $from, string $to): self {
		$start = self::date(value: $from, name: 'from');
		$end = self::date(value: $to, name: 'to');
		if ($end < $start) {
			throw new InvalidArgumentException('`dateRange.to` MUST NOT precede `dateRange.from`');
		}

		return new self(from: $start->format('Y-m-d'), to: $end->format('Y-m-d'));
	}//end fromDates()

	/**
	 * The calendar year as a period.
	 *
	 * @param int $year The year.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public static function year(int $year): self {
		return self::fromDates(from: sprintf('%04d-01-01', $year), to: sprintf('%04d-12-31', $year));
	}//end year()

	/**
	 * The same days one year earlier.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function previousYear(): self {
		return new self(
			from: (new DateTimeImmutable($this->from))->modify('-1 year')->format('Y-m-d'),
			to: (new DateTimeImmutable($this->to))->modify('-1 year')->format('Y-m-d')
		);
	}//end previousYear()

	/**
	 * The period's label: `2026` for a whole year, `Q1 2026`, `2026-01`, else `from/to`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function label(): string {
		return AggregateReportPeriods::periodLabel(from: $this->from, to: $this->to);
	}//end label()

	/**
	 * A filter map narrowed to this period on a date property; a condition
	 * already on that property is kept beside it.
	 *
	 * @param array<string, mixed> $filter The filter map.
	 * @param string               $field  The date property.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function narrow(array $filter, string $field): array {
		$range = AggregateReportPeriods::createdRange(from: $this->from, to: $this->to);
		$existing = ($filter[$field] ?? []);
		if (is_array($existing) === false) {
			$existing = ['eq' => $existing];
		}

		$filter[$field] = array_merge($existing, $range);

		return $filter;
	}//end narrow()

	/**
	 * Parse one bound.
	 *
	 * @param string $value The bound.
	 * @param string $name  Its name, for the message.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When it is not a `Y-m-d` date.
	 */
	private static function date(string $value, string $name): DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		if ($date === false || $date->format('Y-m-d') !== $value) {
			throw new InvalidArgumentException(sprintf('`dateRange.%s` MUST be a date (Y-m-d)', $name));
		}

		return $date;
	}//end date()
}//end class
