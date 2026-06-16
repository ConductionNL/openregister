<?php

/**
 * OpenRegister AppHost — Generic Action Authorization Service
 *
 * Engine-owned generalisation of the per-app `ActionAuthService` implementing
 * the ADR-023 action-level authorization pattern: each controller method
 * declares a dot-separated action name (e.g. "item.publish") and delegates the
 * decision to this service, which resolves the action against an admin-managed
 * matrix stored in IAppConfig under the CALLING (leaf) app id.
 *
 * Per ADR-023:
 *   - Data RBAC (who reads/writes which objects) is OpenRegister's job.
 *   - Action RBAC (who may invoke which controller method) is this service.
 *   - Admin-only operations bypass this service via #[AuthorizedAdminSetting].
 *
 * Admin users always pass (break-glass). The matrix defaults to admin-only for
 * every declared action — first-install-safe / fail-closed posture.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\AppHost\Service
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

namespace OCA\OpenRegister\AppHost\Service;

use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUser;

/**
 * Generic action-level authorization service for AppHost-adopting apps.
 *
 * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
 */
class GenericActionAuthService
{
    /**
     * IAppConfig key holding the JSON-encoded matrix.
     */
    protected const CONFIG_KEY = 'actions';

    /**
     * Constructor.
     *
     * @param string        $appId        The calling (leaf) app id.
     * @param IAppConfig     $appConfig    App config store for the matrix.
     * @param IGroupManager  $groupManager Group manager for resolving user groups.
     */
    public function __construct(
        private readonly string $appId,
        private readonly IAppConfig $appConfig,
        private readonly IGroupManager $groupManager
    ) {
    }//end __construct()

    /**
     * Require that the user may perform the named action.
     *
     * Admin users always pass. Non-admins pass only when any of their groups
     * intersects the matrix entry. Fail-closed: an unknown action or an
     * admin-only entry denies non-admins.
     *
     * @param IUser  $user   The authenticated user.
     * @param string $action Dot-separated action name (e.g. "item.publish").
     *
     * @return void
     *
     * @throws OCSForbiddenException When the user's groups don't match.
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
     */
    public function requireAction(IUser $user, string $action): void
    {
        if ($this->groupManager->isAdmin($user->getUID()) === true) {
            return;
        }

        $allowedGroups = $this->getAllowedGroups(action: $action);

        if (count($allowedGroups) === 0 || $allowedGroups === ['admin']) {
            throw new OCSForbiddenException("Action '{$action}' requires admin rights");
        }

        $userGroups      = $this->groupManager->getUserGroupIds($user);
        $nonAdminAllowed = array_values(array_diff($allowedGroups, ['admin']));

        if (count(array_intersect($userGroups, $nonAdminAllowed)) === 0) {
            throw new OCSForbiddenException("Action '{$action}' not allowed for your groups");
        }
    }//end requireAction()

    /**
     * Check whether the user may perform the named action (non-throwing).
     *
     * @param IUser  $user   The authenticated user.
     * @param string $action Dot-separated action name.
     *
     * @return bool True if the user may perform the action.
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
     */
    public function can(IUser $user, string $action): bool
    {
        try {
            $this->requireAction(user: $user, action: $action);
            return true;
        } catch (OCSForbiddenException $e) {
            return false;
        }
    }//end can()

    /**
     * Groups allowed to perform the action, or ["admin"] when undeclared.
     *
     * @param string $action Dot-separated action name.
     *
     * @return array<int, string>
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
     */
    public function getAllowedGroups(string $action): array
    {
        $matrix = $this->getMatrix();
        return $matrix[$action] ?? ['admin'];
    }//end getAllowedGroups()

    /**
     * The full action-to-groups matrix. Missing/malformed config → empty
     * (default-deny: getAllowedGroups falls back to ["admin"]).
     *
     * @return array<string, array<int, string>>
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
     */
    public function getMatrix(): array
    {
        $json = $this->appConfig->getValueString($this->appId, static::CONFIG_KEY, '{}');

        try {
            $decoded = json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return [];
        }

        if (is_array($decoded) === false) {
            return [];
        }

        $matrix = [];
        foreach ($decoded as $action => $groups) {
            if (is_string($action) === false || is_array($groups) === false) {
                continue;
            }

            $clean = [];
            foreach ($groups as $group) {
                if (is_string($group) === true && $group !== '') {
                    $clean[] = $group;
                }
            }

            $matrix[$action] = array_values(array_unique($clean));
        }

        return $matrix;
    }//end getMatrix()

    /**
     * Write the full matrix. Caller MUST enforce admin-only before invoking;
     * this method does not gate writes (called from an admin-only endpoint).
     *
     * @param array<string, array<int, string>> $matrix The new matrix.
     *
     * @return void
     *
     * @throws \JsonException When the matrix cannot be encoded.
     *
     * @spec openspec/changes/apphost-boilerplate-controllers/tasks.md#task-2.2
     */
    public function setMatrix(array $matrix): void
    {
        $normalized = [];
        foreach ($matrix as $action => $groups) {
            $clean = [];
            foreach ($groups as $group) {
                if ($group !== '') {
                    $clean[] = $group;
                }
            }

            $normalized[$action] = array_values(array_unique($clean));
        }

        $json = json_encode($normalized, flags: JSON_THROW_ON_ERROR);
        $this->appConfig->setValueString($this->appId, static::CONFIG_KEY, $json);
    }//end setMatrix()

    /**
     * List all action keys currently in the matrix.
     *
     * @return array<int, string>
     */
    public function getActions(): array
    {
        return array_keys($this->getMatrix());
    }//end getActions()
}//end class
