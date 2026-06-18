<?php

/**
 * OpenRegister AppHost — Generic Settings Controller
 *
 * Engine-owned settings API (index/create/load) delegating to
 * {@see \OCA\OpenRegister\AppHost\Service\AppHostSettingsService}. A leaf app
 * aliases its conventional `OCA\{App}\Controller\SettingsController` here.
 *
 * Auth posture is owned here and preserves the bespoke fleet behaviour exactly:
 *   - `index()` is `#[NoAdminRequired]` BUT strips admin-sensitive fields
 *     (the register binding) for non-admin callers, so the register UUID is
 *     never exposed to regular authenticated users (ADR-005 / IDOR-safe).
 *   - `create()` and `load()` carry NO no-admin attribute → Nextcloud's
 *     default full-admin requirement applies; only admins may mutate config
 *     or trigger a (re)import.
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

use OCA\OpenRegister\AppHost\Service\AppHostSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Generic settings controller for AppHost-adopting apps.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-1.3
 */
class GenericSettingsController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                 $appName         The calling (leaf) app id.
     * @param IRequest               $request         HTTP request.
     * @param AppHostSettingsService $settingsService Generic settings service bound to this app.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly AppHostSettingsService $settingsService
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Retrieve all current settings.
     *
     * Admin-sensitive fields (the register binding) are stripped for non-admin
     * users so the register UUID is not exposed to regular authenticated users.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-1.3
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $settings = $this->settingsService->getSettings();
        $isAdmin  = ($settings['isAdmin'] ?? false);

        if ($isAdmin === false) {
            unset($settings['register']);
        }

        return new JSONResponse($settings);
    }//end index()

    /**
     * Update settings with the provided data. Full admin required (no attribute).
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-1.3
     */
    public function create(): JSONResponse
    {
        $data   = $this->request->getParams();
        $config = $this->settingsService->updateSettings($data);

        return new JSONResponse(
            [
                'success' => true,
                'config'  => $config,
            ]
        );
    }//end create()

    /**
     * Re-import the configuration from the app's register JSON. Full admin
     * required (no attribute). Forces a fresh import regardless of version.
     *
     * @return JSONResponse
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-1.3
     */
    public function load(): JSONResponse
    {
        $result = $this->settingsService->loadConfiguration(force: true);

        return new JSONResponse($result);
    }//end load()
}//end class
