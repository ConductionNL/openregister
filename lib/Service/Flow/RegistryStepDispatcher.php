<?php

/**
 * The dispatcher every consumer gets for free.
 *
 * `FlowEngine` owns *when* a step runs and asks a `FlowStepDispatcher` to
 * perform it. Before this class each consuming app had to write that dispatcher
 * itself, which meant each app re-implemented "look at the step's type, decide
 * what to do" — and the moment an app has a type switch of its own it has
 * grown half an engine again. That is exactly how this fleet ended up with six
 * things called "flow".
 *
 * This dispatcher resolves the step's `type` out of the shared
 * {@see FlowNodeRegistry} and calls the node that owns it. An app contributes
 * node types and stops there: no dispatcher, no engine, no graph walking.
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
 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Exception\FlowRunExpired;

/**
 * Dispatches each step to the registered node that owns its type.
 */
class RegistryStepDispatcher implements FlowStepDispatcher
{
    /**
     * Constructor.
     *
     * @param FlowNodeRegistry  $registry The node catalogue.
     * @param FlowRunGuard|null $guard    Keeps the run visibly alive between steps
     *                                    and enforces its deadline. Null for
     *                                    callers with no run to guard — the flow
     *                                    tester and the node unit tests dispatch
     *                                    without one.
     */
    public function __construct(
        private readonly FlowNodeRegistry $registry,
        private readonly ?FlowRunGuard $guard=null
    ) {

    }//end __construct()

    /**
     * Resolve the step's type and run its node.
     *
     * A step with no `type` passes its items through untouched. That is not
     * leniency about unknown types — an UNKNOWN type still throws, via the
     * registry. It is the pure-routing edge: an author drawing an edge purely
     * to shape the graph has not asked for work to happen on it.
     *
     * @param array $step    The step configuration.
     * @param array $items   The input items.
     * @param array $context Run-level metadata.
     *
     * @return array The output items.
     *
     * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
     */
    public function dispatch(array $step, array $items, array $context): array
    {
        $type = trim((string) ($step['type'] ?? ''));

        // The guard is per-RUN state, so a container-built dispatcher never has
        // one — the loop node resolves its dispatcher through DI, and the guard's
        // constructor takes a run uuid the container cannot invent. Falling back
        // to the run context keeps steps INSIDE a loop checkpointing, which is
        // where a long run spends its time in the first place.
        $guard = $this->guard;
        if ($guard === null) {
            $fromContext = ($context[FlowRunGuard::CONTEXT_KEY] ?? null);
            if ($fromContext instanceof FlowRunGuard === true) {
                $guard = $fromContext;
            }
        }

        // Checkpoint on the way IN, so a run whose very first step is the long one
        // is marked alive before that step begins, and so a run that is already
        // over budget stops here rather than starting more work.
        $where = $type;
        if ($where === '') {
            $where = 'a routing edge';
        }

        $guard?->checkpoint(where: $where);

        if ($type === '') {
            return $items;
        }

        $node   = $this->registry->get(type: $type);
        $config = (array) ($step['config'] ?? []);

        $startedAt = microtime(true);
        $out       = $node->execute(items: $items, config: $config, context: $context);
        $tookMs    = (int) round((microtime(true) - $startedAt) * 1000);

        $this->assertWithinNodeBudget(type: $type, config: $config, tookMs: $tookMs);

        return $out;

    }//end dispatch()

    /**
     * Stop a step that took longer than its own `maxRuntimeSeconds`.
     *
     * A per-NODE ceiling, separate from the run's: one slow integration should be
     * answerable for itself rather than only showing up as the whole flow running
     * out of time an hour later, by which point the log says the run expired and
     * not which step ate it.
     *
     * Checked AFTER the call, because PHP cannot preempt one already in progress.
     * That is a real limit and worth naming: a node that blocks for ten minutes on
     * a socket is stopped when it returns, not at the moment it passed its ceiling.
     * A node that works in a loop can do better by calling `checkpoint()` on the
     * guard in its context, which is why the guard is exposed to nodes at all.
     *
     * @param string $type   The node type, for the message.
     * @param array  $config The step configuration.
     * @param int    $tookMs How long the node actually ran.
     *
     * @return void
     *
     * @throws FlowRunExpired When the node overran its configured ceiling.
     *
     * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
     */
    private function assertWithinNodeBudget(string $type, array $config, int $tookMs): void
    {
        if (array_key_exists('maxRuntimeSeconds', $config) === false) {
            return;
        }

        $ceiling = max(0, (int) $config['maxRuntimeSeconds']);
        if ($ceiling === 0 || $tookMs <= ($ceiling * 1000)) {
            return;
        }

        throw new FlowRunExpired(
            message: sprintf(
                'Step "%s" ran for %.1fs, over its maxRuntimeSeconds of %d.',
                $type,
                ($tookMs / 1000),
                $ceiling
            )
        );

    }//end assertWithinNodeBudget()
}//end class
