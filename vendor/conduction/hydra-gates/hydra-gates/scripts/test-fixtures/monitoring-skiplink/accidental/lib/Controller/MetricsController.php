<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TextPlainResponse;

/**
 * THE CONTROL for the metrics carve-out.
 *
 * The `stated` fixture's shape with its posture sentence taken out. Nothing
 * here declares what the endpoint requires, which is precisely what a
 * forgotten attribute looks like, so it must still be reported.
 *
 * Nothing in this file names an attribute, in prose or otherwise — a fixture
 * that quotes the pattern it is meant to defeat defeats itself instead (the
 * gate-56 lesson).
 */
class MetricsController extends Controller
{
    /**
     * GET /api/metrics — declarative Prometheus metrics.
     *
     * @return TextPlainResponse Prometheus text exposition 0.0.4.
     */
    #[NoCSRFRequired]
    public function index(): TextPlainResponse
    {
        return new TextPlainResponse($this->engine->render());
    }
}
