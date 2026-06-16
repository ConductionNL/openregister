<?php

/**
 * OpenRegister AppHost — Generic Metrics Controller
 *
 * Engine-owned admin-only Prometheus metrics endpoint. Leaf apps alias their
 * existing `/api/metrics` route at this controller (keeping their URL
 * unchanged); the controller resolves the calling app's manifest, renders its
 * declarative + implicit metrics through the MetricsEngine, and returns
 * `text/plain; version=0.0.4`. The admin-only posture and exposition format
 * are owned here (no `#[NoAdminRequired]`) so leaf apps cannot expose metrics
 * publicly — fixing nldesign's public-metrics + shillinq's JSON drift on
 * adoption (ADR-006 / ADR-040).
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
use OCA\OpenRegister\AppHost\Observability\MetricsEngine;
use OCA\OpenRegister\AppHost\Observability\PrometheusRenderer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\IRequest;

/**
 * Admin-only declarative Prometheus metrics endpoint for AppHost adopters.
 *
 * No `#[NoAdminRequired]` — the absence of that attribute means NC requires an
 * admin session, which is the intended ADR-006 posture for metrics. Anonymous
 * callers get the NC login redirect / 401, never metric data.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/apphost-observability-engine/tasks.md#task-3.8
 */
class GenericMetricsController extends Controller
{
    /**
     * Constructor.
     *
     * The controller's `$appName` is the calling (leaf) app id, set by the
     * leaf app's alias registration in its own Application.php. For OpenRegister
     * self-dogfooding it is `openregister`.
     *
     * @param string         $appName        Calling app id.
     * @param IRequest       $request        HTTP request.
     * @param ManifestLoader $manifestLoader Loads the app's observability config.
     * @param MetricsEngine  $engine         Renders the metrics.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly ManifestLoader $manifestLoader,
        private readonly MetricsEngine $engine
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
     *
     * @return TextPlainResponse Prometheus text exposition 0.0.4.
     *
     * @spec openspec/changes/apphost-observability-engine/specs/apphost-observability/spec.md — Requirement: Declarative Metrics Execution
     */
    #[NoCSRFRequired]
    public function index(): TextPlainResponse
    {
        $appId    = $this->appName;
        $manifest = $this->manifestLoader->load(appId: $appId);
        $body     = $this->engine->render(manifest: $manifest);

        $response = new TextPlainResponse($body);
        $response->addHeader('Content-Type', PrometheusRenderer::CONTENT_TYPE);
        return $response;
    }//end index()
}//end class
