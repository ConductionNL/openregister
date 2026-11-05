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
	 * Decides which exit a token takes.
	 *
	 * @var FlowTokenRouter
	 */
	private readonly FlowTokenRouter $router;

	/**
	 * Decides which items sit on which place.
	 *
	 * @var FlowItemPlacement
	 */
	private readonly FlowItemPlacement $placement;

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
		?FlowTokenRouter $router = null,
		?FlowItemPlacement $placement = null,
		private readonly ?FlowRunContext $runContext = null,
		private readonly ?FlowTaskMootness $mootness = null,
	) {
		$this->router = ($router ?? new FlowTokenRouter());
		$this->placement = ($placement ?? new FlowItemPlacement());
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
	 * @param FlowWalkHop|FlowStreamHop $hop The hop about to run; its index is the length of the pass's log.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-object-attribution/specs/flow-engine/spec.md
	 */
	private function enterHop(FlowWalkHop|FlowStreamHop $hop): void {
		$context = $hop->pass->context;
		$this->runContext?->push(
			runUuid: ($context[FlowRunContext::CONTEXT_RUN] ?? null),
			nodeId: $hop->name(),
			sequence: ((int)($context[FlowRunContext::CONTEXT_BASE] ?? 0) + count($hop->pass->log))
		);
	}//end enterHop()

	/**
	 * Ask the oversight checks whether the next hop may run, and stop the run
	 * when one refuses.
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
	 * A refusal is raised as a FlowStop, so a veto ends the run the way an
	 * author's Stop step does, with the refusing check's id on it.
	 *
	 * @param FlowWalkHop|FlowStreamHop $hop The hop about to run.
	 *
	 * @return void
	 *
	 * @throws FlowStop When a check refuses the hop.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-oversight/spec.md
	 */
	private function assertOversightAllows(FlowWalkHop|FlowStreamHop $hop): void {
		$context = $hop->pass->context;
		if (($context['oversight'] ?? true) === false || $this->oversight === null) {
			return;
		}

		$refusal = $this->oversight->firstRefusal(
			context: array_merge(
				$context,
				['transition' => $hop->name(), 'nodeType' => $hop->stepType()]
			)
		);
		if ($refusal === null) {
			return;
		}

		// Both keys are guaranteed by firstRefusal()'s return type.
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
		// not to the run globally. With a stream walk the buffers persisted by
		// the last commit win, so each branch resumes with the items ITS branch
		// produced.
		$placeItems = $this->placement->seedPlaceItems(
			workflow: $workflow,
			subject: $subject,
			definition: $definition,
			items: $items,
			stored: $streams?->run()->getPlaceItems()
		);

		if ($streams !== null) {
			$pass = new FlowStreamPass(
				flow: $flow,
				workflow: $workflow,
				store: $store,
				subject: $subject,
				dispatcher: $dispatcher,
				context: $context,
				streams: $streams
			);
			$pass->items = $items;
			$pass->placeItems = $placeItems;
			return $this->walkStreams(pass: $pass);
		}

		$walk = new FlowWalkPass(flow: $flow, workflow: $workflow, subject: $subject, dispatcher: $dispatcher);
		$walk->context = $context;
		$walk->items = $items;
		$walk->placeItems = $placeItems;
		return $this->walkSingle(pass: $walk);
	}//end run()

	/**
	 * Walk a single in-memory stream: fire enabled transitions until none
	 * remains or a hop ends the run.
	 *
	 * @param FlowWalkPass $pass The pass.
	 *
	 * @return array The run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function walkSingle(FlowWalkPass $pass): array {
		while (true) {
			$hop = $this->nextSingleHop(pass: $pass);
			if (is_array($hop) === true) {
				return $hop;
			}

			$result = $this->runSingleHop(hop: $hop);
			if ($result !== null) {
				return $result;
			}
		}
	}//end walkSingle()

	/**
	 * Pick the next transition to fire, or end the run.
	 *
	 * With no enabled transition the run reached a final marking, or a join
	 * waits on a branch that never arrives: both complete the run. Where
	 * several are enabled the first whose edge condition holds wins, and an
	 * edge with no condition only wins when no conditioned sibling matches.
	 * When none can fire (a Switch with no matching case and no default) the
	 * run completes rather than spinning until the ceiling.
	 *
	 * @param FlowWalkPass $pass The pass.
	 *
	 * @return FlowWalkHop|array The hop to fire, or the run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function nextSingleHop(FlowWalkPass $pass): FlowWalkHop|array {
		$enabled = $pass->workflow->getEnabledTransitions(subject: $pass->subject);
		if (empty($enabled) === true) {
			return $pass->result(status: self::STATUS_COMPLETED);
		}

		$pass->fired++;
		if ($pass->fired > self::MAX_TRANSITIONS) {
			$this->logger->warning(
				message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($pass->flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
			);
			return $pass->result(status: self::STATUS_FAILED) + [
				'error' => sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS),
			];
		}

		$transition = $this->selectTransition(
			enabled: $enabled,
			flow: $pass->flow,
			placeItems: $pass->placeItems,
			context: $pass->context
		);
		if ($transition === null) {
			return $pass->result(status: self::STATUS_COMPLETED);
		}

		// A join reads every branch's items, in the froms' declared order.
		$pass->items = $this->placement->itemsForTransition(transition: $transition, placeItems: $pass->placeItems);

		return new FlowWalkHop(
			pass: $pass,
			transition: $transition,
			step: $this->router->stepFor(flow: $pass->flow, transitionName: $transition->getName()),
			itemsIn: $pass->items
		);
	}//end nextSingleHop()

	/**
	 * Fire one hop of the single-stream walk.
	 *
	 * A pinned step's stored output is used verbatim and the step is not
	 * dispatched, so it can neither stop, suspend nor fail. Otherwise the hop
	 * is attributed to this run and node, oversight may veto it as a stop, and
	 * the step is dispatched. The attribution frame is popped in `finally`
	 * because every other exit from the hop is a return: a frame left standing
	 * would file a later run's writes under this one.
	 *
	 * @param FlowWalkHop $hop The hop.
	 *
	 * @return array|null The run result when the hop ends the run, null to walk on.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function runSingleHop(FlowWalkHop $hop): ?array {
		$pass = $hop->pass;
		$pinned = $this->pinnedItems(flow: $pass->flow, context: $pass->context, transitionName: $hop->name());
		if ($pinned !== null) {
			$pass->items = $pinned;
			$pass->log[] = $hop->pinnedEntry();
			$this->advanceSingle(hop: $hop);
			return null;
		}

		$this->enterHop(hop: $hop);

		try {
			$this->assertOversightAllows(hop: $hop);

			$produced = $pass->dispatcher->dispatch(step: $hop->step, items: $hop->itemsIn, context: $pass->context);
			$pass->items = FlowItems::normalise(value: $produced);
			$pass->log[] = $hop->completedEntry();
		} catch (FlowStop $stop) {
			return $hop->stopped(stop: $stop);
		} catch (FlowSuspension $suspension) {
			return $hop->suspended(suspension: $suspension);
		} catch (Throwable $e) {
			$pass->log[] = $hop->failedEntry(error: $e);
			$outcome = $this->outcomeForFailedStep(hop: $hop, error: $e);
			if ($outcome !== null) {
				return $outcome;
			}
		} finally {
			$this->runContext?->pop();
		}//end try

		$this->advanceSingle(hop: $hop);
		return null;
	}//end runSingleHop()

	/**
	 * Move the token and the items past a fired hop.
	 *
	 * Exit-id tags resolve to their places first, so the exit choice and the
	 * per-place delivery read the same name. The marking advances even after a
	 * failed `continue` step, and the tokens Symfony deposited on exits the
	 * choice did not take are withdrawn.
	 *
	 * @param FlowWalkHop $hop The fired hop.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function advanceSingle(FlowWalkHop $hop): void {
		$pass = $hop->pass;
		$transition = $hop->transition;
		$pass->items = $this->router->resolveOutputTags(flow: $pass->flow, transition: $transition, items: $pass->items);
		$taken = $this->router->takenExits(flow: $pass->flow, transition: $transition, items: $pass->items, context: $pass->context);
		$pass->placeItems = $this->placement->advanceItems(
			transition: $transition,
			placeItems: $pass->placeItems,
			items: $pass->items,
			taken: $taken
		);
		$pass->workflow->apply(subject: $pass->subject, transitionName: $hop->name());
		$this->pruneUntakenExits(
			workflow: $pass->workflow,
			subject: $pass->subject,
			transition: $transition,
			taken: $taken,
			context: $pass->context
		);
	}//end advanceSingle()

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
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-task-whose-run-or-branch-has-died-is-terminated-not-orphaned
	 */
	private function pruneUntakenExits(Workflow $workflow, object $subject, object $transition, array $taken, array $context): void {
		$tos = array_map(static fn ($t): string => (string)$t, $transition->getTos());
		$pruned = array_values(array_diff($tos, $taken));

		$this->router->keepOnlyTakenExits(
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
				return $this->streamCeilingReached(pass: $pass);
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
	 * End the run as failed because it reached the transition ceiling.
	 *
	 * @param FlowStreamPass $pass The pass.
	 *
	 * @return array The terminal run result.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function streamCeilingReached(FlowStreamPass $pass): array {
		$this->logger->warning(
			message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
			context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($pass->flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
		);
		$error = sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS);
		$pass->streams->finalize(enabled: false, forcedTerminal: self::STATUS_FAILED);

		return $pass->result(status: self::STATUS_FAILED) + ['error' => $error];
	}//end streamCeilingReached()

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
			step: $this->router->stepFor(flow: $pass->flow, transitionName: $name),
			itemsIn: $this->placement->itemsForTransition(transition: $transition, placeItems: $pass->placeItems),
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

		$pinned = $this->pinnedItems(flow: $pass->flow, context: $pass->context, transitionName: $hop->name());
		if ($pinned === null) {
			return $this->dispatchOnStream(hop: $hop);
		}

		$pass->items = $pinned;
		$entry = $hop->pinnedEntry();
		$this->fireOnStream(hop: $hop, entry: $entry);

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
		$this->enterHop(hop: $hop);

		try {
			// OVERSIGHT, per firing, inside the claim — never hoisted per
			// pass, never cached. A refusal ends the RUN, not the branch.
			$this->assertOversightAllows(hop: $hop);

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
		$pass->log[] = $hop->stoppedEntry(stop: $stop) + [
			'streamId' => $hop->streamId,
			'ordinalPath' => $pass->streams->pathOf(id: $hop->streamId),
		];

		$stopStatus = self::STATUS_STOPPED;
		$stopError = null;
		if ($stop->isError() === true) {
			$stopStatus = self::STATUS_FAILED;
			$stopError = $stop->getMessage();
		}

		$pass->streams->finalize(enabled: false, forcedTerminal: $stopStatus);

		return $pass->result(status: $stopStatus) + ['error' => $stopError];
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
		$pass->log[] = $hop->suspendedEntry(suspension: $suspension) + [
			'streamId' => $hop->streamId,
			'ordinalPath' => $pass->streams->pathOf(id: $hop->streamId),
		];
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

		$policy = $this->logStepFailure(hop: $hop, error: $error);

		if ($policy === self::ON_ERROR_CONTINUE) {
			$this->fireOnStream(hop: $hop, entry: $entry);
			return null;
		}

		return $this->endFailedStream(hop: $hop, entry: $entry);
	}//end failOnStream()

	/**
	 * End the failed stream and the run, per the step's terminal policy.
	 *
	 * `failed`, not `stopped`: the same distinction
	 * {@see self::outcomeForFailedStep()} explains for the single-stream walk,
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
		$terminal = self::STATUS_FAILED;
		if ((string)($hop->step['onError'] ?? self::ON_ERROR_STOP) === self::ON_ERROR_DEAD_LETTER) {
			$terminal = self::STATUS_DEAD_LETTER;
		}

		$entry['streamId'] = $hop->streamId;
		$entry['ordinalPath'] = $pass->streams->pathOf(id: $hop->streamId);
		$pass->log[] = $entry;
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
		$items = $this->router->resolveOutputTags(flow: $pass->flow, transition: $transition, items: $pass->items);
		$taken = $this->router->takenExits(flow: $pass->flow, transition: $transition, items: $items, context: $pass->context);
		$placeItems = $this->placement->advanceItems(transition: $transition, placeItems: $pass->placeItems, items: $items, taken: $taken);
		$pass->workflow->apply(subject: $pass->subject, transitionName: $name);
		$this->pruneUntakenExits(workflow: $pass->workflow, subject: $pass->subject, transition: $transition, taken: $taken, context: $pass->context);

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
		$entry['streamId'] = $hop->streamId;
		$entry['ordinalPath'] = $pass->streams->pathOf(id: $hop->streamId);
		$entry['firings'] = $result->firings;
		$pass->log[] = $entry;
	}//end fireOnStream()

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
	 * @param FlowWalkHop $hop   The failed hop; its pass already logs the failure.
	 * @param Throwable   $error The failure.
	 *
	 * @return array|null The terminal result, or null to continue the walk.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function outcomeForFailedStep(FlowWalkHop $hop, Throwable $error): ?array {
		$policy = $this->logStepFailure(hop: $hop, error: $error);

		if ($policy === self::ON_ERROR_CONTINUE) {
			return null;
		}

		$status = self::STATUS_FAILED;
		if ($policy === self::ON_ERROR_DEAD_LETTER) {
			$status = self::STATUS_DEAD_LETTER;
		}

		return $hop->pass->result(status: $status) + ['error' => $error->getMessage()];
	}//end outcomeForFailedStep()

	/**
	 * Log a failed step with its onError policy, and return that policy.
	 *
	 * @param FlowWalkHop|FlowStreamHop $hop   The failed hop.
	 * @param Throwable                 $error The failure.
	 *
	 * @return string The step's onError policy; `stop` when it declares none.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function logStepFailure(FlowWalkHop|FlowStreamHop $hop, Throwable $error): string {
		$policy = (string)($hop->step['onError'] ?? self::ON_ERROR_STOP);
		$this->logger->warning(
			message: '[FlowEngine] Flow step failed',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => ($hop->pass->flow['id'] ?? null),
				'transition' => $hop->name(),
				'policy' => $policy,
				'error' => $error->getMessage(),
			]
		);

		return $policy;
	}//end logStepFailure()

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
			$condition = $this->router->conditionReaching(flow: $flow, nodeId: $transition->getName());

			if ($condition === null || $condition === []) {
				// Remember the first default edge; keep looking for a match.
				$fallback = ($fallback ?? $transition);
				continue;
			}

			$items = $this->placement->itemsForTransition(transition: $transition, placeItems: $placeItems);
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
