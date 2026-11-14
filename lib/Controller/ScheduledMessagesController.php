<?php

/**
 * An object's scheduled and sent messages, and cancelling one.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Notifications\Controller\Support\ReadableObject;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageRefusal;
use OCA\OpenRegister\Notifications\Service\Notification\ScheduledMessageScheduler;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * `GET /api/objects/{register}/{schema}/{id}/messages` lists the messages bound
 * to an object the caller may read; `POST .../messages/{messageId}/cancel`
 * cancels a scheduled one for its author or a user with `update` on the object.
 * The object is resolved under the caller's read access first, so an object the
 * caller may not read is a 404 on both routes.
 *
 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
 */
class ScheduledMessagesController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                  $request   The request.
	 * @param ScheduledMessageScheduler $scheduler The scheduling side of the send leaf.
	 * @param ObjectOperationsInterface $objects   Resolves the object under the caller's read access.
	 */
	public function __construct(
		IRequest $request,
		private readonly ScheduledMessageScheduler $scheduler,
		private readonly ObjectOperationsInterface $objects,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The messages bound to the object, newest first.
	 *
	 * @param string $register Register slug or id.
	 * @param string $schema   Schema slug or id.
	 * @param string $id       Object uuid.
	 *
	 * @return JSONResponse The messages, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	#[NoAdminRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = (new ReadableObject(objects: $this->objects))->resolve(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		$results = $this->scheduler->forObject(object: $object);

		return new JSONResponse(['results' => $results, 'total' => count($results)]);
	}//end index()

	/**
	 * Cancel one scheduled message bound to the object.
	 *
	 * @param string $register  Register slug or id.
	 * @param string $schema    Schema slug or id.
	 * @param string $id        Object uuid.
	 * @param string $messageId The message.
	 *
	 * @return JSONResponse The cancelled message, or 403, 404 or 409.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/send-at-on-the-messaging-leaf/specs/integration-message-dispatch/spec.md#requirement-a-send-may-be-scheduled-and-bound-to-an-object
	 */
	#[NoAdminRequired]
	public function cancel(string $register, string $schema, string $id, string $messageId): JSONResponse {
		$object = (new ReadableObject(objects: $this->objects))->resolve(path: ['register' => $register, 'schema' => $schema, 'id' => $id]);
		if ($object instanceof JSONResponse) {
			return $object;
		}

		try {
			return new JSONResponse($this->scheduler->cancel(object: $object, id: $messageId));
		} catch (ScheduledMessageRefusal $refusal) {
			return new JSONResponse(['message' => $refusal->getMessage()], $refusal->status);
		}
	}//end cancel()
}//end class
