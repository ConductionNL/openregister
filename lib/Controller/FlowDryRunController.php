<?php

/**
 * Tries a flow against an object or an example payload without effect
 * (`POST /api/flows/{id}/dry-run`).
 *
 * A separate controller because FlowController's constructor is already at
 * its limit; the right it checks is the one that edits a flow, since trying a
 * change is part of making it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\Flow\FlowAccessInterface;
use OCA\OpenRegister\Service\Flow\FlowDryRunServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The flow dry-run endpoint.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowDryRunController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest          $request The request.
	 * @param FlowAccessInterface $access  Who the caller is and which flow rights they hold.
	 * @param FlowDryRunServiceInterface $service Walks the flow against the object.
	 */
	public function __construct(
		IRequest $request,
		private readonly FlowAccessInterface $access,
		private readonly FlowDryRunServiceInterface $service,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The steps the flow would take for the object in the body, and what each would do.
	 *
	 * Body: `{register, schema, objectId}`, or `{payload}` with example object
	 * data in place of a stored object. Refused without a session (401),
	 * without the `flow.update` right (403), without an object or with a
	 * payload that is not an object (400), and for a flow or object out of the
	 * caller's reach (404).
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return JSONResponse The steps 200, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Guarded in the service: the flow is found through
	 * `FlowService::find()`, which is organisation-scoped, and the object is
	 * read with the caller's own RBAC, so a flow or object the caller cannot
	 * see answers 404. A payload reads no object.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	#[NoAdminRequired]
	public function dryRun(string $id): JSONResponse {
		$user = $this->access->currentUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->access->may(user: $user, action: 'flow.update') === false) {
			return new JSONResponse(['error' => 'You do not have the "flow.update" right.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return $this->answer(flowId: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end dryRun()

	/**
	 * Try the flow with what the body names: an example payload, else an object.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return JSONResponse The steps 200, or 400 for a body that names neither.
	 *
	 * @throws DoesNotExistException When the flow or the object is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function answer(string $flowId): JSONResponse {
		$payload = $this->request->getParam('payload');
		if ($payload !== null && $payload !== '') {
			return $this->payloadAnswer(flowId: $flowId, payload: $payload);
		}

		return $this->objectAnswer(flowId: $flowId);
	}//end answer()

	/**
	 * Try the flow with an example payload.
	 *
	 * @param string $flowId  The flow uuid.
	 * @param mixed  $payload The payload as the body holds it.
	 *
	 * @return JSONResponse The steps 200, or 400 when it is not a JSON object.
	 *
	 * @throws DoesNotExistException When the flow is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function payloadAnswer(string $flowId, mixed $payload): JSONResponse {
		if (is_array($payload) === false) {
			return new JSONResponse(['error' => 'The payload must be a JSON object.'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->service->dryRunPayload(flowId: $flowId, payload: $payload));
	}//end payloadAnswer()

	/**
	 * Try the flow with the object the body names.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return JSONResponse The steps 200, or 400 for a body that names no object.
	 *
	 * @throws DoesNotExistException When the flow or the object is out of the caller's reach.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-flow-can-be-tried-against-an-object-without-effect
	 */
	private function objectAnswer(string $flowId): JSONResponse {
		$register = trim((string)$this->request->getParam('register', ''));
		$schema = trim((string)$this->request->getParam('schema', ''));
		$objectId = trim((string)$this->request->getParam('objectId', ''));
		if ($register === '' || $schema === '' || $objectId === '') {
			return new JSONResponse(['error' => 'Name the object: register, schema and objectId.'], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($this->service->dryRun(flowId: $flowId, register: $register, schema: $schema, objectId: $objectId));
	}//end objectAnswer()
}//end class
