<?php

/**
 * OpenRegister TransitionController
 *
 * Sugar HTTP entry point over TransitionEngine. Apps that adopt the
 * x-openregister-lifecycle annotation no longer need to write their
 * own action endpoint per schema.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Exception\InvalidTransitionInputException;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\LifecycleSubjectNotFoundException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use RuntimeException;

class TransitionController extends Controller {
	/**
	 * Status per engine failure, checked in this order (first match wins).
	 *
	 * - NotAuthorizedException: the caller lacks `update` permission on the
	 *   object. 403, so clients can distinguish "not allowed" from "transition
	 *   refused" (422) and "object missing" (404).
	 * - HookStoppedException: the validator listener (or another listener)
	 *   rejected the save by calling stopPropagation(). Its message as a
	 *   structured 422 instead of a 500 stack trace.
	 * - LifecycleSubjectNotFoundException: the object itself is gone. Checked
	 *   BEFORE the RuntimeException it extends: "someone deleted this case" and
	 *   "this case cannot take that move" are different things to tell a
	 *   handler, and they answered the same 422 until this type existed. 404 is
	 *   what the read half of the same pair already answers. An object the
	 *   caller may not READ lands here too, by design: GET
	 *   /api/objects/{register}/{schema}/{id} answers 404 for it so its
	 *   existence does not leak, and this endpoint must not leak it either. It
	 *   used to escape as an OCP DoesNotExistException → 500.
	 * - LifecycleProviderException: a provider-mode lifecycle could not be RUN:
	 *   the declared tag resolves to nothing, the provider failed in a way it
	 *   never planned for, or the object could not be read back afterwards.
	 *   Checked BEFORE the RuntimeException it extends, because a broken
	 *   provider is not a refused move. 422 would tell a handler the move was
	 *   considered and declined; 502 says the truthful thing, that OpenRegister
	 *   asked an upstream and did not get an answer. The provider's own
	 *   refusals stay 422 below — they are ordinary RuntimeExceptions by
	 *   contract.
	 * - RuntimeException: the engine throws on missing schema/transition,
	 *   disallowed-from-current-state, or a provider refusing the move.
	 *
	 * @var array<class-string<\Exception>, int>
	 */
	private const TRANSITION_REFUSAL_STATUS = [
		NotAuthorizedException::class => Http::STATUS_FORBIDDEN,
		HookStoppedException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
		LifecycleSubjectNotFoundException::class => Http::STATUS_NOT_FOUND,
		LifecycleProviderException::class => Http::STATUS_BAD_GATEWAY,
		RuntimeException::class => Http::STATUS_UNPROCESSABLE_ENTITY,
	];

	/**
	 * Constructor.
	 *
	 * @param string $appName The application name.
	 * @param IRequest $request The current request.
	 * @param TransitionEngine $engine The transition engine.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly TransitionEngine $engine,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Apply a named transition to an object.
	 *
	 * The action name is taken from the request body (`action` key), so
	 * the same endpoint covers every transition declared on the schema —
	 * apps don't need a route per action.
	 *
	 * An optional `data` object carries input values for the transition's
	 * declared `inputs` (see the engine); an undeclared key, a missing
	 * required input, or a non-object `data` value is a 400.
	 *
	 * @param string $id Object id/uuid/slug.
	 *
	 * @return JSONResponse JSON response with the transitioned object or an error.
	 *
	 * @NoAdminRequired
	 *
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-6
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function transition(string $id): JSONResponse {
		$action = (string)($this->request->getParam('action') ?? '');
		if ($action === '') {
			return $this->badRequest(message: 'Missing required field "action".');
		}

		return $this->transitionWithInputs(id: $id, action: $action);
	}//end transition()

	/**
	 * Read the transition's input values and run the transition.
	 *
	 * @param string $id     Object id/uuid/slug.
	 * @param string $action The transition to apply.
	 *
	 * @return JSONResponse The transitioned object, or the refusal.
	 */
	private function transitionWithInputs(string $id, string $action): JSONResponse {
		$data = $this->request->getParam('data') ?? [];
		if (is_array($data) === false) {
			return $this->badRequest(message: 'Field "data" must be an object of input values.');
		}

		try {
			$object = $this->engine->transition(objectId: $id, action: $action, data: $data);
		} catch (InvalidTransitionInputException | NotAuthorizedException | HookStoppedException | RuntimeException $e) {
			return $this->transitionRefusal(exception: $e);
		}

		return new JSONResponse($object->jsonSerialize());
	}//end transitionWithInputs()

	/**
	 * Answer a malformed transition request.
	 *
	 * @param string $message What is wrong with the request.
	 *
	 * @return JSONResponse 400 response.
	 */
	private function badRequest(string $message): JSONResponse {
		return new JSONResponse(
			['error' => $message],
			Http::STATUS_BAD_REQUEST
		);
	}//end badRequest()

	/**
	 * Answer a transition the engine refused or could not run.
	 *
	 * The payload violating the transition's declared `inputs` allowlist
	 * (undeclared key, or missing required input) is a malformed request → 400,
	 * with the offending field names machine-readable next to the human message.
	 * Every other failure answers its message with the status of
	 * {@see self::TRANSITION_REFUSAL_STATUS}.
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function transitionRefusal(\Exception $exception): JSONResponse {
		if ($exception instanceof InvalidTransitionInputException) {
			return new JSONResponse(
				[
					'error' => $exception->getMessage(),
					'fields' => $exception->getFields(),
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			['error' => $exception->getMessage()],
			$this->refusalStatus(exception: $exception)
		);
	}//end transitionRefusal()

	/**
	 * The status of a refused transition: the first entry of
	 * {@see self::TRANSITION_REFUSAL_STATUS} the failure is an instance of.
	 *
	 * @param \Exception $exception The failure.
	 *
	 * @return int The HTTP status.
	 */
	private function refusalStatus(\Exception $exception): int {
		foreach (self::TRANSITION_REFUSAL_STATUS as $class => $status) {
			if ($exception instanceof $class) {
				return $status;
			}
		}

		return Http::STATUS_UNPROCESSABLE_ENTITY;
	}//end refusalStatus()

	/**
	 * List actions allowed from the object's current state.
	 *
	 * Deprecated for one release: `GET /api/objects/{register}/{schema}/{id}/actions`
	 * answers the same transitions beside the declared actions. The shape here
	 * is unchanged; every answer carries a `Deprecation` header and a `Link` to
	 * the successor.
	 *
	 * @param string $id Object id/uuid/slug.
	 *
	 * @return JSONResponse JSON response with the list of allowed actions.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-ctrl-misc/tasks.md#task-6
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	public function availableActions(string $id): JSONResponse {
		$response = $this->availableActionsResponse(id: $id);
		$response->addHeader('Deprecation', 'true');
		$response->addHeader('Link', '</apps/openregister/api/objects/{register}/{schema}/{id}/actions>; rel="successor-version"');

		return $response;
	}//end availableActions()

	/**
	 * The answer of {@see availableActions()}, before its deprecation headers.
	 *
	 * @param string $id Object id/uuid/slug.
	 *
	 * @return JSONResponse The list, or the refusal.
	 */
	private function availableActionsResponse(string $id): JSONResponse {
		try {
			$actions = $this->engine->availableActions(objectId: $id);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_FORBIDDEN
			);
		} catch (LifecycleProviderException $e) {
			// A provider-mode lifecycle could not be read: the declared tag
			// resolves to nothing, or the app service threw while answering.
			// Caught BEFORE the RuntimeException branch it extends, and
			// deliberately NOT collapsed into an empty action list.
			//
			// A client reading this endpoint treats 404 as "this object has
			// no lifecycle" and any other failure as "could not read", but
			// `{"actions": []}` is a successful answer meaning "no moves from
			// here" — so a swallowed provider failure would render as a dead
			// timeline nobody knows is broken. 502 says the truthful thing:
			// OpenRegister asked an upstream and did not get an answer.
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_GATEWAY
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(['actions' => $actions]);
	}//end availableActionsResponse()
}//end class
