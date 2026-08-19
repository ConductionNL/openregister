<?php
// SPDX-License-Identifier: EUPL-1.2

namespace OCA\Fixture\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;

/**
 * ANTI-DEAD-GATE CONTROL.
 *
 * This app adopts AppHost exactly like the `apphost` fixture next door, and
 * additionally ships one genuinely unguarded routed method. If teaching the
 * gate about AppHost ever degrades into "AppHost apps are exempt", THIS
 * fixture goes green and the suite fails — which is the point of it.
 */
class WidgetController extends Controller
{
    public function show(string $id): JSONResponse
    {
        return new JSONResponse(['id' => $id]);
    }
}
