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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
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
	 * The most objects one selection call runs a macro on.
	 *
	 * @var int
	 */
	public const SELECTION_LIMIT = 100;

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
		$object = $this->findObject(id: $id);
		if ($object === null) {
			return new JSONResponse(['error' => 'No such object'], Http::STATUS_NOT_FOUND);
		}

		$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
		if ($subjectSchema === null) {
			return new JSONResponse(['error' => 'No such schema'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->runOne(
			object: $object,
			subjectSchema: $subjectSchema,
			action: $action,
			register: $register,
			schema: $schema
		);

		return new JSONResponse($result['body'], $result['status']);
	}//end invoke()

	/**
	 * Invoke a declared macro action on a selection of objects.
	 *
	 * One run per object, each through the same path as a single click: the
	 * action's own right is checked per object, a refusal on one object does
	 * not stop the others, and every outcome is reported by object. The
	 * selection is capped at {@see self::SELECTION_LIMIT}, because the runs
	 * are synchronous and a request that runs a thousand flows times out
	 * half way with no summary at all.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $action   The declared action.
	 *
	 * @return JSONResponse The summary per object and `next`.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function invokeOnSelection(string $register, string $schema, string $action): JSONResponse {
		$ids = $this->request->getParam('ids', []);
		if (is_array($ids) === false || $ids === []) {
			return new JSONResponse(['error' => 'Send the selection as a non-empty list "ids".'], Http::STATUS_BAD_REQUEST);
		}

		$ids = array_values(array_unique(array_map('strval', $ids)));
		if (count($ids) > self::SELECTION_LIMIT) {
			return new JSONResponse(
				['error' => sprintf('A selection holds at most %d objects; this one holds %d.', self::SELECTION_LIMIT, count($ids))],
				Http::STATUS_BAD_REQUEST
			);
		}

		$results = [];
		$flow    = null;
		foreach ($ids as $id) {
			$object = $this->findObject(id: $id);
			if ($object === null) {
				$results[] = ['id' => $id, 'status' => 'failed', 'reason' => 'No such object'];
				continue;
			}

			$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
			if ($subjectSchema === null) {
				$results[] = ['id' => $id, 'status' => 'failed', 'reason' => 'No such schema'];
				continue;
			}

			$one = $this->runOne(
				object: $object,
				subjectSchema: $subjectSchema,
				action: $action,
				register: $register,
				schema: $schema
			);
			$flow ??= $this->macros->bindingFor(schema: $subjectSchema, action: $action)?->flow;

			if ($one['status'] === Http::STATUS_OK) {
				$results[] = ['id' => $id, 'status' => 'succeeded', 'run' => $one['body']['run'], 'outcome' => $one['body']['outcome']];
				continue;
			}

			$results[] = ['id' => $id, 'status' => 'failed', 'reason' => (string)($one['body']['error'] ?? 'refused')];
		}//end foreach

		$succeeded = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'succeeded'));

		return new JSONResponse(
			[
				'action' => $action,
				'summary' => ['succeeded' => $succeeded, 'failed' => (count($results) - $succeeded)],
				'results' => $results,
				'next' => $this->macros->nextAfterSelection(flowUuid: $flow),
			]
		);
	}//end invokeOnSelection()

	/**
	 * The object, or null when there is none the caller can reach.
	 *
	 * ObjectService::find() throws when the object does not exist; for this
	 * controller that is a 404, not a 500.
	 *
	 * @param string $id The object's id, uuid or slug.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function findObject(string $id): ?ObjectEntity {
		try {
			return $this->objects->find(id: $id);
		} catch (\Throwable) {
			return null;
		}
	}//end findObject()

	/**
	 * Run the macro on one object: the action's own right, the binding, the
	 * run, and the audit entry that names the action and the run.
	 *
	 * @param ObjectEntity $object        The subject.
	 * @param Schema       $subjectSchema The subject's schema.
	 * @param string       $action        The declared action.
	 * @param string       $register      The register as the caller named it.
	 * @param string       $schema        The schema as the caller named it.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body for this object.
	 *
	 * @spec openspec/changes/macro-flows-with-next-item/specs/declared-actions/spec.md#requirement-a-declared-action-may-run-a-manual-flow-as-a-macro
	 */
	private function runOne(ObjectEntity $object, Schema $subjectSchema, string $action, string $register, string $schema): array {
		$userId = $this->userSession->getUser()?->getUID();

		// THE ACTION'S OWN RIGHT, on this object, before anything is queued.
		// Being able to see the button is not being allowed to press it, and a
		// run started and then refused inside would already have written.
		$allowed = $this->permissions->hasPermission(
			schema: $subjectSchema,
			action: $action,
			userId: $userId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);
		if ($allowed === false) {
			return [
				'status' => Http::STATUS_FORBIDDEN,
				'body' => ['error' => sprintf('You may not perform "%s" on this object.', $action)],
			];
		}

		$binding = $this->macros->bindingFor(schema: $subjectSchema, action: $action);
		if ($binding === null) {
			// Not a macro. Distinct from "you may not": the action exists or
			// does not, and either way no flow is bound to it here.
			return [
				'status' => Http::STATUS_NOT_FOUND,
				'body' => ['error' => sprintf('Action "%s" does not run a flow on this schema.', $action)],
			];
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

			return [
				'status' => Http::STATUS_UNPROCESSABLE_ENTITY,
				'body' => ['error' => $e->getMessage(), 'action' => $action],
			];
		}//end try

		$this->macros->recordRun(object: $object, action: $action, flow: $binding->flow, run: (string)$run->getUuid(), userId: $userId);

		return [
			'status' => Http::STATUS_OK,
			'body' => [
				'run' => (string)$run->getUuid(),
				'outcome' => (string)$run->getStatus(),
				'action' => $action,
				'next' => $this->macros->nextForRun(flowUuid: $binding->flow, run: $run),
			],
		];
	}//end runOne()

}//end class
