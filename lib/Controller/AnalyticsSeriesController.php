<?php

/**
 * AnalyticsSeriesController — register / fetch page-level pre-computed
 * chart series, the HTTP surface a leaf app calls to feed a dashboard
 * chart widget.
 *
 * Endpoints:
 *   POST /api/integrations/analytics/series            — register/refresh a series
 *   GET  /api/integrations/analytics/series/{seriesKey} — fetch a series (RBAC-scoped)
 *
 * Both endpoints require an authenticated user (`#[NoAdminRequired]`).
 * Fine-grained read authorisation (visibility tiers) is enforced inside
 * {@see AnalyticsSeriesService::fetch()}, which returns null for a
 * disallowed read so the controller answers a uniform 404 — no series
 * enumeration oracle (ADR-005, fail-closed).
 *
 * Additive: brand-new routes + controller. No existing endpoint changes.
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
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\AnalyticsSeriesService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Page-level analytics series controller.
 */
class AnalyticsSeriesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name (injected by NC).
	 * @param IRequest $request Current request.
	 * @param AnalyticsSeriesService $seriesService Series register/fetch service.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private AnalyticsSeriesService $seriesService,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * POST /api/integrations/analytics/series
	 *
	 * Register or refresh a page-level series. Body:
	 *   - seriesKey  (string, required)
	 *   - labels     (array)
	 *   - datasets   (array)
	 *   - title      (string, optional)
	 *   - chartType  (string, optional, default 'line')
	 *   - visibility (string, optional, default 'private')
	 *   - registerId (int, optional)
	 *   - schemaId   (int, optional)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The stored series render contract, or 400.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	#[NoAdminRequired]
	public function register(): JSONResponse {
		$params = $this->request->getParams();

		try {
			// Authz guard (visible at the endpoint boundary): registering
			// a series is a write — it requires an authenticated user.
			$this->seriesService->ensureCanRegister();

			$title = null;
			if (isset($params['title']) === true) {
				$title = (string)$params['title'];
			}

			$stored = $this->seriesService->register(
				seriesKey: (string)($params['seriesKey'] ?? ''),
				labels: (array)($params['labels'] ?? []),
				datasets: (array)($params['datasets'] ?? []),
				title: $title,
				chartType: (string)($params['chartType'] ?? 'line'),
				visibility: (string)($params['visibility'] ?? 'private'),
				registerId: $this->intOrNull(value: ($params['registerId'] ?? null)),
				schemaId: $this->intOrNull(value: ($params['schemaId'] ?? null))
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}//end try

		return new JSONResponse($stored, Http::STATUS_CREATED);
	}//end register()

	/**
	 * GET /api/integrations/analytics/series/{seriesKey}
	 *
	 * Fetch a registered series, RBAC-scoped. Returns 404 when unknown
	 * or not readable by the current user.
	 *
	 * @param string $seriesKey The series key.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse The series render contract, or 404.
	 *
	 * @spec openspec/specs/integration-leaf-foundation/spec.md
	 */
	#[NoAdminRequired]
	public function fetch(string $seriesKey): JSONResponse {
		// Authz guard (visible at the endpoint boundary): the read is
		// RBAC-scoped inside the service; a disallowed/unknown read
		// returns null → uniform 404 (no enumeration oracle).
		$series = $this->seriesService->ensureReadableOrNull($seriesKey);
		if ($series === null) {
			return new JSONResponse(['message' => 'Not Found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($series);
	}//end fetch()

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
