<?php

/**
 * Invoking a declared action on one object.
 *
 * An action that applies several changes is a manually triggered flow. The
 * binding lives on the schema; this is where a person's click reaches it.
 *
 * 🔴 THE ACTION AUTHORISES, THE FLOW EXECUTES (ADR-023, design D-1). The
 * caller must hold the DECLARED action's own right on this object, checked
 * before anything is queued, and the run then executes as that person, so
 * every write inside the flow is checked against their rights too. A macro
 * cannot do what its user cannot, and binding a flow to an action adds no
 * second permission model.
 *
 * 🔴 IT ANSWERS A HINT, NEVER A ROUTE (design D-3). `next` is `stay`, `next`
 * or `list`. The list the person came from owns its own notion of "the next
 * item" — its sort, its filter, its page — so a response carrying a URL would
 * be a server deciding a client's navigation from a different sort order.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Runs a declared action bound to a manual flow.
 *
 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
 */
class ObjectActionsController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string            $appName     The app name.
	 * @param IRequest          $request     The request.
	 * @param ObjectService     $objects     Loads the subject.
	 * @param MacroActionResolver $macros      Resolves the schema and the binding this action declares.
	 * @param PermissionHandler $permissions Decides whether the caller may do this.
	 * @param FlowService       $flows       Queues and, by default, runs the flow.
	 * @param IUserSession      $userSession The acting user.
	 * @param LoggerInterface   $logger      Diagnostics.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objects,
		private readonly MacroActionResolver $macros,
		private readonly PermissionHandler $permissions,
		private readonly FlowService $flows,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Invoke a declared macro action on one object.
	 *
	 * Synchronous by default, because a macro is a click (design D-2): a
	 * handler who presses "close and notify" expects the case closed when the
	 * page refreshes, not a row that says `queued`.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id, uuid or slug.
	 * @param string $action   The declared action.
	 *
	 * @return JSONResponse The run id, its outcome and `next`.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function invoke(string $register, string $schema, string $id, string $action): JSONResponse {
		$object = $this->objects->find(id: $id);
		if ($object === null) {
			return new JSONResponse(['error' => 'No such object'], Http::STATUS_NOT_FOUND);
		}

		$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
		if ($subjectSchema === null) {
			return new JSONResponse(['error' => 'No such schema'], Http::STATUS_NOT_FOUND);
		}

		// THE ACTION'S OWN RIGHT, on this object, before anything is queued.
		// Being able to see the button is not being allowed to press it, and a
		// run started and then refused inside would already have written.
		$allowed = $this->permissions->hasPermission(
			schema: $subjectSchema,
			action: $action,
			userId: $this->userSession->getUser()?->getUID(),
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
		if ($allowed === false) {
			return new JSONResponse(
				['error' => sprintf('You may not perform "%s" on this object.', $action)],
				Http::STATUS_FORBIDDEN
			);
		}

		$binding = $this->macros->bindingFor(schema: $subjectSchema, action: $action);
		if ($binding === null) {
			// Not a macro. Distinct from "you may not": the action exists or
			// does not, and either way no flow is bound to it here.
			return new JSONResponse(
				['error' => sprintf('Action "%s" does not run a flow on this schema.', $action)],
				Http::STATUS_NOT_FOUND
			);
		}

		try {
			$run = $this->flows->run(
				uuid: $binding->flow,
				subject: [
					'uuid' => (string)$object->getUuid(),
					'register' => $register,
					'schema' => $schema,
				],
				context: ['action' => $action],
				sync: true
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'[ObjectActionsController] Macro "{action}" failed: {error}',
				['action' => $action, 'error' => $e->getMessage(), 'exception' => $e]
			);

			return new JSONResponse(
				['error' => $e->getMessage(), 'action' => $action],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}//end try

		return new JSONResponse(
			[
				'run' => (string)$run->getUuid(),
				'outcome' => (string)$run->getStatus(),
				'action' => $action,
				'next' => $this->macros->nextFor(flowUuid: $binding->flow),
			]
		);
	}//end invoke()

}//end class
