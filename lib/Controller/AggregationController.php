<?php

/**
 * OpenRegister AggregationController
 *
 * HTTP entry point for the two aggregation surfaces OR exposes:
 *
 *  - {@see aggregate()}  — named-annotation surface backed by the
 *    `x-openregister-aggregations` block on a schema. Schema-author
 *    declared, immutable per release. Original surface.
 *  - {@see timeseries()} — ad-hoc surface where the client supplies
 *    the field, optional bucketing interval, and bounds at request
 *    time. Added by `add-time-bucket-aggregation`. Backs the
 *    nextcloud-vue `CnChartWidget.dataSource` bucket shorthand.
 *
 * Both paths share `AggregationRunner` for RBAC + multi-tenant
 * gating + Postgres / fallback dispatch. The ad-hoc path DOES consult
 * `AggregationCache` (see `AggregationRunner::runAdhoc()`); `value()`,
 * `grouped()` and `timeseries()` all surface the resulting hit/miss
 * verdict via `X-OR-Cache`, closing #1610.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
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
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/specs/aggregations-backend-native/spec.md
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
 * @spec openspec/specs/aggregation-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\AggregationRunner;
use OCA\OpenRegister\Service\Aggregation\TimeseriesRequestValidator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class AggregationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param AggregationRunner $runner The aggregation runner.
	 * @param TimeseriesRequestValidator $validator Ad-hoc request validator.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AggregationRunner $runner,
		private readonly TimeseriesRequestValidator $validator,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Run a named aggregation declared on the schema.
	 *
	 * Surfaces an `X-OR-Cache: hit|miss` response header in addition
	 * to the `cached: true` field in the body, closing the deferred
	 * "controller-response header" follow-up from
	 * `aggregations-backend-native` task 6.3. Reverse proxies and
	 * downstream observability stacks can grep the header without
	 * parsing the JSON envelope.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 * @param string $name Aggregation name.
	 *
	 * @return JSONResponse JSON response with aggregation result.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 */
	public function aggregate(string $register, string $schema, string $name): JSONResponse {
		try {
			$result = $this->runner->run(registerRef: $register, schemaRef: $schema, name: $name);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$response = new JSONResponse($result);
		$cacheHeader = 'miss';
		if (($result['cached'] ?? false) === true) {
			$cacheHeader = 'hit';
		}

		$response->addHeader('X-OR-Cache', $cacheHeader);
		return $response;
	}//end aggregate()

	/**
	 * Ad-hoc single-value aggregation entry point.
	 *
	 * The non-bucketed sibling of {@see timeseries()} — returns one scalar
	 * `value` for manifest-configured KPI widgets (nextcloud-vue CnStatWidget).
	 * RBAC + multi-tenancy are enforced inside AggregationRunner::runAdhocByRef.
	 *
	 * Accepts: metric (default count), field (required for non-count),
	 * filter[...] (operator-aware), metrics[...] (optional multi-metric
	 * list — REQ-AGG-102; when supplied, the response carries `values`
	 * instead of a single scalar `value`). The literal `value` URL never
	 * collides with the named `{name}` aggregate route.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with the scalar aggregation value
	 *                      (or `values` for a multi-metric request) and an
	 *                      `X-OR-Cache: hit|miss` header (REQ-AGG-105).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function value(string $register, string $schema): JSONResponse {
		$metric = (string)$this->request->getParam('metric', 'count');
		$field = $this->request->getParam('field');
		$filter = (array)($this->request->getParam('filter', []));
		$metrics = $this->parseMetricsParam();

		$resolvedField = $field;
		if ($field === '') {
			$resolvedField = null;
		}

		$primaryMetric = $metric;
		$primaryField = $resolvedField;
		if ($metrics !== null) {
			$primaryMetric = $metrics[0]['metric'];
			$primaryField = $metrics[0]['field'];
		}

		try {
			$query = AggregationQuery::create(
				metric: $primaryMetric,
				field: $primaryField,
				filter: $filter,
				metrics: $metrics
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->runner->runAdhocByRef(registerRef: $register, schemaRef: $schema, query: $query);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->withCacheHeader(result: $result);
	}//end value()

	/**
	 * Parse the optional `metrics` multi-metric list request param
	 * (REQ-AGG-102) into the shape {@see AggregationQuery::create()}'s
	 * `metrics` argument expects.
	 *
	 * Wire shape: `metrics[0][metric]=count&metrics[1][metric]=sum&
	 * metrics[1][field]=price` (PHP parses this into a nested array via
	 * `IRequest::getParam()`, same convention as the existing `filter[...]`
	 * param). Returns null when the param is absent/empty so callers can
	 * distinguish "no metrics list — use the legacy single metric/field
	 * pair" from "an explicit (possibly one-element) list".
	 *
	 * @return array<int, array{metric: string, field: ?string}>|null
	 */
	private function parseMetricsParam(): ?array {
		$raw = $this->request->getParam('metrics');
		if (is_array($raw) === false || count($raw) === 0) {
			return null;
		}

		$metrics = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$entryField = ($entry['field'] ?? null);
			if ($entryField === '') {
				$entryField = null;
			}

			$metrics[] = [
				'metric' => (string)($entry['metric'] ?? ''),
				'field' => $entryField,
			];
		}

		if (count($metrics) === 0) {
			return null;
		}

		return $metrics;
	}//end parseMetricsParam()

	/**
	 * Surface the `X-OR-Cache: hit|miss` header on an ad-hoc envelope
	 * (REQ-AGG-105) — the `value`/`grouped`/`timeseries` counterpart of
	 * the header {@see aggregate()} already sets from `cached`.
	 *
	 * @param array<string, mixed> $result The runner's result envelope.
	 *
	 * @return JSONResponse The response with the header attached.
	 */
	private function withCacheHeader(array $result): JSONResponse {
		$response = new JSONResponse($result);
		$cacheHeader = 'miss';
		if (($result['cached'] ?? false) === true) {
			$cacheHeader = 'hit';
		}

		$response->addHeader('X-OR-Cache', $cacheHeader);
		return $response;
	}//end withCacheHeader()

	/**
	 * Ad-hoc categorical group-by aggregation entry point.
	 *
	 * The non-time sibling of {@see timeseries()} — groups by a NON-date field
	 * and returns `{ groups: [{ key, value }] }` for manifest-configured
	 * category charts (nextcloud-vue CnChartWidget group-by source).
	 *
	 * Accepts: groupBy (required field — a single field name, a
	 * comma-separated list, or a repeated `groupBy[]` array for a
	 * multi-field cross-tab groupBy, REQ-AGG-101), metric (default count),
	 * field (required for non-count), metrics[...] (optional multi-metric
	 * list, REQ-AGG-102), sort (asc|desc), limit (top-N), filter[...].
	 * A single groupBy field keeps the legacy `{key, value}` per-group
	 * shape byte-identical; more than one field switches to `{keys, value}`.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with `{ groups, backend, cached }`
	 *                      and an `X-OR-Cache: hit|miss` header (REQ-AGG-105).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function grouped(string $register, string $schema): JSONResponse {
		$metric = (string)$this->request->getParam('metric', 'count');
		$field = $this->request->getParam('field');
		$filter = (array)($this->request->getParam('filter', []));
		$metrics = $this->parseMetricsParam();

		$groupFields = $this->parseGroupByParam();
		if (count($groupFields) === 0) {
			return new JSONResponse(['error' => 'groupBy is required'], Http::STATUS_BAD_REQUEST);
		}

		// A single field keeps the pre-existing `{field: ...}` shape
		// (byte-identical response for existing single-field callers);
		// more than one field switches to the multi-field `{fields: [...]}`
		// cross-tab shape (REQ-AGG-101) — AggregationQuery/AggregationRunner
		// already support both natively.
		$groupSpec = ['field' => $groupFields[0]];
		if (count($groupFields) > 1) {
			$groupSpec = ['fields' => $groupFields];
		}

		$sort = $this->request->getParam('sort');
		if ($sort === 'asc' || $sort === 'desc') {
			$groupSpec['sort'] = $sort;
		}

		$limit = $this->request->getParam('limit');
		if ($limit !== null && (string)$limit !== '' && (int)$limit > 0) {
			$groupSpec['limit'] = (int)$limit;
		}

		$resolvedField = $field;
		if ($field === '') {
			$resolvedField = null;
		}

		$primaryMetric = $metric;
		$primaryField = $resolvedField;
		if ($metrics !== null) {
			$primaryMetric = $metrics[0]['metric'];
			$primaryField = $metrics[0]['field'];
		}

		try {
			$query = AggregationQuery::create(
				metric: $primaryMetric,
				field: $primaryField,
				filter: $filter,
				groupBy: $groupSpec,
				metrics: $metrics
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->runner->runAdhocByRef(registerRef: $register, schemaRef: $schema, query: $query);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->withCacheHeader(result: $result);
	}//end grouped()

	/**
	 * Parse the `groupBy` request param into an ordered list of field
	 * names (REQ-AGG-101).
	 *
	 * Accepts three wire shapes so existing single-field callers are
	 * unaffected:
	 *  - a single field name: `groupBy=status` → `['status']`;
	 *  - a comma-separated list: `groupBy=status,type` → `['status', 'type']`;
	 *  - a repeated-param array (PHP's `groupBy[]=status&groupBy[]=type`
	 *    parsing): `['status', 'type']` passed straight through.
	 * Blank entries are dropped; the result may be empty (caller 400s).
	 *
	 * @return array<int, string> Ordered, non-empty field names.
	 */
	private function parseGroupByParam(): array {
		$raw = $this->request->getParam('groupBy', '');

		$candidates = [];
		if (is_array($raw) === true) {
			$candidates = $raw;
		} else {
			$candidates = explode(',', (string)$raw);
		}

		$fields = [];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false) {
				continue;
			}

			$trimmed = trim($candidate);
			if ($trimmed === '') {
				continue;
			}

			$fields[] = $trimmed;
		}

		return $fields;
	}//end parseGroupByParam()

	/**
	 * Ad-hoc time-bucket aggregation entry point.
	 *
	 * Accepts query params:
	 *  - field        (required)
	 *  - interval     (optional — MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR)
	 *  - from, to     (required when interval set; ISO-8601)
	 *  - metric       (optional, default `count`)
	 *  - metricField  (required when metric != count)
	 *  - filter[...]  (optional, reuses the existing filter vocabulary)
	 *  - cumulative   (optional, default `false` — REQ-AGG-103; running
	 *                  total of the metric alongside each per-bucket
	 *                  value, ordered ascending by bucket start. Only
	 *                  valid when `interval` is set.)
	 *
	 * Returns `{ groups: [{ key, value, cumulative? }], backend, cached }`
	 * matching the GraphQL `groups` field shape so `CnChartWidget` can
	 * normalise once. The `cumulative` per-group field is present only
	 * when the request set `cumulative=true`.
	 *
	 * @param string $register Register reference.
	 * @param string $schema Schema reference.
	 *
	 * @return JSONResponse JSON response with bucketed groups and an
	 *                      `X-OR-Cache: hit|miss` header (REQ-AGG-105).
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-15
	 * @spec openspec/specs/aggregation-api/spec.md
	 */
	public function timeseries(string $register, string $schema): JSONResponse {
		// Resolve schema first so the validator can consult the
		// declared property list. A missing schema is a 404; a bad
		// query-param shape is a 400.
		//
		// The `{register}` path segment is passed through: this lookup must
		// resolve the SAME schema that runAdhocByRef() resolves below, and the
		// register is what disambiguates a slug several apps share. Without it
		// the validator would police one app's property list while the
		// aggregate ran over another's rows.
		try {
			$schemaEntity = $this->runner->findSchema(schemaRef: $schema, registerRef: $register);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		// Pull the request shape from the active IRequest. The filter
		// map comes through as a nested array because PHP parses
		// `filter[x][op]=y` into `$_GET['filter']['x']['op']='y'`.
		$input = [
			'field' => $this->request->getParam('field', ''),
			'interval' => $this->request->getParam('interval'),
			'from' => $this->request->getParam('from'),
			'to' => $this->request->getParam('to'),
			'metric' => $this->request->getParam('metric', 'count'),
			'metricField' => $this->request->getParam('metricField'),
			'filter' => (array)($this->request->getParam('filter', [])),
			'cumulative' => $this->request->getParam('cumulative', false),
		];

		try {
			$query = $this->validator->validate(input: $input, schema: $schemaEntity);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		try {
			$result = $this->runner->runAdhocByRef(
				registerRef: $register,
				schemaRef: $schema,
				query: $query
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->withCacheHeader(result: $result);
	}//end timeseries()
}//end class
