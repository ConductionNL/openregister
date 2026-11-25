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

namespace OCA\OpenRegister\Search\Controller;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateChartData;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportEnvelope;
use OCA\OpenRegister\Search\Service\Aggregation\AggregateReportPeriods;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationLayout;
use OCA\OpenRegister\Search\Service\Aggregation\AggregationQuery;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationRef;
use OCA\OpenRegister\Service\Aggregation\NamedAggregationOptions;
use OCA\OpenRegister\Search\Controller\Support\AggregationControllerServices;
use OCA\OpenRegister\Search\Controller\Support\AggregationRun;
use OCA\OpenRegister\Kernel\Contracts\RegisterSchemaRef;
use OCA\OpenRegister\Service\Resilience\Sheddable;
use OCA\OpenRegister\Support\AggregationFilterRequest;
use OCA\OpenRegister\Support\FilterParams;
use OCA\OpenRegister\Support\UnknownFilterKeys;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The count went to the
 * threshold when reading both filter spellings (openregister#3611) added three
 * references: `FilterParams` for the grammar, `Schema` to pass the already
 * resolved entity into it, and a logger for the one warning an unknown key
 * raises. The cheap way back under the limit is to re-resolve the schema
 * instead of passing it, which would let the validator police one schema's
 * property list while the aggregate ran over another's, and that is exactly
 * the mismatch `timeseries()` resolves the schema up front to avoid.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Already over the limit
 * before the reporting actions (rapportage-bi-export) joined; `report()` and
 * `compare()` share `resolveFilter()`, the metric parsing and the runner
 * refusals with their siblings, and a second controller would have to copy
 * that private request grammar rather than read the same one.
 */
class AggregationController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest                      $request  The current request.
	 * @param AggregationControllerServices $services The runner, the ad-hoc validator and the logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly AggregationControllerServices $services,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Read this request's filter map in BOTH spellings.
	 *
	 * The aggregations have always read `filter[x]=v` and dropped a bare
	 * `x=v`, while object search did the opposite, so one query answered two
	 * different numbers depending on which sibling endpoint served it and
	 * neither errored (openregister#3611). A bare key now joins the filter
	 * map here, on one condition: it must name a property the schema
	 * declares. A parameter that names nothing (a cache-buster, a stray
	 * `v=2`) is still ignored, exactly as before, because turning those into
	 * filters would silently zero every widget that carries one.
	 *
	 * Whatever the endpoint could not make sense of is logged once, naming
	 * the keys, the endpoint and the schema. A warning, not a refusal: a 400
	 * would break every caller on the old spelling in the same minute.
	 *
	 * The schema is looked up only when the request carries something that
	 * could be a filter, so the common control-params-only request pays
	 * nothing. The lookup is the register-scoped one `runAdhocByRef()` will
	 * repeat, so both resolve the same schema.
	 *
	 * @param AggregationRun $run    The register and schema references, and the schema when already resolved.
	 * @param string         $action The controller action, keying its control params.
	 *
	 * @return array<string, mixed> The normalised filter map.
	 *
	 * @throws RuntimeException When the schema cannot be resolved.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-both-filter-spellings-mean-the-same-filter-on-object-search-and-the-aggregations
	 */
	private function resolveFilter(AggregationRun $run, string $action): array {
		$bracket = (array)($this->request->getParam(FilterParams::FILTER_KEY, []));
		$params = $this->request->getParams();
		$controlParams = FilterParams::AGGREGATION_CONTROL_PARAMS[$action];

		if ($bracket === []
			&& FilterParams::hasBareCandidates(params: $params, controlParams: $controlParams) === false
		) {
			return [];
		}

		$resolvedSchema = $run->schemaEntity;
		if ($resolvedSchema === null) {
			$resolvedSchema = $this->services->runner->findSchema(schemaRef: $run->schema, registerRef: $run->register);
		}

		$normalised = FilterParams::forAggregation(
			request: new AggregationFilterRequest(bracket: $bracket, params: $params, controlParams: $controlParams),
			properties: ($resolvedSchema->getProperties() ?? [])
		);

		FilterParams::warnUnknownKeys(
			logger: $this->services->logger,
			unknown: new UnknownFilterKeys(
				keys: $normalised['unknown'],
				endpoint: 'aggregation#' . $action,
				table: new RegisterSchemaRef(register: $run->register, schema: $run->schema)
			)
		);

		return $normalised['filter'];
	}//end resolveFilter()

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
	#[Sheddable]
	public function aggregate(string $register, string $schema, string $name): JSONResponse {
		return $this->runNamed(run: new AggregationRun(register: $register, schema: $schema), name: $name);
	}//end aggregate()

	/**
	 * Run a named aggregation, narrowed by the request's filter.
	 *
	 * @param AggregationRun $run  The register and schema references.
	 * @param string         $name Aggregation name.
	 *
	 * @return JSONResponse The result with its cache header, 403 when refused, 404 when unknown.
	 */
	private function runNamed(AggregationRun $run, string $name): JSONResponse {
		try {
			// `filter[...]` NARROWS a declared aggregation; it can never relax
			// it. The runner refuses any key the declaration already
			// constrains, so this cannot be used to widen a scoping filter —
			// see AggregationRunner::mergeNarrowingFilter().
			//
			// It exists because an annotation has no way to name the caller's
			// context: `$currentUser` is the only placeholder the resolver
			// knows, so a per-tenant figure declared once could not be asked
			// for per tenant.
			//
			// Both spellings narrow: `filter[administrationId]=X` and a bare
			// `administrationId=X` reach the runner as the same constraint.
			$extraFilter = $this->resolveFilter(run: $run, action: 'aggregate');

			$result = $this->services->runner->run(
				ref: new NamedAggregationRef(register: $run->register, schema: $run->schema, name: $name),
				options: new NamedAggregationOptions(bypassRbac: false, extraFilter: $extraFilter)
			);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->withCacheHeader(result: $result);
	}//end runNamed()

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
	#[Sheddable]
	public function value(string $register, string $schema): JSONResponse {
		$run = new AggregationRun(register: $register, schema: $schema);
		try {
			$run->filter = $this->resolveFilter(run: $run, action: 'value');
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->runAdhocQuery(run: $run, groupBy: null);
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
			return $this->parseMetricCommaList();
		}

		$metrics = [];
		foreach ($raw as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$metrics[] = $this->metricEntry(entry: $entry);
		}

		if (count($metrics) === 0) {
			return null;
		}

		return $metrics;
	}//end parseMetricsParam()

	/**
	 * The short multi-metric spelling `metric=count,sum,avg&field=amount`:
	 * every listed metric but count takes the one `field` param. A single
	 * metric (no comma) returns null and stays the legacy single pair, so
	 * existing callers see the same query; a malformed entry is left to
	 * {@see AggregationQuery::create()} to refuse with a 400.
	 *
	 * @return array<int, array{metric: string, field: mixed}>|null The list, or null for one metric.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	private function parseMetricCommaList(): ?array {
		$raw = $this->request->getParam('metric');
		if (is_string($raw) === false || str_contains($raw, ',') === false) {
			return null;
		}

		$names = array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $name): bool => $name !== ''));
		if (count($names) < 2) {
			return null;
		}

		$field = $this->request->getParam('field');
		$metrics = [];
		foreach ($names as $name) {
			$entryField = null;
			if ($name !== AggregationQuery::METRIC_COUNT) {
				$entryField = $field;
			}

			$metrics[] = $this->metricEntry(entry: ['metric' => $name, 'field' => $entryField]);
		}

		return $metrics;
	}//end parseMetricCommaList()

	/**
	 * One `metrics[...]` entry as the query expects it; a blank field means none.
	 *
	 * @param array<string, mixed> $entry The raw entry.
	 *
	 * @return array{metric: string, field: mixed}
	 */
	private function metricEntry(array $entry): array {
		$entryField = ($entry['field'] ?? null);
		if ($entryField === '') {
			$entryField = null;
		}

		return [
			'metric' => (string)($entry['metric'] ?? ''),
			'field' => $entryField,
		];
	}//end metricEntry()

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
		// The reporting spelling (rapportage-bi-export, report caching): the
		// same verdict as `X-Cache: HIT` / `MISS`, beside the original header.
		$response->addHeader('X-Cache', strtoupper($cacheHeader));
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
	#[Sheddable]
	public function grouped(string $register, string $schema): JSONResponse {
		$groupFields = $this->parseGroupByParam();
		if (count($groupFields) === 0) {
			return new JSONResponse(['error' => 'groupBy is required'], Http::STATUS_BAD_REQUEST);
		}

		$run = new AggregationRun(register: $register, schema: $schema);
		try {
			$run->filter = $this->resolveFilter(run: $run, action: 'grouped');
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->runAdhocQuery(run: $run, groupBy: $this->groupSpec(groupFields: $groupFields));
	}//end grouped()

	/**
	 * Build the ad-hoc query from the request's metric params and run it.
	 *
	 * An input AggregationQuery::create() refuses is a 400 and never runs.
	 *
	 * @param AggregationRun            $run     The register and schema references and the resolved filter map.
	 * @param array<string, mixed>|null $groupBy The groupBy spec, or null.
	 *
	 * @return JSONResponse The runner's envelope, or the refusal.
	 */
	private function runAdhocQuery(AggregationRun $run, ?array $groupBy): JSONResponse {
		$metrics = $this->parseMetricsParam();
		$primary = $this->primaryMetric(metrics: $metrics);

		try {
			$query = AggregationQuery::create(
				metric: $primary['metric'],
				field: $primary['field'],
				filter: $run->filter,
				layout: new AggregationLayout(
					groupBy: $groupBy,
					metrics: $metrics
				)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $this->runAndRespond(run: $run, query: $query);
	}//end runAdhocQuery()

	/**
	 * The primary metric and field: the first metrics entry when a list was
	 * sent, else the `metric` (default count) and `field` params, an empty
	 * field meaning none.
	 *
	 * @param array<int, array{metric: string, field: ?string}>|null $metrics The parsed metrics list.
	 *
	 * @return array{metric: string, field: mixed} The primary pair.
	 */
	private function primaryMetric(?array $metrics): array {
		if ($metrics !== null) {
			return ['metric' => $metrics[0]['metric'], 'field' => $metrics[0]['field']];
		}

		$field = $this->request->getParam('field');
		if ($field === '') {
			$field = null;
		}

		return ['metric' => (string)$this->request->getParam('metric', 'count'), 'field' => $field];
	}//end primaryMetric()

	/**
	 * Run an ad-hoc query; the runner decides access.
	 *
	 * A refusal is a 403 and an unknown register or schema a 404, each
	 * carrying the runner's message.
	 *
	 * @param AggregationRun   $run   The register and schema references.
	 * @param AggregationQuery $query The validated query.
	 *
	 * @return JSONResponse The envelope with its cache header, or the refusal.
	 */
	private function runAndRespond(AggregationRun $run, AggregationQuery $query): JSONResponse {
		try {
			$result = $this->services->runner->runAdhocByRef(registerRef: $run->register, schemaRef: $run->schema, query: $query);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		return $this->withCacheHeader(result: $result);
	}//end runAndRespond()

	/**
	 * The groupBy spec of a grouped request.
	 *
	 * A single field keeps the pre-existing `{field: ...}` shape
	 * (byte-identical response for existing single-field callers);
	 * more than one field switches to the multi-field `{fields: [...]}`
	 * cross-tab shape (REQ-AGG-101). `sort` (asc|desc) and a positive
	 * `limit` are carried when sent.
	 *
	 * @param array<int, string> $groupFields The non-empty group fields.
	 *
	 * @return array<string, mixed> The groupBy spec.
	 */
	private function groupSpec(array $groupFields): array {
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

		return $groupSpec;
	}//end groupSpec()

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
		$fields = [];
		foreach ($this->groupByCandidates() as $candidate) {
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
	 * The raw `groupBy` entries: the repeated-param array as given, else the comma-separated parts.
	 *
	 * @return array<int|string, mixed>
	 */
	private function groupByCandidates(): array {
		$raw = $this->request->getParam('groupBy', '');

		if (is_array($raw) === true) {
			return $raw;
		}

		return explode(',', (string)$raw);
	}//end groupByCandidates()

	/**
	 * Ad-hoc time-bucket aggregation entry point.
	 *
	 * Accepts query params:
	 *  - field        (required)
	 *  - interval     (optional — MINUTE|HOUR|DAY|WEEK|MONTH|QUARTER|YEAR)
	 *  - from, to     (required when interval set; ISO-8601)
	 *  - dateField    (optional, without interval: a date property; counts
	 *                  only objects whose dateField lies in [from, to))
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
	#[Sheddable]
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
			$schemaEntity = $this->services->runner->findSchema(schemaRef: $schema, registerRef: $register);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$run = new AggregationRun(register: $register, schema: $schema, schemaEntity: $schemaEntity);

		$input = $this->timeseriesInput(run: $run);

		try {
			$query = $this->services->validator->validate(input: $input, schema: $schemaEntity);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $this->runAndRespond(run: $run, query: $query);
	}//end timeseries()

	/**
	 * The time-bucket request as the validator reads it, from the active request.
	 *
	 * @param AggregationRun $run The register and schema references, with the resolved schema.
	 *
	 * @return array<string, mixed> field, interval, from, to, dateField, metric, metricField, filter and cumulative.
	 *
	 * @throws RuntimeException When the schema cannot be resolved for the filter.
	 *
	 * @spec openspec/changes/aggregations-a-shipped-report-may-rely-on/specs/aggregation-api/spec.md#requirement-a-shipped-report-shall-be-able-to-rely-on-a-stable-count-by-aggregation
	 */
	private function timeseriesInput(AggregationRun $run): array {
		// Pull the request shape from the active IRequest. The filter
		// map comes through as a nested array because PHP parses
		// `filter[x][op]=y` into `$_GET['filter']['x']['op']='y'`.
		return [
			'field' => $this->request->getParam('field', ''),
			'interval' => $this->request->getParam('interval'),
			'from' => $this->request->getParam('from'),
			'to' => $this->request->getParam('to'),
			'dateField' => $this->request->getParam('dateField'),
			'metric' => $this->request->getParam('metric', 'count'),
			'metricField' => $this->request->getParam('metricField'),
			'filter' => $this->resolveFilter(run: $run, action: 'timeseries'),
			'cumulative' => $this->request->getParam('cumulative', false),
		];
	}//end timeseriesInput()

	/**
	 * The reporting aggregation: `GET /api/objects/{register}/{schema}/aggregate`.
	 *
	 * One endpoint for the reporting spec's spelling of the ad-hoc
	 * aggregations: `groupBy` (a property, several, or `@self.created` /
	 * `@self.updated`), `metric` (one, or `count,sum,avg` with one `field`),
	 * `metrics[...]`, `interval` with `from`/`to` (the last twelve buckets
	 * when neither is sent), `having[<metric>][<op>]=<n>` and the filters
	 * object search reads. A time series carries every bucket of its range,
	 * an empty one as 0, labelled `2026-01` for a month.
	 *
	 * Returns `{results: [{<groupField>: ..., <metric>: ...}], total, metric,
	 * field, backend, cached}`; `total` is the summed count before `having`
	 * (or the row count without a count metric). The runner applies RBAC and
	 * multi-tenancy before it aggregates, exactly as for `grouped`.
	 *
	 * @param string $register Register reference.
	 * @param string $schema   Schema reference.
	 *
	 * @return JSONResponse The report envelope with an `X-OR-Cache` header; 400, 403 or 404 when refused.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-a-general-purpose-aggregation-api
	 */
	public function report(string $register, string $schema): JSONResponse {
		$groupFields = $this->parseGroupByParam();
		if (count($groupFields) === 0) {
			return new JSONResponse(['error' => 'groupBy is required'], Http::STATUS_BAD_REQUEST);
		}

		$metrics = $this->parseMetricsParam();
		$entries = $this->reportEntries(metrics: $metrics);
		try {
			$having = AggregateReportEnvelope::parseHaving(
				raw: $this->request->getParam('having'),
				columns: array_values(AggregateReportEnvelope::metricNames(metrics: $entries))
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$run = new AggregationRun(register: $register, schema: $schema);
		try {
			$run->filter = $this->resolveFilter(run: $run, action: 'report');
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$interval = $this->request->getParam('interval');
		$isSeries = (is_string($interval) === true && $interval !== '');
		if ($isSeries === true) {
			$result = $this->reportSeries(run: $run, groupFields: $groupFields, entries: $entries, interval: $interval);
		} else {
			$result = $this->reportGroups(run: $run, groupFields: $groupFields, metrics: $metrics);
		}

		if ($result instanceof JSONResponse) {
			return $result;
		}

		$rows = AggregateReportEnvelope::rows(groups: ($result['groups'] ?? []), groupFields: $groupFields, metrics: $entries);
		if ($isSeries === true && $this->isTrue(value: $this->request->getParam('trend')) === true) {
			$names = AggregateReportEnvelope::metricNames(metrics: $entries);
			$rows = AggregateReportPeriods::withTrend(rows: $rows, column: (string)reset($names));
		}

		return $this->withCacheHeader(
			result: [
				'results' => AggregateReportEnvelope::applyHaving(rows: $rows, having: $having),
				'total' => AggregateReportEnvelope::total(rows: $rows),
				'metric' => implode(',', array_column($entries, 'metric')),
				'field' => $this->reportField(entries: $entries),
				'backend' => ($result['backend'] ?? null),
				'cached' => ($result['cached'] ?? false),
			]
		);
	}//end report()

	/**
	 * The report's metric entries: the parsed list, else the one
	 * `metric`/`field` pair (count taking no field).
	 *
	 * @param array<int, array{metric: string, field: mixed}>|null $metrics The parsed list, or null.
	 *
	 * @return array<int, array{metric: string, field: mixed}>
	 */
	private function reportEntries(?array $metrics): array {
		if ($metrics !== null) {
			return $metrics;
		}

		$primary = $this->primaryMetric(metrics: null);
		if ($primary['metric'] === AggregationQuery::METRIC_COUNT) {
			$primary['field'] = null;
		}

		return [$primary];
	}//end reportEntries()

	/**
	 * The field the report's metrics run over, null when only count runs.
	 *
	 * @param array<int, array{metric: string, field: mixed}> $entries The metric entries.
	 *
	 * @return string|null
	 */
	private function reportField(array $entries): ?string {
		foreach ($entries as $entry) {
			if (is_string($entry['field']) === true && $entry['field'] !== '') {
				return $entry['field'];
			}
		}

		return null;
	}//end reportField()

	/**
	 * Run a categorical report, as `grouped` runs it.
	 *
	 * @param AggregationRun                                       $run         The references and the filter.
	 * @param array<int, string>                                   $groupFields The group fields as the caller wrote them.
	 * @param array<int, array{metric: string, field: mixed}>|null $metrics     The parsed metrics list, or null.
	 *
	 * @return array<string, mixed>|JSONResponse The runner's envelope, or the refusal.
	 */
	private function reportGroups(AggregationRun $run, array $groupFields, ?array $metrics): array|JSONResponse {
		$columns = array_map(static fn (string $field): string => AggregateReportEnvelope::column(field: $field), $groupFields);
		$primary = $this->primaryMetric(metrics: $metrics);

		try {
			$run->filter = $this->withCreatedRange(
				filter: $run->filter,
				from: (string)$this->request->getParam('from', ''),
				to: (string)$this->request->getParam('to', '')
			);
			$query = AggregationQuery::create(
				metric: $primary['metric'],
				field: $primary['field'],
				filter: $run->filter,
				layout: new AggregationLayout(
					groupBy: $this->groupSpec(groupFields: $columns),
					metrics: $metrics
				)
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $this->runReport(run: $run, query: $query);
	}//end reportGroups()

	/**
	 * Run a time-series report: one date field, one metric, every bucket of
	 * the range present and labelled.
	 *
	 * @param AggregationRun                                  $run         The references and the filter.
	 * @param array<int, string>                              $groupFields The group fields; exactly one, a date.
	 * @param array<int, array{metric: string, field: mixed}> $entries     The metric entries; exactly one.
	 * @param string                                          $interval    The bucket width as sent.
	 *
	 * @return array<string, mixed>|JSONResponse The runner's envelope with filled, labelled buckets, or the refusal.
	 */
	private function reportSeries(AggregationRun $run, array $groupFields, array $entries, string $interval): array|JSONResponse {
		if (count($groupFields) > 1 || count($entries) > 1) {
			return new JSONResponse(
				['error' => 'a time series takes one date field in `groupBy` and one metric'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$gap = strtolower($interval);
		$range = [
			'from' => (string)$this->request->getParam('from', ''),
			'to' => (string)$this->request->getParam('to', ''),
		];
		if ($range['from'] === '' && $range['to'] === '') {
			$range = AggregateReportPeriods::defaultRange(gap: $gap, now: new DateTimeImmutable('now'));
		}

		try {
			$schemaEntity = $this->services->runner->findSchema(schemaRef: $run->schema, registerRef: $run->register);
			$query = $this->services->validator->validate(
				input: [
					'field' => AggregateReportEnvelope::column(field: $groupFields[0]),
					'interval' => $interval,
					'from' => $range['from'],
					'to' => $range['to'],
					'metric' => $entries[0]['metric'],
					'metricField' => $entries[0]['field'],
					'filter' => $run->filter,
					'cumulative' => false,
				],
				schema: $schemaEntity
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$result = $this->runReport(run: $run, query: $query);
		if ($result instanceof JSONResponse) {
			return $result;
		}

		$groups = AggregateReportPeriods::fillGaps(
			groups: ($result['groups'] ?? []),
			gap: $gap,
			range: $range,
			metric: $entries[0]['metric']
		);
		foreach ($groups as $index => $group) {
			$groups[$index]['key'] = AggregateReportPeriods::label(key: (string)($group['key'] ?? ''), gap: $gap);
		}

		$result['groups'] = $groups;

		return $result;
	}//end reportSeries()

	/**
	 * Run a report query; the runner decides access.
	 *
	 * @param AggregationRun   $run   The references.
	 * @param AggregationQuery $query The validated query.
	 *
	 * @return array<string, mixed>|JSONResponse The runner's envelope, 403 when refused, 404 when unknown.
	 */
	private function runReport(AggregationRun $run, AggregationQuery $query): array|JSONResponse {
		try {
			return $this->services->runner->runAdhocByRef(registerRef: $run->register, schemaRef: $run->schema, query: $query);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end runReport()

	/**
	 * The filter narrowed to objects created in `from`..`to`, both inclusive;
	 * a `_created` condition the caller already sent is kept beside it.
	 *
	 * @param array<string, mixed> $filter The resolved filter map.
	 * @param string               $from   The range start, or ''.
	 * @param string               $to     The range end, or ''.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws InvalidArgumentException When a bound does not parse.
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
	 */
	private function withCreatedRange(array $filter, string $from, string $to): array {
		$range = AggregateReportPeriods::createdRange(from: $from, to: $to);
		if ($range === []) {
			return $filter;
		}

		$existing = ($filter['_created'] ?? []);
		if (is_array($existing) === false) {
			$existing = ['eq' => $existing];
		}

		$filter['_created'] = array_merge($existing, $range);

		return $filter;
	}//end withCreatedRange()

	/**
	 * Whether a request flag is set: `true`, `1`, `yes` or `on`.
	 *
	 * @param mixed $value The raw param.
	 *
	 * @return bool
	 */
	private function isTrue(mixed $value): bool {
		if (is_bool($value) === true) {
			return $value;
		}

		return in_array(strtolower(trim((string)$value)), ['true', '1', 'yes', 'on'], true);
	}//end isTrue()

	/**
	 * Chart data for one report: `GET /api/objects/{register}/{schema}/chart`.
	 *
	 * Takes every parameter of {@see report()} (`groupBy`, `metric`/`field`,
	 * `metrics[...]`, `interval` with `from`/`to`, `having`, filters) plus
	 * `type` (bar, line, area, pie or doughnut; bar when absent), runs the
	 * report and answers `{labels, series: [{name, data}]}`, or for a pie or
	 * doughnut `{labels, series: [values], percentages}`. A time series keeps
	 * the report's gap-filled buckets, so a line chart is continuous. A
	 * refused report (400, 403, 404) is answered as it is.
	 *
	 * @param string $register Register reference.
	 * @param string $schema   Schema reference.
	 *
	 * @return JSONResponse The chart data with the report's `X-OR-Cache` header; 400 for an unknown type.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-provide-chart-data-api-endpoints-for-frontend-visualization
	 */
	public function chart(string $register, string $schema): JSONResponse {
		try {
			$type = AggregateChartData::type(raw: $this->request->getParam('type'));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		$report = $this->report(register: $register, schema: $schema);
		if ($report->getStatus() !== Http::STATUS_OK) {
			return $report;
		}

		$data = $report->getData();
		$groupFields = $this->parseGroupByParam();
		$entries = $this->reportEntries(metrics: $this->parseMetricsParam());
		$chart = AggregateChartData::fromRows(
			type: $type,
			rows: ($data['results'] ?? []),
			labelColumn: $groupFields[0],
			metricColumns: array_values(AggregateReportEnvelope::metricNames(metrics: $entries))
		);

		$response = new JSONResponse(['type' => $type] + $chart);
		$cacheHeader = (string)($report->getHeaders()['X-OR-Cache'] ?? 'miss');
		$response->addHeader('X-OR-Cache', $cacheHeader);
		$response->addHeader('X-Cache', strtoupper($cacheHeader));
		return $response;
	}//end chart()

	/**
	 * Period-over-period comparison: `GET /api/reports/compare`.
	 *
	 * Reads `register`, `schema`, one `metric` (default count) with its
	 * `field`, the filters object search reads, and two created-date ranges
	 * as `period1.from`, `period1.to`, `period2.from`, `period2.to` (PHP
	 * reads the dots as underscores; `period1[from]` works too). Each period
	 * is its own aggregation, with RBAC and multi-tenancy applied by the
	 * runner as for any other.
	 *
	 * @return JSONResponse `{period1: {label, from, to, <metric>}, period2: {...}, change: {absolute, percentage}, metric, field}`;
	 *                      400, 403 or 404 when refused.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/rapportage-bi-export/specs/rapportage-bi-export/spec.md#requirement-the-system-must-support-date-range-filtering-and-period-over-period-comparison
	 */
	public function compare(): JSONResponse {
		$register = trim((string)$this->request->getParam('register', ''));
		$schema = trim((string)$this->request->getParam('schema', ''));
		$periods = [];
		foreach (['period1', 'period2'] as $name) {
			$periods[$name] = $this->periodParam(name: $name);
		}

		if ($register === '' || $schema === '' || in_array(null, $periods, true) === true) {
			return new JSONResponse(
				['error' => '`register`, `schema`, `period1.from`, `period1.to`, `period2.from` and `period2.to` are required'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$entries = $this->reportEntries(metrics: null);
		$names = AggregateReportEnvelope::metricNames(metrics: $entries);
		$column = (string)reset($names);
		$run = new AggregationRun(register: $register, schema: $schema);
		try {
			$filter = $this->resolveFilter(run: $run, action: 'compare');
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}

		$out = [];
		foreach ($periods as $name => $period) {
			try {
				$query = AggregationQuery::create(
					metric: $entries[0]['metric'],
					field: $entries[0]['field'],
					filter: $this->withCreatedRange(filter: $filter, from: $period['from'], to: $period['to'])
				);
			} catch (InvalidArgumentException $e) {
				return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
			}

			$result = $this->runReport(run: $run, query: $query);
			if ($result instanceof JSONResponse) {
				return $result;
			}

			$out[$name] = [
				'label' => AggregateReportPeriods::periodLabel(from: $period['from'], to: $period['to']),
				'from' => $period['from'],
				'to' => $period['to'],
				$column => ($result['value'] ?? null),
			];
		}//end foreach

		$out['change'] = AggregateReportPeriods::change(current: $out['period1'][$column], previous: $out['period2'][$column]);
		$out['metric'] = $entries[0]['metric'];
		$out['field'] = $this->reportField(entries: $entries);

		return new JSONResponse($out);
	}//end compare()

	/**
	 * One period's bounds from `<name>_from`/`<name>_to` or `<name>[from]`/`<name>[to]`.
	 *
	 * @param string $name `period1` or `period2`.
	 *
	 * @return array{from: string, to: string}|null Null when a bound is missing.
	 */
	private function periodParam(string $name): ?array {
		$nested = $this->request->getParam($name);
		$bounds = [];
		foreach (['from', 'to'] as $bound) {
			$value = $this->request->getParam($name . '_' . $bound);
			if (is_array($nested) === true && isset($nested[$bound]) === true) {
				$value = $nested[$bound];
			}

			if (is_string($value) === false || trim($value) === '') {
				return null;
			}

			$bounds[$bound] = trim($value);
		}

		return $bounds;
	}//end periodParam()
}//end class
