<?php

/**
 * OpenRegister AppHost — Generic Dashboard Controller
 *
 * Engine-owned SPA-serving controller. A leaf app aliases its conventional
 * `OCA\{App}\Controller\DashboardController` at this class (via
 * {@see \OCA\OpenRegister\AppHost\Bootstrap::register()}); the controller then
 * renders the leaf app's own `templates/index.php`, preserving the shared-vendor
 * → shared-nc-vue → main chunk-loading order that the template establishes.
 *
 * The controller's `$appName` is the CALLING (leaf) app id, supplied by the
 * leaf's alias registration closure, so `new TemplateResponse($appName, 'index')`
 * resolves the leaf's own template — never OpenRegister's.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;

/**
 * Generic SPA page + catch-all controller for AppHost-adopting apps.
 *
 * Behavioural parity with the bespoke per-app `DashboardController`:
 *   - `page()`   → `TemplateResponse({appId}, 'index')`
 *   - `catchAll()` → delegates to `page()` (Vue history-mode deep links).
 *
 * Auth posture (authenticated user, no CSRF token for the GET page) is owned
 * here and matches every bespoke copy; leaf apps cannot drift it.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-1.1
 * @spec openspec/changes/apphost-boilerplate-controllers/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
 */
class GenericDashboardController extends Controller
{
    /**
     * Constructor.
     *
     * @param string   $appName The calling (leaf) app id, supplied by the alias closure.
     * @param IRequest $request HTTP request.
     */
    public function __construct(
        string $appName,
        IRequest $request
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Render the main SPA page from the leaf app's `templates/index.php`.
     *
     * @return TemplateResponse The rendered template for the calling app.
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function page(): TemplateResponse
    {
        return $this->renderIndex();
    }//end page()

    /**
     * Serve the SPA for deep links (Vue history mode). Delegates to {@see page()}.
     *
     * @return TemplateResponse The rendered template for the calling app.
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/specs/apphost-boilerplate/spec.md — Requirement: Canonical Route Table
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(): TemplateResponse
    {
        return $this->page();
    }//end catchAll()

    /**
     * Build the `index` TemplateResponse for the calling app.
     *
     * Overridable hook: a leaf app needing extra initial-state or a non-default
     * template name aliases its DashboardController at a local subclass and
     * overrides this single method — all routing stays generic.
     *
     * @return TemplateResponse
     */
    protected function renderIndex(): TemplateResponse
    {
        return new TemplateResponse($this->appName, 'index');
    }//end renderIndex()
}//end class
