<?php

/**
 * Listing and running the actions declared on an object.
 *
 * One list, one run path. A schema declares an action once (its inputs, who
 * may run it, when it is available and what it does) and this controller is
 * where a person's click reaches it; apps stop writing a controller per verb.
 *
 * 🔴 THE ACTION AUTHORISES, THE BODY EXECUTES (ADR-023). The caller must hold
 * the action's own right on the object, checked before anything is queued,
 * and the run then executes as that person. The refusal order is fixed:
 * 404 unknown action, 403 no right, 409 precondition unmet, 422 invalid
 * inputs or a body that refused.
 *
 * 🔴 IT ANSWERS A HINT, NEVER A ROUTE. `next` is `stay`, `next` or `list`; the
 * list the person came from owns its own notion of "the next item".
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
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\Flow\FlowNextHint;
use OCA\OpenRegister\Service\Flow\MacroActionResolver;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionBulk;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinition;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionDefinitions;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionHistory;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionList;
use OCA\OpenRegister\Service\ObjectAction\ObjectActionRunner;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WriteCause;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Lists and runs declared actions on objects and selections.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The fourteenth type is LifecycleProviderException,
 *   answered as 502 so a provider that could not be asked never reads as "nothing to do".
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
 */
class ObjectActionsController extends Controller {

	/**
	 * The most objects one selection call runs an action on synchronously.
	 *
	 * @var int
	 */
	public const SELECTION_LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param ObjectService           $objects     Loads the subject.
	 * @param MacroActionResolver     $macros      Loads a schema; the `next` hint after a selection.
	 * @param ObjectActionDefinitions $definitions The schema's actions, for the selection surface rule.
	 * @param ObjectActionList        $list        Lists the actions.
	 * @param ObjectActionRunner      $runner      Runs one action on one object.
	 * @param ObjectActionHistory     $history     Reads the runs recorded on an object.
	 * @param ObjectActionBulk        $bulk        Hands a large selection to a bulk job.
	 * @param LoggerInterface         $logger      Records a declaration that cannot be read.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI constructor: each argument is a collaborator an endpoint uses.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ObjectService $objects,
		private readonly MacroActionResolver $macros,
		private readonly ObjectActionDefinitions $definitions,
		private readonly ObjectActionList $list,
		private readonly ObjectActionRunner $runner,
		private readonly ObjectActionHistory $history,
		private readonly ObjectActionBulk $bulk,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The actions the caller may run on one object.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id, uuid or slug.
	 *
	 * @return JSONResponse `{actions: [...]}`.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register and $schema are route segments; the object names its own.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-the-actions-a-user-may-run-on-an-object-are-listed-in-one-place
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(string $register, string $schema, string $id): JSONResponse {
		$object = $this->findObject(id: $id);
		$subjectSchema = null;
		if ($object !== null) {
			$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
		}

		if ($object === null || $subjectSchema === null) {
			return new JSONResponse(['error' => 'No such object'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse(['actions' => $this->list->forObject(object: $object, schema: $subjectSchema)]);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (LifecycleProviderException $e) {
			// The app answering a provider-mode lifecycle could not be read;
			// an empty list would claim "nothing to do" when the truth is "could not ask".
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_GATEWAY);
		}
	}//end index()

	/**
	 * The runs of actions on one object, newest first.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id, uuid or slug.
	 *
	 * @return JSONResponse `{results: [...]}`.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $register and $schema are route segments; the object names its own.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function history(string $register, string $schema, string $id): JSONResponse {
		$object = $this->findObject(id: $id);
		$subjectSchema = null;
		if ($object !== null) {
			$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
		}

		if ($object === null || $subjectSchema === null) {
			return new JSONResponse(['error' => 'No such object'], Http::STATUS_NOT_FOUND);
		}

		try {
			return new JSONResponse(['results' => $this->history->forObject(object: $object, schema: $subjectSchema)]);
		} catch (NotAuthorizedException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
	}//end history()

	/**
	 * The actions offered on a selection of a schema's objects.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 *
	 * @return JSONResponse `{actions: [...]}`.
	 *
	 * @no-admin-idor-exempt Takes no object id: it lists the schema's bulk actions.
	 *   ObjectActionList::forSelection() keeps an entry only when ObjectActionGate::may()
	 *   grants the caller its right at schema level with RBAC on; each object is
	 *   checked again when the action runs on it. The schema itself is loaded without
	 *   RBAC or multitenancy (MacroActionResolver::loadSchema()), so where neither the
	 *   schema nor its register declares authorization (default-open unless default-closed
	 *   is enforced) any signed-in user sees that schema's action declarations (key,
	 *   label, description, inputs and the rest of ObjectActionList's shape), also for a
	 *   schema of another organisation. No object data is returned.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function selection(string $register, string $schema): JSONResponse {
		$subjectSchema = $this->macros->loadSchema(schema: $schema);
		if ($subjectSchema === null) {
			return new JSONResponse(['error' => 'No such schema'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse(['actions' => $this->list->forSelection(schema: $subjectSchema, register: $register)]);
	}//end selection()

	/**
	 * Run an action on one object with `{inputs}`.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $id       The object's id, uuid or slug.
	 * @param string $action   The action key.
	 *
	 * @return JSONResponse The outcome, or 202 with the run for async and suspended runs.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function invoke(string $register, string $schema, string $id, string $action): JSONResponse {
		$inputs = $this->request->getParam('inputs', []);
		if (is_array($inputs) === false) {
			return new JSONResponse(['error' => 'Send the inputs as an object "inputs".'], Http::STATUS_BAD_REQUEST);
		}

		$object = $this->findObject(id: $id);
		$subjectSchema = null;
		if ($object !== null) {
			$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
		}

		if ($object === null || $subjectSchema === null) {
			return new JSONResponse(['error' => 'No such object'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->runner->run(
			object: $object,
			schema: $subjectSchema,
			key: $action,
			given: $inputs,
			subject: ['uuid' => (string)$object->getUuid(), 'register' => $register, 'schema' => $schema]
		);

		return new JSONResponse($result['body'], $result['status']);
	}//end invoke()

	/**
	 * Run an action on a selection with `{ids, inputs}`, one run per object.
	 *
	 * Only an action offered on the `bulk` surface is accepted. Each object
	 * goes through the single-object path, so its own right is checked and a
	 * refusal on one does not stop the others.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema   The schema slug or id.
	 * @param string $action   The action key.
	 *
	 * @return JSONResponse The summary per object and `next`.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-selections-run-per-object-and-large-selections-become-a-bulk-job
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function invokeOnSelection(string $register, string $schema, string $action): JSONResponse {
		$ids = $this->request->getParam('ids', []);
		$inputs = $this->request->getParam('inputs', []);
		if (is_array($ids) === false || $ids === [] || is_array($inputs) === false) {
			return new JSONResponse(
				['error' => 'Send the selection as a non-empty list "ids" and the inputs as an object "inputs".'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$selectionSchema = $this->macros->loadSchema(schema: $schema);
		$definition = null;
		if ($selectionSchema !== null) {
			$definition = $this->findDefinition(schema: $selectionSchema, action: $action, register: $register);
		}

		if ($definition === null || $definition->isRunnable() === false || $definition->offersBulk() === false) {
			return new JSONResponse(['error' => sprintf('Action "%s" is not offered on a selection.', $action)], Http::STATUS_BAD_REQUEST);
		}

		$ids = array_values(array_unique(array_map('strval', $ids)));
		if (count($ids) > self::SELECTION_LIMIT) {
			return $this->startJob(schema: $selectionSchema, register: $register, schemaRef: $schema, action: $action, ids: $ids, inputs: $inputs);
		}

		$results = array_map(
			fn (string $id): array => $this->runOnSelected(id: $id, action: $action, inputs: $inputs, register: $register, schema: $schema),
			$ids
		);
		$succeeded = count(array_filter($results, static fn (array $row): bool => $row['status'] === 'succeeded'));

		return new JSONResponse(
			[
				'action' => $action,
				'summary' => ['succeeded' => $succeeded, 'failed' => (count($results) - $succeeded)],
				'results' => $results,
				'next' => ($definition->next ?? FlowNextHint::LIST),
			]
		);
	}//end invokeOnSelection()

	/**
	 * Hand a selection above the limit to a previewed bulk job.
	 *
	 * @param Schema               $schema    The selection's schema.
	 * @param string               $register  The register as the caller named it.
	 * @param string               $schemaRef The schema as the caller named it.
	 * @param string               $action    The action key.
	 * @param list<string>         $ids       The selection.
	 * @param array<string, mixed> $inputs    The inputs.
	 *
	 * @return JSONResponse 202 with the job and the link to commit it.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One argument per part of the request being handed over.
	 */
	private function startJob(Schema $schema, string $register, string $schemaRef, string $action, array $ids, array $inputs): JSONResponse {
		try {
			$job = $this->bulk->start(schema: $schema, register: $register, schemaRef: $schemaRef, action: $action, ids: $ids, inputs: $inputs);
		} catch (BulkJobRefusedException $e) {
			return new JSONResponse(['error' => $e->getMessage(), 'reason' => $e->getReason()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(
			[
				'action' => $action,
				'job' => $job->jsonSerialize(),
				'links' => ['commit' => '/api/bulk-jobs/' . $job->getId() . '/commit'],
			],
			Http::STATUS_ACCEPTED
		);
	}//end startJob()

	/**
	 * Run the action on one selected object and summarise the outcome.
	 *
	 * @param string               $id       The object's id.
	 * @param string               $action   The action key.
	 * @param array<string, mixed> $inputs   The inputs.
	 * @param string               $register The register as the caller named it.
	 * @param string               $schema   The schema as the caller named it.
	 *
	 * @return array<string, mixed> The summary row.
	 */
	private function runOnSelected(string $id, string $action, array $inputs, string $register, string $schema): array {
		$object = $this->findObject(id: $id);
		$subjectSchema = null;
		if ($object !== null) {
			$subjectSchema = $this->macros->loadSchema(schema: (string)$object->getSchema());
		}

		if ($object === null || $subjectSchema === null) {
			return ['id' => $id, 'status' => 'failed', 'reason' => 'No such object'];
		}

		$one = $this->runner->run(
			object: $object,
			schema: $subjectSchema,
			key: $action,
			given: $inputs,
			subject: ['uuid' => (string)$object->getUuid(), 'register' => $register, 'schema' => $schema]
		);

		if ($one['status'] === Http::STATUS_OK || $one['status'] === Http::STATUS_ACCEPTED) {
			return [
				'id' => $id,
				'status' => 'succeeded',
				'run' => ($one['body']['run'] ?? null),
				'outcome' => ($one['body']['outcome'] ?? ($one['body']['status'] ?? null)),
			];
		}

		return ['id' => $id, 'status' => 'failed', 'reason' => (string)($one['body']['message'] ?? ($one['body']['error'] ?? 'refused'))];
	}//end runOnSelected()

	/**
	 * The schema's declaration of an action, or null when it cannot be read.
	 *
	 * A declaration that cannot be resolved reads as "not offered", the same
	 * answer findObject() and loadSchema() give, so a broken schema
	 * configuration is a 400 on the selection and never a 500. The cause is
	 * logged, so a defect behind the 400 still leaves a trace.
	 *
	 * @param Schema $schema   The selection's schema.
	 * @param string $action   The action key.
	 * @param string $register The register as the caller named it.
	 *
	 * @return ObjectActionDefinition|null The declaration.
	 */
	private function findDefinition(Schema $schema, string $action, string $register): ?ObjectActionDefinition {
		try {
			return $this->definitions->find(schema: $schema, key: $action, register: $register);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Object action "{action}" of schema {schema} could not be read: {message}',
				['action' => $action, 'schema' => (string)($schema->getSlug() ?? $schema->getId()), 'message' => $e->getMessage(), 'exception' => $e]
			);
			return null;
		}
	}//end findDefinition()

	/**
	 * The object, or null when there is none the caller can reach.
	 *
	 * The read is a lookup before running an action, not the reader opening
	 * the object, so it stays out of their recently opened list.
	 *
	 * @param string $id The object's id, uuid or slug.
	 *
	 * @return ObjectEntity|null The object.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) WriteCause::asLookup() is the ambient audit-cause frame; there is no instance to inject.
	 */
	private function findObject(string $id): ?ObjectEntity {
		try {
			return WriteCause::asLookup(fn () => $this->objects->find(id: $id));
		} catch (\Throwable) {
			return null;
		}
	}//end findObject()
}//end class
