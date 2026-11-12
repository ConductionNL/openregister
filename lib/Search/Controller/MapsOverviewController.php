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
 * @spec openspec/specs/integration-maps-overview/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Search\Service\MapMarkerSource;
use OCA\OpenRegister\Search\Service\MapsOverviewService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCA\OpenRegister\Search\Service\MapOverview;

/**
 * Page-level multi-object map overview controller.
 */
class MapsOverviewController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request Current request.
	 * @param MapsOverviewService $overviewService Map overview register/query service.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private MapsOverviewService $overviewService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	#[NoAdminRequired]
	public function register(): JSONResponse {
		$params = $this->request->getParams();

		try {
			// Authz guard (visible at the endpoint boundary): declaring a
			// map overview is a write — it requires an authenticated user.
			$this->overviewService->ensureCanRegister();

			$stored = $this->overviewService->declareOverview(overview: $this->overviewFrom(params: $params));
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
	 * @param string $schema Schema slug or id to query.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The RBAC-scoped marker point set, or 400.
	 *
	 * @spec openspec/specs/integration-maps-overview/spec.md
	 */
	#[NoAdminRequired]
	public function points(string $register, string $schema): JSONResponse {
		$params = $this->request->getParams();

		try {
			// Authz guard (visible at the endpoint boundary): the point
			// query is RBAC-scoped inside the service — it runs the
			// canonical OR read path with _rbac:true for non-admins, so a
			// caller only ever sees objects they may read (fail-closed).
			$geoProperty = $this->stringOrNull(params: $params, key: 'geoProperty');

			$filters = (array)($params['filters'] ?? $this->nonReservedParams(params: $params));
			$limit = $this->intOrNull(value: ($params['limit'] ?? null));

			$points = $this->overviewService->ensureReadablePoints(
				source: new MapMarkerSource(register: $register, schema: $schema, geoProperty: $geoProperty, filters: $filters),
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
	private function nonReservedParams(array $params): array {
		$reserved = ['register', 'schema', 'geoProperty', 'limit', 'filters', '_route'];
		$filters = [];
		foreach ($params as $key => $value) {
			if (in_array($key, $reserved, true) === true) {
				continue;
			}

			$filters[$key] = $value;
		}

		return $filters;
	}//end nonReservedParams()

	/**
	 * The map overview a register request body declares.
	 *
	 * @param array<string,mixed> $params The raw request params.
	 *
	 * @return MapOverview The key, marker source, title and base layer.
	 */
	private function overviewFrom(array $params): MapOverview {
		$title = $this->stringOrNull(params: $params, key: 'title');

		$baseLayer = null;
		if (isset($params['baseLayer']) === true) {
			$baseLayer = (array)$params['baseLayer'];
		}

		return new MapOverview(
			key: (string)($params['overviewKey'] ?? ''),
			source: $this->markerSourceFrom(params: $params),
			title: $title,
			baseLayer: $baseLayer
		);
	}//end overviewFrom()

	/**
	 * The marker source a register request body declares.
	 *
	 * @param array<string,mixed> $params The raw request params.
	 *
	 * @return MapMarkerSource The register, schema, geo property and filters.
	 */
	private function markerSourceFrom(array $params): MapMarkerSource {
		return new MapMarkerSource(
			register: (string)($params['register'] ?? ''),
			schema: (string)($params['schema'] ?? ''),
			geoProperty: $this->stringOrNull(params: $params, key: 'geoProperty'),
			filters: (array)($params['filters'] ?? [])
		);
	}//end markerSourceFrom()

	/**
	 * A request param as a string, or null when it is not set.
	 *
	 * @param array<string,mixed> $params The raw request params.
	 * @param string              $key    The param name.
	 *
	 * @return string|null
	 */
	private function stringOrNull(array $params, string $key): ?string {
		if (isset($params[$key]) === true) {
			return (string)$params[$key];
		}

		return null;
	}//end stringOrNull()

	/**
	 * Coerce a request param to an int, or null when absent / non-numeric.
	 *
	 * @param mixed $value The raw param.
	 *
	 * @return int|null
	 */
	private function intOrNull(mixed $value): ?int {
		if ($value === null || is_numeric($value) === false) {
			return null;
		}

		return (int)$value;
	}//end intOrNull()
}//end class
