<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

/**
 * Fixture controller with a REAL IDOR shape: `update` takes an object id
 * straight from the request and writes it, with no auth attribute at all.
 */
class WidgetController extends Controller
{
    /**
     * Guarded — the control half of the pair. Must NOT be reported.
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }

    /**
     * UNGUARDED — no attribute, arbitrary id, write. Must be reported.
     */
    public function update(string $id, array $data): JSONResponse
    {
        return new JSONResponse(['id' => $id, 'data' => $data]);
    }
}
