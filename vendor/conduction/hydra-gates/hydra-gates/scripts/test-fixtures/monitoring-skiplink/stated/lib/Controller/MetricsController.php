<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;

/**
 * Copied in structure from openregister's engine-owned
 * GenericMetricsController, which is where ADR-006 puts the decision.
 */
class MetricsController extends Controller
{
    /**
     * GET /api/metrics — declarative Prometheus metrics (admin-only, ADR-006).
     *
     * Admin-only by the deliberate absence of `#[NoAdminRequired]`.
     *
     * @return TextPlainResponse Prometheus text exposition 0.0.4.
     */
    #[NoCSRFRequired]
    public function index(): TextPlainResponse
    {
        return new TextPlainResponse($this->engine->render());
    }
}
