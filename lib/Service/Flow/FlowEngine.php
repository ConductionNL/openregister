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

use InvalidArgumentException;
use OCA\OpenRegister\Db\FlowRun;
use Psr\Log\LoggerInterface;
use Symfony\Component\Workflow\MarkingStore\MarkingStoreInterface;
use Symfony\Component\Workflow\Workflow;
use Throwable;

/**
 * Runs a stored flow document to completion, or until it can go no further.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) Two walks live here on purpose: the single
 * in-memory stream every unit test and the flow tester use, and the persisted stream walk a
 * worker pass uses. Sharing the hop body between them would make the first depend on the second.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One labelled branch per way a hop can end,
 * in each walk; PHPMD sums them into the class total.
 *
 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
 */
class FlowEngine {

	/**
	 * Run states, ported from openconnector's flow_run lifecycle.
	 */
	public const STATUS_COMPLETED = 'completed';

	public const STATUS_STOPPED = 'stopped';

	public const STATUS_DEAD_LETTER = 'dead_letter';

	public const STATUS_FAILED = 'failed';

	/**
	 * The run has enabled work nobody took this pass — a claim was refused, or
	 * a join became enabled by the last commit — and the next pass drains it.
	 * Only produced by the stream walk; a single in-memory stream never
	 * contends with anyone.
	 */
	public const STATUS_QUEUED = 'queued';

	/**
	 * How many items of a step's input and output the run log keeps.
	 *
	 * Small on purpose. The log answers "what shape was this, and did it look
	 * right" — a question the first few items settle — not "give me the data",
	 * which is what the objects themselves are for. A larger window would put a
	 * synchronisation's whole payload into a record kept for months.
	 *
	 * @var integer
	 */
	public const LOG_ITEM_SAMPLE = 5;

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
	 * $router and $placement are added LAST, and defaulted: three unit tests
	 * construct the engine positionally and one passes the oversight registry
	 * third, so slotting a parameter in ahead of it would silently rebind that
	 * argument — a test that still passes while checking nothing. Neither holds
	 * state, so a locally-made one is indistinguishable from an injected one.
	 *
	 * @param FlowDefinitionBuilder $builder The document -> Petri-net translator.
	 * @param LoggerInterface $logger The logger.
	 * @param FlowOversightRegistry|null $oversight The pre-hop gate. Nullable so the
	 *                                              engine stays unit-testable without a
	 *                                              container; absent, nothing objects,
	 *                                              exactly as an empty registry does.
	 * @param FlowTokenRouter|null $router Decides which exit a token takes.
	 * @param FlowItemPlacement|null $placement Decides which items sit on which place.
	 * @param FlowRunContext|null $runContext The ambient attribution stack. Nullable
	 *                                       so the engine stays unit-testable without
	 *                                       a container; absent, writes are simply
	 *                                       unattributed rather than mis-attributed.
	 * @param FlowTaskMootness|null $mootness Told which places a routing decision
	 *                                        cleared, so a user task waiting on one
	 *                                        of them is terminated rather than
	 *                                        orphaned. Nullable on the same terms;
	 *                                        absent, run-terminality propagation is
	 *                                        the only backstop.
	 */
	public function __construct(
		private readonly FlowDefinitionBuilder $builder,
		private readonly LoggerInterface $logger,
		private readonly ?FlowOversightRegistry $oversight = null,
		private readonly ?FlowTokenRouter $router = null,
		private readonly ?FlowItemPlacement $placement = null,
		private readonly ?FlowRunContext $runContext = null,
		private readonly ?FlowTaskMootness $mootness = null,
	) {

	}//end __construct()

	/**
	 * Open an attribution frame for the hop about to run.
	 *
	 * Split out of run() so the walk reads as the walk. The arithmetic is the
	 * part worth keeping together: the step number is the run's sequence BASE
	 * plus this hop's index in the segment log, and `recordSteps()` numbers the
	 * same entries from the same base in the same order — so an attributed audit
	 * row and its FlowRunStep row carry the same number. Deriving it from a
	 * dispatch counter instead desynchronises the moment a step is PINNED, since
	 * a pin logs an entry without ever reaching the dispatcher.
	 *
	 * @param array   $context The run context, carrying the run uuid and base.
	 * @param string  $name    The node about to run.
	 * @param integer $index   This hop's index within the segment's log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-engine/spec.md
	 */
	private function enterHop(array $context, string $name, int $index): void {
		$this->runContext?->push(
			runUuid: ($context[FlowRunContext::CONTEXT_RUN] ?? null),
			nodeId: $name,
			sequence: ((int)($context[FlowRunContext::CONTEXT_BASE] ?? 0) + $index)
		);
	}//end enterHop()

	/**
	 * The token router, made on demand when none was injected.
	 *
	 * @return FlowTokenRouter The router.
	 *
	 * @spec openspec/changes/or-flow-action-nodes/specs/flow-engine/spec.md
	 */
	private function router(): FlowTokenRouter {
		return ($this->router ?? new FlowTokenRouter());
	}//end router()

	/**
	 * The item placement, made on demand when none was injected.
	 *
	 * @return FlowItemPlacement The placement.
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	private function placement(): FlowItemPlacement {
		return ($this->placement ?? new FlowItemPlacement());
	}//end placement()

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
	 * @param string $name The transition about to fire.
	 * @param string $type The step type about to run.
	 *
	 * @return array{checkId: string, reason: string}|null The refusal, or null to proceed.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	private function oversightRefusal(array $context, string $name, string $type): ?array {
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
	 * @param array $context The run context.
	 * @param string $name The transition about to fire.
	 * @param string $type The node type about to run.
	 *
	 * @return void
	 *
	 * @throws FlowStop When a check refuses the hop.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	private function assertOversightAllows(array $context, string $name, string $type): void {
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
	 * do not run; an author pins their output ({@see FlowWalk::pinnedFor()}) and
	 * re-runs only the part being worked on. It is the initial place overridden,
	 * so a resume — whose marking is already mid-graph and comes from the store —
	 * is unaffected. A non-empty start node replaces the flow's `initial`; the
	 * builder then validates it exists, so an unknown node fails the run exactly
	 * as a bad document does.
	 *
	 * @param array $flow The flow document.
	 * @param MarkingStoreInterface $store Where the marking lives (an OR object, in production).
	 * @param object $subject The object the run is about; holds the marking.
	 * @param FlowStepDispatcher $dispatcher Performs each step's side effect.
	 * @param array $context Run-level metadata handed to every step.
	 * @param array|null $items Seed items; defaults to one item from the subject.
	 * @param string|null $startAt Node to start from; defaults to the flow's own start.
	 * @param FlowStreamWalk|null $streams The per-run stream collaborator: claims before each
	 *                                     firing, a locked delta commit after it, stream-scoped
	 *                                     suspension. Null walks a single in-memory stream
	 *                                     exactly as before — a flow with one stream IS the run.
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
		array $context = [],
		?array $items = null,
		?string $startAt = null,
		?FlowStreamWalk $streams = null,
	): array {
		$items = ($items ?? FlowItems::fromSubject(subject: $subject));
		if (($startAt ?? '') !== '') {
			$flow['initial'] = $startAt;
		}

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
				'status' => self::STATUS_FAILED,
				'log' => [],
				'context' => $context,
				'items' => $items,
				'error' => $e->getMessage(),
			];
		}//end try

		$workflow = new Workflow(definition: $definition, markingStore: $store, name: (string)($flow['id'] ?? 'flow'));

		// Per-place item buffers. Items belong to the PLACES a token sits on,
		// not to the run globally ({@see self::seedPlaceItems()}). With a stream
		// walk the buffers persisted by the last commit win, so each branch
		// resumes with the items ITS branch produced.
		$walk = new FlowWalk(flow: $flow, workflow: $workflow, subject: $subject, dispatcher: $dispatcher, context: $context);
		if ($streams !== null) {
			$walk = new FlowStreamPass(
				flow: $flow,
				workflow: $workflow,
				store: $store,
				subject: $subject,
				dispatcher: $dispatcher,
				context: $context,
				streams: $streams
			);
		}

		$walk->items = $items;
		$walk->placeItems = $this->placement()->seedPlaceItems(
			workflow: $workflow,
			subject: $subject,
			definition: $definition,
			items: $items,
			stored: $streams?->run()->getPlaceItems()
		);

		if ($walk instanceof FlowStreamPass) {
			return $this->walkStreams(pass: $walk);
		}

		return $this->walkSingle(walk: $walk);
	}//end run()

	/**
	 * The single in-memory walk: fire enabled transitions until none remain.
	 *
	 * @param FlowWalk $walk The walk, holding the flow, its collaborators and the items.
	 *
	 * @return array The run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function walkSingle(FlowWalk $walk): array {
		$fired = 0;

		while (true) {
			$enabled = $walk->workflow->getEnabledTransitions(subject: $walk->subject);
			if (empty($enabled) === true) {
				// No transition is enabled: either the run reached a final marking,
				// or a join is still waiting on a branch that never arrives. Both
				// are "as far as this graph goes".
				return $walk->result(status: self::STATUS_COMPLETED);
			}

			$fired++;
			if ($fired > self::MAX_TRANSITIONS) {
				return $this->ceilingResult(walk: $walk);
			}

			// Conditional branching (If/Switch): the first enabled transition
			// whose edge condition holds; an unconditioned edge is the default.
			$transition = $this->selectTransition(enabled: $enabled, flow: $walk->flow, placeItems: $walk->placeItems, context: $walk->context);
			if ($transition === null) {
				// Every active choice point is gated by a condition that did
				// not hold and has no default edge: a Switch with no matching
				// case and no fallback. The run ends here rather than spinning
				// on the same un-fireable transitions until the ceiling.
				return $walk->result(status: self::STATUS_COMPLETED);
			}

			// A step reads the items on its input place(s). For a join that is
			// the concatenation of every branch's items, in the froms' declared
			// order; the Petri net already holds the join until every input
			// place is marked.
			$hop = new FlowWalkHop(
				walk: $walk,
				transition: $transition,
				step: $this->router()->stepFor(flow: $walk->flow, transitionName: $transition->getName()),
				itemsIn: $this->placement()->itemsForTransition(transition: $transition, placeItems: $walk->placeItems)
			);

			$outcome = $this->runHop(hop: $hop);
			if ($outcome !== null) {
				return $outcome;
			}
		}//end while

	}//end walkSingle()

	/**
	 * Run one hop of the single walk: a pinned step is used as it is, any other step is dispatched.
	 *
	 * Pinned output (n8n's "pin data") is used verbatim and the step is NOT
	 * executed, so the side effect is skipped and a pinned step can neither
	 * stop, suspend nor fail.
	 *
	 * @param FlowWalkHop $hop The hop.
	 *
	 * @return array|null The terminal run result, or null when the walk goes on.
	 *
	 * @spec openspec/changes/or-flow-pins/specs/flow-pins/spec.md
	 */
	private function runHop(FlowWalkHop $hop): ?array {
		$walk = $hop->walk;
		$walk->items = $hop->itemsIn;

		$pinned = $walk->pinnedFor(transitionName: $hop->name());
		if ($pinned === null) {
			return $this->dispatchHop(hop: $hop);
		}

		$walk->items = $pinned;
		$walk->log[] = $hop->pinnedEntry();
		$this->advance(hop: $hop);

		return null;
	}//end runHop()

	/**
	 * Dispatch a hop's step inside its attribution frame, and handle how the hop ends.
	 *
	 * ATTRIBUTION, around the hop: everything written until the matching pop
	 * is filed under this run and node. The step number is `base + count($log)`,
	 * which `recordSteps()` numbers the same way, so an attributed audit row and
	 * its `FlowRunStep` row carry the SAME sequence.
	 *
	 * @param FlowWalkHop $hop The hop.
	 *
	 * @return array|null The terminal run result, or null when the walk goes on.
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-engine/spec.md
	 */
	private function dispatchHop(FlowWalkHop $hop): ?array {
		$walk = $hop->walk;
		$this->enterHop(context: $walk->context, name: $hop->name(), index: count($walk->log));

		try {
			// OVERSIGHT, before the hop. A veto is raised as a FlowStop so it
			// travels the same path as an author's Stop step: the run ENDS.
			$this->assertOversightAllows(context: $walk->context, name: $hop->name(), type: $hop->stepType());

			$produced = $walk->dispatcher->dispatch(step: $hop->step, items: $hop->itemsIn, context: $walk->context);
			$walk->items = FlowItems::normalise(value: $produced);
			$walk->log[] = $hop->completedEntry();
		} catch (FlowStop $stop) {
			// A deliberate end, requested by a Stop step or an oversight gate.
			// Caught before Throwable so no onError policy ever applies to it.
			$walk->log[] = $hop->stoppedEntry(stop: $stop);
			return $walk->stoppedResult(stop: $stop);
		} catch (FlowSuspension $suspension) {
			// A pause, not a failure. The marking is NOT advanced: the run must
			// resume ON this transition, re-entering the step that asked to wait.
			$walk->log[] = $hop->suspendedEntry(suspension: $suspension);
			return $walk->result(status: self::STATUS_SUSPENDED) + ['resumeAt' => $suspension->getResumeAt()];
		} catch (Throwable $e) {
			$walk->log[] = $hop->failedEntry(error: $e);
			if ($this->logStepFailure(hop: $hop, error: $e) !== self::ON_ERROR_CONTINUE) {
				return $walk->result(status: $hop->terminalStatus()) + ['error' => $e->getMessage()];
			}
		} finally {
			// 🔴 UNCONDITIONAL, and structurally so. Every other exit from
			// this hop is a `return` inside a catch, and a pop placed on the
			// success path would leave the frame standing on all three: the
			// next write in the process (a LATER run advanced by the same
			// worker) would be filed under a run that had already finished.
			$this->runContext?->pop();
		}//end try

		// The marking advances even when a `continue` step failed: the author
		// asked the run to proceed, and leaving the token behind would spin
		// this transition forever.
		$this->advance(hop: $hop);

		return null;
	}//end dispatchHop()

	/**
	 * Move the token and the items on after a hop of the single walk.
	 *
	 * A routing step's exit-id tags become places first, so the exit choice
	 * and the per-place delivery read the same name. The token takes exactly
	 * one exit of a branching node; the items move in lock-step with it.
	 *
	 * @param FlowWalkHop $hop The hop; its walk holds the items the step produced.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-per-item-routing/specs/flow-per-item-routing/spec.md
	 */
	private function advance(FlowWalkHop $hop): void {
		$walk = $hop->walk;
		$walk->items = $this->router()->resolveOutputTags(flow: $walk->flow, transition: $hop->transition, items: $walk->items);
		$taken = $this->router()->takenExits(flow: $walk->flow, transition: $hop->transition, items: $walk->items, context: $walk->context);
		$walk->placeItems = $this->placement()->advanceItems(
			transition: $hop->transition,
			placeItems: $walk->placeItems,
			items: $walk->items,
			taken: $taken
		);
		$walk->workflow->apply(subject: $walk->subject, transitionName: $hop->name());
		$this->pruneUntakenExits(hop: $hop, taken: $taken);
	}//end advance()

	/**
	 * Fail the run because it reached the transition ceiling.
	 *
	 * A Petri net expresses loops, so a user-drawn cycle can run forever;
	 * hitting the ceiling is a failure, reported as one, not a silent truncation.
	 *
	 * @param FlowWalk $walk The walk.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function ceilingResult(FlowWalk $walk): array {
		$this->logger->warning(
			message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
			context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($walk->flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
		);
		$error = sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS);

		return $walk->result(status: self::STATUS_FAILED) + ['error' => $error];
	}//end ceilingResult()

	/**
	 * Log a failed step and say which `onError` policy applies to it.
	 *
	 * @param FlowWalkHop $hop   The failed hop.
	 * @param Throwable   $error The failure.
	 *
	 * @return string The step's policy.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function logStepFailure(FlowWalkHop $hop, Throwable $error): string {
		$policy = $hop->errorPolicy();
		$this->logger->warning(
			message: '[FlowEngine] Flow step failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => ($hop->walk->flow['id'] ?? null),
				'transition' => $hop->name(),
				'policy' => $policy,
				'error' => $error->getMessage(),
			]
		);

		return $policy;
	}//end logStepFailure()

	/**
	 * Withdraw the tokens a choice did not take, and say so.
	 *
	 * The withdrawal itself is the router's. What is added here is the report:
	 * a place cleared by a routing decision may be one a user-task node had
	 * already asked somebody from, and that node will now never fire from it,
	 * so its task is moot and must not stay in an inbox as actionable work.
	 * The Petri net raises no event for this; the engine is the only thing
	 * that sees the moment, so the engine reports it (design D-7).
	 *
	 * @param FlowWalkHop   $hop   The hop whose transition just fired.
	 * @param array<string> $taken The output places the exit claimed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	private function pruneUntakenExits(FlowWalkHop $hop, array $taken): void {
		$transition = $hop->transition;
		$tos = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		$pruned = array_values(array_diff($tos, $taken));

		$this->router()->keepOnlyTakenExits(
			workflow: $hop->walk->workflow,
			subject: $hop->walk->subject,
			transition: $transition,
			taken: $taken
		);

		if ($pruned === []) {
			return;
		}

		$this->mootness?->placesPruned(
			context: $hop->walk->context,
			places: $pruned,
			byTransition: $transition->getName()
		);
	}//end pruneUntakenExits()

	/**
	 * The per-stream walk: round-robin over advanceable streams, a claim before
	 * every firing, a locked delta commit after it, and suspension scoped to
	 * the stream that raised it.
	 *
	 * Terminality is never concluded from this loop running dry. The pass ends
	 * when no stream can advance, and `finalize()` — under the run-row lock,
	 * from the marking it reads there — decides whether the run is queued (an
	 * enabled firing nobody took), suspended (every live stream parked, woken
	 * at the earliest non-null wake time) or terminal.
	 *
	 * @param FlowStreamPass $pass The pass, holding the flow, its collaborators and the items.
	 *
	 * @return array The run result.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function walkStreams(FlowStreamPass $pass): array {
		$streams = $pass->streams;
		$streams->begin(marking: $pass->workflow->getMarking(subject: $pass->subject)->getPlaces());

		while ($streams->budgetSpent() === false) {
			$streamId = $streams->nextStream();
			if ($streamId === null) {
				break;
			}

			// THE CEILING counts the RUN, not the pass: the durable firing
			// count survives suspension and covers every stream together.
			if ($streams->firings() >= self::MAX_TRANSITIONS) {
				$result = $this->ceilingResult(walk: $pass);
				$streams->finalize(enabled: false, forcedTerminal: self::STATUS_FAILED);
				return $result;
			}

			$hop = $this->claimStreamHop(pass: $pass, streamId: $streamId);
			if ($hop === null) {
				continue;
			}

			$outcome = $this->runStreamHop(hop: $hop);
			if ($outcome !== null) {
				return $outcome;
			}
		}//end while

		return $this->finishStreamPass(pass: $pass);
	}//end walkStreams()

	/**
	 * Choose a stream's next firing and claim the places it touches.
	 *
	 * A stream with no eligible transition is exhausted. A refused claim skips
	 * the candidate without waiting; the firing stays enabled and the run ends
	 * the pass `queued`.
	 *
	 * @param FlowStreamPass $pass     The pass.
	 * @param string         $streamId The stream to advance.
	 *
	 * @return FlowStreamHop|null The claimed hop, or null when there is nothing to fire.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function claimStreamHop(FlowStreamPass $pass, string $streamId): ?FlowStreamHop {
		// This stream's candidates, narrowed by edge conditions exactly as
		// the single-stream walk narrows them.
		$candidates = $pass->candidatesFor(streamId: $streamId);
		$transition = null;
		if ($candidates !== []) {
			$transition = $this->selectTransition(enabled: $candidates, flow: $pass->flow, placeItems: $pass->placeItems, context: $pass->context);
		}

		if ($transition === null) {
			$pass->streams->exhaust(id: $streamId);
			return null;
		}

		$name = $transition->getName();
		$froms = array_map('strval', $transition->getFroms());
		$tos = array_map('strval', $transition->getTos());

		// THE CLAIM, on every place this firing touches, before anything runs.
		$claimed = $pass->streams->claim(id: $streamId, transition: $name, places: array_merge($froms, $tos));
		if ($claimed === null) {
			return null;
		}

		return new FlowStreamHop(
			pass: $pass,
			transition: $transition,
			step: $this->router()->stepFor(flow: $pass->flow, transitionName: $name),
			itemsIn: $this->placement()->itemsForTransition(transition: $transition, placeItems: $pass->placeItems),
			streamId: $streamId,
			claimed: $claimed
		);
	}//end claimStreamHop()

	/**
	 * Run one claimed hop: a pinned step is committed as it is, any other step is dispatched.
	 *
	 * @param FlowStreamHop $hop The claimed hop.
	 *
	 * @return array|null The terminal run result, or null when the walk goes on.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function runStreamHop(FlowStreamHop $hop): ?array {
		$pass = $hop->pass;
		$pass->items = $hop->itemsIn;

		$pinned = $pass->pinnedFor(transitionName: $hop->name());
		if ($pinned === null) {
			return $this->dispatchOnStream(hop: $hop);
		}

		$pass->items = $pinned;
		$this->fireOnStream(hop: $hop, entry: $hop->pinnedEntry());

		return null;
	}//end runStreamHop()

	/**
	 * Dispatch a hop's step inside its attribution frame, and handle how the hop ends.
	 *
	 * The frame is popped in `finally`, so every exit from the hop (a stop, a
	 * suspension, a failed step) leaves no frame standing.
	 *
	 * @param FlowStreamHop $hop The claimed hop.
	 *
	 * @return array|null The terminal run result, or null when the walk goes on.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function dispatchOnStream(FlowStreamHop $hop): ?array {
		$pass = $hop->pass;
		$this->enterHop(context: $pass->context, name: $hop->name(), index: count($pass->log));

		try {
			// OVERSIGHT, per firing, inside the claim — never hoisted per
			// pass, never cached. A refusal ends the RUN, not the branch.
			$this->assertOversightAllows(context: $pass->context, name: $hop->name(), type: $hop->stepType());

			$produced = $pass->dispatcher->dispatch(step: $hop->step, items: $hop->itemsIn, context: $pass->context);
			$pass->items = FlowItems::normalise(value: $produced);
			$entry = $hop->completedEntry();
		} catch (FlowStop $stop) {
			return $this->stopStreams(hop: $hop, stop: $stop);
		} catch (FlowSuspension $suspension) {
			$this->parkStream(hop: $hop, suspension: $suspension);
			return null;
		} catch (Throwable $e) {
			return $this->failOnStream(hop: $hop, error: $e);
		} finally {
			$this->runContext?->pop();
		}//end try

		$this->fireOnStream(hop: $hop, entry: $entry);

		return null;
	}//end dispatchOnStream()

	/**
	 * End the run because a Stop step or an oversight check stopped the hop.
	 *
	 * A run-level end: the claim is released and no stream begins another firing.
	 *
	 * @param FlowStreamHop $hop  The stopped hop.
	 * @param FlowStop      $stop The stop.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	private function stopStreams(FlowStreamHop $hop, FlowStop $stop): array {
		$pass = $hop->pass;
		$pass->streams->release(places: $hop->claimed);
		$pass->log[] = $hop->stoppedEntry(stop: $stop) + $hop->streamTags();

		$result = $pass->stoppedResult(stop: $stop);
		$pass->streams->finalize(enabled: false, forcedTerminal: $result['status']);

		return $result;
	}//end stopStreams()

	/**
	 * Park the stream whose step asked to wait; its siblings keep going.
	 *
	 * The stream's marking is not advanced, so it resumes ON this transition.
	 *
	 * @param FlowStreamHop  $hop        The suspended hop.
	 * @param FlowSuspension $suspension The suspension.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function parkStream(FlowStreamHop $hop, FlowSuspension $suspension): void {
		$pass = $hop->pass;
		$pass->log[] = $hop->suspendedEntry(suspension: $suspension) + $hop->streamTags();
		$pass->streams->park(
			id: $hop->streamId,
			resumeAt: $suspension->getResumeAt(),
			reason: $suspension->getMessage(),
			claimed: $hop->claimed,
			// `settling`: this stream is the one parking, and the walk
			// does not know that yet — its token still enables the very
			// transition it is waiting ON. Counted, the park derives
			// `queued` with no wake time, which a parallel worker picks
			// up immediately.
			enabled: $pass->streams->workRemains(
				transitions: $pass->workflow->getEnabledTransitions(subject: $pass->subject),
				settling: $hop->streamId
			)
		);
	}//end parkStream()

	/**
	 * Apply a failed step's `onError` policy on its stream.
	 *
	 * Under `continue` the marking advances (leaving the token would spin this
	 * transition forever), recorded as a firing whose step failed. Any other
	 * policy ends the run.
	 *
	 * @param FlowStreamHop $hop   The failed hop.
	 * @param Throwable     $error The failure.
	 *
	 * @return array|null The terminal run result, or null when the walk goes on.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function failOnStream(FlowStreamHop $hop, Throwable $error): ?array {
		$entry = $hop->failedEntry(error: $error);
		if ($this->logStepFailure(hop: $hop, error: $error) === self::ON_ERROR_CONTINUE) {
			$this->fireOnStream(hop: $hop, entry: $entry);
			return null;
		}

		return $this->endFailedStream(hop: $hop, entry: $entry);
	}//end failOnStream()

	/**
	 * End the failed stream and the run, per the step's terminal policy.
	 *
	 * `failed`, not `stopped`: the same distinction
	 * {@see FlowWalkHop::terminalStatus()} explains for the single-stream walk,
	 * and it has to be made in BOTH places or which walk happened to run
	 * decides whether a wreck is queryable. The stream row already carries the
	 * message; the RUN row is what a query reads, so the envelope carries it
	 * too and `persistResult()` puts it on `error`.
	 *
	 * @param FlowStreamHop        $hop   The failed hop.
	 * @param array<string, mixed> $entry The failure's log entry, holding `error`.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function endFailedStream(FlowStreamHop $hop, array $entry): array {
		$pass = $hop->pass;
		$terminal = $hop->terminalStatus();
		$pass->log[] = $entry + $hop->streamTags();
		$pass->streams->endStream(id: $hop->streamId, status: $terminal, error: $entry['error'], claimed: $hop->claimed, enabled: false);
		$pass->streams->finalize(enabled: false, forcedTerminal: $terminal);

		return $pass->result(status: $terminal) + ['error' => $entry['error']];
	}//end endFailedStream()

	/**
	 * The pass's last word, under the lock, from the marking there.
	 *
	 * "Work remains" excludes the transitions parked streams keep enabled: a
	 * wait is not work, and counting it would re-queue every parked run.
	 *
	 * @param FlowStreamPass $pass The pass.
	 *
	 * @return array The run result.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function finishStreamPass(FlowStreamPass $pass): array {
		$enabled = $pass->streams->workRemains(transitions: $pass->workflow->getEnabledTransitions(subject: $pass->subject));
		$status = $pass->streams->finalize(enabled: $enabled);

		$result = $pass->result(status: $status);
		if ($status === self::STATUS_SUSPENDED) {
			$result['resumeAt'] = $pass->streams->run()->getResumeAt();
		}

		return $result;
	}//end finishStreamPass()

	/**
	 * Advance the marking for one firing and commit it on its stream.
	 *
	 * The in-memory apply is the same three calls the single-stream walk
	 * makes; the commit then writes the DELTA under the run-row lock and the
	 * store is synced from what was committed, so the next candidate is chosen
	 * against a marking that includes every sibling's progress. A step that
	 * failed under `continue` carries its message in the entry's `error`,
	 * which becomes the stream's error.
	 *
	 * @param FlowStreamHop        $hop   The hop being committed; its pass holds the items produced.
	 * @param array<string, mixed> $entry The log entry for this hop.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	private function fireOnStream(FlowStreamHop $hop, array $entry): void {
		$pass = $hop->pass;
		$transition = $hop->transition;
		$name = $hop->name();

		// Same tag resolution as the single-stream walk: an exit-id tag
		// becomes its place before the exit choice and the item delivery.
		$items = $this->router()->resolveOutputTags(flow: $pass->flow, transition: $transition, items: $pass->items);
		$taken = $this->router()->takenExits(flow: $pass->flow, transition: $transition, items: $items, context: $pass->context);
		$placeItems = $this->placement()->advanceItems(transition: $transition, placeItems: $pass->placeItems, items: $items, taken: $taken);
		$pass->workflow->apply(subject: $pass->subject, transitionName: $name);
		$this->pruneUntakenExits(hop: $hop, taken: $taken);

		$takenTos = $hop->takenInOrder(taken: $taken);
		$result = $pass->streams->commitFiring(
			id: $hop->streamId,
			transition: $name,
			froms: array_map('strval', $transition->getFroms()),
			taken: $takenTos,
			placeItems: $placeItems,
			claimed: $hop->claimed,
			logEntry: $entry,
			// The places this firing TAKES, handed over so the answer describes
			// the marking AFTER the commit rather than the one the walk still
			// holds: the stream picture is only re-read inside commitFiring().
			// Without them every ordinary mid-flow firing reported "no work
			// remains", the commit derived `completed` for a run that was still
			// walking, and every terminal listener fired on it.
			enabledAfter: $pass->streams->workRemains(
				transitions: $pass->workflow->getEnabledTransitions(subject: $pass->subject),
				produced: $takenTos
			),
			streamStatus: FlowRun::STATUS_RUNNING,
			streamError: ($entry['error'] ?? null)
		);

		// Sync from what was COMMITTED: the marking read under the lock holds
		// every sibling's committed effect, which the in-memory apply cannot.
		if ($pass->store instanceof FlowRunMarkingStore) {
			$pass->store->syncCommitted(marking: $result->marking);
		}

		$pass->placeItems = $result->placeItems;

		$entry['recorded'] = true;
		$pass->log[] = $entry + $hop->streamTags() + ['firings' => $result->firings];
	}//end fireOnStream()

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
	 * @param array<int, object> $enabled The enabled transitions.
	 * @param array<string, mixed> $flow The flow document.
	 * @param array<string, array> $placeItems Items per place.
	 * @param array<string, mixed> $context Run-level metadata.
	 *
	 * @return object|null The transition to fire, or null when none is eligible.
	 *
	 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
	 */
	private function selectTransition(array $enabled, array $flow, array $placeItems, array $context): ?object {
		$fallback = null;

		foreach ($enabled as $transition) {
			$condition = $this->router()->conditionReaching(flow: $flow, nodeId: $transition->getName());

			if ($condition === null || $condition === []) {
				// Remember the first default edge; keep looking for a match.
				$fallback = ($fallback ?? $transition);
				continue;
			}

			$items = $this->placement()->itemsForTransition(transition: $transition, placeItems: $placeItems);
			$data = FlowExpression::dataFor(
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
}//end class
