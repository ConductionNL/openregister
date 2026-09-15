<?php

/**
 * NotificationTemplatesController.
 *
 * The shipped message templates for the events the platform raises, and the
 * list of events that have none.
 *
 *   GET /api/notification-templates
 *       → every platform event, with its shipped text, any edit, its variables
 *         and whether it has a template at all.
 *
 *   GET /api/notification-templates/gaps
 *       → the events with no template. A finishable list, which is the point:
 *         a generic fallback hides the gap forever.
 *
 *   PUT /api/notification-templates/{event}
 *       body: { template: { <locale>: { subject, body } } } or { reset: true }
 *       → change the words, or restore what shipped.
 *
 * Reading is open to any signed-in user, because the variables are part of how
 * a schema author writes a rule. Editing is an administrator's act: the words
 * go out under the platform's name to everybody the rule reaches.
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

use OCA\OpenRegister\Service\Notification\NotificationTemplateRegistry;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class NotificationTemplatesController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param NotificationTemplateRegistry $registry The catalogue of platform events and their texts.
	 * @param IGroupManager $groupManager The authority on who is an administrator.
	 * @param IUserSession $userSession Current-user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NotificationTemplateRegistry $registry,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Every platform event, with its template and its variables.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		if ($this->resolveUserId() === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$rows = $this->registry->listAll();
		return new JSONResponse(
			data: [
				'results' => $rows,
				'total' => count($rows),
				// Carried on the listing as well as on its own route, so a
				// screen showing the set cannot show it without showing what
				// is missing from it.
				'gaps' => $this->registry->gaps(),
			]
		);
	}//end index()

	/**
	 * The events with no template.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	#[NoAdminRequired]
	public function gaps(): JSONResponse {
		if ($this->resolveUserId() === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		$gaps = $this->registry->gaps();
		return new JSONResponse(data: ['results' => $gaps, 'total' => count($gaps)]);
	}//end gaps()

	/**
	 * Change one event's words, or restore what shipped.
	 *
	 * @param string $event The event name.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/notification-routing-per-group-and-scope/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 */
	public function update(string $event): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return new JSONResponse(data: ['error' => 'Authentication required'], statusCode: 401);
		}

		if ($this->groupManager->isAdmin($userId) === false) {
			return new JSONResponse(data: ['error' => 'Administrator required'], statusCode: 403);
		}

		$params = $this->request->getParams();
		$template = null;
		if (($params['reset'] ?? false) !== true && ($params['reset'] ?? null) !== 'true') {
			$template = ($params['template'] ?? null);
			if (is_array($template) === false) {
				return new JSONResponse(
					data: ['error' => 'A "template" object, or "reset": true, is required'],
					statusCode: 422
				);
			}
		}

		try {
			$this->registry->edit(event: $event, template: $template);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 422);
		}

		return new JSONResponse(
			data: [
				'event' => $event,
				'template' => $this->registry->templateFor(event: $event),
				'edited' => $this->registry->hasEdit(event: $event),
			]
		);
	}//end update()

	/**
	 * Resolve the current user's UID, or null when anonymous.
	 *
	 * @return string|null
	 */
	private function resolveUserId(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end resolveUserId()
}//end class
