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
class RegistryStepDispatcher implements FlowStepDispatcher {
	/**
	 * Constructor.
	 *
	 * @param FlowNodeRegistry $registry The node catalogue.
	 * @param FlowRunGuard|null $guard Keeps the run visibly alive between steps
	 *                                 and enforces its deadline. Null for
	 *                                 callers with no run to guard — the flow
	 *                                 tester and the node unit tests dispatch
	 *                                 without one.
	 * @param FlowRunAsScope|null $scope Executes a CONTRIBUTED node as the
	 *                                   run's acting identity. Null only for a
	 *                                   dispatcher built by hand with no
	 *                                   container behind it — the flow tester
	 *                                   and the node unit tests — which then
	 *                                   runs every node bare, exactly like the
	 *                                   nullable guard; all three production
	 *                                   construction sites supply one.
	 */
	public function __construct(
		private readonly FlowNodeRegistry $registry,
		private readonly ?FlowRunGuard $guard = null,
		private readonly ?FlowRunAsScope $scope = null,
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
	 * @param array $step The step configuration.
	 * @param array $items The input items.
	 * @param array $context Run-level metadata.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/or-flow-nodes/specs/flow-nodes/spec.md
	 */
	public function dispatch(array $step, array $items, array $context): array {
		$type = trim((string)($step['type'] ?? ''));

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

		$node = $this->registry->get(type: $type);
		$config = (array)($step['config'] ?? []);

		// Scope the run's resume state to THIS node before calling it, so a node
		// reads and writes its own progress without having to know its own id —
		// and cannot reach another node's slot even by accident.
		$scoped = $this->scopeResumeState(step: $step, context: $context);

		// Scope the resume SIGNAL the same way. Without this, the payload that
		// answered ONE node's question is readable by every wait node the
		// resumed walk goes on to enter, and each of them completes on somebody
		// else's answer instead of suspending with a question of its own.
		$this->scopeSignal(context: $context, scoped: $scoped);

		$startedAt = microtime(true);
		$out = $this->executeScoped(node: $node, items: $items, config: $config, context: $context);
		$tookMs = (int)round((microtime(true) - $startedAt) * 1000);

		// Reached only when the node RETURNED. A node that suspends throws, so
		// this line is skipped and its progress survives — which is the whole
		// contract: a resume slot lives from a suspension to the resume that
		// answers it, and not one step longer. Clearing here is what stops a
		// second pass through the same node (inside a loop, or on a later
		// scheduled tick) from being handed a finished node's stale cursor.
		$scoped?->clear();

		$this->assertWithinNodeBudget(type: $type, config: $config, tookMs: $tookMs);

		return $out;
	}//end dispatch()

	/**
	 * Execute the node — a CONTRIBUTED one inside the run's acting identity.
	 *
	 * The engine's `runAs` used to reach a contributed node as a context key
	 * and nothing more: unless the app built its own wrapper, every write the
	 * node performed ran under the ambient session — which under the cron
	 * worker is nobody, so it was refused as anonymous no matter whose rights
	 * the run declared. dossiq shipped three broken nodes (and a fourth
	 * handler) before building that wrapper. The identity is run-level state,
	 * so the dispatcher applies it: every consumer inherits the scoping
	 * instead of re-implementing it.
	 *
	 * WHO IS WRAPPED. A node whose class lives under `OCA\OpenRegister\` is
	 * the engine's own and already scopes itself — `ObjectWriteNode` and
	 * friends validate and wrap internally, with node-specific wording and
	 * skip-when semantics — so wrapping it again would change the ambient
	 * identity of nodes that deliberately run bare. Everything else is
	 * contributed and runs inside {@see FlowRunAsScope}, which validates the
	 * identity (exists, enabled — refused loudly otherwise) and NARROWS to it;
	 * a contributed node that must manage its own identity declares
	 * {@see IFlowSelfScopedNode}, the documented escape hatch.
	 *
	 * A context that names NO identity runs the node bare either way — the
	 * interactive path — so a dispatcher walking a tester context changes
	 * nothing.
	 *
	 * @param IFlowNode $node The resolved node.
	 * @param array $items The input items.
	 * @param array $config The step configuration.
	 * @param array $context The node context.
	 *
	 * @return array The output items.
	 *
	 * @spec openspec/changes/flow-engine-consumer-seams/specs/flow-engine-consumer-seams/spec.md#requirement-a-contributed-node-executes-under-the-runs-acting-identity
	 */
	private function executeScoped(IFlowNode $node, array $items, array $config, array $context): array {
		$engineOwned = str_starts_with(get_class($node), 'OCA\\OpenRegister\\');

		if ($this->scope === null || $engineOwned === true || $node instanceof IFlowSelfScopedNode === true) {
			return $node->execute(items: $items, config: $config, context: $context);
		}

		return (array)$this->scope->call(
			context: $context,
			operation: static function () use ($node, $items, $config, $context): array {
				return $node->execute(items: $items, config: $config, context: $context);
			}
		);
	}//end executeScoped()

	/**
	 * Put this node's resume slot into the context it is about to be called with.
	 *
	 * Returns the scoped handle so the caller can clear it on a normal return.
	 * Null when there is nothing to scope: a dispatcher built by the container
	 * (the flow tester, node unit tests) walks a context with no run behind it,
	 * and a node with no id has no slot to key.
	 *
	 * @param array $step The step configuration, whose `id` keys the slot.
	 * @param array $context The node context, modified in place.
	 *
	 * @return FlowNodeResumeState|null The scoped handle, or null when unavailable.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
	 */
	private function scopeResumeState(array $step, array &$context): ?FlowNodeResumeState {
		$state = ($context[FlowResumeState::CONTEXT_KEY] ?? null);
		if ($state instanceof FlowResumeState === false) {
			return null;
		}

		$nodeId = trim((string)($step['id'] ?? ''));
		if ($nodeId === '') {
			return null;
		}

		$scoped = $state->forNode(nodeId: $nodeId);
		$context[FlowNodeResumeState::CONTEXT_KEY] = $scoped;

		return $scoped;
	}//end scopeResumeState()

	/**
	 * Withhold the resume signal from every node except the one it answers.
	 *
	 * A signal wakes a RUN, but it answers one NODE: the one that suspended and
	 * whose resume slot is still held. The walk's context is shared, so without
	 * this gate every wait node the resumed walk re-enters AFTER the answered
	 * one reads the same payload as its own answer and completes instead of
	 * suspending — a flow with two approval steps auto-approves the second the
	 * moment the first is granted, and a decision step downstream of an answered
	 * task adopts the task's payload as its decision outcome. Observed live on
	 * dossiq case flows (runs f8996ccc and ca50c56c, 2026-09-01): a DECISION
	 * node's outcome held `{decision, node: "ask-indiener", taskId}` — an
	 * applicant task's completion — and a second decision node inherited the
	 * first decision's reference because it never suspended at all.
	 *
	 * The slot is the addressee test, not `$context['resuming']`: the run-wide
	 * flag is true for every node of a resumed walk, while a held slot marks
	 * exactly the node that suspended and has not yet been given its answer.
	 * The dispatcher clears the slot when a node returns, so a wait node
	 * re-entered later in the SAME walk (a loop) asks fresh rather than
	 * re-reading a consumed answer. With no slot machinery at all (a
	 * container-built dispatcher walking a tester context) the signal is
	 * withheld too: with no slots there is no addressee, and withholding makes
	 * the node suspend visibly where delivering would answer the wrong
	 * question silently.
	 *
	 * The strip is LOCAL to this node's context copy — `dispatch()` receives
	 * `$context` by value — so the walk keeps carrying the signal to the node
	 * whose slot it answers, wherever in the round-robin that node is visited.
	 *
	 * @param array $context The node context, modified in place.
	 * @param FlowNodeResumeState|null $scoped This node's resume slot, when it has one.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	private function scopeSignal(array &$context, ?FlowNodeResumeState $scoped): void {
		if (array_key_exists(FlowRunService::SIGNAL_CONTEXT_KEY, $context) === false) {
			return;
		}

		if ($scoped !== null && $scoped->isResuming() === true) {
			// This node is the one that suspended: the answer is its to read.
			return;
		}

		unset($context[FlowRunService::SIGNAL_CONTEXT_KEY]);

	}//end scopeSignal()

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
	 * @param string $type The node type, for the message.
	 * @param array $config The step configuration.
	 * @param int $tookMs How long the node actually ran.
	 *
	 * @return void
	 *
	 * @throws FlowRunExpired When the node overran its configured ceiling.
	 *
	 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
	 */
	private function assertWithinNodeBudget(string $type, array $config, int $tookMs): void {
		if (array_key_exists('maxRuntimeSeconds', $config) === false) {
			return;
		}

		$ceiling = max(0, (int)$config['maxRuntimeSeconds']);
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
