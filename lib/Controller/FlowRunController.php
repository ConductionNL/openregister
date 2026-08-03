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

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowResolverRegistry;
use OCA\OpenRegister\Service\Flow\FlowRunService;
use OCA\OpenRegister\Service\OrganisationService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use stdClass;
use Throwable;

/**
 * REST surface for inspecting and retrying flow runs.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Two over, from the run guard's
 * ObjectService + IAppConfig. Both exist so the flow can be resolved WITH RBAC at
 * the request boundary — the resolver deliberately loads with RBAC off for the
 * engine, and inheriting that bypass here is what left retry() open to an IDOR.
 */
class FlowRunController extends Controller
{
    /**
     * Constructor.
     *
     * @param string               $appName             The app id.
     * @param IRequest             $request             The request.
     * @param FlowRunMapper        $mapper              Reads runs.
     * @param FlowRunService       $runner              Retries, requeues and runs.
     * @param FlowResolverRegistry $resolvers           Resolves a flow id to its document.
     * @param IUserSession         $userSession         Attributes a retried run to the caller.
     * @param OrganisationService  $organisationService Scopes the active-runs list to the caller's tenant.
     * @param ObjectService|null   $objectService       Resolves the flow WITH RBAC for the run guard;
     *                                                  nullable so adding it is not a fatal at
     *                                                  existing construction sites.
     * @param IAppConfig|null      $flowAppConfig       Reads the flow register/schema slugs.
     * @param IGroupManager|null   $groupManager        Distinguishes an administrator, who gets
     *                                                  the unscoped run history. Nullable so
     *                                                  adding it is not a fatal at existing
     *                                                  construction sites; absent means "not an
     *                                                  admin", which SCOPES rather than widens.
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FlowRunMapper $mapper,
        private readonly FlowRunService $runner,
        private readonly FlowResolverRegistry $resolvers,
        private readonly IUserSession $userSession,
        private readonly OrganisationService $organisationService,
        private readonly ?ObjectService $objectService=null,
        private readonly ?IAppConfig $flowAppConfig=null,
        private readonly ?IGroupManager $groupManager=null
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
    public function index(): JSONResponse
    {
        $flowId = $this->request->getParam('flowId');
        $status = $this->request->getParam('status');
        $limit  = min(200, max(1, (int) $this->request->getParam('limit', 50)));
        $offset = max(0, (int) $this->request->getParam('offset', 0));

        $flowFilter = null;
        if ($flowId !== null) {
            $flowFilter = (string) $flowId;
        }

        $statusFilter = null;
        if ($status !== null) {
            $statusFilter = (string) $status;
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
                'limit'   => $limit,
                'offset'  => $offset,
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
     * @return JSONResponse `{results, total, limit}` — the live runs.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     *
     * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function active(): JSONResponse
    {
        $limit        = min(50, max(1, (int) $this->request->getParam('limit', 10)));
        $organisation = $this->activeOrganisation();

        if ($organisation === null) {
            return new JSONResponse(['results' => [], 'total' => 0, 'limit' => $limit]);
        }

        $runs  = $this->mapper->findActive(organisation: $organisation, limit: $limit);
        $total = $this->mapper->countActive(organisation: $organisation);

        return new JSONResponse(
            [
                'results' => array_map(fn (FlowRun $run): array => $this->summarise(run: $run), $runs),
                'total'   => $total,
                'limit'   => $limit,
            ]
        );

    }//end active()

    /**
     * The caller's active organisation uuid, or null when none resolves.
     *
     * @return string|null The organisation uuid.
     *
     * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
     */
    private function activeOrganisation(): ?string
    {
        $uuid = $this->organisationService->getActiveOrganisation()?->getUuid();
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return (string) $uuid;

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
    private function summarise(FlowRun $run): array
    {
        $flowId  = (string) $run->getFlowId();
        $created = $run->getCreated();

        return [
            'uuid'      => $run->getUuid(),
            'flowId'    => $flowId,
            'flowName'  => $this->flowName(flowId: $flowId),
            'status'    => $run->getStatus(),
            'trigger'   => $run->getTrigger(),
            'startedBy' => $run->getTriggeredBy(),
            'subject'   => [
                'uuid'     => $run->getSubjectUuid(),
                'register' => $run->getSubjectRegister(),
                'schema'   => $run->getSubjectSchema(),
            ],
            'step'      => $this->currentStep(run: $run),
            'resumeAt'  => $run->getResumeAt()?->format('c'),
            'created'   => $created?->format('c'),
            'updated'   => $run->getUpdated()?->format('c'),
        ];

    }//end summarise()

    /**
     * The flow's human name, falling back to its id.
     *
     * Resolution is memoised per flow id by FlowResolverRegistry, so a list of
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
    private function flowName(string $flowId): string
    {
        $flow = $this->resolvers->resolveFlow(flowId: $flowId);
        $name = trim((string) ($flow['name'] ?? ''));

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
    private function currentStep(FlowRun $run): ?string
    {
        $marking = $run->getMarking();
        if (is_array($marking) === false || $marking === []) {
            return null;
        }

        $places = array_keys($marking);

        return implode(', ', array_map(static fn ($place): string => (string) $place, $places));

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
    public function show(string $uuid): JSONResponse
    {
        try {
            $run = $this->mapper->findByUuid($uuid);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
        }

        return new JSONResponse($run->jsonSerialize());

    }//end show()

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
    public function retry(string $uuid): JSONResponse
    {
        try {
            $run = $this->mapper->findByUuid($uuid);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'No such run'], Http::STATUS_NOT_FOUND);
        }

        $refusal = $this->refuseUnlessRunnable(flowId: (string) $run->getFlowId());
        if ($refusal !== null) {
            return $refusal;
        }

        $new = $this->runner->retry($run);
        if ($new === null) {
            // The source is not terminal — queued, running, or suspended. Retry
            // is for finished runs; the others are already progressing.
            return new JSONResponse(
                ['error' => 'Only a finished run can be retried; this one is '.$run->getStatus().'.'],
                Http::STATUS_CONFLICT
            );
        }

        return new JSONResponse($new->jsonSerialize(), Http::STATUS_CREATED);

    }//end retry()

    /**
     * The register flows are stored under.
     *
     * @var string
     */
    private const FLOW_REGISTER_KEY = 'flow_register';

    /**
     * The schema flows are stored under.
     *
     * @var string
     */
    private const FLOW_SCHEMA_KEY = 'flow_schema';

    /**
     * Refuse unless the caller may RUN this flow.
     *
     * WHY THE CONTROLLER AND NOT THE RESOLVER. `OpenRegisterFlowResolver::resolveFlow()`
     * loads with `_rbac: false`, and correctly so — the engine runs a flow as its
     * owner, and background jobs and retries have no session to evaluate. But
     * these endpoints inherited that bypass, and `retry()` in particular took a
     * run UUID and retried it with no ownership check at all: any authenticated
     * user could re-run anybody's flow. That is an IDOR (OWASP A01), and the fix
     * belongs where the request enters, not in the engine.
     *
     * WHAT IT CHECKS. The flow is resolved through `ObjectService` with RBAC ON.
     * A caller who cannot READ the flow gets a 404 rather than a 403, so the
     * endpoint cannot be used to discover which flow ids exist.
     *
     * Running is an EXTENSION verb — core's bitmask has no `run` — so per ADR-010
     * Rule 4 it is enforced here, at the endpoint that performs the action,
     * rather than by widening the RBAC vocabulary.
     *
     * @param string $flowId The flow being run.
     *
     * @return JSONResponse|null A refusal, or null when the caller may proceed.
     */
    private function refuseUnlessRunnable(string $flowId): ?JSONResponse
    {
        if ($this->objectService === null || $this->flowAppConfig === null) {
            // Fail CLOSED. Without the collaborators there is no way to decide,
            // and an unguarded run is what this method exists to prevent.
            return new JSONResponse(['error' => 'No such flow: '.$flowId], Http::STATUS_NOT_FOUND);
        }

        try {
            $flow = $this->objectService->find(
                id: $flowId,
                register: $this->flowAppConfig->getValueString('openregister', self::FLOW_REGISTER_KEY, 'flows'),
                schema: $this->flowAppConfig->getValueString('openregister', self::FLOW_SCHEMA_KEY, 'flow'),
                _rbac: true,
                _multitenancy: true
            );
        } catch (Throwable $e) {
            return new JSONResponse(['error' => 'No such flow: '.$flowId], Http::STATUS_NOT_FOUND);
        }

        if ($flow === null) {
            return new JSONResponse(['error' => 'No such flow: '.$flowId], Http::STATUS_NOT_FOUND);
        }

        return null;
    }//end refuseUnlessRunnable()

    /**
     * The current caller's uid, or null when anonymous.
     *
     * @return string|null
     */
    private function callerUid(): ?string
    {
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
    private function isAdmin(): bool
    {
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
    private function flowIdsOwnedByCaller(): array
    {
        $uid = $this->callerUid();
        if ($uid === null || $this->objectService === null || $this->flowAppConfig === null) {
            return [];
        }

        try {
            $flows = $this->objectService->findAll(
                config: [
                    'filters' => [
                        'register' => $this->flowAppConfig->getValueString('openregister', self::FLOW_REGISTER_KEY, 'flows'),
                        'schema'   => $this->flowAppConfig->getValueString('openregister', self::FLOW_SCHEMA_KEY, 'flow'),
                        '@self'    => ['owner' => $uid],
                    ],
                    'limit'   => 1000,
                ],
                _rbac: false
            );
        } catch (Throwable $e) {
            return [];
        }

        if (is_array($flows) === false) {
            return [];
        }

        $ids = [];
        foreach ($flows as $flow) {
            $self = null;
            if (is_array($flow) === true) {
                $self = ($flow['@self'] ?? null);
            }

            $id = null;
            if (is_array($self) === true) {
                $id = ($self['id'] ?? null);
            } else if (is_object($flow) === true && method_exists($flow, 'getUuid') === true) {
                $id = $flow->getUuid();
            }

            if (is_string($id) === true && $id !== '') {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
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
    public function test(): JSONResponse
    {
        $flowId = trim((string) $this->request->getParam('flowId', ''));
        if ($flowId === '') {
            return new JSONResponse(['error' => 'A test run needs a flowId.'], Http::STATUS_BAD_REQUEST);
        }

        $refusal = $this->refuseUnlessRunnable(flowId: $flowId);
        if ($refusal !== null) {
            return $refusal;
        }

        $flow = $this->resolvers->resolveFlow(flowId: $flowId);
        if ($flow === null) {
            return new JSONResponse(['error' => 'No such flow: '.$flowId], Http::STATUS_NOT_FOUND);
        }

        $startAt = trim((string) $this->request->getParam('startAt', ''));
        if ($startAt === '') {
            $startAt = null;
        }

        $pins = (array) $this->request->getParam('pins', []);

        $seed      = null;
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

        return new JSONResponse($run->jsonSerialize());

    }//end test()
}//end class
