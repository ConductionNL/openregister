<?php

/**
 * OpenRegister AppHost — Generic Metrics Controller
 *
 * Engine-owned Prometheus metrics endpoint. Leaf apps alias their existing
 * `/api/metrics` route at this controller (keeping their URL unchanged); the
 * controller resolves the calling app's manifest, renders its declarative +
 * implicit metrics through the MetricsEngine, and returns
 * `text/plain; version=0.0.4`. The access posture and exposition format are
 * owned here so leaf apps cannot expose metrics publicly — fixing nldesign's
 * public-metrics + shillinq's JSON drift on adoption (ADR-006 / ADR-040).
 * Admin-only by default; an admin may opt a scraper in with the app's
 * `metrics_api_token` or `metrics_allowed_ips` (MetricsAccessGuard).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Observability\MetricsAccessGuard;
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Declarative Prometheus metrics endpoint for AppHost adopters.
 *
 * The route is `#[PublicPage]` so a scraper without a Nextcloud session can be
 * admitted, and the access decision is made here instead, by the
 * MetricsAccessGuard: an admin session, the app's scraper token or an allowed
 * address range is served; everyone else gets 401 (anonymous) or 403, never
 * metric data. With nothing configured that is the ADR-006 admin-only posture.
 * Without a guard every request is refused.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.8
 */
class GenericMetricsController extends Controller {
	/**
	 * Constructor.
	 *
	 * The controller's `$appName` is the calling (leaf) app id, set by the
	 * leaf app's alias registration in its own Application.php. For OpenRegister
	 * self-dogfooding it is `openregister`.
	 *
	 * @param string $appName Calling app id.
	 * @param IRequest $request HTTP request.
	 * @param ManifestLoader $manifestLoader Loads the app's observability config.
	 * @param MetricsEngine $engine Renders the metrics.
	 * @param MetricsAccessGuard|null $accessGuard Decides who is served; without one nobody is.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ManifestLoader $manifestLoader,
		private readonly MetricsEngine $engine,
		private readonly ?MetricsAccessGuard $accessGuard = null,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * GET /api/metrics — declarative Prometheus metrics (admin, scraper token or allowed address).
	 *
	 * A wrong bearer token is throttled like a failed login.
	 *
	 * @return TextPlainResponse Prometheus text exposition 0.0.4, or a 401/403 without metric data.
	 *
	 * @spec openspec/specs/apphost-observability/spec.md — Requirement: Declarative Metrics Execution
	 * @spec openspec/changes/production-observability/specs/production-observability/spec.md#requirement-prometheus-metrics-endpoint
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 120, period: 60)]
	#[BruteForceProtection(action: 'apphost_metrics')]
	public function index(): TextPlainResponse {
		$status = Http::STATUS_FORBIDDEN;
		if ($this->accessGuard !== null) {
			$status = $this->accessGuard->decide(appId: $this->appName, request: $this->request);
		}

		if ($status !== Http::STATUS_OK) {
			return $this->refusal(status: $status);
		}

		$appId = $this->appName;
		$manifest = $this->manifestLoader->load(appId: $appId);
		$body = $this->engine->render(manifest: $manifest);

		$response = new TextPlainResponse($body);
		$response->addHeader('Content-Type', PrometheusRenderer::CONTENT_TYPE);
		return $response;
	}//end index()

	/**
	 * A refusal without metric data; throttled when it refuses a bearer token.
	 *
	 * @param int $status Http::STATUS_UNAUTHORIZED or Http::STATUS_FORBIDDEN.
	 *
	 * @return TextPlainResponse
	 */
	private function refusal(int $status): TextPlainResponse {
		$response = new TextPlainResponse('Forbidden', Http::STATUS_FORBIDDEN);
		if ($status === Http::STATUS_UNAUTHORIZED) {
			$response = new TextPlainResponse('Unauthorized', Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard !== null && $this->accessGuard->presentsToken(request: $this->request) === true) {
			$response->throttle(['action' => 'apphost_metrics']);
		}

		return $response;
	}//end refusal()
}//end class
