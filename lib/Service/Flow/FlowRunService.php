<?php

/**
 * Starts, persists and resumes flow runs.
 *
 * `FlowEngine` walks a graph in memory and knows nothing about storage. This
 * service is the half that makes a run durable: it creates the row, hands the
 * engine a marking store backed by that row, writes back what the walk
 * produced, and — when a step asked to wait — leaves the run resumable instead
 * of finished.
 *
 * Keeping the two apart is deliberate. The engine stays unit-testable without
 * a database, and the decision "when does a run get written" lives in exactly
 * one place rather than being sprinkled through the walk.
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
 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\Flow;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use OCA\OpenRegister\Db\FlowRunStep;
use OCA\OpenRegister\Db\FlowRunStepMapper;
use OCA\OpenRegister\Db\FlowStateMapper;
use OCA\OpenRegister\Exception\FlowRunExpired;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The durable half of flow execution.
 */
class FlowRunService {
	/**
	 * How long one run may take, in minutes, when nothing overrides it.
	 *
	 * An hour is far above any flow that is working normally and far below
	 * "forever", which is what the ceiling was before: a run had no time limit
	 * at all, and the only thing that ever stopped a long one was the stale
	 * reaper mistaking it for dead — which did not stop it, it only relabelled
	 * it while it kept running.
	 *
	 * @var integer
	 */
	private const DEFAULT_MAX_RUNTIME_MINUTES = 60;

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
	 * Loads and writes back the state that belongs to the FLOW, not the run.
	 *
	 * A separate collaborator because it handles a different lifetime: the token
	 * dies with its run, this outlives every run of the flow. See
	 * {@see FlowStateBinding}.
	 *
	 * @var FlowStateBinding
	 */
	private readonly FlowStateBinding $flowState;

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper $mapper Persists runs.
	 * @param FlowStateMapper $stateMapper Persists state that outlives a run.
	 * @param FlowEngine $engine Walks the graph.
	 * @param FlowNodeRegistry $registry Resolves step types.
	 * @param LoggerInterface $logger The logger.
	 * @param ContainerInterface $container Lazily resolves OrganisationService.
	 * @param FlowRunStepMapper|null $steps Records one row per node execution.
	 *                                      Nullable so the cron worker and the
	 *                                      unit tests can build this service
	 *                                      without it; history is then simply
	 *                                      not recorded, never faked.
	 * @param IAppConfig|null $appConfig Reads the instance-wide runtime
	 *                                   ceiling. Nullable on the same terms
	 *                                   as $steps; absent, the compiled-in
	 *                                   default applies.
	 */
	public function __construct(
		private readonly FlowRunMapper $mapper,
		FlowStateMapper $stateMapper,
		private readonly FlowEngine $engine,
		private readonly FlowNodeRegistry $registry,
		private readonly LoggerInterface $logger,
		private readonly ContainerInterface $container,
		private readonly ?FlowRunStepMapper $steps = null,
		private readonly ?IAppConfig $appConfig = null,
	) {
		// Built here rather than injected, deliberately: this service's
		// constructor is called explicitly by three test suites, and inserting or
		// swapping a parameter would silently shift every later slot for them.
		// The binding needs nothing this service was not already given.
		$this->flowState = new FlowStateBinding(stateMapper: $stateMapper, logger: $logger);

	}//end __construct()

	/**
	 * The node context a walk starts from, before its live handles are added.
	 *
	 * `triggeredBy` carries the run's owner into the node context. Nodes read it
	 * to attribute what they do — ObjectWriteNode REFUSES to write without it,
	 * SubFlowNode propagates it to child runs, and Hermiq's agent node runs the
	 * turn as that user. Nothing else in lib/ ever wrote this key, so every
	 * trigger reached its nodes ownerless and only hand-injected contexts (tests,
	 * harnesses) worked. An explicit context value still wins, so a caller can
	 * attribute a run to someone other than whoever queued it. See or#2158.
	 *
	 * @param FlowRun $run The run being executed.
	 * @param boolean $resuming Whether this walk resumes a suspended run.
	 *
	 * @return array The starting context.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $resuming is a fact about the
	 * run being reported into the context, not a mode switch on this method.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-storage/spec.md
	 */
	private function baseContextFor(FlowRun $run, bool $resuming): array {
		$context = ($run->getContext() ?? []);

		$context['runUuid'] = $run->getUuid();
		$context['resuming'] = $resuming;
		$context['triggeredBy'] = ($context['triggeredBy'] ?? $run->getTriggeredBy());

		return $context;
	}//end baseContextFor()

	/**
	 * Assemble the context a walk's steps are handed.
	 *
	 * Three things travel as OBJECTS rather than values, for one shared reason:
	 * `IFlowNode::execute()` takes `$context` by value, so a plain array could
	 * only ever be read. A handle survives the copy and buys every node write
	 * access without changing the signature any node implements.
	 *
	 * They answer different questions, and conflating them is the trap:
	 *
	 * - the TOKEN is what the run carries — a correlation id, a resolved
	 *   reference. Belongs to the run and dies with it.
	 * - the RESUME STATE is how far each node got. Belongs to the node, and only
	 *   between a suspension and the resume that answers it.
	 * - the GUARD is the run's liveness signal and deadline. Also handed to the
	 *   dispatcher, which checkpoints between steps — but between-steps is not
	 *   enough alone, because a single step that runs past the threshold gets
	 *   the run failed underneath it. A node working in pages checkpoints
	 *   itself; one that returns quickly never needs to.
	 *
	 * @param FlowRun $run The run being executed.
	 * @param boolean $resuming Whether this walk resumes a suspended run.
	 * @param FlowRunGuard $guard The run's liveness handle.
	 *
	 * @return array The node context.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-must-be-able-to-resume-from-where-it-stopped
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowToken::fromArray and
	 * FlowResumeState::fromArray are stateless rehydrators for value objects;
	 * injecting a factory to call them would add a dependency without removing
	 * any coupling. Carried over from execute(), which held the same suppression
	 * for the same two calls before they moved here.
	 */
	private function nodeContextFor(FlowRun $run, bool $resuming, FlowRunGuard $guard): array {
		$context = $this->baseContextFor(run: $run, resuming: $resuming);

		$context[FlowToken::CONTEXT_KEY] = FlowToken::fromArray(($context[FlowToken::CONTEXT_KEY] ?? null));
		$context[FlowResumeState::CONTEXT_KEY] = FlowResumeState::fromArray(($context[FlowResumeState::CONTEXT_KEY] ?? null));
		$context[FlowRunGuard::CONTEXT_KEY] = $guard;

		$this->flowState->attach(run: $run, context: $context);

		return $context;
	}//end nodeContextFor()


	/**
	 * The liveness-and-deadline handle for one run of one flow.
	 *
	 * @param FlowRun $run The run being executed.
	 * @param array $flow The resolved flow document being run.
	 *
	 * @return FlowRunGuard The guard for this walk.
	 *
	 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
	 */
	private function guardFor(FlowRun $run, array $flow): FlowRunGuard {
		return new FlowRunGuard(
			mapper: $this->mapper,
			logger: $this->logger,
			runUuid: (string)$run->getUuid(),
			startedAt: microtime(true),
			budget: $this->runtimeBudgetSeconds(flow: $flow)
		);

	}//end guardFor()

	/**
	 * Seconds one run of this flow may take before it is stopped.
	 *
	 * Three layers, most specific first: the flow's own `limits.maxRuntimeMinutes`,
	 * then the instance's `flow_max_runtime_minutes`, then an hour. A flow that
	 * legitimately runs longer says so on itself rather than forcing the ceiling
	 * up for every flow on the instance.
	 *
	 * Zero at either layer means "no ceiling", for an operator running
	 * deliberately long imports who would rather see them finish than be killed.
	 *
	 * @param array $flow The resolved flow document being run.
	 *
	 * @return integer The budget in seconds, or 0 for unlimited.
	 *
	 * @spec openspec/changes/or-flow-stale-runs/specs/flow-stale-runs/spec.md
	 */
	private function runtimeBudgetSeconds(array $flow): int {
		$limits = (array)($flow['limits'] ?? []);
		if (array_key_exists('maxRuntimeMinutes', $limits) === true) {
			return (max(0, (int)$limits['maxRuntimeMinutes']) * 60);
		}

		$minutes = self::DEFAULT_MAX_RUNTIME_MINUTES;
		if ($this->appConfig !== null) {
			$minutes = (int)$this->appConfig->getValueString(
				'openregister',
				'flow_max_runtime_minutes',
				(string)self::DEFAULT_MAX_RUNTIME_MINUTES
			);
		}

		return (max(0, $minutes) * 60);
	}//end runtimeBudgetSeconds()

	/**
	 * The organisation to attribute a run to, or null when there is none.
	 *
	 * A run is queued from wherever the trigger fired: a request (there is a
	 * session, so there is an active organisation), or a cron pass (there is
	 * not). Only the first can be attributed, and an unattributed run is
	 * recorded as such rather than guessed at — the active-runs surface scopes
	 * strictly by this value, so a wrong guess would put one tenant's runs on
	 * another's dashboard.
	 *
	 * `OrganisationService` is resolved lazily through the container, not
	 * constructor-injected: `FlowRunService` is what the cron worker builds on
	 * every pass, and it must not drag the whole organisation/RBAC graph into
	 * that path to write a column it usually cannot fill anyway.
	 *
	 * @return string|null The active organisation uuid, or null.
	 *
	 * @spec openspec/changes/or-flow-active-runs/specs/flow-active-runs/spec.md
	 */
	private function activeOrganisation(): ?string {
		try {
			$organisationService = $this->container->get('OCA\OpenRegister\Service\OrganisationService');
			$uuid = $organisationService->getActiveOrganisation()?->getUuid();
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FlowRunService] Could not resolve the active organisation for a run: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		if ($uuid === null || $uuid === '') {
			return null;
		}

		return (string)$uuid;
	}//end activeOrganisation()

	/**
	 * Queue a run without executing it.
	 *
	 * This is what a trigger calls. A Nextcloud Flow rule, an object event or a
	 * file write runs inside the dispatch of the thing that caused it, and an
	 * arbitrary graph must not sit on that critical path — so the trigger only
	 * records the intent and returns.
	 *
	 * @param string $flowId The flow to run.
	 * @param array $subject `{uuid, register, schema}` of the object.
	 * @param string $trigger What caused this run.
	 * @param array $context Run-level metadata.
	 * @param string|null $user The user whose action caused it.
	 *
	 * @return FlowRun The queued run.
	 *
	 * @throws FlowDeadEnd When a node has no outgoing edge and does not end the
	 *                     flow, so the run is refused rather than started. Declared
	 *                     because it is part of this method's contract: every
	 *                     dispatch path arrives here, and a caller that cannot see
	 *                     the refusal in the signature will not handle it — which
	 *                     is how it reached HTTP as a bare 500.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	public function queue(
		string $flowId,
		array $subject = [],
		string $trigger = 'manual',
		array $context = [],
		?string $user = null,
	): FlowRun {
		// Every dispatch path arrives here — manual, trigger, schedule, MCP,
		// the workflow-engine operation and a sub-flow call — so this is the
		// one place a refusal covers all of them. Guarding `FlowService::run()`
		// instead would leave cron-fired flows unguarded, and those are most of
		// them.
		$this->refuseDeadEnd(flowId: $flowId);

		$run = new FlowRun();
		$run->setUuid($this->newUuid());
		$run->setFlowId($flowId);
		$run->setStatus(FlowRun::STATUS_QUEUED);
		$run->setTrigger($trigger);
		$run->setContext($context);
		$run->setLog([]);
		$run->setSubjectUuid(($subject['uuid'] ?? null));
		$run->setSubjectRegister(($subject['register'] ?? null));
		$run->setSubjectSchema(($subject['schema'] ?? null));
		$run->setTriggeredBy($user);
		// Attribute the run to the caller's organisation so the active-runs
		// surface can scope by tenant. Null when queued off a request (cron) —
		// see activeOrganisation().
		$run->setOrganisation($this->activeOrganisation());
		$run->setCreated(new DateTime());
		$run->setUpdated(new DateTime());

		return $this->mapper->insert($run);
	}//end queue()

	/**
	 * Refuse to queue a flow that has a node its token cannot leave.
	 *
	 * Writes the verdict onto the FLOW before throwing, so a refused flow is
	 * distinguishable from one nobody has triggered without reading run
	 * history — there is no run to read, which is the point. A run that IS
	 * accepted clears the verdict back to `ok`, so a fixed flow stops
	 * reporting an error it no longer has.
	 *
	 * Resolved lazily through the container for the same reason
	 * `activeOrganisation()` is: this service is constructed on paths that do
	 * not need either collaborator, and several tests build it by hand.
	 *
	 * @param string $flowId The flow uuid.
	 *
	 * @return void
	 *
	 * @throws FlowDeadEnd When a non-terminal node has no outgoing edge.
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function refuseDeadEnd(string $flowId): void {
		try {
			$mapper = $this->container->get('OCA\OpenRegister\Db\FlowMapper');
			$preflight = $this->container->get('OCA\OpenRegister\Service\Flow\FlowNodePreflight');
			$flow = $mapper->findByUuid($flowId);
		} catch (Throwable $e) {
			// A flow we cannot load is not a flow we can judge — `findByUuid()`
			// throws rather than returning null. Queueing proceeds and the
			// existing not-found handling downstream reports it; inventing a
			// dead-end refusal here would blame the document for a lookup
			// failure.
			return;
		}

		$findings = $preflight->inspect(
			flow: [
				'nodes' => ($flow->getNodes() ?? []),
				'edges' => ($flow->getEdges() ?? []),
			]
		);

		$deadEnds = [];
		foreach ($findings['warnings'] as $warning) {
			if (($warning['reason'] ?? '') === FlowNodePreflight::REASON_DEAD_END) {
				$deadEnds[] = (string)($warning['step'] ?? '');
			}
		}

		if ($deadEnds === []) {
			// Accepted. Clear a stale refusal so a repaired flow stops
			// reporting the error it no longer has.
			if ($flow->getStatus() === Flow::STATUS_ERROR) {
				$flow->setStatus(Flow::STATUS_OK);
				$flow->setStatusMessage(null);
				$mapper->update($flow);
			}

			return;
		}

		$refusal = new FlowDeadEnd(nodeIds: $deadEnds);

		$flow->setStatus(Flow::STATUS_ERROR);
		$flow->setStatusMessage($refusal->getMessage());
		$mapper->update($flow);

		$this->logger->warning(
			message: '[FlowRunService] Refused to queue a flow with a dead end',
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'flow' => $flowId,
				'nodes' => $deadEnds,
			]
		);

		throw $refusal;
	}//end refuseDeadEnd()

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
	 */
	public function hasActiveRun(string $flowId): bool {
		return $this->mapper->hasActiveRun(flowId: $flowId);
	}//end hasActiveRun()


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
	public function retry(FlowRun $run): ?FlowRun {
		if ($run->isTerminal() === false) {
			return null;
		}

		return $this->queue(
			flowId: (string)$run->getFlowId(),
			subject: [
				'uuid' => $run->getSubjectUuid(),
				'register' => $run->getSubjectRegister(),
				'schema' => $run->getSubjectSchema(),
			],
			trigger: 'retry',
			context: ($run->getContext() ?? []),
			user: $run->getTriggeredBy()
		);

	}//end retry()

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
	 * walk ({@see self::persistResult()}), so a node reads the answer to ITS
	 * question rather than one left behind by an earlier suspension.
	 *
	 * @param FlowRun $run The suspended run to wake.
	 * @param array $payload What the signaller wants the run to know.
	 *
	 * @return FlowRun|null The updated run, or null when it was not suspended.
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-run-suspended-on-an-external-signal-must-be-reachable
	 */
	public function signal(FlowRun $run, array $payload = []): ?FlowRun {
		if ($run->getStatus() !== FlowRun::STATUS_SUSPENDED) {
			// A queued or running run has not asked for anything yet, and a
			// terminal one is past asking. Signalling either would either be
			// lost or would resurrect a finished run.
			return null;
		}

		$context = ($run->getContext() ?? []);
		$context[self::SIGNAL_CONTEXT_KEY] = $payload;

		$run->setContext($context);
		$run->setResumeAt(new DateTime());
		$run->setUpdated(new DateTime());

		return $this->mapper->update($run);
	}//end signal()

	/**
	 * Execute (or continue) a run to its next stopping point.
	 *
	 * Called by the queue worker, never by a trigger. Returns the run in
	 * whatever state the walk left it: terminal, or suspended and resumable.
	 *
	 * @param FlowRun $run The run to advance.
	 * @param array $flow The flow document.
	 * @param object $subject The object the run is about.
	 * @param array $seedItems Items to start from; ignored when resuming.
	 * @param string|null $startAt Node to start from (run-from-here); ignored when resuming.
	 *
	 * @return FlowRun The updated run.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowToken::fromArray is a stateless
	 * rehydrator for a value object; injecting a factory to call it would add a
	 * dependency without removing any coupling.
	 */
	public function execute(FlowRun $run, array $flow, object $subject, ?array $seedItems = null, ?string $startAt = null): FlowRun {
		if ($run->isTerminal() === true) {
			// Re-executing a finished run would repeat every side effect it
			// already performed. Retry creates a NEW run instead.
			return $run;
		}

		$resuming = ($run->getStatus() === FlowRun::STATUS_SUSPENDED);
		$run->setStatus(FlowRun::STATUS_RUNNING);
		$run->setUpdated(new DateTime());
		$this->mapper->update($run);

		$items = $seedItems;
		$start = $startAt;
		if ($resuming === true) {
			// On resume the stored items win: they are what the run was
			// carrying when it paused. Re-seeding from the subject would throw
			// away everything the earlier steps produced. A start node is a
			// fresh-run concern too — the marking already holds where to resume.
			$items = ($run->getItems() ?? []);
			$start = null;
		}

		$guard = $this->guardFor(run: $run, flow: $flow);
		$context = $this->nodeContextFor(run: $run, resuming: $resuming, guard: $guard);

		try {
			$result = $this->engine->run(
				flow: $flow,
				store: new FlowRunMarkingStore(run: $run),
				subject: $subject,
				dispatcher: new RegistryStepDispatcher(registry: $this->registry, guard: $guard),
				context: $context,
				items: $items,
				startAt: $start
			);
		} catch (FlowRunExpired $e) {
			// The run stopped ITSELF at a checkpoint, having used its budget.
			// Recorded as a first-class outcome rather than folded into the crash
			// path below: nothing went wrong with the work, and the message has to
			// say so or every timed-out import reads as a broken flow.
			$this->logger->warning(
				message: '[FlowRunService] Flow run stopped at its runtime ceiling',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'run' => $run->getUuid(),
					'flow' => $run->getFlowId(),
					'error' => $e->getMessage(),
				]
			);

			$run->setStatus(FlowRun::STATUS_FAILED);
			$run->setError($e->getMessage());
			$run->setUpdated(new DateTime());

			return $this->mapper->update($run);
		} catch (Throwable $e) {
			// The engine itself failing (rather than a step) is not something
			// the run should be left `running` for — that status would make it
			// look claimed by a worker forever.
			$this->logger->error(
				message: '[FlowRunService] Flow run failed outside the walk',
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $run->getUuid(), 'error' => $e->getMessage()]
			);

			$run->setStatus(FlowRun::STATUS_FAILED);
			$run->setError($e->getMessage());
			$run->setUpdated(new DateTime());

			return $this->mapper->update($run);
		}//end try

		return $this->persistResult(run: $run, result: $result);
	}//end execute()

	/**
	 * Write a completed walk back onto the run.
	 *
	 * @param FlowRun $run The run.
	 * @param array $result What the engine returned.
	 *
	 * @return FlowRun The updated run.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */

	/**
	 * Write one step row per node execution in this segment.
	 *
	 * The aggregate `log` column answers "what happened in this run" and
	 * nothing else — "which node type fails", "every failed step for this
	 * flow", "what did node X output" all require loading and walking every
	 * run's blob. One row per hop makes those queryable, and gives retention
	 * something it can prune per flow.
	 *
	 * Sequence CONTINUES from the highest already recorded rather than
	 * restarting at zero, so a run that suspends on a wait node and resumes
	 * later reads as one ordered history instead of two interleaved ones.
	 *
	 * Failing to record history must never fail the run itself: the run is the
	 * work, the rows are the account of it.
	 *
	 * @param FlowRun $run The run these steps belong to.
	 * @param array<int, mixed> $entries The engine log entries for this segment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	private function recordSteps(FlowRun $run, array $entries): void {
		if ($this->steps === null || empty($entries) === true) {
			return;
		}

		$runUuid = (string)$run->getUuid();

		try {
			$sequence = ($this->steps->highestSequence(runUuid: $runUuid) + 1);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunService] Could not read the step sequence for run ' . $runUuid . ': ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return;
		}

		foreach ($entries as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$step = new FlowRunStep();
			$step->setRunUuid($runUuid);
			$step->setFlowId((string)$run->getFlowId());
			$step->setNodeId((string)($entry['transition'] ?? ''));
			$step->setNodeType(($entry['type'] ?? null));
			$step->setSequence($sequence);
			$step->setStatus((string)($entry['status'] ?? 'unknown'));
			$step->setDurationMs(($entry['durationMs'] ?? null));
			$step->setCreated(new DateTime());
			$step->setFinished(new DateTime());

			// `error` and `reason` are distinct outcomes that both belong in
			// the error column: a thrown step and a deliberately stopped one
			// are each something a person needs to read back.
			$step->setError(($entry['error'] ?? ($entry['reason'] ?? null)));

			// What the node produced, minus the items themselves — a step row
			// is an index into the run, not a second copy of its data.
			$step->setOutput(
				array_filter(
					[
						'itemsIn' => ($entry['itemsIn'] ?? null),
						'itemsOut' => ($entry['itemsOut'] ?? null),
						'checkId' => ($entry['checkId'] ?? null),
					],
					static fn ($v): bool => $v !== null
				)
			);

			try {
				$this->steps->insert($step);
			} catch (Throwable $e) {
				$this->logger->warning(
					message: '[FlowRunService] Could not record a step row for run ' . $runUuid . ': ' . $e->getMessage(),
					context: ['file' => __FILE__, 'line' => __LINE__]
				);
			}

			$sequence++;
		}//end foreach

	}//end recordSteps()

	/**
	 * Write back what a walk produced.
	 *
	 * @param FlowRun $run The run to update.
	 * @param array $result The engine's result envelope.
	 *
	 * @return FlowRun The updated run.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	private function persistResult(FlowRun $run, array $result): FlowRun {
		$status = (string)($result['status'] ?? FlowRun::STATUS_FAILED);

		// The log is appended, not replaced: a resumed run's history is the
		// whole run, not just the segment since it last woke up.
		$log = array_merge(($run->getLog() ?? []), (array)($result['log'] ?? []));

		// Promote THIS segment's entries to step rows. Only the new entries —
		// `$result['log']`, not the merged `$log` — or every resume would
		// re-record the whole history it had already written.
		$this->recordSteps(run: $run, entries: (array)($result['log'] ?? []));

		// The token travels as an object so steps can write to it; the column
		// holds JSON. Serialising here — on the suspended path as much as the
		// terminal ones — is what makes "pause and continue later" keep the
		// values the run had already gathered.
		$context = (array)($result['context'] ?? []);
		if (isset($context[FlowToken::CONTEXT_KEY]) === true && $context[FlowToken::CONTEXT_KEY] instanceof FlowToken === true) {
			$context[FlowToken::CONTEXT_KEY] = $context[FlowToken::CONTEXT_KEY]->jsonSerialize();
		}

		// Flow state persists to its OWN table and is then removed from the
		// run's context. Two reasons it must not be written into the context
		// JSON alongside the token:
		//
		// - it would be a per-run COPY of flow-level state, and a resumed run
		// would restore a stale snapshot over whatever later runs had
		// written
		// - a slot table or a cursor would be duplicated into every run row
		// the flow ever produces
		//
		// Only written when a node actually changed something: a flow that
		// merely READS its state should not touch the row on every tick, and a
		// five-minute schedule makes that difference thousands of writes a week.
		$this->flowState->persist(run: $run, context: $context);
		unset($context[FlowStateHandle::CONTEXT_KEY]);

		// Same reason as the state handle one line up: it is a live collaborator,
		// not run data. Left in, it would be serialised into the run's context
		// column as a meaningless object and handed back to a resumed run as
		// something that no longer beats.
		unset($context[FlowRunGuard::CONTEXT_KEY]);

		// The per-node view the dispatcher scopes for whichever node ran last.
		// A view, not data: it is rebuilt on every dispatch, and persisting it
		// would write one arbitrary node's slot into the run under a key every
		// other node also reads from.
		unset($context[FlowNodeResumeState::CONTEXT_KEY]);

		$resumeState = ($context[FlowResumeState::CONTEXT_KEY] ?? null);
		unset($context[FlowResumeState::CONTEXT_KEY]);
		if ($resumeState instanceof FlowResumeState === true) {
			$storable = $resumeState->storableWhen(suspended: ($status === FlowRun::STATUS_SUSPENDED));
			if ($storable !== null) {
				$context[FlowResumeState::CONTEXT_KEY] = $storable;
			}
		}

		// A signal is consumed by the walk it woke. Kept, it would still be
		// sitting there the NEXT time this run suspends on a signal, and that
		// node would resume immediately on an answer to somebody else's
		// question — a flow with two approval steps would auto-approve the
		// second one the moment the first was granted.
		unset($context[self::SIGNAL_CONTEXT_KEY]);

		$run->setStatus($status);
		$run->setItems((array)($result['items'] ?? []));
		$run->setContext($context);
		$run->setLog($log);
		$run->setError(($result['error'] ?? null));
		$run->setUpdated(new DateTime());

		// `resumeAt` is only meaningful while suspended. Clearing it on every
		// other outcome stops a completed run from being picked up by the
		// due-runs query on its next pass.
		$resumeAt = null;
		if ($status === FlowRun::STATUS_SUSPENDED) {
			$resumeAt = ($result['resumeAt'] ?? null);
		}

		$run->setResumeAt($resumeAt);

		$persisted = $this->mapper->update($run);

		$this->recordLastRun(run: $persisted, status: $status);

		return $persisted;
	}//end persistResult()

	/**
	 * Copy a finished run's outcome onto its flow.
	 *
	 * On a TERMINAL state only. A queued or running run has no outcome yet, and
	 * a suspended one is mid-flight — writing any of those would make the flow
	 * list answer "how did it last go?" with "it hasn't finished", which is a
	 * different question. A suspended run that later completes writes here on
	 * that completion, so nothing is lost by waiting.
	 *
	 * Failure to write is logged and swallowed: this is a reporting
	 * convenience, and losing it must never turn a completed run into a failed
	 * one.
	 *
	 * @param FlowRun $run The run that just reached a terminal state.
	 * @param string $status That terminal status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	private function recordLastRun(FlowRun $run, string $status): void {
		$terminal = [
			FlowRun::STATUS_COMPLETED,
			FlowRun::STATUS_STOPPED,
			FlowRun::STATUS_FAILED,
			FlowRun::STATUS_DEAD_LETTER,
		];

		if (in_array($status, $terminal, true) === false) {
			return;
		}

		$flowId = trim((string)$run->getFlowId());
		if ($flowId === '') {
			return;
		}

		try {
			$mapper = $this->container->get('OCA\OpenRegister\Db\FlowMapper');
			$flow = $mapper->findByUuid($flowId);

			$flow->setLastRunUuid($run->getUuid());
			$flow->setLastRunStatus($status);
			$flow->setLastRunMessage($run->getError());
			$flow->setLastRunAt(new DateTime());

			$mapper->update($flow);
		} catch (Throwable $e) {
			$this->logger->debug(
				message: '[FlowRunService] Could not record the last run on its flow: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'flow' => $flowId]
			);
		}//end try

	}//end recordLastRun()

	/**
	 * A v4 UUID for a new run.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/or-flow-runs/specs/flow-runs/spec.md
	 */
	private function newUuid(): string {
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}//end newUuid()
}//end class
