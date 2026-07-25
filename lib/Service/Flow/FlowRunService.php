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
     * @param FlowRunMapper    $mapper   Persists runs.
     * @param FlowEngine       $engine   Walks the graph.
     * @param FlowNodeRegistry $registry Resolves step types.
     * @param LoggerInterface  $logger   The logger.
     */
    public function __construct(
        private readonly FlowRunMapper $mapper,
        private readonly FlowEngine $engine,
        private readonly FlowNodeRegistry $registry,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

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
        $run->setCreated(new DateTime());
        $run->setUpdated(new DateTime());

        return $this->mapper->insert($run);

    }//end queue()

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
    private function persistResult(FlowRun $run, array $result): FlowRun
    {
        $status = (string) ($result['status'] ?? FlowRun::STATUS_FAILED);

        // The log is appended, not replaced: a resumed run's history is the
        // whole run, not just the segment since it last woke up.
        $log = array_merge(($run->getLog() ?? []), (array) ($result['log'] ?? []));

        $run->setStatus($status);
        $run->setItems((array) ($result['items'] ?? []));
        $run->setContext((array) ($result['context'] ?? []));
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
