<?php

/**
 * OpenRegister CrossRegisterReportController
 *
 * `GET /api/reports/aggregate`: one aggregation across registers. Without
 * `interval` it answers the metric in total with a breakdown per register;
 * with `groupBy=@self.created&interval=<gap>` it answers a count time
 * series with one series per register. `registers=a,b` narrows the
 * registers (slugs or ids); every register is covered without it. Each
 * register/schema pair runs through the aggregation runner, so RBAC and
 * multi-tenancy apply per pair and a pair the caller may not read is left
 * out silently.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Search\Controller
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Search\Service\Aggregation\CrossRegisterReport;
use OCA\OpenRegister\Search\Service\Aggregation\TimeseriesRequestValidator;
use OCA\OpenRegister\Service\Aggregation\AggregationRunnerInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Organisation-wide aggregation across registers.
 */
class CrossRegisterReportController extends Controller {
	/**
	 * The bucket widths a cross-register series takes.
	 *
	 * @var list<string>
	 */
	private const INTERVALS = ['hour', 'day', 'week', 'month', 'quarter', 'year'];

	/**
	 * Constructor.
	 *
	 * @param IRequest                   $request   The current request.
	 * @param CrossRegisterReport        $report    Runs and merges the per-pair aggregations.
	 * @param AggregationRunnerInterface $runner    Resolves a pair's schema for the time-series validator.
	 * @param TimeseriesRequestValidator $validator Validates the time-series query per schema.
	 */
	public function __construct(
		IRequest $request,
		private readonly CrossRegisterReport $report,
		private readonly AggregationRunnerInterface $runner,
		private readonly TimeseriesRequestValidator $validator,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Aggregate across registers.
	 *
	 * Reads `metric` (count, sum, min or max; count by default), `field`
	 * (required for any metric but count), `registers` (comma-separated),
	 * and for a time series `groupBy=@self.created`, `interval` and the
	 * optional `from`/`to` (the last twelve buckets without them).
	 *
	 * @return JSONResponse `{total, byRegister: [{register, <metric>}], metric, field}`, or
	 *                      `{labels, series: [{name, data}], metric, interval}`; 400 on a bad request.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-cross-register-reporting
	 */
	public function aggregate(): JSONResponse {
		$metric = strtolower(trim((string)$this->request->getParam('metric', AggregationQuery::METRIC_COUNT)));
		if ($metric === '') {
			$metric = AggregationQuery::METRIC_COUNT;
		}

		$field = trim((string)$this->request->getParam('field', ''));
		if ($field === '') {
			$field = null;
		}
		$registers = array_values(
			array_filter(array_map('trim', explode(',', (string)$this->request->getParam('registers', ''))), static fn (string $ref): bool => $ref !== '')
		);

		$interval = strtolower(trim((string)$this->request->getParam('interval', '')));
		if ($interval !== '') {
			return $this->series(metric: $metric, registers: $registers, interval: $interval);
		}

		try {
			$totals = $this->report->totals(metric: $metric, field: $field, registerRefs: $registers);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($totals + ['metric' => $metric, 'field' => $field]);
	}//end aggregate()

	/**
	 * The count time series, one series per register.
	 *
	 * @param string             $metric    The requested metric; only count is accepted.
	 * @param array<int, string> $registers The register refs, every register when empty.
	 * @param string             $interval  The bucket width.
	 *
	 * @return JSONResponse
	 */
	private function series(string $metric, array $registers, string $interval): JSONResponse {
		$groupBy = trim((string)$this->request->getParam('groupBy', '@self.created'));
		if ($metric !== AggregationQuery::METRIC_COUNT) {
			return new JSONResponse(['error' => 'a cross-register time series counts objects; use metric=count'], Http::STATUS_BAD_REQUEST);
		}

		// Checked here: a pair whose query does not validate is skipped as
		// unreadable, so a bad interval would otherwise answer an empty series.
		if (in_array($interval, self::INTERVALS, true) === false) {
			return new JSONResponse(['error' => '`interval` must be one of: '.implode(', ', self::INTERVALS)], Http::STATUS_BAD_REQUEST);
		}

		$range = [
			'from' => trim((string)$this->request->getParam('from', '')),
			'to' => trim((string)$this->request->getParam('to', '')),
		];
		if ($range['from'] === '' && $range['to'] === '') {
			$range = AggregateReportPeriods::defaultRange(gap: $interval, now: new DateTimeImmutable('now'));
		}

		$column = AggregateReportEnvelope::column(field: $groupBy);
		$queryFor = fn (string $register, string $schema): AggregationQuery => $this->validator->validate(
			input: [
				'field' => $column,
				'interval' => $interval,
				'from' => $range['from'],
				'to' => $range['to'],
				'metric' => AggregationQuery::METRIC_COUNT,
				'metricField' => null,
				'filter' => [],
				'cumulative' => false,
			],
			schema: $this->runner->findSchema(schemaRef: $schema, registerRef: $register)
		);

		$result = $this->report->series(registerRefs: $registers, gap: $interval, range: $range, queryFor: $queryFor);

		return new JSONResponse($result + ['metric' => $metric, 'interval' => $interval]);
	}//end series()
}//end class
