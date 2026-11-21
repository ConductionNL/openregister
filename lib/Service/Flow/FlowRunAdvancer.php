<?php

/**
 * Advance one queued run to wherever it can get to.
 *
 * Extracted from FlowRunWorker so the SAME code path serves both ways a run is
 * driven: the background worker draining the queue, and a synchronous run that
 * executes inline and answers with the finished run.
 *
 * That sharing is the point. A separate inline implementation would be a second
 * engine entry point that drifts — it would resolve the flow, the subject and
 * the payload seed slightly differently, and a flow would behave one way when a
 * human pressed Run and another way when cron picked it up.
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
 * @spec openspec/specs/flow-runs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Service\Rbac\ProcessRevealAuditInterface;
use OCA\OpenRegister\Service\Rbac\RevealingProcess;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Resolves what a run needs and hands it to the engine.
 *
 * @spec openspec/specs/flow-runs/spec.md
 */
class FlowRunAdvancer implements FlowRunAdvancerInterface {
	/**
	 * Executes the run.
	 *
	 * @var FlowRunServiceInterface
	 */
	private readonly FlowRunServiceInterface $runner;

	/**
	 * Resolves the flow and its subject.
	 *
	 * @var FlowLocator
	 */
	private readonly FlowLocator $resolvers;

	/**
	 * Records failures.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapperInterface $mapper    Persists a run that cannot proceed.
	 * @param FlowExecution          $execution Resolves the flow and its subject, executes the run and logs.
	 * @param ProcessRevealAuditInterface|null $processAudit Records, once per pass, that the run revealed audited values.
	 */
	public function __construct(
		private readonly FlowRunMapperInterface $mapper,
		FlowExecution $execution,
		private readonly ?ProcessRevealAuditInterface $processAudit = null,
	) {
		$this->runner    = $execution->runner;
		$this->resolvers = $execution->resolvers;
		$this->logger    = $execution->logger;
	}//end __construct()

	/**
	 * Advance one run, never letting its failure stop a batch.
	 *
	 * @param FlowRun $run The run to advance.
	 * @param boolean $rethrow Whether to rethrow instead of swallowing. The
	 *                         worker swallows so one poisoned run cannot stop
	 *                         the queue; a synchronous caller wants the error,
	 *                         because it is answering a request about THIS run.
	 *
	 * @return FlowRun The run, in whatever state it reached.
	 *
	 * @throws Throwable When $rethrow is true and the run could not be advanced.
	 *
	 * FlowItems::item is a pure value constructor with no state to inject; a
	 * factory collaborator would add a dependency to say the same thing.
	 *
	 * $rethrow selects who handles a failure, not what the method does. The cron
	 * worker must swallow and record it so one bad run cannot stop the queue; a
	 * synchronous run must let it out so the caller's HTTP response can carry it.
	 * Splitting that into two methods would duplicate the whole advance path to
	 * vary its final catch block.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/specs/flow-runs/spec.md
	 */
	public function advance(FlowRun $run, bool $rethrow = false): FlowRun {
		try {
			$pass = $this->resolvedPass(run: $run);
			if (is_string($pass) === true) {
				$run->setStatus(FlowRun::STATUS_FAILED);
				$run->setError($pass);
				$this->mapper->update($run);
				return $run;
			}

			// 🔴 THE PIN. The pass carries the LIVE flow definition — which is
			// correct for authorization (a revoked owner must stop the next
			// hop) and wrong for the graph. A run parked on a human step for a
			// week must finish the flow it started, not the one its author has
			// since edited. So the graph, and only the graph, comes from the
			// version this run was pinned to at queue time.
			// The pin itself is enforced inside FlowRunService::execute(), which
			// every path into the engine goes through. Checking it here as well
			// would be a second copy of the rule — and the two would drift.

			// A subjectless run walks over a bare holder and is seeded from
			// its payload instead of an object: a non-object trigger (a file, a
			// user) puts what it is about under `payload` on the run context, and
			// that becomes the first item, so the flow reads a file's path or a
			// user's id exactly as it would an object's fields. A subject that
			// named an object but could not be re-resolved (a deletion) takes the
			// same path, on the same payload slot.
			$seed = null;
			$payload = (array)(($run->getContext() ?? [])['payload'] ?? []);
			if ($this->walksPayload(pass: $pass) === true && $payload !== []) {
				$seed = [FlowItems::item(json: $payload)];
			}

			return $this->asRevealingProcess(
				run: $run,
				operation: fn (): FlowRun => $this->runner->executePass(pass: $pass, origin: new FlowRunOrigin(items: $seed))
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[FlowRunAdvancer] Failed to advance a run',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'run' => $run->getUuid(),
					'error' => $e->getMessage(),
				]
			);

			if ($rethrow === true) {
				throw $e;
			}

			return $run;
		}//end try

	}//end advance()

	/**
	 * Advance ONE stream of a suspended run in the calling request, within a budget.
	 *
	 * The completion side of a user task (flow-user-task-node, ADR-098 D9): the
	 * task on one branch was answered, and THAT branch may continue for the
	 * node's budget while its siblings are untouched. Resolution is the same
	 * as {@see self::advance()} so the two cannot drift on what a run needs;
	 * the walk itself is {@see FlowRunService::advanceStream()}, which scopes
	 * the stream collaborator, applies the run's version pin and honours the
	 * budget as a firing ceiling next to the run-wide one.
	 *
	 * Errors are NOT swallowed: the caller is a completion listener that has
	 * already committed the task and treats any failure here as "the worker
	 * takes over", so it wants to see the error to log it.
	 *
	 * @param FlowRun $run The suspended run.
	 * @param string $streamId The stream parked on the answered node.
	 * @param int|string $budget `0`, `N`, or `"all"`.
	 *
	 * @return FlowRun The run as it stands after the advance.
	 *
	 * @throws Throwable When the run could not be advanced.
	 *
	 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-the-advance-budget-says-how-far-a-completion-may-push-the-run
	 */
	public function advanceStream(FlowRun $run, string $streamId, int|string $budget): FlowRun {
		$pass = $this->resolvedPass(run: $run);
		if (is_string($pass) === true) {
			$run->setStatus(FlowRun::STATUS_FAILED);
			$run->setError($pass);

			return $this->mapper->update($run);
		}

		// A resumed walk reads its items from the run, never from a seed.
		return $this->asRevealingProcess(
			run: $run,
			operation: fn (): FlowRun => $this->runner->advanceBranch(
				pass: $pass,
				advance: new FlowStreamAdvance(streamId: $streamId, budget: $budget)
			)
		);
	}//end advanceStream()

	/**
	 * Run one pass of a flow run as a revealing process named after the run.
	 *
	 * @param FlowRun  $run       The run.
	 * @param callable $operation The pass.
	 *
	 * @return FlowRun The run after the pass.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	private function asRevealingProcess(FlowRun $run, callable $operation): FlowRun {
		if ($this->processAudit === null) {
			return $operation();
		}

		return $this->processAudit->run(run: new RevealingProcess(process: 'flow', runId: (string)$run->getUuid()), operation: $operation);
	}//end asRevealingProcess()

	/**
	 * Whether the run names a subject object.
	 *
	 * A run may legitimately have none: a manual or webhook run is seeded from its payload.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return bool
	 */
	private function hasSubject(FlowRun $run): bool {
		return trim((string)$run->getSubjectUuid()) !== '';
	}//end hasSubject()

	/**
	 * Whether the pass walks the run's payload instead of an object.
	 *
	 * True for a subjectless run, and for an object.deleted run whose subject
	 * could not be re-resolved and so walks a bare holder (see resolvedPass()).
	 *
	 * @param FlowRunPass $pass The pass.
	 *
	 * @return bool
	 */
	private function walksPayload(FlowRunPass $pass): bool {
		if ($this->hasSubject(run: $pass->run) === false) {
			return true;
		}

		return $pass->run->hasDeletedSubjectSnapshot() === true
			&& $pass->subject instanceof stdClass
			&& get_object_vars($pass->subject) === [];
	}//end walksPayload()

	/**
	 * The run's flow and subject as a pass, or why the run cannot proceed.
	 *
	 * A subjectless run gets a bare holder as its subject, since the marking
	 * store keeps the marking on the run itself ({@see FlowRunMarkingStore}).
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return FlowRunPass|string The pass, or the reason to fail the run with.
	 */
	private function resolvedPass(FlowRun $run): FlowRunPass|string {
		$flow = $this->resolvers->resolveFlow((string)$run->getFlowId());
		if ($flow === null) {
			// No installed app owns this flow: it was deleted, or the app that
			// stored it was removed. Fail the run rather than leave it queued forever.
			return sprintf('No app provides flow "%s" (deleted, or its app removed?).', $run->getFlowId());
		}

		$subject = new stdClass();
		if ($this->hasSubject(run: $run) === true) {
			$subject = $this->resolvers->resolveObject(
				ref: new ObjectRef(
					register: (string)$run->getSubjectRegister(),
					schema: (string)$run->getSubjectSchema(),
					id: (string)$run->getSubjectUuid()
				)
			);

			// An object.deleted run can never re-resolve its subject; it walks
			// the snapshot under `payload` instead (see FlowRun::hasDeletedSubjectSnapshot()).
			// Every other unresolved subject still fails the run.
			if ($subject === null && $run->hasDeletedSubjectSnapshot() === true) {
				$subject = new stdClass();
			}

			if ($subject === null) {
				return sprintf('Subject "%s" no longer exists.', $run->getSubjectUuid());
			}
		}

		return new FlowRunPass(run: $run, flow: $flow, subject: $subject);
	}//end resolvedPass()

}//end class
