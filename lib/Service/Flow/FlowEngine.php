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
     * @param FlowDefinitionBuilder      $builder   The document -> Petri-net translator.
     * @param LoggerInterface            $logger    The logger.
     * @param FlowOversightRegistry|null $oversight The pre-hop gate. Nullable so the
     *                                              engine stays unit-testable without a
     *                                              container; absent, nothing objects,
     *                                              exactly as an empty registry does.
     */
    public function __construct(
        private readonly FlowDefinitionBuilder $builder,
        private readonly LoggerInterface $logger,
        private readonly ?FlowOversightRegistry $oversight=null
    ) {

    }//end __construct()

    /**
     * Ask the oversight checks whether the next hop may run.
     *
     * Consulted BEFORE EACH HOP rather than once per run: a flow that suspends
     * on a wait node and resumes an hour later, or one walking a long graph,
     * would otherwise sail straight past a kill switch thrown mid-run — which
     * is the case the switch exists for.
     *
     * NO registry CONSENTS, exactly as an empty registry does. The two are the
     * same statement — nothing objects — and treating the absent one as a
     * refusal would make the engine unrunnable wherever it is constructed
     * without a container, which includes every unit test of the walk itself.
     *
     * The fail-closed property that actually matters lives one level down, in
     * `FlowOversightRegistry::firstRefusal()`: a check that THROWS is a veto,
     * never consent. That is the case where something was supposed to have an
     * opinion and could not form one. "Nobody registered an opinion" is not
     * that case.
     *
     * @param array<string, mixed> $context The run context.
     * @param string               $name    The transition about to fire.
     * @param string               $type    The step type about to run.
     *
     * @return array{checkId: string, reason: string}|null The refusal, or null to proceed.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    private function oversightRefusal(array $context, string $name, string $type): ?array
    {
        if (($context['oversight'] ?? true) === false) {
            return null;
        }

        if ($this->oversight === null) {
            return null;
        }

        return $this->oversight->firstRefusal(
            context: array_merge(
                $context,
                ['transition' => $name, 'nodeType' => $type]
            )
        );

    }//end oversightRefusal()

    /**
     * Raise a FlowStop when an oversight check refuses the next hop.
     *
     * Raising rather than returning is deliberate: `run()` already turns a
     * FlowStop into a terminal `stopped` result, so a veto reuses the semantics
     * an author's Stop step already has instead of adding a second, parallel way
     * for a run to end early. The refusing check's id is carried in the reason
     * because that is what an operator needs in order to know WHICH gate closed.
     *
     * @param array  $context The run context.
     * @param string $name    The transition about to fire.
     * @param string $type    The node type about to run.
     *
     * @return void
     *
     * @throws FlowStop When a check refuses the hop.
     *
     * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
     */
    private function assertOversightAllows(array $context, string $name, string $type): void
    {
        $refusal = $this->oversightRefusal(context: $context, name: $name, type: $type);
        if ($refusal === null) {
            return;
        }

        // Both keys are guaranteed by firstRefusal()'s return type, so no
        // defaulting here: a `??` would be dead code that reads like a guard.
        throw new FlowStop(
            reason: $refusal['reason'],
            checkId: $refusal['checkId']
        );

    }//end assertOversightAllows()

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
     * `$startAt` runs the flow from a chosen node instead of its start — n8n's
     * "run from here". The seed items land on that node, and the steps before it
     * do not run; an author pins their output ({@see self::pinnedItems()}) and
     * re-runs only the part being worked on. It is the initial place overridden,
     * so a resume — whose marking is already mid-graph and comes from the store —
     * is unaffected.
     *
     * @param array                 $flow       The flow document.
     * @param MarkingStoreInterface $store      Where the marking lives (an OR object, in production).
     * @param object                $subject    The object the run is about; holds the marking.
     * @param FlowStepDispatcher    $dispatcher Performs each step's side effect.
     * @param array                 $context    Run-level metadata handed to every step.
     * @param array|null            $items      Seed items; defaults to one item from the subject.
     * @param string|null           $startAt    Node to start from; defaults to the flow's own start.
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
        ?array $items=null,
        ?string $startAt=null
    ): array {
        $items = ($items ?? FlowItems::fromSubject(subject: $subject));
        $flow  = $this->withStartNode(flow: $flow, startAt: $startAt);

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

            // The step's catalogue id, carried onto every log entry so the run
            // history can be queried BY NODE TYPE ("which node type fails")
            // rather than only by run.
            $stepType = (string) ($step['type'] ?? '');

            $startedAt = microtime(true);

            // Pinned output (n8n's "pin data"): when a run supplies a pin for
            // this step, its stored output is used verbatim and the step is NOT
            // executed — the side effect is skipped. This is what makes iterating
            // on a flow cheap: pin the node that hits a real API, then re-run the
            // downstream steps as often as needed without calling it again. A pin
            // short-circuits before dispatch, so it also can neither stop,
            // suspend nor fail — a pinned step always "just produces".
            $pinned = $this->pinnedItems(flow: $flow, context: $context, transitionName: $name);
            if ($pinned !== null) {
                $items = $pinned;
                $log[] = [
                    'transition' => $name,
                    'type'       => $stepType,
                    'status'     => 'pinned',
                    'itemsIn'    => count($itemsIn),
                    'itemsOut'   => count($items),
                    'durationMs' => 0,
                ];

                $taken      = $this->takenExits(flow: $flow, transition: $transition, items: $items, context: $context);
                $placeItems = $this->advanceItems(
                    transition: $transition,
                    placeItems: $placeItems,
                    items: $items,
                    taken: $taken
                );
                $workflow->apply(subject: $subject, transitionName: $name);
                $this->keepOnlyTakenExits(
                    workflow: $workflow,
                    subject: $subject,
                    transition: $transition,
                    taken: $taken
                );
                continue;
            }//end if

            try {
                // OVERSIGHT, before the hop. A veto is raised as a FlowStop so it
                // travels the same path as an author's Stop step: the run ENDS.
                // It never skips the hop and carries on, because a skipped step
                // inside a completed run is indistinguishable from one that ran
                // and did nothing — the exact failure this change removes.
                $this->assertOversightAllows(context: $context, name: $name, type: $stepType);

                $produced = $dispatcher->dispatch(step: $step, items: $itemsIn, context: $context);
                $items    = FlowItems::normalise(value: $produced);
                $log[]    = [
                    'transition' => $name,
                    'type'       => $stepType,
                    'status'     => 'completed',
                    'itemsIn'    => count($itemsIn),
                    'itemsOut'   => count($items),
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
            } catch (FlowStop $stop) {
                // A deliberate end, requested by a Stop step. Caught before the
                // generic Throwable so it is never treated as a step failure and
                // never subject to an onError policy — the author asked the run
                // to end, and it ends with their message and their outcome.
                $log[] = [
                    'transition' => $name,
                    'type'       => $stepType,
                    'status'     => 'stopped',
                    'reason'     => $stop->getMessage(),
                    // Null for an author's Stop step; set when an oversight
                    // gate raised the stop, so the history records WHICH gate.
                    'checkId'    => $stop->checkId(),
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
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
                $log[]   = [
                    'transition' => $name,
                    'type'       => $stepType,
                    'status'     => 'failed',
                    'error'      => $e->getMessage(),
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                ];
                $outcome = $this->outcomeForFailedStep(
                    step: $step,
                    error: $e,
                    name: $name,
                    flow: $flow,
                    log: $log,
                    context: $context,
                    items: $items
                );

                // A terminal outcome ends the run; null means the step's policy
                // is `continue`, so the walk goes on.
                if ($outcome !== null) {
                    return $outcome;
                }
            }//end try

            // Which single exit this firing takes. A token is unique and
            // exclusive, so a branching node hands it to exactly one successor.
            $taken = $this->takenExits(flow: $flow, transition: $transition, items: $items, context: $context);

            // Move the items in lock-step with the token: onto the output
            // places, off the consumed inputs ({@see self::advanceItems()}).
            $placeItems = $this->advanceItems(
                transition: $transition,
                placeItems: $placeItems,
                items: $items,
                taken: $taken
            );

            // The marking advances even when a `continue` step failed: the author
            // asked the run to proceed, and leaving the token behind would spin
            // this transition forever.
            $workflow->apply(subject: $subject, transitionName: $name);

            // Symfony's workflow deposits a token on EVERY output place, which
            // is right for a parallel split and wrong for a choice. Withdraw
            // the ones the taken exit did not claim, or every branch runs.
            $this->keepOnlyTakenExits(
                workflow: $workflow,
                subject: $subject,
                transition: $transition,
                taken: $taken
            );
        }//end while

    }//end run()

    /**
     * Override where a flow starts, for "run from here".
     *
     * A non-empty start node replaces the flow's `initial`; the builder then
     * validates it exists, so an unknown node fails the run exactly as a bad
     * document does. An empty or absent start leaves the flow untouched, so the
     * ordinary path is unaffected.
     *
     * @param array       $flow    The flow document.
     * @param string|null $startAt The node to start from, or null/empty for none.
     *
     * @return array The flow document, with `initial` overridden when asked.
     *
     * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
     */
    private function withStartNode(array $flow, ?string $startAt): array
    {
        if (($startAt ?? '') !== '') {
            $flow['initial'] = $startAt;
        }

        return $flow;

    }//end withStartNode()

    /**
     * Find the step configuration attached to a transition.
     *
     * A transition IS a node: `FlowDefinitionBuilder` names each transition
     * after the action node it lowered, so the lookup is a node lookup by id.
     * It used to search `edges[]`, because an edge was the transition and the
     * step rode on it — see `or-flow-action-nodes` for why that inverted.
     *
     * @param array  $flow           The flow document.
     * @param string $transitionName The transition name, which is a node id.
     *
     * @return array The step config, or an empty array when no node matches.
     *
     * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
     */
    private function stepFor(array $flow, string $transitionName): array
    {
        foreach (($flow['nodes'] ?? []) as $node) {
            if (is_array($node) === false) {
                continue;
            }

            if (trim((string) ($node['id'] ?? '')) === $transitionName) {
                return $node;
            }
        }

        return [];

    }//end stepFor()

    /**
     * Decide what a failed step does, per its `onError` policy.
     *
     * Returns the terminal run result when the policy ends the run
     * (`dead_letter`, or `stop` — the default, which also catches an unknown
     * policy so a typo fails safe), or null when the policy is `continue` and
     * the walk should go on. The failure is logged either way.
     *
     * @param array     $step    The step configuration.
     * @param Throwable $error   The failure.
     * @param string    $name    The transition name.
     * @param array     $flow    The flow document (for the log context).
     * @param array     $log     The run log so far (already holding the failure).
     * @param array     $context The run context.
     * @param array     $items   The items in hand at the failure.
     *
     * @return array|null The terminal result, or null to continue the walk.
     *
     * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
     */
    private function outcomeForFailedStep(
        array $step,
        Throwable $error,
        string $name,
        array $flow,
        array $log,
        array $context,
        array $items
    ): ?array {
        $policy = (string) ($step['onError'] ?? self::ON_ERROR_STOP);

        $this->logger->warning(
            message: '[FlowEngine] Flow step failed',
            context: [
                'file'       => __FILE__,
                'line'       => __LINE__,
                'flow'       => ($flow['id'] ?? null),
                'transition' => $name,
                'policy'     => $policy,
                'error'      => $error->getMessage(),
            ]
        );

        if ($policy === self::ON_ERROR_DEAD_LETTER) {
            return ['status' => self::STATUS_DEAD_LETTER, 'log' => $log, 'context' => $context, 'items' => $items];
        }

        if ($policy !== self::ON_ERROR_CONTINUE) {
            return ['status' => self::STATUS_STOPPED, 'log' => $log, 'context' => $context, 'items' => $items];
        }

        return null;

    }//end outcomeForFailedStep()

    /**
     * The pinned output for a step, or null when it is not pinned.
     *
     * Pins are a map of step name to an item list. A run carries them in its
     * `context` under `pins` (a test/authoring run supplies them without
     * touching the stored flow); a flow may also carry a `pins` map of its own,
     * used only as the fallback so a run's pins always win. A step whose name is
     * absent from both is not pinned and runs normally.
     *
     * @param array  $flow           The flow document.
     * @param array  $context        The run context.
     * @param string $transitionName The step's transition name.
     *
     * @return array<int, mixed>|null The pinned items, or null when not pinned.
     *
     * @spec openspec/changes/or-flow-pins/specs/flow-pins/spec.md
     */
    private function pinnedItems(array $flow, array $context, string $transitionName): ?array
    {
        $pins = (array) ($context['pins'] ?? ($flow['pins'] ?? []));
        if (array_key_exists($transitionName, $pins) === false) {
            return null;
        }

        return FlowItems::normalise(value: $pins[$transitionName]);

    }//end pinnedItems()

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
            $condition = $this->conditionReaching(flow: $flow, nodeId: $transition->getName());

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
     * The condition guarding the path into a node, or null when it is a default.
     *
     * ## Conditions live on the NODE, as named exits
     *
     * A node declares its branches, and each branch is an EXIT POINT:
     *
     * ```
     * { "id": "route", "type": "openregister.route", "exits": [
     *     { "id": "high", "condition": { ">":  [ {"var": "json.n"}, 10 ] } },
     *     { "id": "low",  "condition": { "<=": [ {"var": "json.n"}, 10 ] } },
     *     { "id": "else" }
     * ] }
     * ```
     *
     * and an edge says which exit it leaves from
     * (`{ from: 'route', fromExit: 'high', to: 'hi' }`).
     *
     * This is what lets a node have more than one exit point and lets an editor
     * draw one port per branch: the branches are a property of the node and
     * exist BEFORE any edge is drawn, so there is something to drag from. It
     * replaces the previous `edges[].condition`, which could not be rendered as
     * a port for exactly that reason — the branch did not exist until the line
     * did.
     *
     * An exit with no condition is the default/else: eligible, but beaten by a
     * conditioned sibling that matches (the ordering rules in
     * {@see self::selectTransition()} are unchanged).
     *
     * @param array<string, mixed> $flow   The flow document.
     * @param string               $nodeId The candidate node.
     *
     * @return array|null The guard, or null when the path is unconditional.
     *
     * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
     */

    /**
     * The single output place a fired node hands its token to.
     *
     * A token is unique and exclusive: a node may declare several exits, but
     * exactly ONE of them is taken per firing. Exits are tried in declaration
     * order — the first whose condition holds wins — and the exit that declares
     * no condition is the else, taken when nothing matched.
     *
     * The else is not optional. `FlowDefinitionBuilder` refuses a branching node
     * without one, because a token with nowhere to go does not error: the run
     * simply stops, having reported no failure, which is indistinguishable from
     * a flow that finished.
     *
     * Returns every output place unchanged for a node that does not branch,
     * which is also how a genuine parallel split keeps working: it has no
     * conditioned exits, so there is nothing to choose between.
     *
     * @param array<string, mixed> $flow       The flow document.
     * @param object               $transition The fired transition (a node).
     * @param array<int, mixed>    $items      What the step produced.
     * @param array<string, mixed> $context    Run-level metadata.
     *
     * @return array<string> The output places that receive the token.
     *
     * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
     */
    private function takenExits(array $flow, object $transition, array $items, array $context): array
    {
        $all  = array_map(static fn ($t): string => (string) $t, $transition->getTos());
        $node = $this->stepFor(flow: $flow, transitionName: $transition->getName());
        if (empty($node['exits'] ?? []) === true) {
            return $all;
        }

        $data     = FlowExpression::dataFor(
            item: ($items[0] ?? []),
            itemCount: count($items),
            context: $context
        );
        $fallback = null;

        foreach ($node['exits'] as $exit) {
            if (is_array($exit) === false) {
                continue;
            }

            $targets = $this->placesForExit(
                flow: $flow,
                nodeId: $transition->getName(),
                exitId: (string) ($exit['id'] ?? ''),
                candidates: $all
            );
            if (empty($targets) === true) {
                continue;
            }

            $condition = ($exit['condition'] ?? null);
            if (is_array($condition) === false || $condition === []) {
                $fallback = ($fallback ?? $targets);
                continue;
            }

            if (FlowExpression::isTrue(logic: $condition, data: $data) === true) {
                return $targets;
            }
        }//end foreach

        // Nothing matched. The else takes it; a branching node is required to
        // have one, so reaching null here means the document got past the
        // builder's guard and the token would vanish — return nothing rather
        // than silently broadcasting to every branch.
        return ($fallback ?? []);

    }//end takenExits()

    /**
     * The output places reached through one named exit.
     *
     * @param array<string, mixed> $flow       The flow document.
     * @param string               $nodeId     The firing node.
     * @param string               $exitId     The exit.
     * @param array<string>        $candidates The transition's output places.
     *
     * @return array<string> The matching places.
     */
    private function placesForExit(array $flow, string $nodeId, string $exitId, array $candidates): array
    {
        $places = [];
        foreach (($flow['edges'] ?? []) as $edge) {
            if (is_array($edge) === false || (string) ($edge['from'] ?? '') !== $nodeId) {
                continue;
            }

            if (trim((string) ($edge['fromExit'] ?? '')) !== $exitId) {
                continue;
            }

            $to = ($edge['to'] ?? null);
            if (is_array($to) === false) {
                $to = [$to];
            }

            foreach ($to as $target) {
                $target = (string) $target;
                if (in_array($target, $candidates, true) === true) {
                    $places[] = $target;
                }
            }
        }//end foreach

        return array_values(array_unique($places));

    }//end placesForExit()

    /**
     * The condition guarding the path into a node, or null when it is a default.
     *
     * Used to pick between several ENABLED transitions. Exit selection itself
     * happens in {@see self::takenExits()}, at the moment a node fires; this is
     * the read from the other side, for a node deciding whether it is the one
     * that should run.
     *
     * @param array<string, mixed> $flow   The flow document.
     * @param string               $nodeId The candidate node.
     *
     * @return array|null The guard, or null when the path is unconditional.
     *
     * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
     */
    private function conditionReaching(array $flow, string $nodeId): ?array
    {
        foreach (($flow['edges'] ?? []) as $edge) {
            if (is_array($edge) === false) {
                continue;
            }

            $to = ($edge['to'] ?? null);
            if (is_array($to) === false) {
                $to = [$to];
            }

            $targets = array_map(static fn ($t): string => (string) $t, $to);
            if (in_array($nodeId, $targets, true) === false) {
                continue;
            }

            $exitId = trim((string) ($edge['fromExit'] ?? ''));
            if ($exitId === '') {
                // An unbranched edge is unconditional: the node it leaves has
                // one way out, so there is nothing to choose between.
                return null;
            }

            $source = $this->stepFor(flow: $flow, transitionName: (string) ($edge['from'] ?? ''));
            foreach (($source['exits'] ?? []) as $exit) {
                if (is_array($exit) === false || (string) ($exit['id'] ?? '') !== $exitId) {
                    continue;
                }

                $condition = ($exit['condition'] ?? null);

                // An exit that exists but declares no condition is the default
                // branch — reported as unconditional so it becomes the fallback
                // rather than being treated as a failed match.
                if (is_array($condition) === true && $condition !== []) {
                    return $condition;
                }

                return null;
            }

            // The edge names an exit the node does not declare. Treated as
            // unconditional rather than silently unreachable: a branch that can
            // never be taken is a flow that stops for no visible reason.
            return null;
        }//end foreach

        return null;

    }//end conditionReaching()

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
     * Per-item routing (n8n's If/Switch) lives here. An item that names an output
     * ({@see FlowItems::OUTPUT}, set by a routing node) goes only to the output
     * place with that name; an item that names none is broadcast to every output,
     * which is the ordinary behaviour and what a parallel split relies on. So a
     * step whose items carry no output tag distributes exactly as before — this
     * is additive, not a change to any existing flow. The tag is stripped as the
     * item lands, so it never lingers to misroute a later step.
     *
     * Clearing the consumed inputs matters for a loop that re-enters the
     * transition — it must read fresh items, not a stale copy left behind.
     *
     * @param object               $transition The fired transition.
     * @param array<string, array> $placeItems The current per-place buffers.
     * @param array                $items      What the step produced.
     * @param array<string>        $taken      The output places the exit claimed.
     *
     * @return array<string, array> The updated buffers.
     *
     * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
     */
    private function advanceItems(object $transition, array $placeItems, array $items, array $taken): array
    {
        foreach ($transition->getTos() as $to) {
            // Only the taken exit receives items. Seeding a branch the token
            // never reaches would leave stale items for a later firing to pick
            // up as if they were fresh.
            if (in_array((string) $to, $taken, true) === false) {
                unset($placeItems[(string) $to]);
                continue;
            }

            $placeItems[(string) $to] = $this->itemsForOutput(items: $items, output: (string) $to);
        }

        foreach ($transition->getFroms() as $from) {
            unset($placeItems[(string) $from]);
        }

        return $placeItems;

    }//end advanceItems()

    /**
     * Withdraw the tokens the taken exit did not claim.
     *
     * `Workflow::apply()` marks EVERY output place, which is correct for a
     * parallel split and wrong for a choice — and the difference is invisible
     * until the losing branch runs anyway, one iteration later, with no error.
     * That is what a token being "unique and exclusive" rules out.
     *
     * A node with no conditioned exits takes every output, so a genuine split
     * passes through here untouched.
     *
     * @param object        $workflow   The workflow.
     * @param object        $subject    The marking-carrying subject.
     * @param object        $transition The fired transition.
     * @param array<string> $taken      The output places the exit claimed.
     *
     * @return void
     *
     * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
     */
    private function keepOnlyTakenExits(object $workflow, object $subject, object $transition, array $taken): void
    {
        $tos = array_map(static fn ($t): string => (string) $t, $transition->getTos());
        if (count($taken) === count($tos)) {
            return;
        }

        $marking = $workflow->getMarking(subject: $subject);
        foreach ($tos as $place) {
            if (in_array($place, $taken, true) === false && $marking->has($place) === true) {
                $marking->unmark($place);
            }
        }

        $workflow->getMarkingStore()->setMarking(subject: $subject, marking: $marking);

    }//end keepOnlyTakenExits()

    /**
     * The items that belong on one output place: those routed to it, plus the
     * unrouted ones that go everywhere. The output tag is dropped on the way.
     *
     * @param array<int, array> $items  The produced items.
     * @param string            $output The output place's name.
     *
     * @return array<int, array> The items for that output, tag removed.
     *
     * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
     */
    private function itemsForOutput(array $items, string $output): array
    {
        // A routing step tags an item with the NODE it is routing to, and a
        // node's input place is named after the node — so the tag and the place
        // name are normally the same string. The one exception is a declared
        // join, whose input places are `<node>#<edge>` so it can require a token
        // on each. Comparing the raw place name there would match nothing and
        // silently drop every routed item into an empty branch, which is the
        // failure mode this engine exists to refuse rather than produce.
        $target = $output;
        $split  = strpos($output, '#');
        if ($split !== false) {
            $target = substr($output, 0, $split);
        }

        $out = [];
        foreach ($items as $item) {
            $tag = FlowItems::outputOf(member: (array) $item);
            if ($tag !== null && $tag !== $target) {
                // Routed elsewhere: not this output's item.
                continue;
            }

            unset($item[FlowItems::OUTPUT]);
            $out[] = $item;
        }

        return $out;

    }//end itemsForOutput()
}//end class
