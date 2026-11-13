<?php

/**
 * NotificationRecipientAnswerController.
 *
 *   GET /api/notification-preferences/recipient-answer?user=&schema=&notification=[&scope=]
 *       → for one recipient and one declared notification: the channels that
 *         will be used, the layer that decided them (schema default, group
 *         default, user override or administrator-forced), whether the kind is
 *         forced and why, and whether it is internal only.
 *
 * ADMINISTRATORS ONLY, AND BY THE MIDDLEWARE. The method carries no
 * `#[NoAdminRequired]`, so Nextcloud refuses anybody else before the
 * controller is constructed: the answer reads another person's preferences,
 * and an in-body check would be the only barrier in a line a refactor can
 * delete.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Notifications\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Notifications\Service\Notification\RecipientKindAnswer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;

/**
 * The administrator's per-recipient notification answer.
 *
 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
 */
class NotificationRecipientAnswerController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest            $request The request.
	 * @param RecipientKindAnswer $answers The per-recipient answer.
	 * @param IUserManager        $users   The users, to tell an unknown one apart.
	 */
	public function __construct(
		IRequest $request,
		private readonly RecipientKindAnswer $answers,
		private readonly IUserManager $users,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The answer for one recipient and one notification.
	 *
	 * @return JSONResponse 200 with the answer; 422 without user, schema and
	 *                      notification; 404 for an unknown user or a
	 *                      notification no schema declares.
	 *
	 * @spec openspec/changes/notification-kinds-an-administrator-forces/specs/notificatie-engine/spec.md#requirement-an-administrator-can-read-what-one-recipient-will-get-for-one-kind-req-nkf-003
	 */
	#[NoCSRFRequired]
	public function show(): JSONResponse {
		$userId = $this->param(name: 'user');
		$schema = $this->param(name: 'schema');
		$notification = $this->param(name: 'notification');
		if ($userId === '' || $schema === '' || $notification === '') {
			return new JSONResponse(
				data: ['error' => '"user", "schema" and "notification" are all required'],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		if ($this->users->userExists($userId) === false) {
			return new JSONResponse(data: ['error' => 'Unknown user'], statusCode: Http::STATUS_NOT_FOUND);
		}

		$scopes = [];
		$scope = $this->param(name: 'scope');
		if ($scope !== '') {
			$scopes[] = $scope;
		}

		$answer = $this->answers->answer(userId: $userId, schemaSlug: $schema, notificationKey: $notification, scopes: $scopes);
		if ($answer === null) {
			return new JSONResponse(data: ['error' => 'No schema declares this notification'], statusCode: Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(data: $answer);
	}//end show()

	/**
	 * One query parameter as a trimmed string, empty when absent.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string The value.
	 */
	private function param(string $name): string {
		$value = $this->request->getParam($name);
		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end param()
}//end class
