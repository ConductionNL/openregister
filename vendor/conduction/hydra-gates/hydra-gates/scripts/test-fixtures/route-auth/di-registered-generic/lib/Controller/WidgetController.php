<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;

/**
 * An ordinary, guarded controller. Present so this fixture's gate-5 verdict
 * is about the generic and the orphan route, not about a stray IDOR shape.
 */
class WidgetController extends Controller
{
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }
}
