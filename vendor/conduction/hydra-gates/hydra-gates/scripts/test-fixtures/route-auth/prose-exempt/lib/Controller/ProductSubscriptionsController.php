<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Fixture for #196 — prose must not be an auth attribute.
 *
 * Both methods below are admin-only endpoints with NO auth attribute. They
 * differ only in what their docblocks SAY. Under the pre-fix gate that was
 * the whole difference between a pass and a finding.
 */
class ProductSubscriptionsController extends Controller
{
    /**
     * ADMIN ONLY: `#[NoAdminRequired]` is deliberately NOT used here, because
     * the absence of that attribute is what makes the endpoint admin-only.
     *
     * There is no attribute on this method. Before #196 the gate passed it
     * on this sentence alone.
     */
    public function subscribe(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }

    /**
     * Same posture, no such sentence. Reported before the fix and after it —
     * this is the control that proves the pair is decided by CODE now, not by
     * which docblock happens to name an attribute.
     */
    public function analytics(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }
}
