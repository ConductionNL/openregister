<?php

/**
 * MapsOverviewController — register / query a page-level multi-object MAP
 * render surface, the HTTP surface a leaf app (procest "cases on map",
 * issue #112) calls to feed a dashboard map widget.
 *
 * Endpoints:
 *   POST /api/integrations/maps/overviews                         — declare/refresh a map overview widget
 *   GET  /api/integrations/maps/overviews/{register}/{schema}/points — query the RBAC-scoped marker point set
 *
 * Both endpoints require an authenticated user (`#[NoAdminRequired]`).
 * The marker query is RBAC-scoped INSIDE {@see MapsOverviewService::queryPoints()},
 * which runs the canonical OpenRegister read path with `_rbac: true` for
 * non-admins — an anonymous-equivalent / low-privilege caller only ever
 * sees objects the public group may read (ADR-005, fail-closed). No object
 * the caller cannot read can leak as a marker, and the endpoint returns a
 * uniform empty point set rather than an existence oracle.
 *
 * Additive: brand-new routes + controller. The per-object MapsProvider
 * sidebar surface is untouched.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-maps-overview-page-surface/specs/integration-maps-overview/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\MapsOverviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Page-level multi-object map overview controller.
 */
class MapsOverviewController extends Controller
{
    /**
     * Constructor.
     *
     * @param string              $appName         App name (injected by NC).
     * @param IRequest            $request         Current request.
     * @param MapsOverviewService $overviewService Map overview register/query service.
     *
     * @return void
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private MapsOverviewService $overviewService,
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * POST /api/integrations/maps/overviews
     *
     * Declare or refresh a page-level map overview widget. Body:
     *   - overviewKey (string, required)
     *   - register    (string|int, required)
     *   - schema      (string|int, required)
     *   - title       (string, optional)
     *   - geoProperty (string, optional)
     *   - filters     (array, optional)
     *   - baseLayer   (array, optional — declarative base-layer override)
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The stored widget render contract, or 400.
     *
     * @spec openspec/changes/integration-maps-overview-page-surface/specs/integration-maps-overview/spec.md
     */
    #[NoAdminRequired]
    public function register(): JSONResponse
    {
        $params = $this->request->getParams();

        try {
            // Authz guard (visible at the endpoint boundary): declaring a
            // map overview is a write — it requires an authenticated user.
            $this->overviewService->ensureCanRegister();

            $title = null;
            if (isset($params['title']) === true) {
                $title = (string) $params['title'];
            }

            $geoProperty = null;
            if (isset($params['geoProperty']) === true) {
                $geoProperty = (string) $params['geoProperty'];
            }

            $baseLayer = null;
            if (isset($params['baseLayer']) === true) {
                $baseLayer = (array) $params['baseLayer'];
            }

            $stored = $this->overviewService->registerOverview(
                overviewKey: (string) ($params['overviewKey'] ?? ''),
                register: (string) ($params['register'] ?? ''),
                schema: (string) ($params['schema'] ?? ''),
                title: $title,
                geoProperty: $geoProperty,
                filters: (array) ($params['filters'] ?? []),
                baseLayer: $baseLayer
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }//end try

        return new JSONResponse($stored, Http::STATUS_CREATED);
    }//end register()

    /**
     * GET /api/integrations/maps/overviews/{register}/{schema}/points
     *
     * Query the marker point set for a register/schema, RBAC-scoped.
     * Returns `[{id,label,lat,lng,register,schema,geometry}]` for every
     * object the current user may read that carries a geometry. The read is
     * RBAC-scoped inside the service (fail-closed): objects the caller
     * cannot read never appear, and the response is a uniform point list
     * (empty when nothing is readable) rather than an enumeration oracle.
     *
     * @param string $register Register slug or id to query.
     * @param string $schema   Schema slug or id to query.
     *
     * @NoAdminRequired
     *
     * @return JSONResponse The RBAC-scoped marker point set, or 400.
     *
     * @spec openspec/changes/integration-maps-overview-page-surface/specs/integration-maps-overview/spec.md
     */
    #[NoAdminRequired]
    public function points(string $register, string $schema): JSONResponse
    {
        $params = $this->request->getParams();

        try {
            // Authz guard (visible at the endpoint boundary): the point
            // query is RBAC-scoped inside the service — it runs the
            // canonical OR read path with _rbac:true for non-admins, so a
            // caller only ever sees objects they may read (fail-closed).
            $geoProperty = null;
            if (isset($params['geoProperty']) === true) {
                $geoProperty = (string) $params['geoProperty'];
            }

            $filters = (array) ($params['filters'] ?? $this->nonReservedParams(params: $params));
            $limit   = $this->intOrNull(value: ($params['limit'] ?? null));

            $points = $this->overviewService->ensureReadablePoints(
                register: $register,
                schema: $schema,
                filters: $filters,
                geoProperty: $geoProperty,
                limit: $limit
            );
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }//end try

        return new JSONResponse(['points' => $points, 'count' => count($points)]);
    }//end points()

    /**
     * Strip framework / reserved params, leaving the object filter set.
     *
     * When no explicit `filters` object is posted, treat the remaining
     * query params (minus route + control keys) as object filters.
     *
     * @param array<string,mixed> $params The raw request params.
     *
     * @return array<string,mixed> The object filter params.
     */
    private function nonReservedParams(array $params): array
    {
        $reserved = ['register', 'schema', 'geoProperty', 'limit', 'filters', '_route'];
        $filters  = [];
        foreach ($params as $key => $value) {
            if (in_array($key, $reserved, true) === true) {
                continue;
            }

            $filters[$key] = $value;
        }

        return $filters;
    }//end nonReservedParams()

    /**
     * Coerce a request param to an int, or null when absent / non-numeric.
     *
     * @param mixed $value The raw param.
     *
     * @return int|null
     */
    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || is_numeric($value) === false) {
            return null;
        }

        return (int) $value;
    }//end intOrNull()
}//end class
