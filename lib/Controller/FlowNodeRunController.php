<?php

/**
 * Run ONE named node of a published flow, against ONE subject (or-flow-run-node).
 *
 * A separate controller rather than another method on the already-strained
 * `FlowController` (which documents its own PHPMD suppressions as "splitting
 * the class would put two controllers behind one prefix, which costs more
 * than it buys" — a judgement this endpoint does not have to inherit, since it
 * genuinely IS a separable concern: nothing here is flow CRUD or the catalogue
 * reads, and its authorization shape has nothing in common with either).
 *
 * WHAT THIS IS FOR. `documents-on-the-case` 3.3 (dossiq) needs a case-detail
 * button that generates a document from one flow node, without the caller
 * ever having run — or being trusted to run — the graph around it. Nothing
 * else in OpenRegister lets an app do that safely: `FlowController::run()`
 * runs the WHOLE flow under the flat, subject-blind `flow.run` right,
 * and `FlowRunController::test()` is an editor tool. RN-1
 * (`openspec/changes/or-flow-run-node/design.md`) decided this needs BOTH of
 * two independent, fail-closed checks:
 *
 *  1. the node type opted in ({@see IFlowDirectlyInvokable}) — an author
 *     decision, once, in code review;
 *  2. the CALLER holds OpenRegister's own object-RBAC permission on the
 *     SUBJECT object named in the request — evaluated on every call, the
 *     same {@see \OCA\OpenRegister\Service\Object\PermissionHandlerInterface} the
 *     patch/create object-op path already goes through.
 *
 * `flow.run` (the flow named-rights matrix `FlowAccess` guards) is
 * DELIBERATELY not consulted here — see design.md: it is subject-blind and
 * seeded `@authenticated`, so combining it would only make the check
 * stricter for no added subject-safety, and design.md is explicit that it
 * adds nothing this endpoint needs.
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
 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\FlowNodeCall;
use OCA\OpenRegister\Controller\Support\FlowNodeRunControllerServices;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Flow\FlowDeadEndInterface;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefusedInterface;
use OCA\OpenRegister\Service\Flow\FlowLocatorInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistryInterface;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraphInterface;
use OCA\OpenRegister\Service\Flow\FlowRunServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowServiceInterface;
use OCA\OpenRegister\Service\Flow\FlowUnattributedInterface;
use OCA\OpenRegister\Service\Flow\IFlowDirectlyInvokable;
use OCA\OpenRegister\Service\Flow\IFlowNode;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;
use OCA\OpenRegister\Service\Flow\FlowRunPass;
use OCA\OpenRegister\Service\Flow\FlowRunQueueOptions;

/**
 * `POST /api/flows/{id}/nodes/{nodeId}/run` and its GET description sibling.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Seven collaborators: the two
 * flow-side lookups (org-scoped flow, its published graph), the node
 * registry, the run/execution seam, and the three object-side lookups a
 * subject permission check needs (the object itself, its schema, and the
 * permission matrix). Every one is REQUIRED — this endpoint's whole reason to
 * exist is composing "does the flow/node resolve" with "may this caller act
 * on this object", and neither half is optional.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) 20 against 13, for the same
 * reason as the parameter count above plus the exception vocabulary `run()`
 * must distinguish (`FlowLifecycleRefused`, `FlowDeadEnd`, `FlowUnattributed`,
 * `DelegationRefused` all want different HTTP answers — collapsing them
 * loses exactly the information `FlowController::run()` already keeps for
 * the same reasons) and the two node-eligibility interfaces
 * (`IFlowDirectlyInvokable`, `IFlowNodeConfigForm`) RN-1/RN-2 are about.
 * Splitting `form()` and `run()` into two controllers would not lower this:
 * both need the same node resolution, which is the actual coupling.
 */
class FlowNodeRunController extends Controller {
	/**
	 * Resolves the calling user.
	 *
	 * @var IUserSession
	 */
	private readonly IUserSession $userSession;

	/**
	 * Org-scoped flow lookup — the same non-oracle
	 *
	 * @var FlowServiceInterface
	 */
	private readonly FlowServiceInterface $flows;

	/**
	 * Resolves the flow's PUBLISHED
	 *
	 * @var FlowPublishedGraphInterface
	 */
	private readonly FlowPublishedGraphInterface $publishedGraphs;

	/**
	 * Resolves the live flow document (for
	 *
	 * @var FlowLocatorInterface
	 */
	private readonly FlowLocatorInterface $subjects;

	/**
	 * Resolves a
	 *
	 * @var FlowNodeRegistryInterface
	 */
	private readonly FlowNodeRegistryInterface $nodes;

	/**
	 * Queues and executes the one node.
	 *
	 * @var FlowRunServiceInterface
	 */
	private readonly FlowRunServiceInterface $runner;

	/**
	 * Loads the subject, scoped to the CALLER's
	 *
	 * @var ObjectOperationsInterface
	 */
	private readonly ObjectOperationsInterface $objects;

	/**
	 * Resolves the subject's
	 *
	 * @var SchemaMapperInterface
	 */
	private readonly SchemaMapperInterface $schemas;

	/**
	 * The SAME object-RBAC evaluator the
	 *
	 * @var PermissionCheckInterface
	 */
	private readonly PermissionCheckInterface $permissions;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param FlowNodeRunControllerServices $services The flow node run controller services.
	 *                              {@see FlowRunService::executeNode()}'s
	 *                              version pin) — NOT used for its
	 *                              `resolveObject()`, which loads with RBAC
	 *                              off and is for background paths only; the
	 *                              subject here is loaded via `$objects`
	 *                              instead, under this caller's own RBAC and
	 *                              multitenancy.
	 */
	public function __construct(
		IRequest $request,
		FlowNodeRunControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->userSession = $services->platform->userSession;
		$this->flows = $services->graph->flows;
		$this->publishedGraphs = $services->graph->publishedGraphs;
		$this->subjects = $services->graph->subjects;
		$this->nodes = $services->graph->nodes;
		$this->runner = $services->execution->runner;
		$this->objects = $services->execution->objects;
		$this->schemas = $services->mappers->schemas;
		$this->permissions = $services->execution->permissions;

	}//end __construct()

	/**
	 * Describe a node before running it: its type, and its config form.
	 *
	 * The "GET-shaped describe-before-running call" design.md's RN-2
	 * anticipated: a caller (dossiq's manifest action, through nextcloud-vue's
	 * `open-form` dispatcher) needs the node's declared fields — including any
	 * `optionsFrom` reference — to render a picker BEFORE it has a config to
	 * submit. Gated the same way the POST is on the node-eligibility half
	 * (opt-in required, same 404), because a form describing a node's
	 * internal config vocabulary is part of the same reachability boundary
	 * `IFlowDirectlyInvokable` draws — a node nobody may invoke this way has
	 * nothing this endpoint should describe either.
	 *
	 * NOT gated on subject permission: there is no subject yet, only a
	 * question about which fields a form needs. `configForm()` describes
	 * field shape (labels, types, `optionsFrom` URLs), never subject data —
	 * whatever `optionsFrom` points at is its own, separately authorized,
	 * resource.
	 *
	 * @param string $id The flow's uuid.
	 * @param string $nodeId The node's id within the flow's published graph.
	 *
	 * @return JSONResponse `{id, type, configForm}` or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-a-direct-invoked-nodes-config-form-is-inspectable
	 */
	#[NoAdminRequired]
	public function form(string $id, string $nodeId): JSONResponse {
		$resolved = $this->resolveInvokableStep(flowId: $id, nodeId: $nodeId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		['node' => $node, 'step' => $step] = $resolved;

		$body = [
			'id' => $nodeId,
			'type' => (string)($step['type'] ?? ''),
		];

		// ABSENT, not empty, when the node declares no form — same convention
		// as FlowNodeRegistry::palette(), so a caller can tell "this node
		// described no form, fall back to raw JSON" from "this node has no
		// fields".
		if (($node instanceof IFlowNodeConfigForm) === true) {
			$body['configForm'] = array_values($node->configForm());
		}

		return new JSONResponse($body);
	}//end form()

	/**
	 * Run the named node against the named subject.
	 *
	 * @param string $id The flow's uuid.
	 * @param string $nodeId The node's id within the flow's published graph.
	 *
	 * @return JSONResponse The FlowRun (201), or a 4xx refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	#[NoAdminRequired]
	public function run(string $id, string $nodeId): JSONResponse {
		$resolved = $this->resolveInvokableStep(flowId: $id, nodeId: $nodeId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		$step = $resolved['step'];

		$subjectRef = (array)$this->request->getParam('subject', []);
		$subjectResolution = $this->resolveAuthorizedSubject(subjectRef: $subjectRef);
		if ($subjectResolution instanceof JSONResponse) {
			return $subjectResolution;
		}

		['object' => $object, 'ref' => $ref] = $subjectResolution;

		// The caller's config OVERLAYS the step's authored config — the
		// authored config is the flow author's defaults for this graph
		// position; the caller's is what a picker (sourced from
		// IFlowNodeConfigForm, above) actually chose for THIS call. Caller
		// values win: they are the more specific of the two.
		$callerConfig = (array)$this->request->getParam('config', []);
		$mergedStep = [
			'id' => $nodeId,
			'type' => (string)($step['type'] ?? ''),
			'config' => array_merge((array)($step['config'] ?? []), $callerConfig),
		];

		$run = $this->queueRun(call: new FlowNodeCall(flowId: $id, nodeId: $nodeId), ref: $ref);
		if ($run instanceof JSONResponse) {
			return $run;
		}

		$flowDocument = ($this->subjects->resolveFlow(flowId: $id) ?? ['id' => $id, 'nodes' => [$mergedStep], 'edges' => []]);

		// The caller's config goes in explicitly: executeNode() re-pins the
		// PUBLISHED graph, which replaces every node in $flowDocument, so a
		// $mergedStep placed there alone never reached the node.
		$run = $this->runner->runNode(
			pass: new FlowRunPass(run: $run, flow: $flowDocument, subject: $object),
			config: $callerConfig
		);

		return new JSONResponse($run->jsonSerialize(), Http::STATUS_CREATED);
	}//end run()

	/**
	 * Queue the direct run as the caller, answering each queue refusal with its 409.
	 *
	 * @param FlowNodeCall          $call The flow and the node invoked.
	 * @param array<string, string> $ref  The subject reference `{uuid, register, schema}`.
	 *
	 * @return FlowRun|JSONResponse The queued run, or the 409 refusal.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	private function queueRun(FlowNodeCall $call, array $ref): FlowRun|JSONResponse {
		$callerUid = $this->callerUid();

		try {
			return $this->runner->queueRun(
				flowId: $call->flowId,
				options: new FlowRunQueueOptions(
					subject: $ref,
					trigger: FlowRunServiceInterface::TRIGGER_DIRECT_NODE,
					context: [FlowRunServiceInterface::NODE_ID_CONTEXT_KEY => $call->nodeId],
					user: $callerUid
				)
			);
		} catch (FlowLifecycleRefusedInterface $e) {
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'reason' => $e->getReason(),
					'lifecycleStatus' => $e->getState(),
					'flowId' => $e->getFlowId(),
				],
				Http::STATUS_CONFLICT
			);
		} catch (FlowDeadEndInterface $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'nodes' => $e->getNodeIds()],
				Http::STATUS_CONFLICT
			);
		} catch (FlowUnattributedInterface|DelegationRefused $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_CONFLICT);
		}//end try
	}//end queueRun()

	/**
	 * Resolve the flow, its published node, and the node's opt-in — the half
	 * of RN-1 shared by both `form()` and `run()`.
	 *
	 * @param string $flowId The flow's uuid.
	 * @param string $nodeId The node's id.
	 *
	 * @return array{node: \OCA\OpenRegister\Service\Flow\IFlowNode, step: array<string, mixed>}|JSONResponse
	 *         The resolved node and its authored step, or a 404 refusal.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-a-node-type-opts-in-to-direct-invocation
	 */
	private function resolveInvokableStep(string $flowId, string $nodeId): array|JSONResponse {
		try {
			// Org-scoped, same non-oracle 404 as every other flow-by-id
			// endpoint: a flow the caller may not see reads exactly like one
			// that does not exist.
			$this->flows->find(uuid: $flowId);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		$graph = $this->publishedGraphs->graphOf(flowId: $flowId);
		if ($graph === null) {
			// No published version at all. Folded into the SAME 404 a missing
			// node id gets, rather than a distinct "not published" answer —
			// distinguishing them would let a caller probe whether a flow has
			// ever been published, which is not this endpoint's business to
			// reveal.
			return new JSONResponse(['error' => 'No such node: ' . $nodeId], Http::STATUS_NOT_FOUND);
		}

		$step = $this->publishedStep(graph: $graph, nodeId: $nodeId);
		if ($step === null) {
			return new JSONResponse(['error' => 'No such node: ' . $nodeId], Http::STATUS_NOT_FOUND);
		}

		$node = $this->invokableNode(type: (string)($step['type'] ?? ''));
		if ($node === null) {
			return new JSONResponse(['error' => 'No such node: ' . $nodeId], Http::STATUS_NOT_FOUND);
		}

		return ['node' => $node, 'step' => $step];
	}//end resolveInvokableStep()

	/**
	 * The first step of the published graph with this node id.
	 *
	 * @param array  $graph  The published graph.
	 * @param string $nodeId The node's id.
	 *
	 * @return array<string, mixed>|null The authored step, or null when the graph has none.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-a-node-type-opts-in-to-direct-invocation
	 */
	private function publishedStep(array $graph, string $nodeId): array|null {
		foreach ((array)($graph['nodes'] ?? []) as $candidate) {
			if ((string)($candidate['id'] ?? '') === $nodeId) {
				return $candidate;
			}
		}

		return null;
	}//end publishedStep()

	/**
	 * The registered node of this type, when it opted in to direct invocation.
	 *
	 * THE OPT-IN. A node type that has not implemented this is refused
	 * identically to a node id that does not exist at all (spec.md's own
	 * scenario) — there is no oracle for "exists but not invokable this
	 * way" versus "no such node".
	 *
	 * @param string $type The node type.
	 *
	 * @return IFlowNode|null The node, or null when unknown or not invokable this way.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-a-node-type-opts-in-to-direct-invocation
	 */
	private function invokableNode(string $type): IFlowNode|null {
		try {
			$node = $this->nodes->get(type: $type);
		} catch (Throwable $e) {
			return null;
		}

		if (($node instanceof IFlowDirectlyInvokable) === false) {
			return null;
		}

		return $node;
	}//end invokableNode()

	/**
	 * Resolve the subject and require the caller's own update permission on
	 * it — RN-1(c)'s second, independent half.
	 *
	 * Loaded through `ObjectService::find()` with `_rbac: false` but
	 * `_multitenancy: true`: multitenancy still scopes existence to the
	 * caller's own organisation (an object in another tenant answers 404,
	 * the same non-oracle refusal every cross-tenant lookup in this codebase
	 * gives), but RBAC is evaluated explicitly, next, so a 403 can say WHY —
	 * "you may not write to this object" is meaningfully different
	 * information from "no such object", and unlike a cross-tenant probe,
	 * exposing that difference within the caller's OWN organisation is not a
	 * new information leak: ADR-022/023's `object-op` path already answers
	 * exactly this way.
	 *
	 * @param array<string, mixed> $subjectRef `{uuid, register, schema}` from the request body.
	 *
	 * @return array{object: ObjectEntity, ref: array<string, string>}|JSONResponse
	 *         The loaded subject and its reference, or a 4xx refusal.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	private function resolveAuthorizedSubject(array $subjectRef): array|JSONResponse {
		$ref = $this->subjectReference(subjectRef: $subjectRef);
		if ($ref instanceof JSONResponse) {
			return $ref;
		}

		$object = $this->loadSubject(ref: $ref);
		if ($object === null) {
			return new JSONResponse(['error' => 'No such object.'], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->refuseSubjectUpdate(object: $object);
		if ($refusal !== null) {
			return $refusal;
		}

		return [
			'object' => $object,
			'ref' => $ref,
		];
	}//end resolveAuthorizedSubject()

	/**
	 * The trimmed subject reference, or the 400 when a part of it is missing.
	 *
	 * @param array<string, mixed> $subjectRef `{uuid, register, schema}` from the request body.
	 *
	 * @return array<string, string>|JSONResponse `{uuid, register, schema}`, or the 400.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	private function subjectReference(array $subjectRef): array|JSONResponse {
		$uuid = trim((string)($subjectRef['uuid'] ?? ''));
		$register = trim((string)($subjectRef['register'] ?? ''));
		$schema = trim((string)($subjectRef['schema'] ?? ''));

		if ($uuid === '' || $register === '' || $schema === '') {
			return new JSONResponse(
				['error' => 'A subject needs uuid, register and schema.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		return ['uuid' => $uuid, 'register' => $register, 'schema' => $schema];
	}//end subjectReference()

	/**
	 * Load the subject in the caller's organisation, RBAC evaluated separately.
	 *
	 * @param array<string, string> $ref `{uuid, register, schema}`.
	 *
	 * @return ObjectEntity|null The subject, or null when it does not load.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	private function loadSubject(array $ref): ObjectEntity|null {
		try {
			$object = $this->objects->find(
				id: $ref['uuid'],
				register: $ref['register'],
				schema: $ref['schema'],
				_rbac: false,
				_multitenancy: true,
				// This load is the boundary CHECK, not the business read — the
				// node itself performs whatever reads/writes it is built to,
				// and those are what should appear in the audit trail. Auditing
				// this preliminary load too would double every direct-invoke
				// call in the trail for a read that touched no shown data.
				_audit: false
			);
		} catch (Throwable $e) {
			return null;
		}

		if (($object instanceof ObjectEntity) === false) {
			return null;
		}

		return $object;
	}//end loadSubject()

	/**
	 * Refuse the run unless the caller may update the subject; no decision is a refusal.
	 *
	 * @param ObjectEntity $object The subject.
	 *
	 * @return JSONResponse|null The 403, or null when the caller may update it.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-direct-invocation-is-authorized-against-the-subject-object
	 */
	private function refuseSubjectUpdate(ObjectEntity $object): JSONResponse|null {
		try {
			$schemaEntity = $this->schemas->find(id: (string)$object->getSchema(), _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// The object resolved but its own schema did not — an internal
			// inconsistency, not the caller's fault, but still no way to
			// evaluate a permission. Fails CLOSED: no way to decide is a
			// refusal, never an allow.
			return new JSONResponse(['error' => 'The subject\'s schema could not be resolved.'], Http::STATUS_FORBIDDEN);
		}

		$permitted = $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schemaEntity, action: 'update'))
				->askedBy(userId: $this->callerUid(), objectOwner: $object->getOwner())
				->about(object: $object, register: null),
			_rbac: true
		);

		if ($permitted === false) {
			return new JSONResponse(
				['error' => 'You do not have permission to act on this object.'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refuseSubjectUpdate()

	/**
	 * The current caller's uid, or null when anonymous.
	 *
	 * @return string|null
	 */
	private function callerUid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end callerUid()

}//end class
