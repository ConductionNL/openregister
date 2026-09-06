<?php

/**
 * Run history and retry — the execution-tooling surface over flow runs.
 *
 * A run is persisted with everything a person needs to work with it: its
 * status, its per-step log, the items it carried, its error. This controller
 * exposes that — list runs (filter by flow and status), inspect one, retry a
 * finished one, and requeue a dead-lettered one. It is the answer to "what did
 * my flow do, and can I run it again".
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
 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller;

use OCA\OpenRegister\Db\AuditFlowAttribution;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowDeadEnd;
use OCA\OpenRegister\Service\Flow\FlowLifecycleRefused;
use OCA\OpenRegister\Service\Flow\FlowLocator;
use OCA\OpenRegister\Exception\FlowSignalRefused;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\Flow\FlowRunSignalService;
use OCA\OpenRegister\Service\Flow\FlowService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use stdClass;
use Throwable;

/**
 * REST surface for inspecting and retrying flow runs.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Two over, from the run guard's
 * FlowService. It exists so a flow can be resolved under the CALLER's scoping at
 * the request boundary — the resolver deliberately loads with RBAC off for the
 * engine, and inheriting that bypass here is what left retry() open to an IDOR.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Over budget for the same
 * reason: the two authorization concerns this controller now carries — the run
 * guard (retry/test may not run somebody else's flow) and history scoping (a run
 * log is subject data, so it is visible per caller) — are both request-boundary
 * checks. They belong at the boundary rather than in the engine, which is
 * deliberately unauthenticated because it runs flows as their owner with no
 * session. Moving them out would either re-open the bypass or add a second
 * indirection over four small private helpers.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   Eleven constructor parameters, the
 * last four nullable-with-default precisely so adding them broke no existing
 * construction site. They are collaborators of those same guards, not options.
 */
class FlowRunController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app id.
	 * @param IRequest $request The request.
	 * @param FlowRunMapper $mapper Reads runs.
	 * @param FlowRunService $runner Retries, requeues and runs.
	 * @param FlowLocator $resolvers Resolves a flow id to its document.
	 * @param IUserSession $userSession Attributes a retried run to the caller.
	 * @param OrganisationService $organisationService Scopes the active-runs list to the caller's tenant.
	 * @param IGroupManager|null $groupManager Distinguishes an administrator, who gets
	 *                                         the unscoped run history. Nullable so
	 *                                         adding it is not a fatal at existing
	 *                                         construction sites; absent means "not an
	 *                                         admin", which SCOPES rather than widens.
	 * @param FlowService|null $flows Reads which flows the caller owns, from the
	 *                                native flow store. Nullable for the same
	 *                                reason as $groupManager: absent yields no
	 *                                owned ids, which scopes rather than widens.
	 * @param AuditFlowAttribution|null $auditTrails Reads the attribution stamped on
	 *                                           audit rows, for the objects a run
	 *                                           touched. Nullable and LAST so
	 *                                           adding it shifts no positional
	 *                                           caller; absent, the endpoint
	 *                                           reports the surface unavailable
	 *                                           rather than an empty run.
	 * @param FlowRunSignalService|null $signalService The guarded signal seam the
	 *                                                 resume endpoints delegate to.
	 *                                                 Nullable and appended so no
	 *                                                 construction site shifts;
	 *                                                 absent, one is built on
	 *                                                 demand from this
	 *                                                 controller's own
	 *                                                 collaborators.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly FlowRunMapper $mapper,
		private readonly FlowRunService $runner,
		private readonly FlowLocator $resolvers,
		private readonly IUserSession $userSession,
		private readonly OrganisationService $organisationService,
		private readonly ?IGroupManager $groupManager = null,
		private readonly ?FlowService $flows = null,
		// Appended LAST and nullable on purpose: a new constructor argument
		// inserted anywhere else shifts every positional caller, and the
		// resulting TypeError names the argument AFTER the one that moved.
		private readonly ?AuditFlowAttribution $auditTrails = null,
		private readonly ?FlowRunSignalService $signalService = null,
	) {
		parent::__construct(appName: $appName, request: $request);

	}//end __construct()

	/**
	 * List runs, newest first, optionally filtered by flow and status.
	 *
	 * @return JSONResponse The runs plus the paging window.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): JSONResponse {
		$flowId = $this->request->getParam('flowId');
		$status = $this->request->getParam('status');
		$limit = min(200, max(1, (int)$this->request->getParam('limit', 50)));
		$offset = max(0, (int)$this->request->getParam('offset', 0));

		$flowFilter = null;
		if ($flowId !== null) {
			$flowFilter = (string)$flowId;
		}

		$statusFilter = null;
		if ($status !== null) {
			$statusFilter = (string)$status;
		}

		// Scope the history. Until this landed the endpoint returned EVERY run on
		// the instance to any authenticated caller — including each run's log,
		// which records the subject data the flow touched. Design D7 of
		// `shared-credentials-and-flows`: a recipient sees their OWN runs; runs
		// triggered by the owner or by other recipients stay invisible.
		//
		// A null uid means no scoping, which is correct only for an administrator.
		$requesterUid = null;
		$ownedFlowIds = [];
		if ($this->isAdmin() === false) {
			$requesterUid = $this->callerUid();
			$ownedFlowIds = $this->flowIdsOwnedByCaller();

			// No session means there is nobody to scope to — and a null uid
			// reaches findAllRuns() as "do not scope", which is an
			// ADMINISTRATOR's semantics. Falling through would therefore turn
			// the absence of a caller into the widest possible read. Return
			// empty instead: a non-admin with no identity owns no runs.
			if ($requesterUid === null || $requesterUid === '') {
				return new JSONResponse(
					[
						'results' => [],
						'limit' => $limit,
						'offset' => $offset,
					]
				);
			}
		}

		$runs = $this->mapper->findAllRuns(
			flowId: $flowFilter,
			status: $statusFilter,
			limit: $limit,
			offset: $offset,
			requesterUid: $requesterUid,
			ownedFlowIds: $ownedFlowIds
		);

		return new JSONResponse(
			[
				'results' => array_map(static fn (FlowRun $r): array => $r->jsonSerialize(), $runs),
				'limit' => $limit,
				'offset' => $offset,
			]
		);

	}//end index()

	/**
	 * The runs that are still going, for the caller's organisation.
	 *
	 * This is the read behind the shared "running flows" widget every app can
	 * place on a dashboard, so it is deliberately narrow: non-terminal runs
	 * only, newest first, a bounded list plus an honest total, and each run
	 * carrying the flow's NAME rather than only its id — a uuid on a dashboard
	 * tells a person nothing.
	 *
	 * Scoping is strict (see FlowRunMapper::findActive): a caller with no
	 * resolvable organisation gets an empty list rather than everybody's runs.
	 *
	 * The two surfaces scope DIFFERENTLY on purpose. This one is a tenant view of
	 * live activity — a dashboard widget, deliberately showing the organisation's
	 * runs. `index()` is the history surface, and history carries each run's LOG,
	 * which records the subject data the flow touched; it is therefore scoped per
	 * CALLER (runs you triggered, plus runs of flows you own) per design D7 of
	 * `shared-credentials-and-flows`. Until that landed `index()` was unscoped and
	 * returned every run on the instance to any authenticated user.
	 *
	 * An optional `subject` (a subject object uuid) narrows the list to the
	 * runs anchored to that one object: a case detail page's view of the
	 * engine. It narrows INSIDE the organisation scope and can never widen it;
	 * the mapper applies both predicates, and the total counts the filtered
	 * set. Without `subject` the read is bit-identical to what it was.
	 *
	 * @return JSONResponse `{results, total, limit}` — the live runs.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function active(): JSONResponse {
		$limit = $this->pageLimit();
		$subject = $this->subjectFilter();
		$organisation = $this->activeOrganisation();

		if ($organisation === null) {
			return new JSONResponse(['results' => [], 'total' => 0, 'limit' => $limit]);
		}

		$runs = $this->mapper->findActive(organisation: $organisation, limit: $limit, subject: $subject);
		$total = $this->mapper->countActive(organisation: $organisation, subject: $subject);

		return $this->page(runs: $runs, total: $total, limit: $limit);

	}//end active()

	/**
	 * The finished runs on ONE subject object: a case page's run history.
	 *
	 * The other half of the case view. A flow that completed on a case must
	 * not look like nothing ever happened, so this returns the terminal runs
	 * (`FlowRun::TERMINAL`: completed, stopped, dead_letter, failed) anchored
	 * to the given subject, newest first, bounded, with an honest total.
	 *
	 * The subject is REQUIRED. There is no org-wide "everything that ever
	 * finished" here: `or-flow-active-runs` requires the history surface
	 * (`index()`, with its per-caller visibility rule) to stay unchanged, and
	 * this read exists beside it rather than as a loosening of it. A request
	 * without a subject is refused, not answered widely.
	 *
	 * Authorization is the organisation scope, exactly as on `active()`: the
	 * mapper's organisation predicate is unconditional, a caller with no
	 * resolvable organisation reads nothing without a query being issued, and
	 * a subject uuid from another tenant matches zero rows. Rows are the same
	 * summarised shape as the live read, so a widget renders one list.
	 *
	 * @return JSONResponse `{results, total, limit}`, or 400 when no subject was given.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function completedForSubject(): JSONResponse {
		$subject = $this->subjectFilter();
		if ($subject === null) {
			return new JSONResponse(
				['error' => 'The completed-runs read needs a subject: pass the subject object uuid as `subject`.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$limit = $this->pageLimit();
		$organisation = $this->activeOrganisation();

		if ($organisation === null) {
			return new JSONResponse(['results' => [], 'total' => 0, 'limit' => $limit]);
		}

		$runs = $this->mapper->findCompletedForSubject(organisation: $organisation, subject: $subject, limit: $limit);
		$total = $this->mapper->countCompletedForSubject(organisation: $organisation, subject: $subject);

		return $this->page(runs: $runs, total: $total, limit: $limit);

	}//end completedForSubject()

	/**
	 * The bounded page size for the two case-widget reads, capped at 50.
	 *
	 * @return integer The limit.
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	private function pageLimit(): int {
		return min(50, max(1, (int)$this->request->getParam('limit', 10)));
	}//end pageLimit()

	/**
	 * The `subject` request parameter as a uuid, or null when none was given.
	 *
	 * Blank and non-string values count as absent: a filter that is not a
	 * uuid cannot name a subject, and treating it as one would either match
	 * nothing (misleading) or be coerced into something the caller did not
	 * send (worse).
	 *
	 * @return string|null The subject object uuid.
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	private function subjectFilter(): ?string {
		$subject = $this->request->getParam('subject');
		if (is_string($subject) === false) {
			return null;
		}

		$subject = trim($subject);
		if ($subject === '') {
			return null;
		}

		return $subject;
	}//end subjectFilter()

	/**
	 * A bounded list of summarised runs plus its honest total.
	 *
	 * Shared by the live read and the completed read so the two cannot drift
	 * apart on shape: the spec makes one row contract a requirement.
	 *
	 * @param array<int, FlowRun> $runs The page of runs.
	 * @param integer $total How many runs matched in all.
	 * @param integer $limit The page size that was applied.
	 *
	 * @return JSONResponse `{results, total, limit}`.
	 *
	 * @spec openspec/changes/flow-runs-subject-scope/specs/flow-runs-subject-scope/spec.md
	 */
	private function page(array $runs, int $total, int $limit): JSONResponse {
		return new JSONResponse(
			[
				'results' => array_map(fn (FlowRun $run): array => $this->summarise(run: $run), $runs),
				'total' => $total,
				'limit' => $limit,
			]
		);
	}//end page()

	/**
	 * The caller's active organisation uuid, or null when none resolves.
	 *
	 * @return string|null The organisation uuid.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 */
	private function activeOrganisation(): ?string {
		$uuid = $this->organisationService->getActiveOrganisation()?->getUuid();
		if ($uuid === null || $uuid === '') {
			return null;
		}

		return (string)$uuid;
	}//end activeOrganisation()

	/**
	 * A run reduced to what a dashboard row needs.
	 *
	 * The full `jsonSerialize()` carries the marking, the items and the whole
	 * step log — kilobytes per run that a list view never renders, and items
	 * can hold the record data itself. Summarising keeps the widget's payload
	 * proportional to what it shows, and keeps run *contents* on the
	 * single-run endpoint where a caller asks for one run explicitly.
	 *
	 * @param FlowRun $run The run to summarise.
	 *
	 * @return array<string, mixed> The row shape.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 */
	private function summarise(FlowRun $run): array {
		$flowId = (string)$run->getFlowId();
		$created = $run->getCreated();

		return [
			'uuid' => $run->getUuid(),
			'flowId' => $flowId,
			'flowName' => $this->flowName(flowId: $flowId),
			'status' => $run->getStatus(),
			'trigger' => $run->getTrigger(),
			'startedBy' => $run->getTriggeredBy(),
			'subject' => [
				'uuid' => $run->getSubjectUuid(),
				'register' => $run->getSubjectRegister(),
				'schema' => $run->getSubjectSchema(),
			],
			'step' => $this->currentStep(run: $run),
			'resumeAt' => $run->getResumeAt()?->format('c'),
			'created' => $created?->format('c'),
			'updated' => $run->getUpdated()?->format('c'),
		];

	}//end summarise()

	/**
	 * The flow's human name, falling back to its id.
	 *
	 * Resolution is memoised per flow id by FlowLocator, so a list of
	 * runs over a handful of flows costs one resolve per DISTINCT flow rather
	 * than one per row. A flow whose owning app is disabled no longer resolves;
	 * the id is returned so the row still identifies something.
	 *
	 * @param string $flowId The flow id.
	 *
	 * @return string The name, or the id.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 */
	private function flowName(string $flowId): string {
		$flow = $this->resolvers->resolveFlow(flowId: $flowId);
		$name = trim((string)($flow['name'] ?? ''));

		if ($name === '') {
			return $flowId;
		}

		return $name;
	}//end flowName()

	/**
	 * Where the run currently is, as a step label — or null when unknown.
	 *
	 * The marking is the Petri net's token placement: the places that hold a
	 * token are the steps the run sits on right now. That is the one piece of
	 * "what is it doing" a person can read off a live run, so it is worth the
	 * one line it takes to surface.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return string|null The step name(s), comma-joined.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 */
	private function currentStep(FlowRun $run): ?string {
		$marking = $run->getMarking();
		if (is_array($marking) === false || $marking === []) {
			return null;
		}

		$places = array_keys($marking);

		return implode(', ', array_map(static fn ($place): string => (string)$place, $places));
	}//end currentStep()

	/**
	 * One run in full — its log, items, context and error.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return JSONResponse The run, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function show(string $uuid): JSONResponse {
		// Scoped, as `index()` has been since `shared-credentials-and-flows`
		// D7. It was not, and the omission was the whole point of that scoping:
		// a run's serialisation carries its log, which records the subject data
		// the flow touched, so an unscoped read by uuid handed any authenticated
		// caller the contents of anyone's run.
		$run = $this->visibleRun(uuid: $uuid);
		if ($run === null) {
			return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($run->jsonSerialize());
	}//end show()

	/**
	 * The objects one run touched, grouped by the node that touched them.
	 *
	 * A run recorded what it DID (its steps) and, of what it did it TO, only
	 * the object that triggered it. This reads back the attribution stamped on
	 * every audit row the run caused, which is the other half.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return JSONResponse The touched objects grouped by node, or 404.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function objects(string $uuid): JSONResponse {
		// Resolved through the SAME visibility rule as reading the run itself.
		// A new endpoint that answered for runs `show()` refuses would widen
		// access without anything looking like an access change.
		$run = $this->visibleRun(uuid: $uuid);
		if ($run === null) {
			return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
		}

		if ($this->auditTrails === null) {
			return new JSONResponse(['error' => 'Audit trail unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$rows = $this->auditTrails->findByRun(runUuid: $uuid);

		$byNode = [];
		foreach ($rows as $row) {
			$node = (string)$row->getFlowNode();

			if (isset($byNode[$node]) === false) {
				$byNode[$node] = [
					'node' => $node,
					'step' => $row->getFlowStep(),
					'objects' => [],
				];
			}

			$byNode[$node]['objects'][] = [
				'objectUuid' => $row->getObjectUuid(),
				'register' => $row->getRegister(),
				'schema' => $row->getSchema(),
				'action' => $row->getAction(),
				'step' => $row->getFlowStep(),
				'created' => $row->getCreated()?->format('c'),
				'auditUuid' => $row->getUuid(),
			];
		}

		// Step order, so a reader follows the run in the order it happened
		// rather than in whatever order the rows came back.
		usort(
			$byNode,
			static fn (array $a, array $b): int => (((int)$a['step']) <=> ((int)$b['step']))
		);

		return new JSONResponse(
			[
				'run' => $uuid,
				'flowId' => $run->getFlowId(),
				// An empty list is the honest answer for a run that wrote
				// nothing, and for a suspended run that has not written
				// anything YET. Neither is an error, and neither is withheld
				// until the run finishes.
				// usort() re-indexed $byNode into a list, so no array_values()
				// here: it would be a no-op that reads as a safeguard.
				'nodes' => $byNode,
			]
		);
	}//end objects()

	/**
	 * Resolve a run by uuid, or null when this caller may not see it.
	 *
	 * One place, so `show()` and `objects()` cannot drift apart on who may read
	 * a run. Delegates the predicate itself to the mapper, which is where the
	 * list read gets it too.
	 *
	 * A non-admin with no session resolves to null rather than falling through:
	 * a null uid means "no scoping" at the mapper, which is an ADMINISTRATOR's
	 * semantics, so treating an absent caller as one would turn having no
	 * identity into the widest possible read.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return FlowRun|null The run, or null when absent or not visible.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-object-attribution/spec.md
	 */
	private function visibleRun(string $uuid): ?FlowRun {
		if ($this->isAdmin() === true) {
			return $this->mapper->findByUuidVisibleTo(uuid: $uuid, requesterUid: null);
		}

		$uid = $this->callerUid();
		if ($uid === null || $uid === '') {
			return null;
		}

		return $this->mapper->findByUuidVisibleTo(
			uuid: $uuid,
			requesterUid: $uid,
			ownedFlowIds: $this->flowIdsOwnedByCaller()
		);
	}//end visibleRun()

	/**
	 * Retry a finished run: queue a fresh one, leave the original untouched.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return JSONResponse The new run, or a 4xx when the source cannot be retried.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function retry(string $uuid): JSONResponse {
		try {
			$run = $this->mapper->findByUuid($uuid);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->refuseUnlessRunnable(flowId: (string)$run->getFlowId());
		if ($refusal !== null) {
			return $refusal;
		}

		$new = $this->runner->retry($run);
		if ($new === null) {
			// The source is not terminal — queued, running, or suspended. Retry
			// is for finished runs; the others are already progressing.
			return new JSONResponse(
				['error' => 'Only a finished run can be retried; this one is ' . $run->getStatus() . '.'],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse($new->jsonSerialize(), Http::STATUS_CREATED);
	}//end retry()

	/**
	 * Tell a suspended run that the thing it was waiting for has happened.
	 *
	 * The delivery point for {@see FlowSuspension} with no `resumeAt` — an
	 * approval granted, a webhook received, a child run finished. Without it
	 * such a run could never be woken by anything, and because `hasActiveRun()`
	 * counts suspended runs, it also blocked its flow from being scheduled ever
	 * again.
	 *
	 * Guarded by the same ownership check as `retry()`, and for the same reason:
	 * resuming somebody else's run is the same IDOR as re-running it.
	 *
	 * Accepted on a SUSPENDED run only. The response is the parked run, not a
	 * finished one — the worker advances it on its next pass.
	 *
	 * @param string $uuid The run uuid.
	 *
	 * @return JSONResponse The parked run, or a 4xx when it cannot be signalled.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function resume(string $uuid): JSONResponse {
		try {
			$run = $this->mapper->findByUuid($uuid);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
		}

		$refusal = $this->refuseUnlessRunnable(flowId: (string)$run->getFlowId());
		if ($refusal !== null) {
			return $refusal;
		}

		$payload = $this->request->getParams();
		// Routing artefacts, not part of what the signaller is telling the run.
		unset($payload['uuid'], $payload['_route']);

		// The guard and the delivery are ONE seam — the same one a PHP consumer
		// calls — so who may answer is decided in exactly one place.
		try {
			$signalled = $this->signals()->signalRunAs(run: $run, payload: $payload, actorUid: $this->callerUid());
		} catch (FlowSignalRefused $refused) {
			return $this->refusalResponse(refused: $refused, run: $run, verb: 'resumed');
		}

		return new JSONResponse($signalled->jsonSerialize());
	}//end resume()

	/**
	 * Deliver a signal addressed by BUSINESS KEY instead of run uuid.
	 *
	 * The caller that knows "the vote on proposal X closed" does not know a
	 * run uuid; an await-signal step that declared a `correlationKey` made
	 * its suspension addressable by that key. Resolution is FAIL-CLOSED in
	 * both directions (flow-approval-consolidation design D-7):
	 *
	 * - zero matches is a 404 and the signal is NOT buffered: a run that
	 *   suspends with that key later was never the addressee
	 * - more than one match is a 409 and wakes NOTHING: picking one is
	 *   picking wrong half the time, silently
	 *
	 * The authority is exactly {@see resume()}'s — the same flow-level check
	 * and the same recorded-assignee check, on the resolved run. A
	 * correlated signal cannot complete, claim or advance a user task: a run
	 * suspended on a user-task node treats any signal as a nudge, and a
	 * nudge is not an answer.
	 *
	 * @param string $key The business key.
	 *
	 * @return JSONResponse The signalled run, 404 when unmatched, 409 when
	 *                      ambiguous or not suspended.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @contract tests/integration/openregister-integrations.postman_collection.json
	 *
	 * @spec openspec/changes/flow-approval-consolidation/specs/flow-approval-consolidation/spec.md#requirement-the-signal-node-keeps-machine-to-machine-work-and-gains-a-correlation-key
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function signalByKey(string $key): JSONResponse {
		$key = trim($key);
		$matches = [];
		if ($key !== '') {
			$matches = $this->mapper->findSuspendedByCorrelationKey(correlationKey: $key);
		}

		if ($matches === []) {
			return new JSONResponse(
				['error' => 'No suspended run is waiting for this key. The signal was not stored.'],
				Http::STATUS_NOT_FOUND
			);
		}

		if (count($matches) > 1) {
			return new JSONResponse(
				['error' => 'More than one suspended run carries this key. Nothing was signalled; address the run by its uuid instead.'],
				Http::STATUS_CONFLICT
			);
		}

		$run = $matches[0];

		$refusal = $this->refuseUnlessRunnable(flowId: (string)$run->getFlowId());
		if ($refusal !== null) {
			return $refusal;
		}

		$payload = $this->request->getParams();
		// Routing artefacts, not part of what the signaller is telling the run.
		unset($payload['key'], $payload['_route']);

		// Same seam as resume() and as every PHP consumer: one guard.
		try {
			$signalled = $this->signals()->signalRunAs(run: $run, payload: $payload, actorUid: $this->callerUid());
		} catch (FlowSignalRefused $refused) {
			return $this->refusalResponse(refused: $refused, run: $run, verb: 'signalled');
		}

		return new JSONResponse($signalled->jsonSerialize());
	}//end signalByKey()

	/**
	 * Refuse unless the caller may RUN this flow.
	 *
	 * WHY THE CONTROLLER AND NOT THE RESOLVER. `FlowLocator::resolveSubject()`
	 * loads with `_rbac: false`, and correctly so — the engine runs a flow as its
	 * owner, and background jobs and retries have no session to evaluate. But
	 * these endpoints inherited that bypass, and `retry()` in particular took a
	 * run UUID and retried it with no ownership check at all: any authenticated
	 * user could re-run anybody's flow. That is an IDOR (OWASP A01), and the fix
	 * belongs where the request enters, not in the engine.
	 *
	 * WHAT IT CHECKS. The flow is resolved through `FlowService`, which applies
	 * the organisation scoping and the per-flow guard. A caller who may not see
	 * the flow gets the SAME 404 as one asking for a flow that does not exist,
	 * so the endpoint cannot be used to discover which flow ids exist.
	 *
	 * Running is an EXTENSION verb — core's bitmask has no `run` — so per ADR-010
	 * Rule 4 it is enforced here, at the endpoint that performs the action,
	 * rather than by widening the RBAC vocabulary.
	 *
	 * @param string $flowId The flow being run.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function refuseUnlessRunnable(string $flowId): ?JSONResponse {
		if ($this->flows === null) {
			// Fail CLOSED. Without the collaborator there is no way to decide,
			// and an unguarded run is what this method exists to prevent.
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		try {
			$this->flows->find(uuid: $flowId);
		} catch (Throwable $e) {
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end refuseUnlessRunnable()

	/**
	 * Translate the seam's typed refusal into this endpoint's HTTP contract.
	 *
	 * The GUARD lives in {@see FlowRunSignalService} — the same seam a PHP
	 * consumer calls, so who may answer is decided in one place (ADR-098 named
	 * the gap this closes: "no task authz — anyone reaching the resume endpoint
	 * can decide"). What stays here is only the WORDING, which differs per
	 * endpoint and is part of the existing HTTP contract.
	 *
	 * A step with no assignee is unchanged — silence still means anyone,
	 * because tightening that would break every existing webhook and child-run
	 * signal, which are not human decisions at all.
	 *
	 * @param FlowSignalRefused $refused The seam's refusal.
	 * @param FlowRun $run The run the signal addressed.
	 * @param string $verb This endpoint's verb for the 409 wording ('resumed' or 'signalled').
	 *
	 * @return JSONResponse The 403 or 409 the refusal maps to.
	 *
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	private function refusalResponse(FlowSignalRefused $refused, FlowRun $run, string $verb): JSONResponse {
		if ($refused->getReason() === FlowSignalRefused::NOT_ASSIGNEE) {
			if ($refused->getActorUid() === null) {
				return new JSONResponse(
					['error' => 'This step is assigned; sign in as the assignee to answer it.'],
					Http::STATUS_FORBIDDEN
				);
			}

			return new JSONResponse(
				['error' => 'This step is assigned to someone else.'],
				Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(
			['error' => 'Only a suspended run can be ' . $verb . '; this one is ' . $run->getStatus() . '.'],
			Http::STATUS_CONFLICT
		);
	}//end refusalResponse()

	/**
	 * The guarded signal seam, made on demand when none was injected.
	 *
	 * Built locally rather than required as a constructor argument so adding it
	 * breaks no existing construction site. The refusal a locally-built seam
	 * cannot log still reaches the caller as the 403 above, so nothing is
	 * silent; the container-built instance audits too.
	 *
	 * @return FlowRunSignalService The seam.
	 *
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-server-side-signal-passes-the-same-guard-as-the-http-resume
	 */
	private function signals(): FlowRunSignalService {
		return ($this->signalService ?? new FlowRunSignalService(
			mapper: $this->mapper,
			runner: $this->runner,
			groupManager: $this->groupManager
		));
	}//end signals()

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

	/**
	 * Whether the caller is a Nextcloud administrator.
	 *
	 * Fails CLOSED — no group manager, or no session, means "not an admin", so
	 * the caller gets the scoped view rather than the unscoped one. The scoping
	 * switch in `index()` treats a null uid as "no scoping", which is only ever
	 * correct for an administrator; combined with this method returning false for
	 * an anonymous caller, `index()` must and does resolve a real uid before it
	 * can skip scoping.
	 *
	 * @return boolean
	 */
	private function isAdmin(): bool {
		if ($this->groupManager === null) {
			return false;
		}

		$uid = $this->callerUid();
		if ($uid === null) {
			return false;
		}

		return $this->groupManager->isAdmin($uid);
	}//end isAdmin()

	/**
	 * Flow ids the caller owns.
	 *
	 * Resolved in ONE query rather than per run: the owner filter is a metadata
	 * filter, and OpenRegister only recognises those NESTED under `@self` — a bare
	 * `owner` key is read as a filter on a schema PROPERTY called `owner`, which
	 * the flow schema does not have, so it silently matches nothing and this would
	 * return an empty list for everybody. `_rbac: false` is deliberate and safe:
	 * the query is already constrained to objects owned by this exact uid, so RBAC
	 * could only narrow a set the caller is by definition entitled to.
	 *
	 * Degrades to an empty list, which NARROWS the run history rather than
	 * widening it — the safe direction for a failure in a visibility filter.
	 *
	 * @return array<int, string> The owned flow ids (uuids), possibly empty.
	 */
	private function flowIdsOwnedByCaller(): array {
		// Reads the NATIVE flow store. This used to enumerate flow OBJECTS in a
		// register named by `flow_register`/`flow_schema` config — a store that
		// no longer exists. The visibility RULE is unchanged (D7): a caller sees
		// the runs they triggered plus the runs of flows they own.
		if ($this->flows === null) {
			return [];
		}

		return $this->flows->idsOwnedByCaller();
	}//end flowIdsOwnedByCaller()

	/**
	 * Run a flow now and return its result — the interactive test run.
	 *
	 * Unlike a trigger, which queues a run for the worker, this runs the flow
	 * synchronously and hands back the whole trace, so an author gets the log and
	 * the items straight away. It carries the two authoring aids: `startAt` runs
	 * from a chosen node (run-from-here), and `pins` supplies stored output for
	 * named steps so the expensive ones are skipped. Together they are the
	 * "iterate on the tail of a flow" loop.
	 *
	 * The run is persisted like any other (trigger `test`), so it also shows up
	 * in the history — a test run is not a throwaway.
	 *
	 * @return JSONResponse The finished run, or a 4xx when the flow is unknown.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowItems::normalise is a pure
	 * value-normaliser with no state to inject; wrapping it in a collaborator
	 * would add a constructor dependency to say the same thing.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function test(): JSONResponse {
		$flowId = trim((string)$this->request->getParam('flowId', ''));
		if ($flowId === '') {
			return new JSONResponse(['error' => 'A test run needs a flowId.'], Http::STATUS_BAD_REQUEST);
		}

		$refusal = $this->refuseUnlessRunnable(flowId: $flowId);
		if ($refusal !== null) {
			return $refusal;
		}

		$flow = $this->resolvers->resolveFlow(flowId: $flowId);
		if ($flow === null) {
			return new JSONResponse(['error' => 'No such flow: ' . $flowId], Http::STATUS_NOT_FOUND);
		}

		$startAt = trim((string)$this->request->getParam('startAt', ''));
		if ($startAt === '') {
			$startAt = null;
		}

		$pins = (array)$this->request->getParam('pins', []);

		$seed = null;
		$seedParam = $this->request->getParam('seedItems');
		if ($seedParam !== null) {
			$seed = FlowItems::normalise(value: $seedParam);
		}

		// Attribute the test run to the caller. Without this the run is
		// ownerless, so `context['triggeredBy']` is null and every
		// attribution-requiring node refuses — ObjectWriteNode returns "this
		// flow run has no owner". An interactive test run has a session by
		// definition, so there is no reason for it to be the one dispatch path
		// that discards its actor. Same defect class as or#2158 in
		// FlowMcpToolProvider::runFlow().
		// 🔴 A REFUSAL MUST NOT LEAVE HERE AS A 500. A dead end, or a flow with
		// no published version, is the engine DECLINING to run something — an
		// answer the author can act on. Unwrapped, both reached the editor as
		// an HTML error page, which reads as "the server is broken" and sends
		// the author to the wrong place entirely.
		try {
			$run = $this->runner->queue(
				flowId: $flowId,
				subject: [],
				trigger: 'test',
				context: ['pins' => $pins],
				user: $this->userSession->getUser()?->getUID()
			);

			$run = $this->runner->execute(
				run: $run,
				flow: $flow,
				subject: new stdClass(),
				seedItems: $seed,
				startAt: $startAt
			);
		} catch (FlowLifecycleRefused $e) {
			return new JSONResponse(
				[
					'error' => $e->getMessage(),
					'reason' => $e->getReason(),
					'lifecycleStatus' => $e->getState(),
					'flowId' => $e->getFlowId(),
				],
				Http::STATUS_CONFLICT
			);
		} catch (FlowDeadEnd $e) {
			return new JSONResponse(
				['error' => $e->getMessage(), 'reason' => 'dead-end', 'flowId' => $flowId],
				Http::STATUS_CONFLICT
			);
		}//end try

		return new JSONResponse($run->jsonSerialize());
	}//end test()
}//end class
