<?php

/**
 * One section of a report template, counted over a period.
 *
 * `total` is one number, `grouped` a metric per value of `groupBy` with every
 * listed row present (0 included), `series` a count per bucket of the date
 * property with every bucket present. Each runs with the caller's rights.
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

use OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;

/**
 * Runs one template section with the caller's rights and shapes its groups.
 */
class ReportSectionQuery {
	/**
	 * Constructor.
	 *
	 * @param AggregationControllerServices $services The aggregation runner (the caller's rights) and the series validator.
	 */
	public function __construct(
		private readonly AggregationControllerServices $services,
	) {
	}//end __construct()

	/**
	 * A section's data by its type, as `{groups: [{key, value}]}`.
	 *
	 * @param ReportSectionSource  $source  Where the section counts.
	 * @param array<string, mixed> $section The section.
	 * @param ReportPeriod         $period  The period.
	 *
	 * @return array{groups: array<int, array<string, mixed>>}
	 *
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller may not read the section's schema.
	 * @throws \RuntimeException When the section's register or schema does not exist.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-configurable-report-templates
	 */
	public function data(ReportSectionSource $source, array $section, ReportPeriod $period): array {
		$type = (string)($section['type'] ?? 'grouped');
		if ($type === 'series') {
			return ['groups' => $this->seriesGroups(source: $source, section: $section, period: $period)];
		}

		$filter = $period->narrow(filter: $source->filter, field: $source->dateField);
		$metric = (string)($section['metric'] ?? AggregationQuery::METRIC_COUNT);
		$field = $this->metricField(section: $section, metric: $metric);
		if ($type === 'total') {
			$result = $this->run(source: $source, query: $this->query(metric: $metric, field: $field, filter: $filter, layout: null));
			return ['groups' => [['key' => (string)($section['title'] ?? 'Total'), 'value' => $this->scalar(result: $result)]]];
		}

		$layout = new AggregationLayout(groupBy: ['field' => (string)$section['groupBy']]);
		$groups = ($this->run(source: $source, query: $this->query(metric: $metric, field: $field, filter: $filter, layout: $layout))['groups'] ?? []);

		return ['groups' => (new ReportSectionRows())->withListedRows(groups: $groups, rows: ($section['rows'] ?? []))];
	}//end data()

	/**
	 * A series section: one labelled bucket per interval of the period, none missing.
	 *
	 * @param ReportSectionSource  $source  Where the section counts.
	 * @param array<string, mixed> $section The section.
	 * @param ReportPeriod         $period  The period.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregateReportPeriods is the aggregate report's own pure
	 *   bucket helper (gap filling and labels, as `/aggregate` uses it); no instance alternative exists.
	 */
	private function seriesGroups(ReportSectionSource $source, array $section, ReportPeriod $period): array {
		$gap = strtolower((string)($section['interval'] ?? 'month'));
		$range = ['from' => $period->from, 'to' => $period->exclusiveEnd()];
		$query = $this->services->validator->validate(
			input: [
				'field' => $source->dateField,
				'interval' => $gap,
				'from' => $range['from'],
				'to' => $range['to'],
				'metric' => AggregationQuery::METRIC_COUNT,
				'metricField' => null,
				'filter' => $source->filter,
				'cumulative' => false,
			],
			schema: $this->services->runner->findSchema(schemaRef: $source->schema, registerRef: $source->register)
		);
		$groups = AggregateReportPeriods::fillGaps(
			groups: ($this->run(source: $source, query: $query)['groups'] ?? []),
			gap: $gap,
			range: $range,
			metric: AggregationQuery::METRIC_COUNT
		);
		foreach ($groups as $index => $group) {
			$groups[$index]['key'] = AggregateReportPeriods::label(key: (string)($group['key'] ?? ''), gap: $gap);
		}

		return $groups;
	}//end seriesGroups()

	/**
	 * The validated query of a total or grouped section.
	 *
	 * @param string                 $metric The metric.
	 * @param string|null            $field  The field it reads, null for count.
	 * @param array<string, mixed>   $filter The filter map.
	 * @param AggregationLayout|null $layout The grouping, null for one number.
	 *
	 * @return AggregationQuery
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) AggregationQuery::create() is the engine's fail-fast static
	 *   factory (mirrors AggregationRunner's own usage); no instance alternative exists.
	 */
	private function query(string $metric, ?string $field, array $filter, ?AggregationLayout $layout): AggregationQuery {
		return AggregationQuery::create(metric: $metric, field: $field, filter: $filter, layout: $layout);
	}//end query()

	/**
	 * The field a metric reads; count reads none.
	 *
	 * @param array<string, mixed> $section The section.
	 * @param string               $metric  The metric.
	 *
	 * @return string|null
	 */
	private function metricField(array $section, string $metric): ?string {
		if ($metric === AggregationQuery::METRIC_COUNT || is_string($section['field'] ?? null) === false) {
			return null;
		}

		return $section['field'];
	}//end metricField()

	/**
	 * Run a section's query with the caller's rights.
	 *
	 * @param ReportSectionSource $source Where the section counts.
	 * @param AggregationQuery    $query  The query.
	 *
	 * @return array<string, mixed> The runner's envelope.
	 */
	private function run(ReportSectionSource $source, AggregationQuery $query): array {
		return $this->services->runner->runAdhocByRef(registerRef: $source->register, schemaRef: $source->schema, query: $query);
	}//end run()

	/**
	 * The single value of an ungrouped run.
	 *
	 * @param array<string, mixed> $result The runner's envelope.
	 *
	 * @return int|float
	 */
	private function scalar(array $result): int|float {
		$value = ($result['value'] ?? $result['count'] ?? 0);
		if (is_int($value) === true || is_float($value) === true) {
			return $value;
		}

		return (float)$value;
	}//end scalar()
}//end class
