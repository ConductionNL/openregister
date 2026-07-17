<?php

/**
 * OpenRegister AppHost — Generic Preferences Controller
 *
 * Engine-owned, parameterised per-user preferences controller. A leaf app
 * aliases its conventional `OCA\{App}\Controller\PreferencesController` at this
 * class (via {@see \OCA\OpenRegister\AppHost\Bootstrap::register()}); the
 * controller then reads/writes small per-user key/value UI flags backed by
 * Nextcloud `IConfig` user values, scoped to the CALLING (leaf) app id.
 *
 * Behavioural parity with the ~15 bespoke per-app `PreferencesController`
 * copies (pipelinq, decidesk, procest, opencatalogi, …):
 *   - `getPreference($key)`  → `{value: string|null}` for the current user.
 *   - `setPreference($key, $value='')` → store, or delete when `$value === ''`.
 *   - keys are sanitised to `[a-z0-9-]{0,64}` and stored under the `pref_`
 *     namespace, so a caller can never reach arbitrary IConfig user values.
 *
 * The controller's `$appName` is the leaf app id, supplied by the leaf's alias
 * registration closure, so every user value is read/written under the leaf's
 * own app namespace — never OpenRegister's.
 *
 * ## Authorization (ADR-005)
 *
 * Both endpoints are `#[NoAdminRequired]` (any authenticated user) but strictly
 * user-scoped: the userId is always taken from the active session
 * (`IUserSession::getUser()`), never from a request parameter. A user can
 * therefore only read or write their OWN preferences — there is no object id or
 * userId input, so no IDOR surface. Anonymous requests are rejected with 401.
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
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Generic per-user preferences controller for AppHost-adopting apps.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/apphost-generic-preferences/tasks.md#task-1.1
 * @spec openspec/changes/apphost-generic-preferences/specs/apphost-boilerplate/spec.md — Requirement: Generic Preferences Controller
 */
class GenericPreferencesController extends Controller
{
    /**
     * Constructor.
     *
     * @param string       $appName     The calling (leaf) app id, supplied by the alias closure.
     * @param IRequest     $request     HTTP request.
     * @param IConfig      $config      The Nextcloud config (user values).
     * @param IUserSession $userSession The user session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly IConfig $config,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Read a per-user preference value for the current user.
     *
     * @param string $key The preference key (kebab/alphanumeric).
     *
     * @return JSONResponse `{value: string|null}`, 401 when anonymous, 400 on an invalid key.
     *
     * @spec openspec/changes/apphost-generic-preferences/specs/apphost-boilerplate/spec.md — Requirement: Generic Preferences Controller
     */
    #[NoAdminRequired]
    public function getPreference(string $key): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $safeKey = $this->sanitizeKey(key: $key);
        if ($safeKey === '') {
            return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        $value = $this->config->getUserValue(
            userId: $user->getUID(),
            appName: $this->appName,
            key: 'pref_'.$safeKey,
            default: ''
        );

        $stored = null;
        if ($value !== '') {
            $stored = $value;
        }

        return new JSONResponse(data: ['value' => $stored]);
    }//end getPreference()

    /**
     * Write a per-user preference value for the current user. An empty value clears it.
     *
     * @param string $key   The preference key (kebab/alphanumeric).
     * @param string $value The value to store (empty string clears it).
     *
     * @return JSONResponse `{value: string|null}`, 401 when anonymous, 400 on an invalid key.
     *
     * @spec openspec/changes/apphost-generic-preferences/specs/apphost-boilerplate/spec.md — Requirement: Generic Preferences Controller
     */
    #[NoAdminRequired]
    public function setPreference(string $key, string $value=''): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
        }

        $safeKey = $this->sanitizeKey(key: $key);
        if ($safeKey === '') {
            return new JSONResponse(data: ['message' => 'Invalid key'], statusCode: Http::STATUS_BAD_REQUEST);
        }

        if ($value === '') {
            $this->config->deleteUserValue(
                userId: $user->getUID(),
                appName: $this->appName,
                key: 'pref_'.$safeKey
            );

            return new JSONResponse(data: ['value' => null]);
        }

        $this->config->setUserValue(
            userId: $user->getUID(),
            appName: $this->appName,
            key: 'pref_'.$safeKey,
            value: $value
        );

        return new JSONResponse(data: ['value' => $value]);
    }//end setPreference()

    /**
     * Restrict keys to a safe charset so callers cannot reach arbitrary
     * IConfig user values outside the `pref_` namespace.
     *
     * @param string $key The raw key.
     *
     * @return string The sanitised key, or '' when nothing safe remains.
     */
    private function sanitizeKey(string $key): string
    {
        $safe = preg_replace(pattern: '/[^a-z0-9-]/', replacement: '', subject: strtolower($key));
        return substr((string) $safe, offset: 0, length: 64);
    }//end sanitizeKey()
}//end class
