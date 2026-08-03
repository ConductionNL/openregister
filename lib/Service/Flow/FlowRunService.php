<?php

/**
 * Starts, persists and resumes flow runs.
 *
 * `FlowEngine` walks a graph in memory and knows nothing about storage. This
 * service is the half that makes a run durable: it creates the row, hands the
 * engine a marking store backed by that row, writes back what the walk
 * produced, and — when a step asked to wait — leaves the run resumable instead
 * of finished.
 *
 * Keeping the two apart is deliberate. The engine stays unit-testable without
 * a database, and the decision "when does a run get written" lives in exactly
 * one place rather than being sprinkled through the walk.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The durable half of flow execution.
 */
class FlowRunService
{
    /**
     * Constructor.
     *
     * @param FlowRunMapper          $mapper      Persists runs.
     * @param FlowStateMapper        $stateMapper Persists state that outlives a run.
     * @param FlowEngine             $engine      Walks the graph.
     * @param FlowNodeRegistry       $registry    Resolves step types.
     * @param LoggerInterface        $logger      The logger.
     * @param ContainerInterface     $container   Lazily resolves OrganisationService.
     * @param FlowRunStepMapper|null $steps       Records one row per node execution.
     *                                            Nullable so the cron worker and the
     *                                            unit tests can build this service
     *                                            without it; history is then simply
     *                                            not recorded, never faked.
     */
    public function __construct(
        private readonly FlowRunMapper $mapper,
        private readonly FlowStateMapper $stateMapper,
        private readonly FlowEngine $engine,
        private readonly FlowNodeRegistry $registry,
        private readonly LoggerInterface $logger,
        private readonly ContainerInterface $container,
        private readonly ?FlowRunStepMapper $steps=null
    ) {

    }//end __construct()

    /**
     * The organisation to attribute a run to, or null when there is none.
     *
     * A run is queued from wherever the trigger fired: a request (there is a
     * session, so there is an active organisation), or a cron pass (there is
     * not). Only the first can be attributed, and an unattributed run is
     * recorded as such rather than guessed at — the active-runs surface scopes
     * strictly by this value, so a wrong guess would put one tenant's runs on
     * another's dashboard.
     *
     * `OrganisationService` is resolved lazily through the container, not
     * constructor-injected: `FlowRunService` is what the cron worker builds on
     * every pass, and it must not drag the whole organisation/RBAC graph into
     * that path to write a column it usually cannot fill anyway.
     *
     * @return string|null The active organisation uuid, or null.
     *
     * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
     */
    private function activeOrganisation(): ?string
    {
        try {
            $organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
            $uuid = $organisationService->getActiveOrganisation()?->getUuid();
        } catch (Throwable $e) {
            $this->logger->debug(
                message: '[FlowRunService] Could not resolve the active organisation for a run: '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return null;
        }

        if ($uuid === null || $uuid === '') {
            return null;
        }

        return (string) $uuid;

    }//end activeOrganisation()

    /**
     * Queue a run without executing it.
     *
     * This is what a trigger calls. A Nextcloud Flow rule, an object event or a
     * file write runs inside the dispatch of the thing that caused it, and an
     * arbitrary graph must not sit on that critical path — so the trigger only
     * records the intent and returns.
     *
     * @param string      $flowId  The flow to run.
     * @param array       $subject `{uuid, register, schema}` of the object.
     * @param string      $trigger What caused this run.
     * @param array       $context Run-level metadata.
     * @param string|null $user    The user whose action caused it.
     *
     * @return FlowRun The queued run.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    public function queue(
        string $flowId,
        array $subject=[],
        string $trigger='manual',
        array $context=[],
        ?string $user=null
    ): FlowRun {
        $run = new FlowRun();
        $run->setUuid($this->newUuid());
        $run->setFlowId($flowId);
        $run->setStatus(FlowRun::STATUS_QUEUED);
        $run->setTrigger($trigger);
        $run->setContext($context);
        $run->setLog([]);
        $run->setSubjectUuid(($subject['uuid'] ?? null));
        $run->setSubjectRegister(($subject['register'] ?? null));
        $run->setSubjectSchema(($subject['schema'] ?? null));
        $run->setTriggeredBy($user);
        // Attribute the run to the caller's organisation so the active-runs
        // surface can scope by tenant. Null when queued off a request (cron) —
        // see activeOrganisation().
        $run->setOrganisation($this->activeOrganisation());
        $run->setCreated(new DateTime());
        $run->setUpdated(new DateTime());

        return $this->mapper->insert($run);

    }//end queue()

    /**
     * Whether a flow already has a run that has not finished.
     *
     * Exposed for the SCHEDULER, which must not start tick N+1 of a flow while
     * tick N is still going: a scheduled flow can outlive its own interval, and
     * two concurrent runs of one flow race on whatever that flow is
     * bookkeeping. See FlowRunMapper::hasActiveRun() for why "not finished"
     * includes `suspended` and `queued`, not just `running`.
     *
     * @param string $flowId The flow's uuid.
     *
     * @return boolean True when a non-terminal run exists for this flow.
     */
    public function hasActiveRun(string $flowId): bool
    {
        return $this->mapper->hasActiveRun(flowId: $flowId);

    }//end hasActiveRun()

    /**
     * Write a run's flow state back to its own table, when a node changed it.
     *
     * @param FlowRun $run     The run that just finished a pass.
     * @param array   $context The context the engine returned.
     *
     * @return void
     */
    private function persistFlowState(FlowRun $run, array $context): void
    {
        $handle = ($context[FlowStateHandle::CONTEXT_KEY] ?? null);
        if (($handle instanceof FlowStateHandle) === false) {
            return;
        }

        if ($handle->isDirty() === false) {
            return;
        }

        $flowId = trim((string) $run->getFlowId());
        if ($flowId === '') {
            return;
        }

        try {
            $this->stateMapper->put(flowId: $flowId, state: $handle->all());
        } catch (Throwable $e) {
            // Deliberately non-fatal, and deliberately LOUD. A run that did its
            // work should not be recorded as failed because its bookkeeping
            // could not be saved — but a silently dropped state write would let
            // a flow repeat work forever while looking healthy, so this must be
            // visible rather than swallowed.
            $this->logger->error(
                message: '[FlowRunService] Could not persist flow state',
                context: [
                    'file'  => __FILE__,
                    'line'  => __LINE__,
                    'flow'  => $flowId,
                    'run'   => $run->getUuid(),
                    'error' => $e->getMessage(),
                ]
            );
        }//end try

    }//end persistFlowState()

    /**
     * Queue a fresh run that repeats a finished one.
     *
     * Retry NEVER re-executes the old run — that would repeat every side effect
     * it already performed. It creates a NEW queued run against the same flow,
     * subject and trigger, so the worker executes it from the start. The
     * original is left exactly as it ended, as the record of what happened.
     *
     * Only a terminal run can be retried: a queued or running one is already on
     * its way, and a suspended one resumes rather than restarts.
     *
     * @param FlowRun $run The run to repeat.
     *
     * @return FlowRun|null The new queued run, or null when the source is not terminal.
     *
     * @spec openspec/changes/or-flow-tooling/specs/flow-tooling/spec.md
     */
    public function retry(FlowRun $run): ?FlowRun
    {
        if ($run->isTerminal() === false) {
            return null;
        }

        return $this->queue(
            flowId: (string) $run->getFlowId(),
            subject: [
                'uuid'     => $run->getSubjectUuid(),
                'register' => $run->getSubjectRegister(),
                'schema'   => $run->getSubjectSchema(),
            ],
            trigger: 'retry',
            context: ($run->getContext() ?? []),
            user: $run->getTriggeredBy()
        );

    }//end retry()

    /**
     * Execute (or continue) a run to its next stopping point.
     *
     * Called by the queue worker, never by a trigger. Returns the run in
     * whatever state the walk left it: terminal, or suspended and resumable.
     *
     * @param FlowRun     $run       The run to advance.
     * @param array       $flow      The flow document.
     * @param object      $subject   The object the run is about.
     * @param array       $seedItems Items to start from; ignored when resuming.
     * @param string|null $startAt   Node to start from (run-from-here); ignored when resuming.
     *
     * @return FlowRun The updated run.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     *
     * @SuppressWarnings(PHPMD.StaticAccess) FlowToken::fromArray is a stateless
     * rehydrator for a value object; injecting a factory to call it would add a
     * dependency without removing any coupling.
     */
    public function execute(FlowRun $run, array $flow, object $subject, ?array $seedItems=null, ?string $startAt=null): FlowRun
    {
        if ($run->isTerminal() === true) {
            // Re-executing a finished run would repeat every side effect it
            // already performed. Retry creates a NEW run instead.
            return $run;
        }

        $resuming = ($run->getStatus() === FlowRun::STATUS_SUSPENDED);
        $run->setStatus(FlowRun::STATUS_RUNNING);
        $run->setUpdated(new DateTime());
        $this->mapper->update($run);

        $items = $seedItems;
        $start = $startAt;
        if ($resuming === true) {
            // On resume the stored items win: they are what the run was
            // carrying when it paused. Re-seeding from the subject would throw
            // away everything the earlier steps produced. A start node is a
            // fresh-run concern too — the marking already holds where to resume.
            $items = ($run->getItems() ?? []);
            $start = null;
        }

        $context            = ($run->getContext() ?? []);
        $context['runUuid'] = $run->getUuid();
        $context['resuming'] = $resuming;
        // Carry the run's owner into the node context. Nodes read
        // `context['triggeredBy']` to attribute what they do — ObjectWriteNode
        // REFUSES to write without it, SubFlowNode propagates it to child runs,
        // and Hermiq's agent node runs the turn as that user. Nothing else in
        // lib/ ever wrote this key, so every trigger reached its nodes
        // ownerless and only hand-injected contexts (tests, harnesses) worked.
        // An explicit context value still wins, so a caller can attribute a run
        // to someone other than whoever queued it. See or#2158.
        $context['triggeredBy'] = ($context['triggeredBy'] ?? $run->getTriggeredBy());

        // The token is stored as plain values but handed to steps as an object:
        // a step receives $context by value, so only a handle gives it write
        // access. Rehydrating here (and serialising in persistResult) is what
        // carries a value across a suspension — the run that resumes days later
        // gets back exactly what it held when it stopped.
        $context[FlowToken::CONTEXT_KEY] = FlowToken::fromArray(($context[FlowToken::CONTEXT_KEY] ?? null));

        // Flow state is the token's long-lived sibling: the token belongs to
        // THIS run and dies with it, this belongs to the FLOW and outlives every
        // run of it. Loaded from its own table rather than from the run's
        // context, because a scheduled flow's next tick is a different run and
        // would otherwise start blank — which is the whole gap OR#2216 exists
        // to close.
        //
        // Handed over as an object for the same reason as the token: nodes take
        // $context by value, so only a handle gives them write access.
        $flowState = null;
        if (trim((string) $run->getFlowId()) !== '') {
            $stored    = $this->stateMapper->findByFlow(flowId: (string) $run->getFlowId());
            $flowState = new FlowStateHandle(values: ($stored?->getState() ?? []));
            $context[FlowStateHandle::CONTEXT_KEY] = $flowState;
        }

        try {
            $result = $this->engine->run(
                flow: $flow,
                store: new FlowRunMarkingStore(run: $run),
                subject: $subject,
                dispatcher: new RegistryStepDispatcher(registry: $this->registry),
                context: $context,
                items: $items,
                startAt: $start
            );
        } catch (Throwable $e) {
            // The engine itself failing (rather than a step) is not something
            // the run should be left `running` for — that status would make it
            // look claimed by a worker forever.
            $this->logger->error(
                message: '[FlowRunService] Flow run failed outside the walk',
                context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $run->getUuid(), 'error' => $e->getMessage()]
            );

            $run->setStatus(FlowRun::STATUS_FAILED);
            $run->setError($e->getMessage());
            $run->setUpdated(new DateTime());

            return $this->mapper->update($run);
        }//end try

        return $this->persistResult(run: $run, result: $result);

    }//end execute()

    /**
     * Write a completed walk back onto the run.
     *
     * @param FlowRun $run    The run.
     * @param array   $result What the engine returned.
     *
     * @return FlowRun The updated run.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */

    /**
     * Write one step row per node execution in this segment.
     *
     * The aggregate `log` column answers "what happened in this run" and
     * nothing else — "which node type fails", "every failed step for this
     * flow", "what did node X output" all require loading and walking every
     * run's blob. One row per hop makes those queryable, and gives retention
     * something it can prune per flow.
     *
     * Sequence CONTINUES from the highest already recorded rather than
     * restarting at zero, so a run that suspends on a wait node and resumes
     * later reads as one ordered history instead of two interleaved ones.
     *
     * Failing to record history must never fail the run itself: the run is the
     * work, the rows are the account of it.
     *
     * @param FlowRun           $run     The run these steps belong to.
     * @param array<int, array> $entries The engine log entries for this segment.
     *
     * @return void
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
     */
    private function recordSteps(FlowRun $run, array $entries): void
    {
        if ($this->steps === null || empty($entries) === true) {
            return;
        }

        $runUuid = (string) $run->getUuid();

        try {
            $sequence = ($this->steps->highestSequence(runUuid: $runUuid) + 1);
        } catch (Throwable $e) {
            $this->logger->warning(
                message: '[FlowRunService] Could not read the step sequence for run '.$runUuid.': '.$e->getMessage(),
                context: ['file' => __FILE__, 'line' => __LINE__]
            );
            return;
        }

        foreach ($entries as $entry) {
            if (is_array($entry) === false) {
                continue;
            }

            $step = new FlowRunStep();
            $step->setRunUuid($runUuid);
            $step->setFlowId((string) $run->getFlowId());
            $step->setNodeId((string) ($entry['transition'] ?? ''));
            $step->setNodeType(($entry['type'] ?? null));
            $step->setSequence($sequence);
            $step->setStatus((string) ($entry['status'] ?? 'unknown'));
            $step->setDurationMs(($entry['durationMs'] ?? null));
            $step->setCreated(new DateTime());
            $step->setFinished(new DateTime());

            // `error` and `reason` are distinct outcomes that both belong in
            // the error column: a thrown step and a deliberately stopped one
            // are each something a person needs to read back.
            $step->setError(($entry['error'] ?? ($entry['reason'] ?? null)));

            // What the node produced, minus the items themselves — a step row
            // is an index into the run, not a second copy of its data.
            $step->setOutput(
                array_filter(
                    [
                        'itemsIn'  => ($entry['itemsIn'] ?? null),
                        'itemsOut' => ($entry['itemsOut'] ?? null),
                        'checkId'  => ($entry['checkId'] ?? null),
                    ],
                    static fn ($v): bool => $v !== null
                )
            );

            try {
                $this->steps->insert($step);
            } catch (Throwable $e) {
                $this->logger->warning(
                    message: '[FlowRunService] Could not record a step row for run '.$runUuid.': '.$e->getMessage(),
                    context: ['file' => __FILE__, 'line' => __LINE__]
                );
            }

            $sequence++;
        }//end foreach

    }//end recordSteps()

    /**
     * Write back what a walk produced.
     *
     * @param FlowRun $run    The run to update.
     * @param array   $result The engine's result envelope.
     *
     * @return FlowRun The updated run.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    private function persistResult(FlowRun $run, array $result): FlowRun
    {
        $status = (string) ($result['status'] ?? FlowRun::STATUS_FAILED);

        // The log is appended, not replaced: a resumed run's history is the
        // whole run, not just the segment since it last woke up.
        $log = array_merge(($run->getLog() ?? []), (array) ($result['log'] ?? []));

        // Promote THIS segment's entries to step rows. Only the new entries —
        // `$result['log']`, not the merged `$log` — or every resume would
        // re-record the whole history it had already written.
        $this->recordSteps(run: $run, entries: (array) ($result['log'] ?? []));

        // The token travels as an object so steps can write to it; the column
        // holds JSON. Serialising here — on the suspended path as much as the
        // terminal ones — is what makes "pause and continue later" keep the
        // values the run had already gathered.
        $context = (array) ($result['context'] ?? []);
        if (isset($context[FlowToken::CONTEXT_KEY]) === true && $context[FlowToken::CONTEXT_KEY] instanceof FlowToken === true) {
            $context[FlowToken::CONTEXT_KEY] = $context[FlowToken::CONTEXT_KEY]->jsonSerialize();
        }

        // Flow state persists to its OWN table and is then removed from the
        // run's context. Two reasons it must not be written into the context
        // JSON alongside the token:
        //
        // - it would be a per-run COPY of flow-level state, and a resumed run
        // would restore a stale snapshot over whatever later runs had
        // written
        // - a slot table or a cursor would be duplicated into every run row
        // the flow ever produces
        //
        // Only written when a node actually changed something: a flow that
        // merely READS its state should not touch the row on every tick, and a
        // five-minute schedule makes that difference thousands of writes a week.
        $this->persistFlowState(run: $run, context: $context);
        unset($context[FlowStateHandle::CONTEXT_KEY]);

        $run->setStatus($status);
        $run->setItems((array) ($result['items'] ?? []));
        $run->setContext($context);
        $run->setLog($log);
        $run->setError(($result['error'] ?? null));
        $run->setUpdated(new DateTime());

        // `resumeAt` is only meaningful while suspended. Clearing it on every
        // other outcome stops a completed run from being picked up by the
        // due-runs query on its next pass.
        $resumeAt = null;
        if ($status === FlowRun::STATUS_SUSPENDED) {
            $resumeAt = ($result['resumeAt'] ?? null);
        }

        $run->setResumeAt($resumeAt);

        return $this->mapper->update($run);

    }//end persistResult()

    /**
     * A v4 UUID for a new run.
     *
     * @return string The uuid.
     *
     * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
     */
    private function newUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

    }//end newUuid()
}//end class
