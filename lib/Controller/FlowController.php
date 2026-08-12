<?php

/**
 * FlowController — the flow surface every app's builder talks to.
 *
 * Two halves. The catalog endpoints expose the triggers a flow may subscribe to
 * and the node types the engine can execute, so a builder populates its palette
 * from the backend rather than a hard-coded list — the catalog is
 * authoritative, and a builder that invents its own ids produces flows the
 * engine cannot run. The CRUD endpoints read and write the flow definitions
 * themselves, which live in one native store shared by every app and are scoped
 * per app by `app`.
 *
 * Every CRUD method goes through `FlowService`, never `FlowMapper`, because the
 * service is where the organisation scoping and the per-flow guard live. A
 * controller method that reached for the mapper directly would be reproducing
 * the unguarded lookups this subsystem already shipped once.
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
 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Service\Flow\EventCatalogService;
use OCA\OpenRegister\Service\Flow\FlowAccess;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\WorkflowEngine\IManager;

/**
 * Catalog and CRUD endpoints for flows.
 *
 * Ten of the eleven public methods are routed endpoints — the class's public
 * surface IS the flow API, so the count measures how many routes flows have
 * rather than how much this class does. Splitting the catalogue reads off the
 * resource writes would put two controllers behind one `/api/flows` prefix and
 * make the routes file the only place the API's shape is visible.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * Coupling is 13 for the same reason: this class IS the flow API surface, so it
 * names the two catalogues, the preflight, the registry, the state mapper, the
 * service and the entity, plus the framework's own request/response/attribute
 * types. Twelve of the thirteen are already required to serve the routes; the
 * thirteenth is `FlowDeadEnd`, and refusing it would mean the run endpoint goes
 * back to letting that refusal escape as an HTML 500. Splitting the class to
 * lower the number would put two controllers behind one `/api/flows` prefix,
 * which costs more than it buys.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
 */
class FlowController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App id.
	 * @param IRequest $request HTTP request.
	 * @param EventCatalogService $eventCatalog The flow trigger catalog.
	 * @param FlowNodeRegistry $nodes The registered flow-step types.
	 * @param FlowStateMapper $flowState Reads state a flow keeps between runs.
	 * @param FlowNodePreflight $preflight Resolves a document's step types.
	 * @param FlowService $flows Reads, writes and runs flow definitions.
	 * @param FlowAccess $access Who may do what with a flow.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly EventCatalogService $eventCatalog,
		private readonly FlowNodeRegistry $nodes,
		private readonly FlowStateMapper $flowState,
		private readonly FlowNodePreflight $preflight,
		private readonly FlowService $flows,
		private readonly FlowAccess $access,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Refuse unless the caller holds the named right.
	 *
	 * Returns a RESPONSE rather than throwing, because every method here
	 * answers in JSON and an OCS exception escaping a plain `Controller`
	 * surfaces as a 500 — a right that reads as a server fault is one nobody
	 * can act on.
	 *
	 * These four rights are seeded `@authenticated`, which is exactly the
	 * access that existed before them: the flow endpoints were
	 * `@NoAdminRequired` and scoped only by organisation. So this changes
	 * nothing on an untouched instance, and gives an admin something to
	 * tighten.
	 *
	 * @param string $action The action name.
	 *
	 * @return JSONResponse|null A 403 when refused, null when allowed.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-creating-editing-and-running-a-flow-are-named-rights
	 */
	private function denyUnless(string $action): ?JSONResponse {
		$user = $this->access->currentUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not signed in.'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->access->may(user: $user, action: $action) === true) {
			return null;
		}

		return new JSONResponse(
			['error' => sprintf('You do not have the "%s" right.', $action)],
			Http::STATUS_FORBIDDEN
		);

	}//end denyUnless()

	/**
	 * Whether the calling user is a Nextcloud administrator.
	 *
	 * Fails CLOSED: an unresolvable session answers false, so the reduced
	 * SCOPE_USER palette is what gets served rather than the admin one.
	 *
	 * @return boolean Whether the caller is an administrator.
	 */
	private function callerIsAdmin(): bool {
		// Delegated rather than reimplemented: FlowAccess owns the fail-closed
		// rule (an unresolvable session is NOT an administrator), and having it
		// in one place is the point of the extraction.
		return $this->access->callerIsAdmin();
	}//end callerIsAdmin()

	/**
	 * Return the declarative-flow trigger catalog.
	 *
	 * @return JSONResponse `{ results: [{id,label,group,legacy?}], total }`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function eventCatalog(): JSONResponse {
		$results = $this->eventCatalog->getCatalog();

		return new JSONResponse(
			[
				'results' => $results,
				'total' => count($results),
			]
		);
	}//end eventCatalog()

	/**
	 * Return the flow NODE catalog — the step types a flow may use.
	 *
	 * The trigger catalog above answers "what can start a flow"; this answers
	 * "what can a flow do", which had no HTTP surface at all. A flow is authored
	 * as an object whose `edges[].type` names a registered node, so without this
	 * a builder has to hardcode the list — and that list goes stale silently the
	 * moment an app contributes a leaf, because an unknown type only surfaces
	 * when the flow RUNS (FlowNodeRegistry::get() throws). Apps really do
	 * contribute: openconnector ships source-call and synchronization-run,
	 * hermiq ships agent-step.
	 *
	 * Scope-filtered, so a non-administrator is never offered a step they could
	 * not run. Every node implements `isAvailableForScope()` already.
	 *
	 * The scope is derived from the CALLER, not from a request parameter. It
	 * used to default to SCOPE_ADMIN and drop to SCOPE_USER only when the
	 * request said `?scope=user`, which meant the docblock claim above was
	 * false in the one direction that matters: a non-administrator who simply
	 * omitted the parameter — the default for anything that is not deliberately
	 * asking for the reduced view — was served the ADMIN palette. The parameter
	 * was the caller's to set, so it could never have been a privilege check.
	 *
	 * An administrator may still pass `?scope=user` to preview what a
	 * non-administrator sees; that direction only ever narrows the result. A
	 * non-administrator is pinned to SCOPE_USER regardless of what they send.
	 *
	 * @return JSONResponse `{ results: [{id,displayName,description,icon}], total }`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/visual-flow-builder/specs/flow-builder/spec.md
	 */
	public function nodeCatalog(): JSONResponse {
		$scope = IManager::SCOPE_USER;
		if ($this->callerIsAdmin() === true && $this->request->getParam('scope') !== 'user') {
			$scope = IManager::SCOPE_ADMIN;
		}

		$results = $this->nodes->palette(scope: $scope);

		return new JSONResponse(
			[
				'results' => $results,
				'total' => count($results),
			]
		);
	}//end nodeCatalog()

	/**
	 * The links one run-log entry earns, from the node that wrote it.
	 *
	 * The editor sends the entry and gets back whatever the contributing app
	 * says it points at — an openconnector call's source and call log, an agent
	 * step's session. OpenRegister does not know what any of those are, which
	 * is the whole point: the app that ships the node ships its links.
	 *
	 * POST, not GET. A log entry carries the run's payload, and a GET would put
	 * that data in a URL — and therefore in every access log, proxy and browser
	 * history it passes through.
	 *
	 * Never fails on a bad entry: an unknown node type, a removed app or a
	 * provider that throws all yield an empty list. This decorates a log an
	 * operator is already reading, and losing the log to decorate it would be
	 * the worse outcome.
	 *
	 * @return JSONResponse `{ results: [{label, href, icon?}] }`.
	 *
	 * @no-admin-idor-exempt This endpoint dereferences nothing. The log entry is
	 *       the caller's own POST body, and the only thing read on this side is
	 *       the node registry — `FlowNodeRegistry::logActions()` looks the entry's
	 *       `type` up and forwards the entry to that node, returning an empty list
	 *       for an unknown type. There is no stored object addressed by id here, so
	 *       there is no reference to substitute for another user's. Where a
	 *       contributing app's node DOES resolve something (openconnector's call
	 *       log, an agent session), that node authorises it — a requirement now
	 *       stated on IFlowNodeLogActions::logActions() rather than left implied,
	 *       because a guard added here could only check ids this app does not
	 *       understand.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
	 */
	public function logActions(): JSONResponse {
		$entry = (array)($this->request->getParam('entry') ?? []);

		return new JSONResponse(['results' => $this->nodes->logActions(entry: $entry)]);
	}//end logActions()

	/**
	 * What a flow is currently holding between its runs.
	 *
	 * The state itself has existed since #2219 and nodes can write it since
	 * #2221, but nothing could READ it from outside the engine — so a slot
	 * table was live data nobody could render. This is the surface a dashboard
	 * needs to answer "which slot is running what, and since when".
	 *
	 * A flow with no state yet returns an empty map rather than 404: "nothing
	 * claimed" is a perfectly good answer, and a widget should not have to
	 * special-case a flow's first tick.
	 *
	 * `?list=<key>` additionally serves that key as `results` — a LIST, one
	 * entry per slot, each carrying its own `slot` number. That exists because
	 * the state's natural shape and a table's required shape genuinely differ:
	 * a slot table is keyed by slot number so a claim is one lookup, while a
	 * manifest `object-table` requires an array at its `responsePath`. Without
	 * this a dashboard has to reshape the payload in code, which is the thing
	 * manifest-driven widgets exist to avoid.
	 *
	 * It is opt-in and names its key explicitly. Nothing is inferred from the
	 * shape of the data — a flow's state is arbitrary and "looks like a slot
	 * table" is not a contract.
	 *
	 * The flow is resolved through `FlowService` FIRST, and the state is only
	 * read once that resolution succeeds. This method used to hand the
	 * client-supplied `{flowId}` straight to `FlowStateMapper`, which applies no
	 * organisation scoping at all — so any authenticated user could read any
	 * other organisation's flow state by uuid. It was also the one method in
	 * this class that broke the invariant stated at the top of the file ("every
	 * CRUD method goes through FlowService, never FlowMapper"). A flow the
	 * caller may not see now 404s exactly like a flow that does not exist,
	 * which is the same non-oracle refusal `FlowService::find()` gives
	 * everywhere else.
	 *
	 * @param string $flowId The flow's uuid.
	 *
	 * @return JSONResponse `{ flowId, state, updated }`, plus `{ key, results, total }` with `?list=`, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/federation-scope-enforcement/specs/federation-scope-enforcement/spec.md
	 */
	public function state(string $flowId): JSONResponse {
		try {
			$this->flows->find(uuid: $flowId);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such flow'], Http::STATUS_NOT_FOUND);
		}

		$stored = $this->flowState->findByFlow(flowId: $flowId);
		$state = [];
		$updated = null;

		if ($stored !== null) {
			$payload = $stored->jsonSerialize();
			$state = (array)($payload['state'] ?? []);
			$updated = ($payload['updated'] ?? null);
		}

		$body = [
			'flowId' => $flowId,
			'state' => $state,
			'updated' => $updated,
		];

		$list = trim((string)$this->request->getParam('list', ''));
		if ($list !== '') {
			$results = $this->asList(value: ($state[$list] ?? []));

			$body['key'] = $list;
			$body['results'] = $results;
			$body['total'] = count($results);
		}

		return new JSONResponse($body);
	}//end state()

	/**
	 * Resolve a flow document's step types without saving it.
	 *
	 * The listener that guards the save path answers "may this be stored"; this
	 * answers "would it run", which a CI job, an editor and a deployment check
	 * all need to ask about a document they are not writing. It is the same
	 * FlowNodePreflight, so the two answers cannot drift.
	 *
	 * Always 200 with a verdict — a document that cannot run is a valid answer,
	 * not a failed request. `valid` is false only for findings no install can
	 * fix; a step type owned by an app this instance has not enabled comes back
	 * under `warnings` with `valid` still true, because installing that app is
	 * the fix and the document is not wrong.
	 *
	 * @return JSONResponse `{ valid, blocking, warnings, message? }`.
	 *
	 * @no-admin-idor-exempt Nothing stored is read or written. The document under
	 *       inspection arrives whole in the request body — `validate()` never
	 *       loads a flow by id, and pointedly does not, since the callers that
	 *       need it (a CI job, the editor, a deployment check) are asking about a
	 *       document they are NOT writing. `FlowNodePreflight::inspect()` walks
	 *       that literal array and compares each node's `type` against the node
	 *       registry. With no direct object reference in the request there is
	 *       nothing to substitute, which is the same structural argument the gate
	 *       recognises for session-scoped endpoints.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/or-flow-preflight/specs/flow-preflight/spec.md
	 */
	public function validate(): JSONResponse {
		$flow = $this->request->getParam('flow');
		if (is_array($flow) === false) {
			$flow = $this->request->getParams();
		}

		if ($this->preflight->looksLikeFlow(data: $flow) === false) {
			return new JSONResponse(
				[
					'valid' => false,
					'blocking' => [],
					'warnings' => [],
					'message' => 'Body does not describe a flow graph: it needs a non-empty "nodes" list whose '
						. 'entries carry an "id", and a non-empty "edges" list whose entries carry "from"/"to".',
				],
				Http::STATUS_BAD_REQUEST
			);
		}

		$report = $this->preflight->inspect(flow: $flow);
		$name = (string)($flow['name'] ?? 'flow');
		$body = [
			'valid' => ($report['blocking'] === []),
			'blocking' => $report['blocking'],
			'warnings' => $report['warnings'],
		];

		if ($report['blocking'] !== []) {
			$body['message'] = $this->preflight->describe(flow: $name, blocking: $report['blocking']);
		}

		return new JSONResponse($body);
	}//end validate()

	/**
	 * One state key as a list, with each entry carrying the key it was under.
	 *
	 * A FREE slot is emitted too, as `{slot: n, holder: null, …}` — not skipped.
	 * "Slot 3 of 10 is empty" is what an operator needs to see, and a table that
	 * silently omits free slots reads as a smaller pool rather than an idle one.
	 * That is also why the node materialises the whole table rather than storing
	 * only what is taken.
	 *
	 * @param mixed $value The state value.
	 *
	 * @return array<int, array> The list.
	 */
	private function asList(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$results = [];
		foreach ($value as $key => $entry) {
			$row = ['slot' => $key];
			if (is_array($entry) === true) {
				$row = array_merge($row, $entry);
			} elseif ($entry !== null) {
				$row['holder'] = $entry;
			}

			$results[] = $row;
		}

		return $results;
	}//end asList()

	/**
	 * List flows, newest first.
	 *
	 * `app` is the per-app scoping key: OpenConnector's index passes
	 * `openconnector`, hermiq passes `hermiq`, and OpenRegister's own index
	 * passes nothing and sees every app's flows. Organisation scoping is not a
	 * parameter — `FlowService` applies it unconditionally.
	 *
	 * @return JSONResponse `{ results, total, limit, offset }`.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$limit = min(200, max(1, (int)$this->request->getParam('limit', 100)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));

		$app = $this->request->getParam('app');
		$appFilter = null;
		if ($app !== null && trim((string)$app) !== '') {
			$appFilter = (string)$app;
		}

		$enabledFilter = null;
		$enabled = $this->request->getParam('enabled');
		if ($enabled !== null && $enabled !== '') {
			$enabledFilter = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
		}

		$flows = $this->flows->findAll(
			app: $appFilter,
			enabled: $enabledFilter,
			limit: $limit,
			offset: $offset
		);

		return new JSONResponse(
			[
				'results' => array_map(static fn (Flow $flow): array => $flow->jsonSerialize(), $flows),
				'total' => $this->flows->count(app: $appFilter),
				'limit' => $limit,
				'offset' => $offset,
			]
		);

	}//end index()

	/**
	 * Read one flow.
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return JSONResponse The flow, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $id): JSONResponse {
		try {
			$flow = $this->flows->find(uuid: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such flow'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($flow->jsonSerialize());
	}//end show()

	/**
	 * Create a flow.
	 *
	 * @return JSONResponse The stored flow, 201.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt No caller-supplied id to confuse: a create takes no
	 * uuid. `FlowService::flowToSave()` stamps `owner` from the session and
	 * `organisation` from the active one, and neither is in
	 * `applyEditableFields()`'s allowlist — so a payload cannot claim another
	 * user's identity. The allowlist IS the boundary here.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	#[NoAdminRequired]
	public function create(): JSONResponse {
		$denied = $this->denyUnless(action: 'flow.create');
		if ($denied !== null) {
			return $denied;
		}

		$data = $this->flowPayload();

		if (trim((string)($data['name'] ?? '')) === '') {
			return new JSONResponse(['error' => 'A flow needs a name.'], Http::STATUS_BAD_REQUEST);
		}

		$flow = $this->flows->save(data: $data);

		return new JSONResponse($this->savedBody(flow: $flow), Http::STATUS_CREATED);
	}//end create()

	/**
	 * The stored flow, plus any warning the author should see about it.
	 *
	 * The save SUCCEEDS and then warns. A half-wired graph is the normal state
	 * of one being authored, so refusing to store it would force the author to
	 * build the graph in an order that is never disconnected — which no editor
	 * can require. The refusal happens at RUN time instead, where the cost of a
	 * silent stop is actually paid ({@see FlowDeadEnd}).
	 *
	 * `warnings` is added ALONGSIDE the flow's own fields rather than wrapping
	 * them, so a client that ignores it keeps working unchanged.
	 *
	 * @param Flow $flow The stored flow.
	 *
	 * @return array The response body.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function savedBody(Flow $flow): array {
		$body = $flow->jsonSerialize();

		$report = $this->preflight->inspect(
			flow: [
				'nodes' => ($flow->getNodes() ?? []),
				'edges' => ($flow->getEdges() ?? []),
			]
		);

		$body['warnings'] = $report['warnings'];

		return $body;
	}//end savedBody()

	/**
	 * Update a flow.
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return JSONResponse The stored flow, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @no-admin-idor-exempt Guarded downstream: `FlowService::flowToSave()`
	 * resolves the uuid through `find()`, so an update to a flow the caller
	 * cannot see is refused with the same "no such flow" as one that does not
	 * exist — the id is never trusted to select the row. `owner` and
	 * `organisation` are outside `applyEditableFields()`'s allowlist, so an
	 * update cannot reassign ownership either.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	#[NoAdminRequired]
	public function update(string $id): JSONResponse {
		$denied = $this->denyUnless(action: 'flow.update');
		if ($denied !== null) {
			return $denied;
		}

		try {
			$flow = $this->flows->save(data: $this->flowPayload(), uuid: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such flow'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->savedBody(flow: $flow));
	}//end update()

	/**
	 * Delete a flow.
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return JSONResponse An empty 200, or 404.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$denied = $this->denyUnless(action: 'flow.delete');
		if ($denied !== null) {
			return $denied;
		}

		try {
			$this->flows->delete(uuid: $id);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such flow'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse([]);
	}//end destroy()

	/**
	 * Queue a manual run of a flow.
	 *
	 * @param string $id The flow uuid.
	 *
	 * @return JSONResponse The queued run 201, 404, or 409 when unrunnable.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	#[NoAdminRequired]
	public function run(string $id): JSONResponse {
		$denied = $this->denyUnless(action: 'flow.run');
		if ($denied !== null) {
			return $denied;
		}

		$subject = $this->request->getParam('subject', []);
		$context = $this->request->getParam('context', []);

		// `sync` executes the run inline and answers with the FINISHED run
		// rather than a queued one. Two callers want it: a person pressing Run
		// in the editor, who should see the outcome instead of a row that says
		// `queued`; and any test, which otherwise has to keep a background
		// worker alive and poll — a harness whose absence makes a perfectly
		// healthy engine look stuck.
		//
		// Accepts the string forms too: a query string carries `?sync=true`,
		// not a JSON boolean, and `(bool) 'false'` is TRUE — so reading this
		// with a bare cast would make sync=false run synchronously.
		$syncParam = $this->request->getParam('sync', false);
		$sync = ($syncParam === true || $syncParam === 'true' || $syncParam === '1' || $syncParam === 1);

		try {
			$flowRun = $this->flows->run(
				uuid: $id,
				subject: (array)$subject,
				context: (array)$context,
				sync: $sync
			);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such flow'], Http::STATUS_NOT_FOUND);
		} catch (FlowDeadEnd $e) {
			// A REFUSAL, not a fault. `FlowRunService::queue()` declines to run
			// a flow whose token cannot leave one of its nodes, and that is a
			// verdict about the caller's document — the caller can fix it by
			// wiring the node or ending it deliberately.
			//
			// Uncaught, it left the dispatcher to turn it into a bare 500 with
			// an HTML body, so the one thing the author needed — WHICH node
			// dead-ends — never reached them, and a routine authoring mistake
			// was indistinguishable from the server falling over. Measured
			// against the flow editor: pressing "Run now" on a single-step flow
			// produced exactly that 500, and the UI showed nothing at all.
			//
			// 409 rather than 400: the document was understood and stored
			// perfectly well: it is the flow's current STATE that conflicts
			// with running it, which is also why the refusal is recorded on the
			// flow itself (`status: error`) before this point.
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'nodes' => $e->getNodeIds(),
				],
				Http::STATUS_CONFLICT
			);
		}//end try

		return new JSONResponse($flowRun->jsonSerialize(), Http::STATUS_CREATED);
	}//end run()

	/**
	 * The flow fields carried on a create or update request.
	 *
	 * Read as a whole body rather than field by field so a partial update can
	 * be distinguished from one that blanks a field: `FlowService` only touches
	 * keys that are actually present, which it cannot do if absent keys arrive
	 * as nulls manufactured here.
	 *
	 * @return array<string, mixed> The submitted fields.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function flowPayload(): array {
		$body = $this->request->getParams();

		// Route placeholders and framework noise are not flow fields.
		unset($body['id'], $body['_route']);

		return $body;
	}//end flowPayload()
}//end class
