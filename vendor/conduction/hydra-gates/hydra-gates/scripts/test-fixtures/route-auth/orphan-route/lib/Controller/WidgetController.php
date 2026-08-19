<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

/**
 * `ghost` is routed but does not exist here — the second reachability shape.
 */
class WidgetController extends Controller
{
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }
}
