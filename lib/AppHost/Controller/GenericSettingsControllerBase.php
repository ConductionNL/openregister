<?php

/**
 * OpenRegister AppHost — Generic Settings Controller Base
 *
 * Abstract controller base publishing the canonical ADR-066 settings dialect —
 * `index` (read merged config), `update` (write), `load` (import register
 * configuration with a boolean `force` parameter) — over the
 * {@see \OCA\OpenRegister\AppHost\Service\GenericSettingsService}. A leaf app
 * subclasses this in a few lines:
 *
 *   class SettingsController extends GenericSettingsControllerBase
 *   {
 *       public function __construct(IRequest $request, GenericSettingsService $settingsService, LoggerInterface $logger)
 *       {
 *           parent::__construct(Application::APP_ID, $request, $settingsService, $logger);
 *       }
 *   }
 *
 * A SettingsController with more than this canonical surface is
 * review-blocking (ADR-066): stats, import/export, email, SSE live in their
 * own controllers.
 *
 * ## Auth posture (ADR-005)
 *
 *   - `index()` is `#[NoAdminRequired]` (matches the existing AppHost
 *     `GenericSettingsController` posture) BUT strips admin-sensitive fields
 *     (the register binding) for non-admin callers, so the register UUID is
 *     never exposed to regular authenticated users (IDOR-safe).
 *   - `update()` / `load()` carry `#[AuthorizedAdminSetting]` bound to the
 *     AppHost {@see \OCA\OpenRegister\AppHost\Settings\GenericAdminSettings}
 *     panel: full admins always pass; users delegated for that panel class
 *     pass; everyone else is rejected by SecurityMiddleware (fail-closed). A
 *     leaf app whose delegation rows reference its own aliased AdminSettings
 *     class name overrides the method and re-declares the attribute with its
 *     own class.
 *
 * ## Error contract (ADR-049 / ADR-050 / ADR-051)
 *
 * All service failures are translated through the hardened
 * {@see \OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait}:
 * foundation-missing → explicit 503 `{message, error}` (never a silent null
 * or empty-success), unexpected throwables → generic 500 with the detailed
 * message written to the server log only.
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

use OCA\OpenRegister\AppHost\Service\GenericSettingsService;
use OCA\OpenRegister\AppHost\Settings\GenericAdminSettings;
use OCA\OpenRegister\Controller\Trait\HandlesExceptionsTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Abstract base for the canonical index/update/load settings surface.
 *
 * @psalm-suppress UnusedClass Subclassed by leaf apps (adoption waves per ADR-066).
 *
 * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Generic settings surface
 */
abstract class GenericSettingsControllerBase extends Controller
{
    use HandlesExceptionsTrait;

    /**
     * Constructor.
     *
     * @param string                 $appName         The calling (leaf) app id.
     * @param IRequest               $request         HTTP request.
     * @param GenericSettingsService $settingsService Generic settings service bound to this app.
     * @param LoggerInterface        $logger          PSR logger (leak-safe error logging).
     */
    public function __construct(
        string $appName,
        IRequest $request,
        protected readonly GenericSettingsService $settingsService,
        protected readonly LoggerInterface $logger
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Read the merged configuration (flat ADR-050 payload).
     *
     * Admin-sensitive fields (the register binding) are stripped for non-admin
     * users so the register UUID is not exposed to regular authenticated users.
     *
     * @return JSONResponse The current settings map.
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
     *   — Requirement: Generic settings surface (Scenario: Canonical settings round-trip)
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        try {
            $settings = $this->settingsService->getSettings();
        } catch (Throwable $e) {
            return $this->handleApiException(e: $e, context: 'Failed to read settings');
        }

        $isAdmin = ($settings['isAdmin'] ?? false);
        if ($isAdmin === false) {
            unset($settings['register']);
        }

        return new JSONResponse($settings);
    }//end index()

    /**
     * Write the managed settings keys and return the refreshed settings.
     *
     * @return JSONResponse The refreshed settings map (flat ADR-050 payload).
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
     *   — Requirement: Generic settings surface (Scenario: Canonical settings round-trip)
     */
    #[AuthorizedAdminSetting(settings: GenericAdminSettings::class)]
    public function update(): JSONResponse
    {
        try {
            $settings = $this->settingsService->updateSettings($this->request->getParams());
        } catch (Throwable $e) {
            return $this->handleApiException(e: $e, context: 'Failed to update settings');
        }

        return new JSONResponse($settings);
    }//end update()

    /**
     * Import the app's register configuration (with `force` flag).
     *
     * Foundation-missing surfaces as an explicit 503 `{message, error}` via
     * the typed exception translation — never a silent empty-success.
     *
     * @param bool $force Force re-import even when the stored version matches (default true — matches the fleet's `settings#load` behaviour).
     *
     * @return JSONResponse Import result with `success`, `message`, and `version`.
     *
     * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The `force` flag is the API-level
     * disposition mandated by the spec ("import register config with a boolean force parameter").
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md
     *   — Requirement: Generic settings surface (Scenario: Foundation missing is explicit)
     */
    #[AuthorizedAdminSetting(settings: GenericAdminSettings::class)]
    public function load(bool $force=true): JSONResponse
    {
        try {
            $result = $this->settingsService->loadConfiguration(force: $force);
        } catch (Throwable $e) {
            return $this->handleApiException(e: $e, context: 'Failed to load register configuration');
        }

        return new JSONResponse($result);
    }//end load()

    /**
     * Legacy alias for {@see update()} — the canonical AppHost route table
     * ({@see \OCA\OpenRegister\AppHost\Routes}) still ships `settings#create`
     * (POST /api/settings) for backwards compatibility with the fleet's
     * pre-ADR-066 `index/create/load` dialect, so a subclass of this base must
     * stay reachable on that route (ADR-029).
     *
     * @return JSONResponse The refreshed settings map.
     *
     * @spec openspec/changes/apphost-settings-plane/specs/apphost-settings-plane/spec.md — Requirement: Generic settings surface
     */
    #[AuthorizedAdminSetting(settings: GenericAdminSettings::class)]
    public function create(): JSONResponse
    {
        return $this->update();
    }//end create()
}//end class
