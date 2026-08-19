<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller\AppHost\Controller;

/**
 * Reached only through a NAMESPACED route name. `read` without -r flattens
 * `AppHost\Controller\GenericLiveness` to `AppHostControllerGenericLiveness`,
 * whose file cannot exist, and gate-30 then skipped this endpoint silently —
 * the same corruption #217 fixed in gates 5 and 14 while gate-30 kept the
 * defective line.
 *
 * No stated posture, so once it is READ AT ALL it must be a finding.
 */
class GenericLivenessController extends Controller
{
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);
    }
}
