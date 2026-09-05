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
	 * do not run; an author pins their output ({@see self::pinnedItems()}) and
	 * re-runs only the part being worked on. It is the initial place overridden,
	 * so a resume — whose marking is already mid-graph and comes from the store —
	 * is unaffected.
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
	 * @SuppressWarnings(PHPMD.NPathComplexity) 226 against a threshold of 200, and the
	 * 26 came from adding a `finally` to the hop. That `finally` is the attribution
	 * safety property, not a convenience: every other exit from a hop is a `return`
	 * inside a catch — a stop, a suspension, a terminally-failed step — so a pop on the
	 * success path would leave the frame standing and attribute LATER writes, in a LATER
	 * run advanced by the same worker, to a run that had already finished. That failure
	 * is silent and produces well-formed rows. Restructuring the walk to win the metric
	 * would trade a real correctness guarantee for a number; the branches themselves are
	 * each one clearly-labelled outcome of a hop.
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
		$flow = $this->withStartNode(flow: $flow, startAt: $startAt);

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
		$log = [];
		$fired = 0;

		// Per-place item buffers. Items belong to the PLACES a token sits on,
		// not to the run globally ({@see self::seedPlaceItems()}). With a stream
		// walk the buffers persisted by the last commit win, so each branch
		// resumes with the items ITS branch produced.
		$stored = null;
		if ($streams !== null) {
			$stored = $streams->run()->getPlaceItems();
		}

		$placeItems = $this->placement()->seedPlaceItems(
			workflow: $workflow,
			subject: $subject,
			definition: $definition,
			items: $items,
			stored: $stored
		);

		if ($streams !== null) {
			return $this->walkStreams(
				flow: $flow,
				workflow: $workflow,
				store: $store,
				subject: $subject,
				dispatcher: $dispatcher,
				context: $context,
				items: $items,
				placeItems: $placeItems,
				streams: $streams
			);
		}

		while (true) {
			$enabled = $workflow->getEnabledTransitions(subject: $subject);
			if (empty($enabled) === true) {
				// No transition is enabled: either the run reached a final marking,
				// or a join is still waiting on a branch that never arrives. Both
				// are "as far as this graph goes".
				return [
					'status' => self::STATUS_COMPLETED,
					'log' => $log,
					'context' => $context,
					'items' => $items,
				];
			}

			$fired++;
			if ($fired > self::MAX_TRANSITIONS) {
				$this->logger->warning(
					message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
					context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
				);
				return [
					'status' => self::STATUS_FAILED,
					'log' => $log,
					'context' => $context,
					'items' => $items,
					'error' => sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS),
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
					'status' => self::STATUS_COMPLETED,
					'log' => $log,
					'context' => $context,
					'items' => $items,
				];
			}

			$name = $transition->getName();
			$step = $this->router()->stepFor(flow: $flow, transitionName: $name);

			// A step reads the items on its input place(s). For a join — several
			// incoming edges converging on one node — that is the concatenation
			// of every branch's items, in the froms' declared order, which is
			// exactly what a Merge node then refines. The Petri net already
			// holds the join until every input place is marked, so wait-for-both
			// is the default and needs no code here.
			$itemsIn = $this->placement()->itemsForTransition(transition: $transition, placeItems: $placeItems);
			$items = $itemsIn;

			// The step's catalogue id, carried onto every log entry so the run
			// history can be queried BY NODE TYPE ("which node type fails")
			// rather than only by run.
			$stepType = (string)($step['type'] ?? '');

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
					'type' => $stepType,
					'status' => 'pinned',
					'itemsIn' => count($itemsIn),
					'itemsOut' => count($items),
					'durationMs' => 0,
				];

				// An exit-id tag becomes its place BEFORE routing, so the
				// exit choice and the per-place delivery read the same name.
				$items = $this->router()->resolveOutputTags(flow: $flow, transition: $transition, items: $items);
				$taken = $this->router()->takenExits(flow: $flow, transition: $transition, items: $items, context: $context);
				$placeItems = $this->placement()->advanceItems(
					transition: $transition,
					placeItems: $placeItems,
					items: $items,
					taken: $taken
				);
				$workflow->apply(subject: $subject, transitionName: $name);
				$this->pruneUntakenExits(
					workflow: $workflow,
					subject: $subject,
					transition: $transition,
					taken: $taken,
					context: $context
				);
				continue;
			}//end if

			// ATTRIBUTION, around the hop. Everything written from here until the
			// matching pop is filed under this run and node — including writes
			// made by code that has no idea a flow is running, which is the
			// whole point (a node cannot report what it did not know it did).
			//
			// The step number is `base + count($log)`, and that is not an
			// approximation: `recordSteps()` numbers this segment's entries from
			// the same base in the same order, so an attributed audit row and
			// its `FlowRunStep` row carry the SAME sequence. Deriving it from a
			// dispatch counter instead would desynchronise the moment a step is
			// PINNED — a pin logs an entry without ever reaching the dispatcher.
			//
			// Pushed here rather than around the pinned branch above because a
			// pinned step is not executed at all: it produces no writes, so it
			// needs no frame, and skipping it costs nothing since the index is
			// read from the log rather than counted per push.
			$this->enterHop(context: $context, name: $name, index: count($log));

			try {
				// OVERSIGHT, before the hop. A veto is raised as a FlowStop so it
				// travels the same path as an author's Stop step: the run ENDS.
				// It never skips the hop and carries on, because a skipped step
				// inside a completed run is indistinguishable from one that ran
				// and did nothing — the exact failure this change removes.
				$this->assertOversightAllows(context: $context, name: $name, type: $stepType);

				$produced = $dispatcher->dispatch(step: $step, items: $itemsIn, context: $context);
				$items = FlowItems::normalise(value: $produced);
				$entry = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'completed',
					'itemsIn' => count($itemsIn),
					'itemsOut' => count($items),
					// What the step RECEIVED and RETURNED, not just how many.
					// A count answers "did it work"; the question actually
					// asked of a run that completed and produced the wrong
					// answer is "what did it get, and what did it do with it",
					// and no count can answer that.
					'input' => $this->sampleItems(items: $itemsIn),
					'output' => $this->sampleItems(items: $items),
					'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				];

				// What the step LOGGED, beyond its items: a side-effect node's
				// own report (a send node's per-recipient outcomes, say). The
				// report handle travels in the context and is drained per hop,
				// so a node's detail lands on ITS entry and no other.
				$report = $this->stepReport(context: $context);
				if ($report !== []) {
					$entry['report'] = $report;
				}

				$log[] = $entry;
			} catch (FlowStop $stop) {
				// A deliberate end, requested by a Stop step. Caught before the
				// generic Throwable so it is never treated as a step failure and
				// never subject to an onError policy — the author asked the run
				// to end, and it ends with their message and their outcome.
				$log[] = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'stopped',
					'reason' => $stop->getMessage(),
					// Null for an author's Stop step; set when an oversight
					// gate raised the stop, so the history records WHICH gate.
					'checkId' => $stop->checkId(),
					'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				];

				$stopStatus = self::STATUS_STOPPED;
				$stopError = null;
				if ($stop->isError() === true) {
					$stopStatus = self::STATUS_FAILED;
					$stopError = $stop->getMessage();
				}

				return [
					'status' => $stopStatus,
					'log' => $log,
					'context' => $context,
					'items' => $items,
					'error' => $stopError,
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
					'status' => 'suspended',
					'reason' => $suspension->getMessage(),
				];

				return [
					'status' => self::STATUS_SUSPENDED,
					'log' => $log,
					'context' => $context,
					'items' => $itemsIn,
					'resumeAt' => $suspension->getResumeAt(),
				];
			} catch (Throwable $e) {
				$entry = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'failed',
					'error' => $e->getMessage(),
					'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				];

				// A failing step's report is worth MORE than a completing one's:
				// a send node that delivered to two recipients and failed on the
				// third records exactly which sends went out before the throw.
				$report = $this->stepReport(context: $context);
				if ($report !== []) {
					$entry['report'] = $report;
				}

				$log[] = $entry;
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
			} finally {
				// 🔴 UNCONDITIONAL, and structurally so. Every other exit from
				// this hop is a `return` inside a catch — a stop, a suspension,
				// a failed step whose policy is terminal. A pop placed on the
				// success path would leave the frame standing on all three, and
				// the next write in the process — a LATER run advanced by the
				// same worker — would be filed under a run that had already
				// finished. Nothing about that row looks wrong, which is why it
				// has to be impossible rather than merely remembered.
				$this->runContext?->pop();
			}//end try

			// A routing step tags items with the exit id its rule named;
			// resolve those to the exit's PLACE first, so the choice below and
			// the per-place delivery in advanceItems() read the same name —
			// unresolved, a routed branch fired with zero items.
			$items = $this->router()->resolveOutputTags(flow: $flow, transition: $transition, items: $items);

			// Which single exit this firing takes. A token is unique and
			// exclusive, so a branching node hands it to exactly one successor.
			$taken = $this->router()->takenExits(flow: $flow, transition: $transition, items: $items, context: $context);

			// Move the items in lock-step with the token: onto the output
			// places, off the consumed inputs ({@see self::advanceItems()}).
			$placeItems = $this->placement()->advanceItems(
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
			$this->pruneUntakenExits(
				workflow: $workflow,
				subject: $subject,
				transition: $transition,
				taken: $taken,
				context: $context
			);
		}//end while

	}//end run()

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
	 * @param Workflow $workflow The running workflow.
	 * @param object $subject The marking holder.
	 * @param object $transition The transition that just fired.
	 * @param array<string> $taken The output places the exit claimed.
	 * @param array<string, mixed> $context The run context, carrying the resume state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-user-task-node/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	private function pruneUntakenExits(Workflow $workflow, object $subject, object $transition, array $taken, array $context): void {
		$tos = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		$pruned = array_values(array_diff($tos, $taken));

		$this->router()->keepOnlyTakenExits(
			workflow: $workflow,
			subject: $subject,
			transition: $transition,
			taken: $taken
		);

		if ($pruned === []) {
			return;
		}

		$this->mootness?->placesPruned(
			context: $context,
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
	 * @param array $flow The flow document.
	 * @param Workflow $workflow The Petri net over the run's marking store.
	 * @param MarkingStoreInterface $store The marking store (synced from each commit).
	 * @param object $subject The subject holding the marking.
	 * @param FlowStepDispatcher $dispatcher Performs each step's side effect.
	 * @param array $context Run-level metadata handed to every step.
	 * @param array $items The items in hand (returned with the result).
	 * @param array<string, array> $placeItems Items per place.
	 * @param FlowStreamWalk $streams The stream collaborator.
	 *
	 * @return array The run result.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) One labelled branch per way a hop can
	 * end — pinned, completed, stopped, suspended, failed-and-continuing, failed-and-terminal —
	 * each with its stream bookkeeping; splitting them would scatter the protocol.
	 * @SuppressWarnings(PHPMD.NPathComplexity) The same branches, multiplied.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The walk reads top to bottom as the walk.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function walkStreams(
		array $flow,
		Workflow $workflow,
		MarkingStoreInterface $store,
		object $subject,
		FlowStepDispatcher $dispatcher,
		array $context,
		array $items,
		array $placeItems,
		FlowStreamWalk $streams,
	): array {
		$log = [];
		$streams->begin(marking: $workflow->getMarking(subject: $subject)->getPlaces());

		while (true) {
			if ($streams->budgetSpent() === true) {
				break;
			}

			$streamId = $streams->nextStream();
			if ($streamId === null) {
				break;
			}

			// THE CEILING counts the RUN, not the pass: the durable firing
			// count survives suspension and covers every stream together.
			if ($streams->firings() >= self::MAX_TRANSITIONS) {
				$this->logger->warning(
					message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
					context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
				);
				$error = sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS);
				$streams->finalize(enabled: false, forcedTerminal: self::STATUS_FAILED);

				return ['status' => self::STATUS_FAILED, 'log' => $log, 'context' => $context, 'items' => $items, 'error' => $error];
			}

			// This stream's candidates: the enabled transitions consuming from
			// the place its token sits on, narrowed by edge conditions exactly
			// as the single-stream walk narrows them.
			$place = $streams->placeOf(id: $streamId);
			$candidates = [];
			foreach ($workflow->getEnabledTransitions(subject: $subject) as $transition) {
				if ($place !== null && in_array($place, array_map('strval', $transition->getFroms()), true) === true) {
					$candidates[] = $transition;
				}
			}

			$transition = null;
			if ($candidates !== []) {
				$transition = $this->selectTransition(enabled: $candidates, flow: $flow, placeItems: $placeItems, context: $context);
			}

			if ($transition === null) {
				$streams->exhaust(id: $streamId);
				continue;
			}

			$name = $transition->getName();
			$froms = array_map('strval', $transition->getFroms());
			$tos = array_map('strval', $transition->getTos());

			// THE CLAIM, on every place this firing touches, before anything
			// runs. A refusal skips the candidate without waiting; the firing
			// stays enabled and the run ends the pass `queued`.
			$claimed = $streams->claim(id: $streamId, transition: $name, places: array_merge($froms, $tos));
			if ($claimed === null) {
				continue;
			}

			$step = $this->router()->stepFor(flow: $flow, transitionName: $name);
			$itemsIn = $this->placement()->itemsForTransition(transition: $transition, placeItems: $placeItems);
			$items = $itemsIn;
			$stepType = (string)($step['type'] ?? '');
			$startedAt = microtime(true);

			$pinned = $this->pinnedItems(flow: $flow, context: $context, transitionName: $name);
			if ($pinned !== null) {
				$items = $pinned;
				$entry = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'pinned',
					'itemsIn' => count($itemsIn),
					'itemsOut' => count($items),
					'durationMs' => 0,
				];
				[$placeItems, $log] = $this->fireOnStream(
					flow: $flow,
					workflow: $workflow,
					store: $store,
					subject: $subject,
					transition: $transition,
					items: $items,
					placeItems: $placeItems,
					context: $context,
					streams: $streams,
					streamId: $streamId,
					claimed: $claimed,
					entry: $entry,
					log: $log
				);
				continue;
			}//end if

			$this->enterHop(context: $context, name: $name, index: count($log));

			try {
				// OVERSIGHT, per firing, inside the claim — never hoisted per
				// pass, never cached. A refusal ends the RUN, not the branch.
				$this->assertOversightAllows(context: $context, name: $name, type: $stepType);

				$produced = $dispatcher->dispatch(step: $step, items: $itemsIn, context: $context);
				$items = FlowItems::normalise(value: $produced);
				$entry = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'completed',
					'itemsIn' => count($itemsIn),
					'itemsOut' => count($items),
					'input' => $this->sampleItems(items: $itemsIn),
					'output' => $this->sampleItems(items: $items),
					'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				];
				$report = $this->stepReport(context: $context);
				if ($report !== []) {
					$entry['report'] = $report;
				}
			} catch (FlowStop $stop) {
				$streams->release(places: $claimed);
				$log[] = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'stopped',
					'reason' => $stop->getMessage(),
					'checkId' => $stop->checkId(),
					'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
					'streamId' => $streamId,
					'ordinalPath' => $streams->pathOf(id: $streamId),
				];

				$stopStatus = self::STATUS_STOPPED;
				$stopError = null;
				if ($stop->isError() === true) {
					$stopStatus = self::STATUS_FAILED;
					$stopError = $stop->getMessage();
				}

				// A run-level end: no stream begins another firing.
				$streams->finalize(enabled: false, forcedTerminal: $stopStatus);

				return ['status' => $stopStatus, 'log' => $log, 'context' => $context, 'items' => $items, 'error' => $stopError];
			} catch (FlowSuspension $suspension) {
				// A pause of THIS stream. Its marking is not advanced — it
				// resumes ON this transition — and its siblings keep going.
				$log[] = [
					'transition' => $name,
					'status' => 'suspended',
					'reason' => $suspension->getMessage(),
					'streamId' => $streamId,
					'ordinalPath' => $streams->pathOf(id: $streamId),
				];
				$streams->park(
					id: $streamId,
					resumeAt: $suspension->getResumeAt(),
					reason: $suspension->getMessage(),
					claimed: $claimed,
					// `settling`: this stream is the one parking, and the walk
					// does not know that yet — its token still enables the very
					// transition it is waiting ON. Counted, the park derives
					// `queued` with no wake time, which a parallel worker picks
					// up immediately.
					enabled: $streams->workRemains(
						transitions: $workflow->getEnabledTransitions(subject: $subject),
						settling: $streamId
					)
				);
				continue;
			} catch (Throwable $e) {
				$entry = [
					'transition' => $name,
					'type' => $stepType,
					'status' => 'failed',
					'error' => $e->getMessage(),
					'durationMs' => (int)round((microtime(true) - $startedAt) * 1000),
				];
				$report = $this->stepReport(context: $context);
				if ($report !== []) {
					$entry['report'] = $report;
				}

				$policy = (string)($step['onError'] ?? self::ON_ERROR_STOP);
				$this->logger->warning(
					message: '[FlowEngine] Flow step failed',
					context: [
						'file' => __FILE__,
						'line' => __LINE__,
						'flow' => ($flow['id'] ?? null),
						'transition' => $name,
						'policy' => $policy,
						'error' => $e->getMessage(),
					]
				);

				if ($policy !== self::ON_ERROR_CONTINUE) {
					// Terminal for the run: the failed stream ends with the
					// reason, every other stream ends with the run.
					//
					// `failed`, not `stopped` — the same distinction
					// {@see self::outcomeForFailedStep()} explains for the
					// single-stream walk, and it has to be made in BOTH places
					// or which walk happened to run decides whether a wreck is
					// queryable. The stream row already carried the message;
					// the RUN row is what a query reads, so the envelope
					// carries it too and `persistResult()` puts it on `error`.
					$terminal = self::STATUS_FAILED;
					if ($policy === self::ON_ERROR_DEAD_LETTER) {
						$terminal = self::STATUS_DEAD_LETTER;
					}

					$entry['streamId'] = $streamId;
					$entry['ordinalPath'] = $streams->pathOf(id: $streamId);
					$log[] = $entry;
					$streams->endStream(id: $streamId, status: $terminal, error: $e->getMessage(), claimed: $claimed, enabled: false);
					$streams->finalize(enabled: false, forcedTerminal: $terminal);

					return [
						'status' => $terminal,
						'log' => $log,
						'context' => $context,
						'items' => $items,
						'error' => $e->getMessage(),
					];
				}

				// `continue`: the marking advances (leaving the token would spin
				// this transition forever), recorded as a firing whose step failed.
				[$placeItems, $log] = $this->fireOnStream(
					flow: $flow,
					workflow: $workflow,
					store: $store,
					subject: $subject,
					transition: $transition,
					items: $items,
					placeItems: $placeItems,
					context: $context,
					streams: $streams,
					streamId: $streamId,
					claimed: $claimed,
					entry: $entry,
					log: $log,
					streamError: $e->getMessage()
				);
				continue;
			} finally {
				$this->runContext?->pop();
			}//end try

			[$placeItems, $log] = $this->fireOnStream(
				flow: $flow,
				workflow: $workflow,
				store: $store,
				subject: $subject,
				transition: $transition,
				items: $items,
				placeItems: $placeItems,
				context: $context,
				streams: $streams,
				streamId: $streamId,
				claimed: $claimed,
				entry: $entry,
				log: $log
			);
		}//end while

		// THE PASS'S LAST WORD, under the lock, from the marking there. "Work
		// remains" excludes the transitions parked streams keep enabled: a wait
		// is not work, and counting it would re-queue every parked run.
		$enabled = $streams->workRemains(transitions: $workflow->getEnabledTransitions(subject: $subject));
		$status = $streams->finalize(enabled: $enabled);

		$result = ['status' => $status, 'log' => $log, 'context' => $context, 'items' => $items];
		if ($status === self::STATUS_SUSPENDED) {
			$result['resumeAt'] = $streams->run()->getResumeAt();
		}

		return $result;
	}//end walkStreams()

	/**
	 * Advance the marking for one firing and commit it on its stream.
	 *
	 * The in-memory apply is the same three calls the single-stream walk
	 * makes; the commit then writes the DELTA under the run-row lock and the
	 * store is synced from what was committed, so the next candidate is chosen
	 * against a marking that includes every sibling's progress.
	 *
	 * @param array $flow The flow document.
	 * @param Workflow $workflow The Petri net.
	 * @param MarkingStoreInterface $store The marking store.
	 * @param object $subject The subject.
	 * @param object $transition The fired transition.
	 * @param array $items The items produced.
	 * @param array<string, array> $placeItems Items per place, before the firing.
	 * @param array $context The run context.
	 * @param FlowStreamWalk $streams The stream collaborator.
	 * @param string $streamId The firing stream.
	 * @param array<int, string> $claimed The claimed places.
	 * @param array $entry The log entry for this hop.
	 * @param array $log The run log so far.
	 * @param string|null $streamError The step's error when it failed under `continue`.
	 *
	 * @return array{0: array<string, array>, 1: array} The place items after, and the log.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) The hop's whole state, handed once.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-marking-must-be-written-as-a-delta-never-as-a-whole-overwrite
	 */
	private function fireOnStream(
		array $flow,
		Workflow $workflow,
		MarkingStoreInterface $store,
		object $subject,
		object $transition,
		array $items,
		array $placeItems,
		array $context,
		FlowStreamWalk $streams,
		string $streamId,
		array $claimed,
		array $entry,
		array $log,
		?string $streamError = null,
	): array {
		$name = $transition->getName();

		// Same tag resolution as the single-stream walk: an exit-id tag
		// becomes its place before the exit choice and the item delivery.
		$items = $this->router()->resolveOutputTags(flow: $flow, transition: $transition, items: $items);
		$taken = $this->router()->takenExits(flow: $flow, transition: $transition, items: $items, context: $context);
		$placeItems = $this->placement()->advanceItems(transition: $transition, placeItems: $placeItems, items: $items, taken: $taken);
		$workflow->apply(subject: $subject, transitionName: $name);
		$this->pruneUntakenExits(workflow: $workflow, subject: $subject, transition: $transition, taken: $taken, context: $context);

		// The places actually taken, in the transition's declaration order.
		$takenTos = [];
		foreach ($transition->getTos() as $to) {
			if (in_array((string)$to, array_map('strval', $taken), true) === true) {
				$takenTos[] = (string)$to;
			}
		}

		$result = $streams->commitFiring(
			id: $streamId,
			transition: $name,
			froms: array_map('strval', $transition->getFroms()),
			taken: $takenTos,
			placeItems: $placeItems,
			claimed: $claimed,
			logEntry: $entry,
			// The places this firing TAKES, handed over so the answer describes
			// the marking AFTER the commit rather than the one the walk still
			// holds: the stream picture is only re-read inside commitFiring().
			// Without them every ordinary mid-flow firing reported "no work
			// remains", the commit derived `completed` for a run that was still
			// walking, and every terminal listener fired on it.
			enabledAfter: $streams->workRemains(
				transitions: $workflow->getEnabledTransitions(subject: $subject),
				produced: $takenTos
			),
			streamStatus: FlowRun::STATUS_RUNNING,
			streamError: $streamError
		);

		// Sync from what was COMMITTED: the marking read under the lock holds
		// every sibling's committed effect, which the in-memory apply cannot.
		if ($store instanceof FlowRunMarkingStore) {
			$store->syncCommitted(marking: $result->marking);
		}

		$placeItems = $result->placeItems;

		$entry['recorded'] = true;
		$entry['streamId'] = $streamId;
		$entry['ordinalPath'] = $streams->pathOf(id: $streamId);
		$entry['firings'] = $result->firings;
		$log[] = $entry;

		return [$placeItems, $log];
	}//end fireOnStream()

	/**
	 * Override where a flow starts, for "run from here".
	 *
	 * A non-empty start node replaces the flow's `initial`; the builder then
	 * validates it exists, so an unknown node fails the run exactly as a bad
	 * document does. An empty or absent start leaves the flow untouched, so the
	 * ordinary path is unaffected.
	 *
	 * @param array $flow The flow document.
	 * @param string|null $startAt The node to start from, or null/empty for none.
	 *
	 * @return array The flow document, with `initial` overridden when asked.
	 *
	 * @spec openspec/changes/or-flow-partial-run/specs/flow-partial-run/spec.md
	 */
	private function withStartNode(array $flow, ?string $startAt): array {
		if (($startAt ?? '') !== '') {
			$flow['initial'] = $startAt;
		}

		return $flow;
	}//end withStartNode()

	/**
	 * Decide what a failed step does, per its `onError` policy.
	 *
	 * Returns the terminal run result when the policy ends the run
	 * (`dead_letter`, or `stop` — the default, which also catches an unknown
	 * policy so a typo fails safe), or null when the policy is `continue` and
	 * the walk should go on. The failure is logged either way.
	 *
	 * 🔴 THE POLICY SAYS WHETHER TO END THE RUN; IT DOES NOT SAY THE RUN ENDED
	 * WELL. `onError: stop` used to return `stopped` with no `error`, which is
	 * byte-for-byte what a deliberate Stop node returns — so a run killed by a
	 * broken step and a run an author ended on purpose were the same row. The
	 * only trace of the difference was the last entry of the JSON `log`, which
	 * no query reaches: `SELECT ... WHERE status = 'stopped'` cannot tell a
	 * healthy guard branch from a wreck. Measured on dossiq's shipped `Case
	 * behandeling` flow, where nine runs died on `status_not_found_on_case_type`
	 * and every one of them read as a clean end.
	 *
	 * So a step failure ends the run as `failed`, with the step's message on the
	 * `error` column. Both were already in the vocabulary and used elsewhere
	 * ({@see FlowRun::STATUS_FAILED}, ranked most-severe in
	 * {@see FlowRunCommit::SEVERITY}); this path simply was not reaching for
	 * them. `stopped` goes back to meaning what {@see SubFlowNode::itemsFrom()}
	 * already documents it to mean — a deliberate, successful end — which also
	 * stops a sub-flow whose step failed from handing its stale items to its
	 * parent as though it had finished.
	 *
	 * @param array $step The step configuration.
	 * @param Throwable $error The failure.
	 * @param string $name The transition name.
	 * @param array $flow The flow document (for the log context).
	 * @param array $log The run log so far (already holding the failure).
	 * @param array $context The run context.
	 * @param array $items The items in hand at the failure.
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
		array $items,
	): ?array {
		$policy = (string)($step['onError'] ?? self::ON_ERROR_STOP);

		$this->logger->warning(
			message: '[FlowEngine] Flow step failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => ($flow['id'] ?? null),
				'transition' => $name,
				'policy' => $policy,
				'error' => $error->getMessage(),
			]
		);

		if ($policy === self::ON_ERROR_DEAD_LETTER) {
			return [
				'status' => self::STATUS_DEAD_LETTER,
				'log' => $log,
				'context' => $context,
				'items' => $items,
				'error' => $error->getMessage(),
			];
		}

		if ($policy !== self::ON_ERROR_CONTINUE) {
			return [
				'status' => self::STATUS_FAILED,
				'log' => $log,
				'context' => $context,
				'items' => $items,
				'error' => $error->getMessage(),
			];
		}

		return null;
	}//end outcomeForFailedStep()

	/**
	 * Drain the hop's step report from the context, when one travels there.
	 *
	 * Taking (rather than reading) is what scopes a report to one hop: the
	 * handle is cleared by the read, so a node's detail can never bleed onto a
	 * later step's log entry.
	 *
	 * @param array $context The run context.
	 *
	 * @return array<string, mixed> The report detail, or an empty array.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	private function stepReport(array $context): array {
		$report = ($context[FlowStepReport::CONTEXT_KEY] ?? null);
		if (($report instanceof FlowStepReport) === false) {
			return [];
		}

		return $report->take();
	}//end stepReport()

	/**
	 * A bounded, honest sample of an item list, for the run log.
	 *
	 * BOUNDED, because a node returning ten thousand items would otherwise put
	 * ten thousand items in a log — and an unbounded log is one that fills a
	 * disk and is then deleted wholesale, taking the runs that mattered with
	 * it. Retention is per flow (`retentionDays`), so these live for months.
	 *
	 * HONEST, because a truncated list that does not say it is truncated is
	 * worse than a count: a reader comparing "10 items" against a node that
	 * processed 10,000 concludes the flow dropped data. The envelope always
	 * carries the true `count`, and `truncated` says whether `items` is all of
	 * them.
	 *
	 * @param array $items The item list.
	 *
	 * @return array{count: int, truncated: bool, items: array<int, mixed>} The sample.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-records-what-each-node-received-returned-and-logged
	 */
	private function sampleItems(array $items): array {
		$total = count($items);

		return [
			'count' => $total,
			'truncated' => ($total > self::LOG_ITEM_SAMPLE),
			'items' => array_slice($items, 0, self::LOG_ITEM_SAMPLE),
		];

	}//end sampleItems()

	/**
	 * The pinned output for a step, or null when it is not pinned.
	 *
	 * Pins are a map of step name to an item list. A run carries them in its
	 * `context` under `pins` (a test/authoring run supplies them without
	 * touching the stored flow); a flow may also carry a `pins` map of its own,
	 * used only as the fallback so a run's pins always win. A step whose name is
	 * absent from both is not pinned and runs normally.
	 *
	 * @param array $flow The flow document.
	 * @param array $context The run context.
	 * @param string $transitionName The step's transition name.
	 *
	 * @return array<int, mixed>|null The pinned items, or null when not pinned.
	 *
	 * @spec openspec/changes/or-flow-pins/specs/flow-pins/spec.md
	 */
	private function pinnedItems(array $flow, array $context, string $transitionName): ?array {
		$pins = (array)($context['pins'] ?? ($flow['pins'] ?? []));
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
