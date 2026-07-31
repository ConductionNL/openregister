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
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use stdClass;

/**
 * REST surface for inspecting and retrying flow runs.
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
     */
    public function __construct(
        string $appName,
        IRequest $request,
        private readonly FlowRunMapper $mapper,
        private readonly FlowRunService $runner,
        private readonly FlowResolverRegistry $resolvers,
        private readonly IUserSession $userSession,
        private readonly OrganisationService $organisationService
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

        $runs = $this->mapper->findAllRuns(
            flowId: $flowFilter,
            status: $statusFilter,
            limit: $limit,
            offset: $offset
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
     * The full history surface (`index`) is unchanged and still unscoped —
     * this endpoint exists precisely so that widening visibility of run
     * activity to every user of every app does not widen it across tenants.
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
