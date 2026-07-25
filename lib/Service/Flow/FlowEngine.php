<?php

/**
 * The OpenRegister flow engine.
 *
 * OpenRegister is the only home for a flow engine in this fleet (ADR-022,
 * ADR-065): leaf apps consume this rather than growing their own. openconnector,
 * procest and openbuild are consumers, not owners.
 *
 * The execution core is symfony/workflow — a Petri net, chosen because it is a
 * superset of both flow models this fleet already has (ADR-065). What Symfony
 * does NOT provide, and what therefore lives here, is everything that makes a
 * run real: persistence of the marking, a run lifecycle, an append-only trace,
 * and a per-step error policy. Those are ported from openconnector's
 * FlowRunnerService, which had them right.
 *
 * This class is deliberately ignorant of what a step *does*. Side effects are
 * dispatched through a FlowStepDispatcher supplied by the caller, so the engine
 * can be unit-tested without a Nextcloud container and so a consuming app can
 * contribute its own step types without touching the engine.
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
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Workflow;
use Throwable;

/**
 * Runs a stored flow document to completion, or until it can go no further.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
class FlowEngine
{

    /**
     * Run states, ported from openconnector's flow_run lifecycle.
     */
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_DEAD_LETTER = 'dead_letter';

    public const STATUS_FAILED = 'failed';

    /**
     * The run stopped mid-graph and will be resumed.
     *
     * Distinct from `stopped`, which is terminal. A suspended run keeps its
     * marking and its items, and the marking is exactly what makes resuming
     * cheap: the run picks up where it was instead of replaying side effects
     * it already performed.
     */
    public const STATUS_SUSPENDED = 'suspended';

    /**
     * Per-step error policies, ported from openconnector's `onError`.
     */
    public const ON_ERROR_STOP = 'stop';

    public const ON_ERROR_CONTINUE = 'continue';

    public const ON_ERROR_DEAD_LETTER = 'dead_letter';

    /**
     * Hard ceiling on transitions fired in one run.
     *
     * A Petri net expresses loops, so a user-drawn cycle can run forever. This is
     * a backstop against a graph that never settles, not a business rule: hitting
     * it is a failure, reported as one, not a silent truncation.
     */
    private const MAX_TRANSITIONS = 1000;

    /**
     * Constructor.
     *
     * @param FlowDefinitionBuilder $builder The document -> Petri-net translator.
     * @param LoggerInterface       $logger  The logger.
     */
    public function __construct(
        private readonly FlowDefinitionBuilder $builder,
        private readonly LoggerInterface $logger
    ) {

    }//end __construct()

    /**
     * Run a flow document.
     *
     * Fires enabled transitions until none remain. Where several are enabled at
     * once the first is taken, so ordering is the author's (declaration order),
     * not ours — a genuinely concurrent net is expressed as a split, not as a
     * race between edges.
     *
     * The data channel is an item LIST ({@see FlowItems}), threaded from step to
     * step. `$subject` is not that data: it carries the Petri-net marking and
     * names what the run is about. A run with no explicit seed starts from one
     * item built out of the subject, so a flow that never fans out behaves
     * exactly like the single-object model this replaces.
     *
     * @param array                 $flow       The flow document.
     * @param MarkingStoreInterface $store      Where the marking lives (an OR object, in production).
     * @param object                $subject    The object the run is about; holds the marking.
     * @param FlowStepDispatcher    $dispatcher Performs each step's side effect.
     * @param array                 $context    Run-level metadata handed to every step.
     * @param array|null            $items      Seed items; defaults to one item from the subject.
     *
     * @return array The run result: `{status, log: [], context: [], items: []}`.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    public function run(
        array $flow,
        MarkingStoreInterface $store,
        object $subject,
        FlowStepDispatcher $dispatcher,
        array $context=[],
        ?array $items=null
    ): array {
        $items = ($items ?? FlowItems::fromSubject(subject: $subject));

        try {
            $definition = $this->builder->build(flow: $flow);
        } catch (InvalidArgumentException $e) {
            // A malformed document is the author's error and must be visible.
            // Unlike x-openregister-flows, this engine does not swallow.
            $this->logger->warning(
                message: '[FlowEngine] Refusing to run a malformed flow',
                context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $e->getMessage()]
            );
            return [
                'status'  => self::STATUS_FAILED,
                'log'     => [],
                'context' => $context,
                'items'   => $items,
                'error'   => $e->getMessage(),
            ];
        }//end try

        $workflow = new Workflow(definition: $definition, markingStore: $store, name: (string) ($flow['id'] ?? 'flow'));
        $log      = [];
        $fired    = 0;

        while (true) {
            $enabled = $workflow->getEnabledTransitions(subject: $subject);
            if (empty($enabled) === true) {
                // No transition is enabled: either the run reached a final marking,
                // or a join is still waiting on a branch that never arrives. Both
                // are "as far as this graph goes".
                return [
                    'status'  => self::STATUS_COMPLETED,
                    'log'     => $log,
                    'context' => $context,
                    'items'   => $items,
                ];
            }

            $fired++;
            if ($fired > self::MAX_TRANSITIONS) {
                $this->logger->warning(
                    message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
                    context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
                );
                return [
                    'status'  => self::STATUS_FAILED,
                    'log'     => $log,
                    'context' => $context,
                    'items'   => $items,
                    'error'   => sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS),
                ];
            }

            $transition = $enabled[0];
            $name       = $transition->getName();
            $step       = $this->stepFor(flow: $flow, transitionName: $name);
            $itemsIn    = $items;

            try {
                $produced = $dispatcher->dispatch(step: $step, items: $items, context: $context);
                $items    = FlowItems::normalise(value: $produced);
                $log[]    = [
                    'transition' => $name,
                    'status'     => 'completed',
                    'itemsIn'    => count($itemsIn),
                    'itemsOut'   => count($items),
                ];
            } catch (FlowSuspension $suspension) {
                // A pause, not a failure. Caught before Throwable so the step's
                // onError policy never sees it — `continue` would otherwise skip
                // straight past a Wait, which is the opposite of waiting.
                //
                // The marking is NOT advanced: the run must resume ON this
                // transition, re-entering the step that asked to wait.
                $log[] = [
                    'transition' => $name,
                    'status'     => 'suspended',
                    'reason'     => $suspension->getMessage(),
                ];

                return [
                    'status'   => self::STATUS_SUSPENDED,
                    'log'      => $log,
                    'context'  => $context,
                    'items'    => $itemsIn,
                    'resumeAt' => $suspension->getResumeAt(),
                ];
            } catch (Throwable $e) {
                $policy = (string) ($step['onError'] ?? self::ON_ERROR_STOP);
                $log[]  = ['transition' => $name, 'status' => 'failed', 'error' => $e->getMessage()];

                $this->logger->warning(
                    message: '[FlowEngine] Flow step failed',
                    context: [
                        'file'       => __FILE__,
                        'line'       => __LINE__,
                        'flow'       => ($flow['id'] ?? null),
                        'transition' => $name,
                        'policy'     => $policy,
                        'error'      => $e->getMessage(),
                    ]
                );

                if ($policy === self::ON_ERROR_DEAD_LETTER) {
                    return ['status' => self::STATUS_DEAD_LETTER, 'log' => $log, 'context' => $context, 'items' => $items];
                }

                if ($policy !== self::ON_ERROR_CONTINUE) {
                    // `stop` is the default: an unknown policy stops rather than
                    // continues, so a typo fails safe instead of running on.
                    return ['status' => self::STATUS_STOPPED, 'log' => $log, 'context' => $context, 'items' => $items];
                }
            }//end try

            // The marking advances even when a `continue` step failed: the author
            // asked the run to proceed, and leaving the token behind would spin
            // this transition forever.
            $workflow->apply(subject: $subject, transitionName: $name);
        }//end while

    }//end run()

    /**
     * Find the step configuration attached to a transition.
     *
     * @param array  $flow           The flow document.
     * @param string $transitionName The transition name.
     *
     * @return array The step config, or an empty array when the edge carries none.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private function stepFor(array $flow, string $transitionName): array
    {
        foreach (($flow['edges'] ?? []) as $index => $edge) {
            $name = trim((string) ($edge['name'] ?? $edge['id'] ?? ''));
            if ($name === '') {
                $name = sprintf('edge-%d', $index);
            }

            if ($name === $transitionName) {
                return $edge;
            }
        }

        return [];

    }//end stepFor()
}//end class
