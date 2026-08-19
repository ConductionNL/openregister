<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

class GenericHealthController extends Controller
{
    /**
     * Health probe.
     *
     * Says NOTHING about its auth posture — neither #[PublicPage] nor
     * #[AuthorizedAdminSetting]. In Nextcloud the absence of an attribute IS
     * the admin gate, so the kubelet gets a redirect to /login. This is the
     * true positive the whole gate exists for, and it must fire.
     */
    #[NoCSRFRequired]
    public function index(): JSONResponse
    {
        return new JSONResponse(['status' => 'ok']);
    }
}
