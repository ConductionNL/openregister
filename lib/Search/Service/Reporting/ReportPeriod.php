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

/**
 * A period of whole days, both bounds inclusive.
 */
final class ReportPeriod {
	/**
	 * First day, `Y-m-d`.
	 *
	 * @var string
	 */
	public readonly string $from;

	/**
	 * Last day, `Y-m-d`.
	 *
	 * @var string
	 */
	public readonly string $to;

	/**
	 * A period from two dates, refused when one does not parse or the end precedes the start.
	 *
	 * @param string $from First day, `Y-m-d`.
	 * @param string $to   Last day, `Y-m-d`.
	 *
	 * @throws InvalidArgumentException When a bound is not a date or the range is reversed.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function __construct(string $from, string $to) {
		$start = $this->date(value: $from, name: 'from');
		$end = $this->date(value: $to, name: 'to');
		if ($end < $start) {
			throw new InvalidArgumentException('`dateRange.to` MUST NOT precede `dateRange.from`');
		}

		$this->from = $start->format('Y-m-d');
		$this->to = $end->format('Y-m-d');
	}//end __construct()

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
	 * The day after the last day: the exclusive end a bucketed series takes.
	 *
	 * @return string `Y-m-d`.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function exclusiveEnd(): string {
		return (new DateTimeImmutable($this->to))->modify('+1 day')->format('Y-m-d');
	}//end exclusiveEnd()

	/**
	 * The period's label: `2026` for a whole year, `2026-01` for a whole month, else `from/to`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function label(): string {
		$start = new DateTimeImmutable($this->from);
		if ($start->format('m-d') === '01-01' && $this->to === $start->format('Y') . '-12-31') {
			return $start->format('Y');
		}

		if ($start->format('d') === '01' && $this->to === $start->format('Y-m-t')) {
			return $start->format('Y-m');
		}

		return $this->from . '/' . $this->to;
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
		$existing = ($filter[$field] ?? []);
		if (is_array($existing) === false) {
			$existing = ['eq' => $existing];
		}

		$filter[$field] = array_merge($existing, ['gte' => $this->from, 'lt' => $this->exclusiveEnd()]);

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
	private function date(string $value, string $name): DateTimeImmutable {
		$parts = [];
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1
			|| checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) === false
		) {
			throw new InvalidArgumentException(sprintf('`dateRange.%s` MUST be a date (Y-m-d)', $name));
		}

		return new DateTimeImmutable($value);
	}//end date()
}//end class
