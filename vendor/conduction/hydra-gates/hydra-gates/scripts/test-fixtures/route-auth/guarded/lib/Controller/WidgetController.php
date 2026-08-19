<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;

/**
 * Fixture controller — both routed methods declare their auth posture.
 */
class WidgetController extends Controller
{
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }

    #[NoAdminRequired]
    public function update(string $id, array $data): JSONResponse
    {
        return new JSONResponse(['id' => $id, 'data' => $data]);
    }
}
