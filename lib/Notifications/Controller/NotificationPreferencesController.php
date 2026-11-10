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
 *       optional query: ?scope=<scope>
 *       → every notification the current user's accessible schemas declare,
 *         merged over the three layers — schema default, group default, the
 *         user's own value — with `source` naming the layer that decided and
 *         `layers` carrying the trace behind it. Each entry
 *         also carries `application` (the owning app id, e.g. "pipelinq",
 *         or null when the schema has no known owning app) so a consuming
 *         settings UI can scope the list to the currently open app.
 *
 *   PUT /api/notification-preferences
 *       body: { schema, notification, enabled?, channels?, scope?, reset? }
 *       → record (or, with `reset: true`, clear) one override for the
 *         current user only. With a `scope` the value applies to that
 *         register, schema or declared domain only, and the caller's global
 *         value is left untouched.
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

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationPreferencesControllerServices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;

class NotificationPreferencesController extends Controller {
	use NotificationRequestTrait;

	/**
	 * The three-layer preference resolver.
	 *
	 * @var NotificationPreferencesInterface
	 */
	private readonly NotificationPreferencesInterface $preferenceService;

	/**
	 * Current-user session.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Constructor.
	 *
	 * @param IRequest                                   $request  Request.
	 * @param NotificationPreferencesControllerServices $services The preference resolver and the session.
	 */
	public function __construct(
		IRequest $request,
		NotificationPreferencesControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);

		$this->preferenceService = $services->preferenceService;
		$this->userSession = $services->userSession;
	}//end __construct()

	/**
	 * Return the effective notifications for the current user, merged over the
	 * three layers and tagged with the one that decided.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-the-effective-preference-merges-schema-group-and-user-and-names-the-layer-req-nrg-002
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		// An optional `scope` answers the same list AS IT APPLIES in one
		// register, schema or declared domain, so a screen that is showing one
		// case domain shows the preference that domain actually gets rather
		// than the reader's global one.
		$scopes = [];
		$scope = $this->nonEmptyString(value: $this->request->getParam('scope'));
		if ($scope !== null) {
			$scopes[] = $scope;
		}

		$items = $this->preferenceService->getEffectiveForUser(userId: $userId, scopes: $scopes);
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
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	public function update(): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		$params = $this->request->getParams();
		$schema = $this->stringParam(params: $params, key: 'schema');
		$notification = $this->stringParam(params: $params, key: 'notification');
		if ($schema === null || $notification === null) {
			return new JSONResponse(data: ['error' => 'Both "schema" and "notification" are required'], statusCode: 422);
		}

		// An optional scope pins this value to one register, one schema or one
		// domain a leaf app declares, leaving the caller's global value alone.
		// Absent means global, which is what every stored preference is.
		$scope = $this->stringParam(params: $params, key: 'scope');

		return $this->writeOverride(userId: $userId, target: ['schema' => $schema, 'notification' => $notification, 'scope' => $scope]);
	}//end update()

	/**
	 * Store the override, then answer with what is stored now.
	 *
	 * Clearing the override (`reset`) restores the layer below: the group
	 * default when the user's groups hold one, else the schema default. A
	 * cleared override is answered as null without reading it back.
	 *
	 * @param string                                                      $userId The current user.
	 * @param array{schema: string, notification: string, scope: ?string} $target What the override is for.
	 *
	 * @return JSONResponse The stored override.
	 */
	private function writeOverride(string $userId, array $target): JSONResponse {
		[$schema, $notification, $scope] = [$target['schema'], $target['notification'], $target['scope']];
		$override = $this->requestedSetting(params: $this->request->getParams());
		$this->preferenceService->setOverride(userId: $userId, schemaSlug: $schema, notificationKey: $notification, override: $override, scope: $scope);

		$stored = null;
		if ($override !== null) {
			$stored = $this->preferenceService->getOverride(userId: $userId, schemaSlug: $schema, notificationKey: $notification, scope: $scope);
		}

		return $this->settingResponse(target: $target, stored: ['override' => $stored]);
	}//end writeOverride()
}//end class
