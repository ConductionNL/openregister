<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

/**
 * THE ANTI-WIDENING HALF. An unparameterised GET on /api/health with no
 * stated posture — exactly what a kubelet calls, exactly what silently
 * redirects to /login. Sitting in the SAME fixture as the two shapes the
 * gate must now ignore, so "the shape filter switched the gate off" cannot
 * pass as "the false positives are gone".
 */
class HealthController extends Controller
{
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);
    }
}
