<?php

/**
 * NotificationPreferencesController.
 *
 * REST surface for the per-user, override-only notification preferences
 * consumed by the user-settings preferences pane. Rules and their on/off
 * defaults live in the schema annotation `x-openregister-notifications`;
 * these endpoints only read the EFFECTIVE merge and write/clear a single
 * `(schema, notification)` override in Nextcloud per-user app config.
 *
 *   GET /api/notification-preferences
 *       → every notification the current user's accessible schemas declare,
 *         merged with that user's overrides, tagged by source. Each entry
 *         also carries `application` (the owning app id, e.g. "pipelinq",
 *         or null when the schema has no known owning app) so a consuming
 *         settings UI can scope the list to the currently open app.
 *
 *   PUT /api/notification-preferences
 *       body: { schema, notification, enabled?, channels?, reset? }
 *       → record (or, with `reset: true`, clear) one override for the
 *         current user only.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class NotificationPreferencesController extends Controller
{
    /**
     * Constructor.
     *
     * @param string                        $appName           App name.
     * @param IRequest                      $request           Request.
     * @param NotificationPreferenceService $preferenceService Override-only preference resolver.
     * @param IUserSession                  $userSession       Current-user session.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly NotificationPreferenceService $preferenceService,
        private readonly IUserSession $userSession
    ) {
        parent::__construct(appName: $appName, request: $request);
    }//end __construct()

    /**
     * Return the effective notifications (schema default ⊕ user override)
     * for the current user.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function index(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
        }

        $items = $this->preferenceService->getEffectiveForUser(userId: $userId);
        return new JSONResponse(data: ['results' => $items, 'total' => count($items)]);
    }//end index()

    /**
     * Record or clear a single `(schema, notification)` override for the
     * current user only.
     *
     * Body: `{ schema, notification, enabled?, channels?, reset? }`.
     * `reset: true` removes the override so the schema default applies.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function update(): JSONResponse
    {
        $userId = $this->resolveUserId();
        if ($userId === null) {
            return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
        }

        $params = $this->request->getParams();

        $schema       = $this->nonEmptyString(value: ($params['schema'] ?? null));
        $notification = $this->nonEmptyString(value: ($params['notification'] ?? null));
        if ($schema === null || $notification === null) {
            return new JSONResponse(
                data: ['error' => 'Both "schema" and "notification" are required'],
                statusCode: 422
            );
        }

        // Clearing the override restores the schema default.
        if (($params['reset'] ?? false) === true || ($params['reset'] ?? null) === 'true') {
            $this->preferenceService->setOverride(
                userId: $userId,
                schemaSlug: $schema,
                notificationKey: $notification,
                override: null
            );
            return new JSONResponse(
                data: ['schema' => $schema, 'notification' => $notification, 'override' => null]
            );
        }

        $override = ['enabled' => (bool) ($params['enabled'] ?? true)];
        if (isset($params['channels']) === true && is_array($params['channels']) === true) {
            $override['channels'] = $params['channels'];
        }

        $this->preferenceService->setOverride(
            userId: $userId,
            schemaSlug: $schema,
            notificationKey: $notification,
            override: $override
        );

        return new JSONResponse(
            data: [
                'schema'       => $schema,
                'notification' => $notification,
                'override'     => $this->preferenceService->getOverride(
                    userId: $userId,
                    schemaSlug: $schema,
                    notificationKey: $notification
                ),
            ]
        );
    }//end update()

    /**
     * Resolve the current user's UID, or null when anonymous.
     *
     * @return string|null
     */
    private function resolveUserId(): ?string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }

        return $user->getUID();
    }//end resolveUserId()

    /**
     * Coerce a request value to a non-empty string, or null.
     *
     * @param mixed $value Input.
     *
     * @return string|null
     */
    private function nonEmptyString(mixed $value): ?string
    {
        if (is_string($value) === false || $value === '') {
            return null;
        }

        return $value;
    }//end nonEmptyString()
}//end class
