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
use OCA\OpenRegister\Db\FlowStreamMapper;
use OCA\OpenRegister\Exception\FlowRunExpired;
use OCA\OpenRegister\Service\Delegation\DelegationRefused;
use OCA\OpenRegister\Service\Delegation\DelegationService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The durable half of flow execution.
 *
 * @spec openspec/specs/flow-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The run lifecycle — queue, execute, resume,
 * signal, persist — plus the stream walk's wiring and the in-request advance; each is one
 * entry into the same engine and belongs beside the others.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) 50 against 50, and the
 * one branch is the version-pin refusal in advanceStream(): the same rule
 * execute() enforces, applied to the completion path so a week-old task
 * continues the graph its run was pinned to.
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
	 * @param FlowStreamMapper|null $streamMapper Stream rows; with the two below, enables the stream walk.
	 * @param FlowPlaceClaims|null $claims The claim protocol.
	 * @param FlowRunCommit|null $commit The locked delta commit path.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI-injected collaborators, appended so
	 * the three test suites that construct this service positionally keep their slots.
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
		private readonly ?FlowStreamMapper $streamMapper = null,
		private readonly ?FlowPlaceClaims $claims = null,
		private readonly ?FlowRunCommit $commit = null,
	) {
		// Built here rather than injected, deliberately: this service's
		// constructor is called explicitly by three test suites, and inserting or
		// swapping a parameter would silently shift every later slot for them.
		// The binding needs nothing this service was not already given.
		$this->flowState = new FlowStateBinding(stateMapper: $stateMapper, logger: $logger);

	}//end __construct()

	/**
	 * The run's step history — its numbering, and its recording.
	 *
	 * Made on demand rather than injected: this constructor is called explicitly
	 * by three test suites, and inserting a parameter would silently shift every
	 * later slot for them. It holds no state beyond the collaborators this
	 * service already has.
	 *
	 * @return FlowStepHistory The history recorder.
	 *
	 * @spec openspec/changes/flow-engine-unification/specs/flow-execution-history/spec.md
	 */
	private function stepHistory(): FlowStepHistory {
		return new FlowStepHistory(steps: $this->steps, logger: $this->logger);
	}//end stepHistory()

	/**
	 * The stream collaborator for a persisted run, or null when the three
	 * parts are not wired (a test-constructed service) — the engine then
	 * walks a single in-memory stream exactly as before.
	 *
	 * @param FlowRun $run The run about to be walked.
	 * @param array $flow The resolved flow document (for a per-flow stream cap).
	 * @param string|null $onlyStream Restrict the walk to one stream (an in-request advance).
	 * @param int|null $budget Firings the walk may commit; null for unbounded.
	 *
	 * @return FlowStreamWalk|null The collaborator.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) FlowPlaceClaims::newOwner() mints a pass token; no instance state.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-independent-branches-of-one-run-must-advance-independently
	 */
	private function streamWalkFor(FlowRun $run, array $flow, ?string $onlyStream = null, ?int $budget = null): ?FlowStreamWalk {
		if ($this->streamMapper === null || $this->claims === null || $this->commit === null) {
			return null;
		}

		$cap = null;
		$limits = (array)($flow['limits'] ?? []);
		if (array_key_exists('streams', $limits) === true) {
			$cap = (int)$limits['streams'];
		}

		return new FlowStreamWalk(
			run: $run,
			claims: $this->claims,
			commit: $this->commit,
			streamMapper: $this->streamMapper,
			owner: FlowPlaceClaims::newOwner(),
			runCap: $cap,
			onlyStream: $onlyStream,
			budget: $budget
		);
	}//end streamWalkFor()

	/**
	 * Release the claims a failed walk still holds, so a pass that died inside
	 * the engine does not leave its branches locked until the reaper's cutoff.
	 * Best-effort: the run is being failed anyway, and the reaper remains the
	 * backstop for a release that itself fails.
	 *
	 * @param FlowStreamWalk|null $walk The walk, when there was one.
	 *
	 * @return void
	 */
	private function releaseWalk(?FlowStreamWalk $walk): void {
		if ($walk === null) {
			return;
		}

		try {
			$walk->finalize(enabled: false, forcedTerminal: FlowRun::STATUS_FAILED);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunService] Could not release a failed walk\'s claims; the reaper will',
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $walk->run()->getUuid(), 'error' => $e->getMessage()]
			);
		}
	}//end releaseWalk()

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
	 * @param FlowRun $run The run, already resolved and pinned.
	 * @param array $flow The pinned flow document.
	 * @param object $subject The subject.
	 * @param string $streamId The completing branch.
	 * @param int|string $budget `0`, `N`, or `"all"`.
	 *
	 * @return FlowRun The run as it stands after the advance.
	 *
	 * @spec openspec/changes/flow-parallel-streams/specs/flow-parallel-streams/spec.md#requirement-a-completions-advance-budget-must-apply-to-the-completing-branch
	 */
	public function advanceStream(FlowRun $run, array $flow, object $subject, string $streamId, int|string $budget): FlowRun {
		$firings = null;
		if ($budget !== 'all') {
			$firings = max(0, (int)$budget);
		}

		if ($firings === 0) {
			$run->setStatus(FlowRun::STATUS_QUEUED);
			$run->setUpdated(new DateTime());

			return $this->mapper->update($run);
		}

		// 🔴 THE RUN'S PIN OUTRANKS THE CALLER'S DOCUMENT, here as in execute():
		// a completion that lands a week after the run started must continue
		// the graph the run was pinned to, not the one its author has since
		// edited. An unpinned (draft test) run passes its document through.
		$pinned = (new FlowPublishedGraph($this->container))->overlayOnto(run: $run, live: $flow);
		if ($pinned === null) {
			return $this->failUnresolvableVersion(run: $run);
		}

		$flow = $pinned;

		$walk = $this->streamWalkFor(run: $run, flow: $flow, onlyStream: $streamId, budget: $firings);
		if ($walk === null) {
			// Without the stream layer there is no branch to scope to; the
			// queue advances the whole run on its next pass.
			$run->setStatus(FlowRun::STATUS_QUEUED);
			$run->setUpdated(new DateTime());

			return $this->mapper->update($run);
		}

		$run->setStatus(FlowRun::STATUS_RUNNING);
		$run->setUpdated(new DateTime());
		$this->mapper->update($run);

		$guard = $this->guardFor(run: $run, flow: $flow);
		$context = $this->nodeContextFor(run: $run, resuming: true, guard: $guard);

		$result = $this->engine->run(
			flow: $flow,
			store: new FlowRunMarkingStore(run: $run),
			subject: $subject,
			dispatcher: new RegistryStepDispatcher(registry: $this->registry, guard: $guard),
			context: $context,
			items: ($run->getItems() ?? []),
			startAt: null,
			streams: $walk
		);

		return $this->persistResult(run: $run, result: $result);
	}//end advanceStream()

	/**
	 * Make an unattributed refusal visible on the flow, and stop a dead schedule.
	 *
	 * A logged warning is not a control surface. `FlowDeadEnd` already learned
	 * this: it writes status and status_message onto the flow so the author sees
	 * why without reading logs, and this follows the same path for the same
	 * reason.
	 *
	 * 🔴 A SCHEDULE is additionally DISABLED, and that asymmetry is deliberate.
	 * Every other trigger is driven by something that will notice — a person
	 * clicking, an event with a caller, a parent run that gets an exception. A
	 * schedule retries every tick forever with nobody watching, so leaving it
	 * enabled means it stays "on" in the UI while firing nothing, indefinitely.
	 * A schedule that quietly stops working is an instrument that lies; one that
	 * is switched off with a reason attached is a fault someone can act on.
	 *
	 * Best-effort by construction. A failure to record the refusal must not
	 * replace it — the caller still gets `FlowUnattributed`, which is the part
	 * that actually prevents the run.
	 *
	 * @param string            $flowId  The flow that could not be attributed.
	 * @param string            $trigger What started the run.
	 * @param FlowUnattributed  $refusal The refusal, whose message is stored.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegated-identity/spec.md
	 */
	private function recordUnattributed(string $flowId, string $trigger, FlowUnattributed $refusal): void {
		try {
			$mapper = $this->container->get('OCA\OpenRegister\Db\FlowMapper');
			$flow = $mapper->findByUuid($flowId);

			$flow->setStatus(Flow::STATUS_ERROR);
			$flow->setStatusMessage($refusal->getMessage());

			if ($trigger === 'schedule') {
				$flow->setEnabled(false);
			}

			$mapper->update($flow);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunService] Could not record the unattributed refusal on the flow: ' . $e->getMessage(),
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'flow' => $flowId,
				]
			);
		}//end try

	}//end recordUnattributed()


	/**
	 * The node context a walk starts from, before its live handles are added.
	 *
	 * `triggeredBy` carries PROVENANCE into the node context — who caused the run.
	 * Nothing else in lib/ ever wrote this key, so every trigger reached its nodes
	 * ownerless and only hand-injected contexts (tests, harnesses) worked. An
	 * explicit context value still wins for provenance, so a caller can record
	 * that a run was made on someone's behalf. See or#2158.
	 *
	 * 🔴 `runAs` carries AUTHORIZATION, and comes from the RUN — never from the
	 * context, even when the context names one. That asymmetry is the point.
	 * Context is caller-supplied at queue time, so honouring a context `runAs`
	 * would let any caller who can start a flow name the identity its steps
	 * execute as, which is precisely the widening ADR-099 forbids: identity may
	 * narrow along an invocation chain, and widening needs a grant checked
	 * against the caller, not a key in a payload.
	 *
	 * The run's value is re-read on EVERY walk, including a resume, so a run
	 * parked for weeks picks up its identity again rather than carrying a stale
	 * copy forward in its stored context.
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

		// Assignment, not coalesce: the run wins over anything the stored context
		// carries. See the docblock — a context-supplied acting identity would be
		// an authoring-time privilege escalation.
		$context['runAs'] = $run->getRunAs();

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
		$context[FlowStepReport::CONTEXT_KEY] = new FlowStepReport();

		// ATTRIBUTION. Read BEFORE the walk: the audit rows are written during
		// it, so the base has to be predicted. See {@see FlowStepHistory}.
		$context[FlowRunContext::CONTEXT_RUN] = (string)$run->getUuid();
		$context[FlowRunContext::CONTEXT_BASE] = $this->stepHistory()->baseFor(runUuid: (string)$run->getUuid());

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
		// 🔴 WHICH GRAPH WILL THIS RUN, AND IS IT SOUND — both answered here,
		// before anything else is decided. A run that cannot name the graph it
		// will execute must not exist, and the soundness check must judge THAT
		// graph rather than the flow's editable head. Both refusals cover all
		// six dispatch paths because all six funnel through this method.
		$version = (new FlowRunVersionPin($this->container, $this->logger))
			->requirePublishedAndSound(flowId: $flowId, trigger: $trigger);

		// A run is only runnable if it names WHOSE RIGHTS it executes with, and
		// only listable if it names WHICH tenant it belongs to.
		//
		// This is the general form of a defect this codebase has now patched
		// five times one call site at a time (or#2158: FlowMcpToolProvider,
		// FlowRunService::execute(), FlowRunController::test(), and
		// FlowScheduleService::fire(), which spells out that an ownerless run
		// makes every attribution-requiring node refuse — ObjectWriteNode
		// answers "this flow run has no owner"). Fixing it HERE covers all six
		// dispatch paths for the same reason the dead-end refusal lives here,
		// so the next path added inherits it instead of becoming the sixth.
		$attribution = (new FlowRunAttribution($this->container, $this->logger))
			->resolve(flowId: $flowId, user: $user, trigger: $trigger);

		// REFUSE, rather than record a run every node will reject one at a time.
		//
		// The old behaviour wrote the row and let each attribution-requiring
		// node fail separately, which surfaced as a per-node PERMISSION error
		// ("User 'Anonymous' does not have permission to…") for what is actually
		// a DISPATCH problem. That reads as "this user may not do this" when the
		// truth is "nobody was named", and the two want opposite fixes — one
		// sends you to the RBAC config, the other to the trigger.
		//
		// Refusing here also means a half-executed run cannot exist: without
		// this guard the nodes BEFORE the first object write still ran, so a
		// flow could send mail and then fail to record why.
		if ($attribution['user'] === null) {
			$refusal = new FlowUnattributed(flowId: $flowId, trigger: $trigger);

			$this->logger->warning(
				message: '[FlowRunService] Refused to queue an unattributed run',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'flow' => $flowId,
					'trigger' => $trigger,
				]
			);

			$this->recordUnattributed(flowId: $flowId, trigger: $trigger, refusal: $refusal);

			throw $refusal;
		}

		// 🔴 RE-RESOLVE, never trust the definition. The save-time check answered
		// "may this be saved", against the grants that existed then. A grant can
		// be revoked, expire, or be denied after a schedule is saved and before
		// it ever fires — and the whole point of a revocation is that the next
		// firing stops. Treating a stored trigger as standing authorization would
		// make revocation cosmetic for exactly the runs nobody is watching.
		$park = (new FlowDelegationCheck($this->container, $this->logger))->refuseIfRevoked(
			flowId: $flowId,
			trigger: $trigger,
			declaredBy: $attribution['declaredBy'],
			runAs: $attribution['user']
		);

		$run = (new FlowRunRow())->build(
			flowId: $flowId,
			subject: $subject,
			trigger: $trigger,
			context: $context,
			attribution: $attribution,
			version: $version?->getVersion()
		);

		return $this->parkIfAwaiting(run: $this->mapper->insert($run), park: $park);
	}//end queue()

	/**
	 * Park a freshly-inserted run whose delegation is still unanswered.
	 *
	 * Inserted FIRST, then parked. The row has to exist before it can carry a
	 * state, and it holding `queued` for the instant between the two is harmless:
	 * the worker reads in a separate process on a cron tick, not inside this
	 * call.
	 *
	 * Resolved through the container, like every other collaborator on this path:
	 * three test suites build this service by hand, and a new constructor
	 * parameter would shift every later slot for them. Not a new fail-open —
	 * {@see FlowDelegationCheck} already resolved the same service to get here.
	 *
	 * @param FlowRun    $run  The inserted run.
	 * @param array|null $park Park instructions, or null when the run may proceed.
	 *
	 * @return FlowRun The run, parked or untouched.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function parkIfAwaiting(FlowRun $run, ?array $park): FlowRun {
		if ($park === null) {
			return $run;
		}

		$parking = new FlowConsentParking(
			runs: $this->mapper,
			delegation: $this->container->get(DelegationService::class),
			logger: $this->logger
		);

		return $parking->park(
			run: $run,
			declaredBy: $park['principal'],
			runAs: $park['actingAs'],
			reason: $park['reason']
		);
	}//end parkIfAwaiting()

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

		// 🔴 THE RUN'S PIN OUTRANKS THE CALLER'S DOCUMENT. Four call sites hand
		// a flow document in, and three of them resolved it LIVE — a pinned run
		// reached the engine and then walked the current graph anyway, which is
		// the exact defect versioning exists to remove. Enforcing it HERE covers
		// all four, so the next caller inherits the rule instead of becoming the
		// fifth exception.
		$pinned = (new FlowPublishedGraph($this->container))->overlayOnto(run: $run, live: $flow);

		if ($pinned === null) {
			return $this->failUnresolvableVersion(run: $run);
		}

		$flow = $pinned;

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
		$walk = $this->streamWalkFor(run: $run, flow: $flow);

		try {
			$result = $this->engine->run(
				flow: $flow,
				store: new FlowRunMarkingStore(run: $run),
				subject: $subject,
				dispatcher: new RegistryStepDispatcher(registry: $this->registry, guard: $guard),
				context: $context,
				items: $items,
				startAt: $start,
				streams: $walk
			);
		} catch (FlowRunExpired $e) {
			$this->releaseWalk(walk: $walk);
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
			$this->releaseWalk(walk: $walk);
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
	 * Fail a run whose pinned version cannot be resolved.
	 *
	 * 🔴 FAIL, NEVER SUBSTITUTE. The version is gone — the flow was deleted,
	 * its app removed, or the row is absent. Promoting the run onto the newest
	 * published version would silently change what it does halfway through:
	 * its marking, its taken decisions and its log all belong to the version it
	 * started on. The message names the VERSION, which is what distinguishes it
	 * from "no app provides this flow" — the two want different fixes, and an
	 * operator has to be able to tell them apart.
	 *
	 * @param FlowRun $run The run to fail.
	 *
	 * @return FlowRun The failed run.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	private function failUnresolvableVersion(FlowRun $run): FlowRun {
		$run->setStatus(FlowRun::STATUS_FAILED);
		$run->setError(sprintf(
			'Version %s of flow "%s" could not be resolved, so this run cannot continue. '
			. 'It has NOT been moved to another version.',
			(string)($run->getFlowVersion() ?? 'unknown'),
			(string)$run->getFlowId()
		));

		$this->mapper->update($run);

		return $run;

	}//end failUnresolvableVersion()


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
		$this->stepHistory()->record(run: $run, entries: (array)($result['log'] ?? []));

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

}//end class
