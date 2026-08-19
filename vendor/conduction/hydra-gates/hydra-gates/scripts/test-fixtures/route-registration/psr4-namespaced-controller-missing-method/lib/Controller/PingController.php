<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

// The CONVENTIONAL spelling, in the same repo. Its route name has no backslash,
// so it resolves under lib/Controller/ — the anti-widening control for the
// namespaced case next door.
class PingController extends Controller
{
    #[PublicPage]
    public function index(): JSONResponse
    {
        return new JSONResponse(['pong' => true]);
    }
}
