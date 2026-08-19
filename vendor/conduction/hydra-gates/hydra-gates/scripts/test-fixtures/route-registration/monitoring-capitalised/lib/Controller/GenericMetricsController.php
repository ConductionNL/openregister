<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

class GenericMetricsController extends Controller
{
    /**
     * Prometheus exposition endpoint.
     *
     * ADR-006 makes /api/metrics admin-only ON PURPOSE, and the engine that
     * owns the decision states it in prose: this endpoint is admin-only.
     *
     * The docblock below is deliberately longer than twenty lines. The
     * posture sentence therefore sits OUTSIDE the fixed 20-line lookback
     * both gate-5 and gate-30 used to take, which is how a correctly
     * annotated method came to be reported as unannotated — measured on
     * openregister Settings\FileSettings#getFileExtractionStats, whose
     * @NoCSRFRequired sits 22 lines above its declaration.
     *
     * Series exposed:
     *  - fixture_objects_total
     *  - fixture_requests_total
     *  - fixture_request_duration_seconds
     *  - fixture_errors_total
     *  - fixture_cache_hits_total
     *  - fixture_cache_misses_total
     *  - fixture_queue_depth
     *  - fixture_worker_saturation
     *  - fixture_storage_bytes
     *  - fixture_index_lag_seconds
     *
     * @return JSONResponse
     */
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse([]);
    }
}
