<?php
// SPDX-License-Identifier: EUPL-1.2
declare(strict_types=1);

namespace OCA\Fixture\Controller;

/**
 * The leaf ships its OWN dashboard controller — the supported shape;
 * Bootstrap::aliasControllerUnlessLeafDefinesIt() exists precisely to allow
 * it. Its two routes are supplied by Routes::standard(), so neither name
 * appears in appinfo/routes.php.
 */
class DashboardController extends Controller
{
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        return new TemplateResponse('fixture', 'index');
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse
    {
        return new TemplateResponse('fixture', 'index');
    }
}
