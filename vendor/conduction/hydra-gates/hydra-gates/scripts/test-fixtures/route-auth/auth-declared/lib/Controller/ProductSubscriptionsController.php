<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

/**
 * Fixture for #196 — the three ways a posture can be DECLARED.
 *
 * Note this class docblock deliberately mentions `#[NoAdminRequired]` in
 * prose, exactly like the ../prose-exempt fixture does. It must buy nothing
 * for any method here; each one has to declare its own posture.
 */
class ProductSubscriptionsController extends Controller
{
    /**
     * Admin-only, declared. The reason is required and must be long enough to
     * be a reason rather than a shrug.
     *
     * @auth admin-only writes billing state for every tenant; admin posture is the absence of NoAdminRequired
     */
    public function subscribe(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }

    /**
     * An ordinary attribute, in code position.
     */
    #[NoAdminRequired]
    public function analytics(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }

    /**
     * The legacy docblock form, at tag position. Still accepted.
     *
     * @NoAdminRequired
     */
    public function legacy(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }
}
