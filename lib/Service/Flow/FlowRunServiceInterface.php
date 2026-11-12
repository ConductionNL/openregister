<?php

/**
 * FlowRunServiceInterface: the public surface of FlowRunService, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;

/**
 * Every public method of FlowRunService. Callers depend on this interface, not on the class.
 */
interface FlowRunServiceInterface {

	/**
	 * The context key an arriving signal's payload lands at.
	 *
	 * Read by whichever node suspended waiting for it, and cleared once that
	 * walk finishes — a signal answers one question, and leaving it behind would
	 * let a later suspension read an answer meant for an earlier one.
	 *
	 * @var string
	 */
	public const SIGNAL_CONTEXT_KEY = 'signal';

	/**
	 * The context key the run's ACTING IDENTITY travels under.
	 *
	 * Stamped by {@see self::baseContextFor()} from the run — never from the
	 * context, per ADR-099 — and read back by every node whose work needs the
	 * run's rights. Exported so consumers reference one name instead of each
	 * hard-coding the literal; the VALUE is frozen, because it is stored inside
	 * every parked run's context and a rename would strand them.
	 *
	 * @var string
	 */
	public const RUN_AS_CONTEXT_KEY = 'runAs';

	/**
	 * The context key the run's FLOW id travels under.
	 *
	 * Stamped from the run, like the acting identity, so a node can name the
	 * flow it belongs to (a sent-email event does) without a lookup.
	 *
	 * @var string
	 */
	public const FLOW_ID_CONTEXT_KEY = 'flowId';

	/**
	 * The trigger a direct node invocation carries (or-flow-run-node).
	 *
	 * Unlike {@see FlowRunVersionPin::TRIGGER_TEST}, this trigger is NOT
	 * exempt from the published-version requirement: a direct-invoked node
	 * still runs against a real, published graph (RN-3) — it is the AUTHORING
	 * trust that is absent here (this is an app button, not the editor), not
	 * the soundness/publication requirement every live dispatch already
	 * carries.
	 *
	 * @var string
	 */
	public const TRIGGER_DIRECT_NODE = 'direct-node';

	/**
	 * The run-context key under which a direct-node run names the node it runs.
	 *
	 * The caller puts it there when it queues the run, and runNode() reads it
	 * back, so the node that was authorised is the node that is dispatched.
	 *
	 * @var string
	 */
	public const NODE_ID_CONTEXT_KEY = 'nodeId';

	/**
	 * Advance ONE stream of a run in the calling request, within a budget.
	 *
	 * ADR-098 D9's advance budget follows the token: completing a task on one
	 * branch may advance THAT branch — taking claims exactly as a worker does,
	 * bounded by the ceiling, per-firing oversight and the runtime budget —
	 * while its siblings are untouched. A sibling's claim ends the advance
	 * and returns the run as it stands; the queue does the rest.
	 *
	 * `$budget` is `0` (nothing; the run is left queued), a count, or `"all"`
	 * (bounded by the same three things a worker is).
	 *
	 * @param FlowRunPass       $pass    The run, already resolved and pinned, its flow document and subject.
	 * @param FlowStreamAdvance $advance The completing branch and its budget (`0`, `N`, or `"all"`).
	 *
	 * @return FlowRun The run as it stands after the advance.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-completions-advance-budget-must-apply-to-the-completing-branch
	 */
	public function advanceBranch(FlowRunPass $pass, FlowStreamAdvance $advance): FlowRun;

	/**
	 * Queue a run without executing it.
	 *
	 * This is what a trigger calls. A Nextcloud Flow rule, an object event or a
	 * file write runs inside the dispatch of the thing that caused it, and an
	 * arbitrary graph must not sit on that critical path — so the trigger only
	 * records the intent and returns.
	 *
	 * @param string              $flowId  The flow to run.
	 * @param FlowRunQueueOptions $options The subject (`{uuid, register, schema}`), what caused this run,
	 *                                     run-level metadata and the user whose action caused it.
	 *
	 * @return FlowRun The queued run.
	 *
	 * @throws FlowLifecycleRefused When the trigger is not `test` and the flow
	 *                     has no published version (or its published version
	 *                     is unsound) — `requirePublishedAndSound()`'s
	 *                     refusal. Pre-existing but previously undeclared
	 *                     here: `FlowController::run()` already catches it
	 *                     around this same call chain, so the contract was
	 *                     always real, just missing from this signature —
	 *                     surfaced by phpstan flagging a new caller's catch
	 *                     of it as dead, which it was not.
	 * @throws FlowDeadEnd When a node has no outgoing edge and does not end the
	 *                     flow, so the run is refused rather than started. Declared
	 *                     because it is part of this method's contract: every
	 *                     dispatch path arrives here, and a caller that cannot see
	 *                     the refusal in the signature will not handle it — which
	 *                     is how it reached HTTP as a bare 500.
	 * @throws FlowUnattributed When nothing names the identity the run would
	 *                         execute as. Declared for the same reason: the
	 *                         schedule sweep must catch it PER FLOW, and a caller
	 *                         that cannot see it in the signature lets one
	 *                         unattributed flow abort the whole sweep.
	 * @throws DelegationRefused When the trigger asserts a delegation that is no
	 *                         longer live. Declared for the same reason again —
	 *                         and it is a DIFFERENT fault from the one above, so
	 *                         it must not be folded into it: "nobody was named"
	 *                         and "the person named it may no longer act as them"
	 *                         want opposite fixes.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	public function queueRun(string $flowId, FlowRunQueueOptions $options): FlowRun;

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
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function hasActiveRun(string $flowId): bool;

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
	public function retry(FlowRun $run): ?FlowRun;

	/**
	 * Deliver the external signal a suspended run is waiting for.
	 *
	 * The missing half of {@see FlowSuspension}. That exception has always
	 * documented `resumeAt: null` as "waits for an external signal (a child run,
	 * a webhook)", and the engine has always suspended correctly on it — but
	 * nothing could ever wake such a run, because no query reads it and no
	 * endpoint existed to say the signal had arrived. The documented case was
	 * unreachable, and any run that used it stranded its flow.
	 *
	 * The run is PARKED, not advanced inline: a signal arrives on an HTTP
	 * request, and that request has to return. Setting `resume_at` to now makes
	 * the run due, so the next worker pass picks it up through the ordinary
	 * `findDue()` path rather than through a second execution route that would
	 * have to re-implement guards, fairness and error handling. The cost is
	 * latency — up to one worker pass, and the stock system cron is every five
	 * minutes — which is the right trade for an approval and the wrong one for a
	 * tight machine-to-machine handshake. Worth knowing before using it for the
	 * latter.
	 *
	 * The payload lands at `$context['signal']` and is consumed by the next
	 * walk ({@see FlowRunService::persistResult()}), so a node reads the answer to ITS
	 * question rather than one left behind by an earlier suspension.
	 *
	 * 🔴 THIS IS THE UNGUARDED PRIMITIVE, for trusted engine-internal delivery
	 * only: it checks the run's STATUS and nothing about the CALLER, so calling
	 * it directly is asserting "whoever I am acting for has already been
	 * allowed to answer this". Everything outside the engine goes through
	 * {@see FlowRunSignalService::signalAs()}, which applies the
	 * recorded-assignee guard (group resolution included) and audits a refusal
	 * — the HTTP resume endpoints use that same seam, so there is exactly one
	 * guard. A consumer that calls this method instead has re-opened the gap
	 * the seam exists to close, silently: the wrong person's answer arrives
	 * correctly formatted.
	 *
	 * Signals that arrive before the run wakes are merged, not replaced: a later
	 * signal adds the keys the waiting one lacks. A key both carry with different
	 * values is a conflict between two signallers: the answer already waiting
	 * stands, the later value is refused and logged, so one signaller can never
	 * overwrite what another delivered (and the two answers are never mixed
	 * under one key).
	 *
	 * @param FlowRun $run The suspended run to wake.
	 * @param array $payload What the signaller wants the run to know.
	 *
	 * @return FlowRun|null The updated run, or null when it was not suspended.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	public function signal(FlowRun $run, array $payload = []): ?FlowRun;

	/**
	 * Execute (or continue) a run to its next stopping point.
	 *
	 * Called by the queue worker, never by a trigger. Returns the run in
	 * whatever state the walk left it: terminal, or suspended and resumable.
	 *
	 * @param FlowRunPass   $pass   The run to advance, the flow document and the object the run is about.
	 * @param FlowRunOrigin $origin Items and node to start from (run-from-here); ignored when resuming.
	 *
	 * @return FlowRun The updated run.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowToken::fromArray is a stateless
	 * rehydrator for a value object; injecting a factory to call it would add a
	 * dependency without removing any coupling.
	 */
	public function executePass(FlowRunPass $pass, FlowRunOrigin $origin): FlowRun;

	/**
	 * Run exactly ONE named node of a queued run's published graph, and stop.
	 *
	 * The mode or-flow-run-node's tasks.md asked for, distinct from
	 * {@see executePass()}'s `startAt`: `startAt` still walks the WHOLE graph
	 * onward from a chosen node, following every real edge, join and loop it
	 * meets. This does not walk at all — it dispatches the one named step
	 * directly through {@see RegistryStepDispatcher}, exactly the way
	 * {@see FlowEngine} would for that one hop, and records the result. There
	 * is deliberately no engine-level graph traversal here: a node invoked
	 * this way is being asked to do its own work in isolation, not to hand
	 * off to whatever it happens to point at in its authoring graph — routing
	 * output onward would silently run MORE of the flow than the caller was
	 * authorized (RN-1) to run.
	 *
	 * The queued run this expects came from {@see queueRun()} with
	 * `trigger: FlowRunService::TRIGGER_DIRECT_NODE`, so it already carries a resolved
	 * attribution and a version pin exactly like any other dispatch — a
	 * direct-invoked node still needs to know WHOSE rights it writes with and
	 * WHICH published graph it belongs to.
	 *
	 * The node to run is the one the run was queued for, named in its context
	 * under {@see self::NODE_ID_CONTEXT_KEY}.
	 *
	 * @param FlowRunPass $pass   The queued run, the flow document (used only to resolve the published pin)
	 *                            and the subject object the node acts on.
	 * @param array       $config The caller's config, overlaid on the published step's config after
	 *                      pinning (caller keys win). The pin replaces the graph's nodes, so
	 *                      this is the only way a picker's choice reaches the node.
	 *
	 * @return FlowRun The updated run: COMPLETED with the node's output items,
	 *                 or FAILED with the node's exception recorded. Never
	 *                 SUSPENDED — see {@see failSuspendedNode()}.
	 *
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#requirement-a-node-type-opts-in-to-direct-invocation
	 * @spec openspec/changes/or-flow-run-node/specs/flow-run-node/spec.md#scenario-the-callers-config-reaches-the-node
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowItems::fromSubject/normalise are
	 * stateless value normalisers, the same ones executePass() calls under the same
	 * suppression reasoning already given there: injecting a factory to call
	 * them would add a dependency without removing any coupling.
	 */
	public function runNode(FlowRunPass $pass, array $config = []): FlowRun;

	/**
	 * The items a run starts from, read from a seed exactly as a caller sent it.
	 *
	 * A test run may be seeded by hand; what arrives is whatever the request
	 * carried. Anything that is not an item list (or one item) yields none.
	 *
	 * @param mixed $value The seed as sent.
	 *
	 * @return array<int, array<string, mixed>> The well-formed items.
	 *
	 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
	 */
	public function seedItemsFrom(mixed $value): array;
}//end interface
