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
 * gating + Postgres / fallback dispatch. The ad-hoc path does not
 * consult `AggregationCache` (its key shape is keyed on the named
 * annotation — extending it is tracked in issue #1610).
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
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-1
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-2
 * @spec openspec/changes/retrofit-2026-05-24-aggregations-backend-native/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
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

class AggregationController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                     $appName   The application name.
     * @param IRequest                   $request   The current request.
     * @param AggregationRunner          $runner    The aggregation runner.
     * @param TimeseriesRequestValidator $validator Ad-hoc request validator.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AggregationRunner $runner,
        private readonly TimeseriesRequestValidator $validator
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
     * @param string $schema   Schema reference.
     * @param string $name     Aggregation name.
     *
     * @return JSONResponse JSON response with aggregation result.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    public function aggregate(string $register, string $schema, string $name): JSONResponse
    {
        try {
            $result = $this->runner->run(registerRef: $register, schemaRef: $schema, name: $name);
        } catch (NotAuthorizedException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        $response    = new JSONResponse($result);
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
     * filter[...] (operator-aware). The literal `value` URL never collides with
     * the named `{name}` aggregate route.
     *
     * @param string $register Register reference.
     * @param string $schema   Schema reference.
     *
     * @return JSONResponse JSON response with the scalar aggregation value.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    public function value(string $register, string $schema): JSONResponse
    {
        $metric = (string) $this->request->getParam('metric', 'count');
        $field  = $this->request->getParam('field');
        $filter = (array) ($this->request->getParam('filter', []));

        try {
            $query = AggregationQuery::create(
                metric: $metric,
                field: ($field === '' ? null : $field),
                filter: $filter
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

        return new JSONResponse($result);

    }//end value()

    /**
     * Ad-hoc categorical group-by aggregation entry point.
     *
     * The non-time sibling of {@see timeseries()} — groups by a NON-date field
     * and returns `{ groups: [{ key, value }] }` for manifest-configured
     * category charts (nextcloud-vue CnChartWidget group-by source).
     *
     * Accepts: groupBy (required field), metric (default count), field
     * (required for non-count), sort (asc|desc), limit (top-N), filter[...].
     *
     * @param string $register Register reference.
     * @param string $schema   Schema reference.
     *
     * @return JSONResponse JSON response with `{ groups, backend, cached }`.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-24-annotate-openregister/tasks.md#task-19
     */
    public function grouped(string $register, string $schema): JSONResponse
    {
        $metric  = (string) $this->request->getParam('metric', 'count');
        $field   = $this->request->getParam('field');
        $groupBy = (string) $this->request->getParam('groupBy', '');
        $filter  = (array) ($this->request->getParam('filter', []));

        if ($groupBy === '') {
            return new JSONResponse(['error' => 'groupBy is required'], Http::STATUS_BAD_REQUEST);
        }

        $groupSpec = ['field' => $groupBy];
        $sort      = $this->request->getParam('sort');
        if ($sort === 'asc' || $sort === 'desc') {
            $groupSpec['sort'] = $sort;
        }

        $limit = $this->request->getParam('limit');
        if ($limit !== null && (string) $limit !== '' && (int) $limit > 0) {
            $groupSpec['limit'] = (int) $limit;
        }

        try {
            $query = AggregationQuery::create(
                metric: $metric,
                field: ($field === '' ? null : $field),
                filter: $filter,
                groupBy: $groupSpec
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

        return new JSONResponse($result);

    }//end grouped()

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
     *
     * Returns `{ groups: [{ key, value }], backend, cached }` matching the
     * GraphQL `groups` field shape so `CnChartWidget` can normalise once.
     *
     * @param string $register Register reference.
     * @param string $schema   Schema reference.
     *
     * @return JSONResponse JSON response with bucketed groups.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/retrofit-2026-05-25-bw2-ctrl-2/tasks.md#task-15
     */
    public function timeseries(string $register, string $schema): JSONResponse
    {
        // Resolve schema first so the validator can consult the
        // declared property list. A missing schema is a 404; a bad
        // query-param shape is a 400.
        try {
            $schemaEntity = $this->runner->findSchema(schemaRef: $schema);
        } catch (RuntimeException $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }

        // Pull the request shape from the active IRequest. The filter
        // map comes through as a nested array because PHP parses
        // `filter[x][op]=y` into `$_GET['filter']['x']['op']='y'`.
        $input = [
            'field'       => $this->request->getParam('field', ''),
            'interval'    => $this->request->getParam('interval'),
            'from'        => $this->request->getParam('from'),
            'to'          => $this->request->getParam('to'),
            'metric'      => $this->request->getParam('metric', 'count'),
            'metricField' => $this->request->getParam('metricField'),
            'filter'      => (array) ($this->request->getParam('filter', [])),
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

        return new JSONResponse($result);

    }//end timeseries()
}//end class
