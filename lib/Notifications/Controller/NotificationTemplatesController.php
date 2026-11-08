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

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\Kernel\Contracts\NotificationTemplatesInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCA\OpenRegister\Notifications\Controller\Support\NotificationRequestTrait;

class NotificationTemplatesController extends Controller {
	use NotificationRequestTrait;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param NotificationTemplatesInterface $registry The catalogue of platform events and their texts.
	 * @param IGroupManager $groupManager The authority on who is an administrator.
	 * @param IUserSession $userSession Current-user session.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly NotificationTemplatesInterface $registry,
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 *
	 * @no-admin-idor-exempt The catalogue is the platform's own, identical for
	 * every reader, and this method takes no caller-supplied identifier at all:
	 * there is no object here to address somebody else's copy of. What it
	 * returns is the text the platform sends and the variables each event
	 * carries, which a schema author needs in order to write a rule. Editing it
	 * is a different method and requires an administrator.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): JSONResponse {
		if ($this->resolveUserId() === null) {
			return $this->authenticationRequired();
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function gaps(): JSONResponse {
		if ($this->resolveUserId() === null) {
			return $this->authenticationRequired();
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
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-every-platform-event-ships-an-editable-template-req-nrg-006
	 *
	 * @NoCSRFRequired
	 */
	public function update(string $event): JSONResponse {
		$userId = $this->resolveUserId();
		if ($userId === null) {
			return $this->authenticationRequired();
		}

		if ($this->groupManager->isAdmin($userId) === false) {
			return new JSONResponse(data: ['error' => 'Administrator required'], statusCode: 403);
		}

		return $this->editTemplate(event: $event, params: $this->request->getParams());
	}//end update()

	/**
	 * Store the payload's template edit (or its reset), then answer with the result.
	 *
	 * @param string               $event  The event whose template is edited.
	 * @param array<string, mixed> $params The request payload.
	 *
	 * @return JSONResponse The template now in effect, or a 422.
	 */
	private function editTemplate(string $event, array $params): JSONResponse {
		$template = null;
		if ($this->wantsReset(params: $params) === false) {
			$template = ($params['template'] ?? null);
			if (is_array($template) === false) {
				return new JSONResponse(data: ['error' => 'A "template" object, or "reset": true, is required'], statusCode: 422);
			}
		}

		return $this->storeTemplate(event: $event, template: $template);
	}//end editTemplate()

	/**
	 * Store a template (null clears the edit), then answer with the template in effect.
	 *
	 * @param string                    $event    The event.
	 * @param array<string, mixed>|null $template The template, or null to clear the edit.
	 *
	 * @return JSONResponse The template in effect, or a 422 when the registry refuses it.
	 */
	private function storeTemplate(string $event, ?array $template): JSONResponse {
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
	}//end storeTemplate()
}//end class
