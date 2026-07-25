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
use Symfony\Component\Workflow\Definition;
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

        // Per-place item buffers. Items belong to the PLACES a token sits on,
        // not to the run globally ({@see self::seedPlaceItems()}).
        $placeItems = $this->seedPlaceItems(
            workflow: $workflow,
            subject: $subject,
            definition: $definition,
            items: $items
        );

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

            // Conditional branching (If/Switch). Among the enabled transitions,
            // fire the first whose edge condition holds; an edge with no
            // condition is the default/else and only wins when no conditioned
            // sibling matches. This is what makes a multi-output node a switch,
            // with no special node type — routing is a property of the edge.
            $transition = $this->selectTransition(
                enabled: $enabled,
                flow: $flow,
                placeItems: $placeItems,
                context: $context
            );

            if ($transition === null) {
                // Every active choice point is gated by a condition that did
                // not hold and has no default edge: a Switch with no matching
                // case and no fallback. The run ends here rather than spinning
                // on the same un-fireable transitions until the ceiling.
                return [
                    'status'  => self::STATUS_COMPLETED,
                    'log'     => $log,
                    'context' => $context,
                    'items'   => $items,
                ];
            }

            $name = $transition->getName();
            $step = $this->stepFor(flow: $flow, transitionName: $name);

            // A step reads the items on its input place(s). For a join — several
            // incoming edges converging on one node — that is the concatenation
            // of every branch's items, in the froms' declared order, which is
            // exactly what a Merge node then refines. The Petri net already
            // holds the join until every input place is marked, so wait-for-both
            // is the default and needs no code here.
            $itemsIn = $this->itemsForTransition(transition: $transition, placeItems: $placeItems);
            $items   = $itemsIn;

            try {
                $produced = $dispatcher->dispatch(step: $step, items: $itemsIn, context: $context);
                $items    = FlowItems::normalise(value: $produced);
                $log[]    = [
                    'transition' => $name,
                    'status'     => 'completed',
                    'itemsIn'    => count($itemsIn),
                    'itemsOut'   => count($items),
                ];
            } catch (FlowStop $stop) {
                // A deliberate end, requested by a Stop step. Caught before the
                // generic Throwable so it is never treated as a step failure and
                // never subject to an onError policy — the author asked the run
                // to end, and it ends with their message and their outcome.
                $log[] = [
                    'transition' => $name,
                    'status'     => 'stopped',
                    'reason'     => $stop->getMessage(),
                ];

                $stopStatus = self::STATUS_STOPPED;
                $stopError  = null;
                if ($stop->isError() === true) {
                    $stopStatus = self::STATUS_FAILED;
                    $stopError  = $stop->getMessage();
                }

                return [
                    'status'  => $stopStatus,
                    'log'     => $log,
                    'context' => $context,
                    'items'   => $items,
                    'error'   => $stopError,
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

            // Move the items in lock-step with the token: onto the output
            // places, off the consumed inputs ({@see self::advanceItems()}).
            $placeItems = $this->advanceItems(transition: $transition, placeItems: $placeItems, items: $items);

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

    /**
     * Choose which enabled transition to fire, honouring edge conditions.
     *
     * Rules, in order:
     *  - A conditioned edge whose condition holds wins, first by declaration
     *    order — so a Switch's cases are tried top to bottom.
     *  - An edge with no condition is the default/else. It is eligible, but a
     *    matching conditioned sibling beats it, so it is only taken when no
     *    condition matched.
     *  - If nothing is eligible (every enabled transition is gated by a
     *    condition that did not hold, with no default), null is returned and
     *    the run ends at this choice point.
     *
     * Each candidate's condition is evaluated against the items on ITS input
     * place — the data the branch would actually carry — not a single global
     * list. Per-item routing (each item down a different branch) is a larger
     * feature and is not attempted here; the condition uses the first item as
     * the branch's representative.
     *
     * @param array<int, object>   $enabled    The enabled transitions.
     * @param array<string, mixed> $flow       The flow document.
     * @param array<string, array> $placeItems Items per place.
     * @param array<string, mixed> $context    Run-level metadata.
     *
     * @return object|null The transition to fire, or null when none is eligible.
     *
     * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
     */
    private function selectTransition(array $enabled, array $flow, array $placeItems, array $context): ?object
    {
        $fallback = null;

        foreach ($enabled as $transition) {
            $edge      = $this->stepFor(flow: $flow, transitionName: $transition->getName());
            $condition = ($edge['condition'] ?? null);

            if ($condition === null || $condition === []) {
                // Remember the first default edge; keep looking for a match.
                $fallback = ($fallback ?? $transition);
                continue;
            }

            $items = $this->itemsForTransition(transition: $transition, placeItems: $placeItems);
            $data  = FlowExpression::dataFor(
                item: ($items[0] ?? []),
                itemCount: count($items),
                context: $context
            );

            if (FlowExpression::isTrue(logic: $condition, data: $data) === true) {
                return $transition;
            }
        }//end foreach

        return $fallback;

    }//end selectTransition()

    /**
     * Gather the items a transition reads: every input place's items, in the
     * froms' declared order.
     *
     * For a normal step this is just its one input place. For a join it is the
     * concatenation of every incoming branch's items — which is what a Merge
     * node receives and then combines.
     *
     * @param object               $transition The transition.
     * @param array<string, array> $placeItems Items per place.
     *
     * @return array<int, mixed> The gathered input items.
     *
     * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
     */
    private function itemsForTransition(object $transition, array $placeItems): array
    {
        $items = [];
        foreach ($transition->getFroms() as $from) {
            foreach (($placeItems[(string) $from] ?? []) as $item) {
                $items[] = $item;
            }
        }

        return $items;

    }//end itemsForTransition()

    /**
     * Seed the per-place item buffers from the current marking.
     *
     * Items belong to the PLACES a token sits on, not to the run globally: a
     * parallel split hands each branch the items from the split point, and a
     * join reads the items every incoming branch left on it. A single shared
     * list cannot express either — the second branch to run would overwrite
     * the first.
     *
     * Seeded from the CURRENT marking, which is what makes resume work: a fresh
     * run's marking is the initial place, a resumed run's is wherever it
     * suspended, and either way the stored items land on the place that holds
     * the token.
     *
     * @param Workflow   $workflow   The workflow.
     * @param object     $subject    The subject holding the marking.
     * @param Definition $definition The definition (for the initial-place fallback).
     * @param array      $items      The seed items.
     *
     * @return array<string, array> Items keyed by place.
     *
     * @spec openspec/changes/or-flow-merge/specs/flow-merge/spec.md
     */
    private function seedPlaceItems(Workflow $workflow, object $subject, Definition $definition, array $items): array
    {
        $placeItems = [];
        foreach (array_keys($workflow->getMarking(subject: $subject)->getPlaces()) as $place) {
            $placeItems[(string) $place] = $items;
        }

        if ($placeItems === []) {
            foreach ($definition->getInitialPlaces() as $place) {
                $placeItems[(string) $place] = $items;
            }
        }

        return $placeItems;

    }//end seedPlaceItems()

    /**
     * Move a fired transition's items: onto its output places, off its inputs.
     *
     * Clearing the consumed inputs matters for a loop that re-enters the
     * transition — it must read fresh items, not a stale copy left behind.
     *
     * @param object               $transition The fired transition.
     * @param array<string, array> $placeItems The current per-place buffers.
     * @param array                $items      What the step produced.
     *
     * @return array<string, array> The updated buffers.
     *
     * @spec openspec/changes/or-flow-merge/specs/flow-merge/spec.md
     */
    private function advanceItems(object $transition, array $placeItems, array $items): array
    {
        foreach ($transition->getTos() as $to) {
            $placeItems[(string) $to] = $items;
        }

        foreach ($transition->getFroms() as $from) {
            unset($placeItems[(string) $from]);
        }

        return $placeItems;

    }//end advanceItems()
}//end class
