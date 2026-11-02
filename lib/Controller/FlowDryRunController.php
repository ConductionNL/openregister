<?php

/**
 * Tries a flow against an object without effect (`POST /api/flows/{id}/dry-run`).
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

use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowDryRunService;
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
	 * @param string            $appName The app name.
	 * @param IRequest          $request The request.
	 * @param FlowAccess        $access  Who the caller is and which flow rights they hold.
	 * @param FlowDryRunService $service Walks the flow against the object.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FlowAccess $access,
		private readonly FlowDryRunService $service,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The steps the flow would take for the object in the body, and what each would do.
	 *
	 * Body: `{register, schema, objectId}`. Refused without a session (401),
	 * without the `flow.update` right (403), without an object (400), and for a
	 * flow or object out of the caller's reach (404).
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
	 * see answers 404.
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

		$register = trim((string)$this->request->getParam('register', ''));
		$schema = trim((string)$this->request->getParam('schema', ''));
		$objectId = trim((string)$this->request->getParam('objectId', ''));
		if ($register === '' || $schema === '' || $objectId === '') {
			return new JSONResponse(['error' => 'Name the object: register, schema and objectId.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->service->dryRun(flowId: $id, register: $register, schema: $schema, objectId: $objectId));
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		}
	}//end dryRun()
}//end class
