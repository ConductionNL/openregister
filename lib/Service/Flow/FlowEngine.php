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
	 * Plans each hop and moves its token and items.
	 *
	 * @var FlowHopRouting
	 */
	private readonly FlowHopRouting $routing;




	/**
	 * Constructor.
	 *
	 * $services comes after $oversight, and is defaulted: unit tests construct
	 * the engine positionally and one passes the oversight registry third.
	 *
	 * @param FlowDefinitionBuilder $builder The document -> Petri-net translator.
	 * @param LoggerInterface $logger The logger.
	 * @param FlowOversightRegistry|null $oversight The pre-hop gate. Nullable so the
	 *                                              engine stays unit-testable without a
	 *                                              container; absent, nothing objects,
	 *                                              exactly as an empty registry does.
	 * @param FlowEngineServices $services The router, the item placement, the
	 *                                     optional attribution stack and the
	 *                                     optional mootness hook.
	 */
	public function __construct(
		private readonly FlowDefinitionBuilder $builder,
		private readonly LoggerInterface $logger,
		private readonly ?FlowOversightRegistry $oversight = null,
		private readonly FlowEngineServices $services=new FlowEngineServices(),
	) {
		$this->routing = new FlowHopRouting(router: $services->router, placement: $services->placement, mootness: $services->mootness);
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
		$this->services->runContext?->push(
			frame: new FlowRunFrame(
				runUuid: ($context[FlowRunContext::CONTEXT_RUN] ?? null),
				nodeId: $hop->name(),
				sequence: ((int)($context[FlowRunContext::CONTEXT_BASE] ?? 0) + count($hop->pass->log))
			)
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
	 * Nothing switches this off: not a flow, not the run's context, not an
	 * instance setting. It is what stops a running flow when the kill switch is
	 * thrown, so an exemption would be a way past it.
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
		if ($this->oversight === null) {
			return;
		}

		$refusal = $this->oversight->firstRefusal(
			context: array_merge(
				$hop->pass->context,
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
	 * do not run; an author pins their output ({@see FlowItemPlacement::pinnedItems()}) and
	 * re-runs only the part being worked on. It is the initial place overridden,
	 * so a resume — whose marking is already mid-graph and comes from the store —
	 * is unaffected.
	 *
	 * The flow, the marking store, the subject, the dispatcher, the run-level
	 * context and the per-run stream collaborator travel together in
	 * `$start` ({@see FlowRunStart}); a null stream collaborator walks a single
	 * in-memory stream exactly as before — a flow with one stream IS the run.
	 *
	 * @param FlowRunStart       $start  The flow, its collaborators, the context and the streams.
	 * @param FlowRunOrigin|null $origin Seed items (default: one item from the subject) and the node to start
	 *                                   from (default: the flow's own start).
	 *
	 * @return array The run result: `{status, log: [], context: [], items: []}`.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	public function run(FlowRunStart $start, ?FlowRunOrigin $origin=null): array {
		$begun = $start->startingAt(node: $origin?->startAt);
		$begun->items = ($origin->items ?? FlowItems::fromSubject(subject: $start->subject));

		return $this->startRun(start: $begun);
	}//end run()

	/**
	 * Build the workflow of a run and walk it.
	 *
	 * @param FlowRunStart $start What the run starts from.
	 *
	 * @return array The run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function startRun(FlowRunStart $start): array {
		try {
			$definition = $this->builder->build(flow: $start->flow);
		} catch (InvalidArgumentException $e) {
			return $this->refuseMalformedFlow(start: $start, error: $e);
		}

		$workflow = new Workflow(definition: $definition, markingStore: $start->store, name: (string)($start->flow['id'] ?? 'flow'));
		$start->placeItems = $this->routing->seed(start: $start, workflow: $workflow);

		if ($start->streams !== null) {
			return $this->walkStreams(pass: $start->streamPass(workflow: $workflow, streams: $start->streams));
		}

		return $this->walkSingle(pass: $start->walkPass(workflow: $workflow));
	}//end startRun()

	/**
	 * A malformed document is the author's error and must be visible.
	 *
	 * Unlike x-openregister-flows, this engine does not swallow.
	 *
	 * @param FlowRunStart             $start What the run starts from.
	 * @param InvalidArgumentException $error What the builder refused.
	 *
	 * @return array The failed run result.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function refuseMalformedFlow(FlowRunStart $start, InvalidArgumentException $error): array {
		$this->logger->warning(
			message: '[FlowEngine] Refusing to run a malformed flow',
			context: ['file' => __FILE__, 'line' => __LINE__, 'error' => $error->getMessage()]
		);

		return $start->refused(error: $error->getMessage());
	}//end refuseMalformedFlow()

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
			return $pass->result(status: self::STATUS_FAILED) + ['error' => $this->ceilingReached(pass: $pass)];
		}

		$transition = $this->selectTransition(enabled: $enabled, pass: $pass);
		if ($transition === null) {
			return $pass->result(status: self::STATUS_COMPLETED);
		}

		return $this->singleHop(pass: $pass, transition: $transition);
	}//end nextSingleHop()

	/**
	 * The hop of a chosen transition; a join reads every branch's items, in the froms' declared order.
	 *
	 * @param FlowWalkPass $pass       The pass.
	 * @param object       $transition The chosen transition.
	 *
	 * @return FlowWalkHop The hop to fire.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function singleHop(FlowWalkPass $pass, object $transition): FlowWalkHop {
		$plan = $this->routing->plan(transition: $transition, pass: $pass);
		$pass->items = $plan->itemsIn;

		return new FlowWalkHop(pass: $pass, plan: $plan);
	}//end singleHop()

	/**
	 * Report that a run reached the transition ceiling.
	 *
	 * @param FlowWalkPass|FlowStreamPass $pass The pass.
	 *
	 * @return string The run's error.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function ceilingReached(FlowWalkPass|FlowStreamPass $pass): string {
		$this->logger->warning(
			message: '[FlowEngine] Flow exceeded the transition ceiling; aborting',
			context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => ($pass->flow['id'] ?? null), 'ceiling' => self::MAX_TRANSITIONS]
		);

		return sprintf('Flow did not settle within %d transitions; it may contain an unbounded loop.', self::MAX_TRANSITIONS);
	}//end ceilingReached()

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
	private function runSingleHop(FlowWalkHop $hop): array|null {
		$pass = $hop->pass;
		$pinned = $this->services->placement->pinnedItems(pass: $pass, transitionName: $hop->name());
		if ($pinned !== null) {
			$pass->items = $pinned;
			$pass->log[] = $hop->pinnedEntry();
			$this->advanceSingle(hop: $hop);
			return null;
		}

		$outcome = $this->dispatchSingleHop(hop: $hop);
		if ($outcome !== null) {
			return $outcome;
		}

		$this->advanceSingle(hop: $hop);
		return null;
	}//end runSingleHop()

	/**
	 * Attribute, check oversight for and dispatch one unpinned hop.
	 *
	 * @param FlowWalkHop $hop The hop.
	 *
	 * @return array|null The run result when the hop ends the run, null when the walk advances.
	 *
	 * @spec openspec/changes/or-flow-engine/specs/flow-engine/spec.md
	 */
	private function dispatchSingleHop(FlowWalkHop $hop): array|null {
		$pass = $hop->pass;
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
			return $this->outcomeForFailedStep(hop: $hop, error: $e);
		} finally {
			$this->services->runContext?->pop();
		}//end try

		return null;
	}//end dispatchSingleHop()

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
		$advance = $this->routing->advance(hop: $hop);
		$hop->pass->items = $advance->items;
		$hop->pass->placeItems = $advance->placeItems;
	}//end advanceSingle()

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

			$outcome = $this->advanceStream(pass: $pass, streamId: $streamId);
			if ($outcome !== null) {
				return $outcome;
			}
		}

		return $pass->finish();
	}//end walkStreams()

	/**
	 * Take one firing on a stream, when it has one and the ceiling allows it.
	 *
	 * THE CEILING counts the RUN, not the pass: the durable firing count
	 * survives suspension and covers every stream together.
	 *
	 * @param FlowStreamPass $pass     The pass.
	 * @param string         $streamId The stream to advance.
	 *
	 * @return array|null The terminal run result, or null when the walk goes on.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function advanceStream(FlowStreamPass $pass, string $streamId): ?array {
		if ($pass->streams->firings() >= self::MAX_TRANSITIONS) {
			return $this->streamCeilingReached(pass: $pass);
		}

		$hop = $this->claimStreamHop(pass: $pass, streamId: $streamId);
		if ($hop === null) {
			return null;
		}

		return $this->runStreamHop(hop: $hop);
	}//end advanceStream()

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
		$error = $this->ceilingReached(pass: $pass);
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
		$transition = $this->selectTransition(enabled: $pass->candidatesFor(streamId: $streamId), pass: $pass);
		if ($transition === null) {
			$pass->streams->exhaust(id: $streamId);
			return null;
		}

		// THE CLAIM, on every place this firing touches, before anything runs.
		$places = array_map('strval', array_merge($transition->getFroms(), $transition->getTos()));
		$claimed = $pass->streams->claim(id: $streamId, transition: $transition->getName(), places: $places);
		if ($claimed === null) {
			return null;
		}

		return new FlowStreamHop(pass: $pass, plan: $this->routing->plan(transition: $transition, pass: $pass), streamId: $streamId, claimed: $claimed);
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

		$pinned = $this->services->placement->pinnedItems(pass: $pass, transitionName: $hop->name());
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
			return $hop->stopped(stop: $stop);
		} catch (FlowSuspension $suspension) {
			$hop->parked(suspension: $suspension);
			return null;
		} catch (Throwable $e) {
			return $this->failOnStream(hop: $hop, error: $e);
		} finally {
			$this->services->runContext?->pop();
		}//end try

		$this->fireOnStream(hop: $hop, entry: $entry);

		return null;
	}//end dispatchOnStream()

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

		return $hop->ended(entry: $entry);
	}//end failOnStream()

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
		$advance = $this->routing->advance(hop: $hop);
		$takenTos = $hop->takenInOrder(taken: $advance->taken);
		// The places this firing TAKES, handed over so the answer describes
		// the marking AFTER the commit rather than the one the walk still
		// holds: the stream picture is only re-read inside commitFiring().
		// Without them every ordinary mid-flow firing reported "no work
		// remains", the commit derived `completed` for a run that was still
		// walking, and every terminal listener fired on it.
		$enabled = $pass->workflow->getEnabledTransitions(subject: $pass->subject);
		$enabledAfter = $pass->streams->workRemains(transitions: $enabled, produced: $takenTos);
		$firing = $hop->firing(takenTos: $takenTos)
			->withPlaceItems(placeItems: $advance->placeItems)
			->withLogEntry(logEntry: $entry, enabledAfter: $enabledAfter)
			->withStreamState(status: FlowRun::STATUS_RUNNING, error: ($entry['error'] ?? null));

		$result = $pass->streams->commitFiring(firing: $firing);
		$hop->settle(result: $result);
		$pass->log[] = $hop->committedEntry(entry: $entry, firings: $result->firings);
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
	 * @param array<int, object>          $enabled The enabled transitions.
	 * @param FlowWalkPass|FlowStreamPass $pass    The pass.
	 *
	 * @return object|null The transition to fire, or null when none is eligible.
	 *
	 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
	 */
	private function selectTransition(array $enabled, FlowWalkPass|FlowStreamPass $pass): ?object {
		$fallback = null;

		foreach ($enabled as $transition) {
			$condition = $this->services->router->conditionReaching(flow: $pass->flow, nodeId: $transition->getName());

			if (empty($condition) === true) {
				// Remember the first default edge; keep looking for a match.
				$fallback = ($fallback ?? $transition);
				continue;
			}

			if (FlowExpression::isTrue(logic: $condition, data: $this->conditionData(transition: $transition, pass: $pass)) === true) {
				return $transition;
			}
		}

		return $fallback;
	}//end selectTransition()

	/**
	 * The data a transition's condition reads: the first item on its input places.
	 *
	 * @param object                      $transition The candidate transition.
	 * @param FlowWalkPass|FlowStreamPass $pass       The pass.
	 *
	 * @return array<string, mixed> The expression data.
	 *
	 * @spec openspec/changes/or-flow-logic/specs/flow-logic/spec.md
	 */
	private function conditionData(object $transition, FlowWalkPass|FlowStreamPass $pass): array {
		$items = $this->services->placement->itemsForTransition(transition: $transition, placeItems: $pass->placeItems);

		return FlowExpression::documentFor(
			item: ($items[0] ?? []),
			options: new FlowExpressionDataOptions(itemCount: count($items), context: $pass->context)
		);
	}//end conditionData()
}//end class
