<?php

/**
 * The lifecycle of a bulk action job: create, commit, cancel, retry.
 *
 * A bulk action over four hundred statutory cases is unrecoverable when it
 * is a client-side loop. Here it is one named job: previewed before it
 * commits, run in the background with progress, reporting a per-object
 * outcome, cancellable, and carrying the reason the operator typed.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\JobHandles;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\IdCursor;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OutcomeFilter;
use OCA\OpenRegister\Db\QueryPage;
use OCA\OpenRegister\Exception\BulkJobRefusedException;

/**
 * Class BulkJobService
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The lifecycle is the seam
 * between the action catalogue, the selection, the queue and the job record.
 */
class BulkJobService implements BulkJobServiceInterface {

	/**
	 * The app id every config key is stored under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * The instance ceiling on the largest selection one job may carry.
	 *
	 * @var string
	 */
	public const CEILING_KEY = 'bulk_job_max_selection';

	/**
	 * The default ceiling when the instance names none.
	 *
	 * @var int
	 */
	public const CEILING_DEFAULT = 1000;

	/**
	 * The instance ceiling on how much undo data one job may store.
	 *
	 * In bytes of encoded prior and applied values across every member. The
	 * bound is explicit because an unbounded undo buffer is a second copy of
	 * the register (D-2).
	 *
	 * @var string
	 */
	public const UNDO_CEILING_KEY = 'bulk_job_max_undo_bytes';

	/**
	 * The default undo ceiling: one mebibyte.
	 *
	 * @var int
	 */
	public const UNDO_CEILING_DEFAULT = 1048576;

	/**
	 * How many members one background batch walks.
	 *
	 * @var string
	 */
	public const BATCH_SIZE_KEY = 'bulk_job_batch_size';

	/**
	 * The default batch size.
	 *
	 * @var int
	 */
	public const BATCH_SIZE_DEFAULT = 25;

	/**
	 * Constructor.
	 *
	 * @param BulkJobStore $store The job and member tables, the background queue and the log.
	 * @param BulkJobActions $actions The action catalogue, the selection resolver and the executor.
	 * @param BulkJobEnvironment $environment The object service for the job's actor, the actor lookup and the instance settings.
	 */
	public function __construct(
		private readonly BulkJobStore $store,
		private readonly BulkJobActions $actions,
		private readonly BulkJobEnvironment $environment,
	) {
	}//end __construct()

	/**
	 * The largest selection one job may carry on this instance.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getCeiling(): int {
		$ceiling = $this->environment->appConfig->getValueInt(self::APP_ID, self::CEILING_KEY, self::CEILING_DEFAULT);

		if ($ceiling < 1) {
			return self::CEILING_DEFAULT;
		}

		return $ceiling;
	}//end getCeiling()

	/**
	 * How many members one background batch walks.
	 *
	 * @return int The batch size.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getBatchSize(): int {
		$size = $this->environment->appConfig->getValueInt(self::APP_ID, self::BATCH_SIZE_KEY, self::BATCH_SIZE_DEFAULT);

		if ($size < 1) {
			return self::BATCH_SIZE_DEFAULT;
		}

		return $size;
	}//end getBatchSize()

	/**
	 * How much undo data one job may store on this instance, in bytes.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getUndoCeiling(): int {
		$ceiling = $this->environment->appConfig->getValueInt(self::APP_ID, self::UNDO_CEILING_KEY, self::UNDO_CEILING_DEFAULT);

		if ($ceiling < 1) {
			return self::UNDO_CEILING_DEFAULT;
		}

		return $ceiling;
	}//end getUndoCeiling()

	/**
	 * The action a job runs: its id and its parameters.
	 *
	 * @param string               $actionId   The action to run.
	 * @param array<string, mixed> $parameters The action's parameters.
	 *
	 * @return BulkJobActionCall The call.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function actionCall(string $actionId, array $parameters): BulkJobActionCall {
		return new BulkJobActionCall(actionId: $actionId, parameters: $parameters);
	}//end actionCall()

	/**
	 * The objects a job runs on: the criteria, and the register and schema they live in.
	 *
	 * @param array<string, mixed> $criteria   Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param int|null             $registerId The register the selection lives in.
	 * @param int|null             $schemaId   The schema the selection lives in.
	 *
	 * @return BulkJobSelection The selection.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function selection(array $criteria, ?int $registerId, ?int $schemaId): BulkJobSelection {
		return new BulkJobSelection(criteria: $criteria, registerId: $registerId, schemaId: $schemaId);
	}//end selection()

	/**
	 * Create a job from what the operator asked for, rehearse it, and write nothing.
	 *
	 * @param BulkJobRequest $request The action, the selection, the actor and the reason.
	 *
	 * @return BulkJob The previewed job.
	 *
	 * @throws InvalidArgumentException When the action, its parameters or the
	 *                                  register and schema do not resolve.
	 * @throws BulkJobRefusedException When the ceiling or a guard refuses the selection.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function createFromRequest(BulkJobRequest $request): BulkJob {
		return $this->preview(request: $request);
	}//end createFromRequest()

	/**
	 * Resolve, guard and rehearse a request, and write the previewed job.
	 *
	 * @param BulkJobRequest $request What the operator asked for.
	 *
	 * @return BulkJob The previewed job.
	 *
	 * @throws InvalidArgumentException When the action, its parameters or the
	 *                                  register and schema do not resolve.
	 * @throws BulkJobRefusedException When the ceiling or a guard refuses the selection.
	 */
	private function preview(BulkJobRequest $request): BulkJob {
		$action = $this->actions->registry->get(id: $request->call->actionId);
		$action->validateParameters(parameters: $request->call->parameters);
		$this->guards()->assertScope(registerId: $request->selection->registerId, schemaId: $request->selection->schemaId);

		$ceiling  = $this->getCeiling();
		$resolved = $this->resolve(selection: $request->selection, ceiling: $ceiling);
		$uuids    = $resolved->uuids;

		$this->guards()->assertCeiling(count: count($uuids), ceiling: $ceiling);

		$objects = $this->actions->resolver->hydrate(uuids: $uuids, scope: $request->selection);
		$this->actions->executor->assertGuards(action: $action, objects: $objects);
		$this->guards()->assertUndoCeiling(job: new RehearsedJob(action: $action, objects: $objects, parameters: $request->call->parameters));

		$job = $this->store->jobMapper->createFromArray(
			array_merge(
				$this->previewRow(request: $request, resolved: $resolved),
				$this->reversalFields(action: $action)
			)
		);

		$this->actions->executor->writePreviewMembers(
			pass: new BulkJobPass(
				job: $job,
				action: $action,
				actor: $this->environment->userManager->get($request->actorUid),
				objects: $objects
			),
			uuids: $uuids
		);

		// The cursor is still zero, so refreshCounts reports nothing walked
		// while reporting what the rehearsal found.
		$this->actions->executor->refreshCounts(job: $job);

		return $this->store->jobMapper->save($job);
	}//end preview()

	/**
	 * Which kind a selection is and the uuids it stands for.
	 *
	 * @param BulkJobSelection $selection The caller's selection.
	 * @param int              $ceiling   The largest selection a job may carry.
	 *
	 * @return ResolvedSelection The resolved selection.
	 *
	 * @throws InvalidArgumentException When the selection is neither an id list nor a query.
	 */
	private function resolve(BulkJobSelection $selection, int $ceiling): ResolvedSelection {
		$kind  = $this->selectionTypeOf(selection: $selection->criteria);
		$uuids = match ($kind) {
			BulkJob::SELECTION_IDS => $this->actions->resolver->idsOf(criteria: $selection->criteria),
			default => $this->actions->resolver->queryUuids(selection: $selection, ceiling: $ceiling),
		};

		return new ResolvedSelection(selection: $selection, kind: $kind, uuids: $uuids);
	}//end resolve()

	/**
	 * The previewed job row, up to its reversal fields.
	 *
	 * @param BulkJobRequest    $request  What the operator asked for.
	 * @param ResolvedSelection $resolved The selection's kind and uuids.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function previewRow(BulkJobRequest $request, ResolvedSelection $resolved): array {
		return [
			'action' => $request->call->actionId,
			'parameters' => $request->call->parameters,
			'selectionType' => $resolved->kind,
			'selection' => $resolved->stored(),
			'registerId' => $request->selection->registerId,
			'schemaId' => $request->selection->schemaId,
			'justification' => $request->justification,
			'state' => BulkJob::STATE_PREVIEWED,
			'total' => count($resolved->uuids),
			'report' => ['selection' => ['kind' => $resolved->kind, 'countAtCreation' => count($resolved->uuids)]],
			'startedBy' => $request->actorUid,
		];
	}//end previewRow()

	/**
	 * The reversal window of a reversible action and its provisional deadline.
	 *
	 * Provisional: the preview has to be able to NAME the window before the
	 * job commits, and the executor re-stamps this the moment the job stops
	 * writing.
	 *
	 * @param BulkActionInterface $action The action.
	 *
	 * @return array{reversalWindow: int|null, reversibleUntil: DateTime|null} Both null for an irreversible action.
	 */
	private function reversalFields(BulkActionInterface $action): array {
		if (($action instanceof ReversibleBulkActionInterface) === false) {
			return ['reversalWindow' => null, 'reversibleUntil' => null];
		}

		$window = $action->getReversalWindow();

		return [
			'reversalWindow' => $window,
			'reversibleUntil' => (new DateTime())->modify('+'.$window.' seconds'),
		];
	}//end reversalFields()

	/**
	 * Commit a previewed job: re-resolve, report the delta, queue the work.
	 *
	 * @param BulkJob $job The previewed job.
	 * @param string|null $justification A reason given at commit time.
	 *
	 * @return BulkJob The running job.
	 *
	 * @throws BulkJobRefusedException When the action needs a reason and has none.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function commit(BulkJob $job, ?string $justification = null): BulkJob {
		if ($justification !== null && trim($justification) !== '') {
			$job->setJustification($justification);
		}

		$action = $this->actions->registry->get(id: (string)$job->getAction());
		$this->guards()->assertJustification(action: $action, job: $job);

		if ($job->getSelectionType() === BulkJob::SELECTION_QUERY) {
			$this->reconcileQuerySelection(job: $job, action: $action);
		}

		$job->setState(BulkJob::STATE_RUNNING);
		$job->setCursor(0);
		$saved = $this->store->jobMapper->save($job);

		$this->store->jobList->add(JobHandles::BULK_JOB_RUNNER, ['job_id' => $saved->getId()]);

		return $saved;
	}//end commit()

	/**
	 * Ask a running job to stop before its next object.
	 *
	 * @param BulkJob $job The job to cancel.
	 *
	 * @return BulkJob The job, cancelling or cancelled.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function cancel(BulkJob $job): BulkJob {
		if ($job->getState() === BulkJob::STATE_RUNNING) {
			$job->setState(BulkJob::STATE_CANCELLING);

			return $this->store->jobMapper->save($job);
		}

		// A previewed job has never run and a paused one has no batch in
		// flight, so neither needs the `cancelling` handshake: there is no
		// runner to notice it. Cancelling straight through matters because
		// the alternative is returning the job unchanged, which reads on the
		// console as a cancel that worked and did nothing.
		if (in_array($job->getState(), [BulkJob::STATE_PREVIEWED, BulkJob::STATE_PAUSED], true) === true) {
			$job->setState(BulkJob::STATE_CANCELLED);

			return $this->store->jobMapper->save($job);
		}

		return $job;
	}//end cancel()

	/**
	 * Hold a running job where it stands, keeping its cursor.
	 *
	 * The batch already in flight finishes; the runner then reads a state
	 * that is not `running` and does not re-enqueue itself, which is what
	 * makes the pause hold rather than merely being recorded. Nothing is
	 * rolled back, so resuming carries on at the same member.
	 *
	 * A pause is not a cancel: the members that were never walked stay
	 * pending, and the job keeps its place in `ACTIVE_STATES`.
	 *
	 * @param BulkJob $job The running job.
	 *
	 * @return BulkJob The paused job.
	 *
	 * @throws BulkJobRefusedException When the job is not running.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function pause(BulkJob $job): BulkJob {
		if ($job->getState() !== BulkJob::STATE_RUNNING) {
			throw new BulkJobRefusedException(
				message: 'Only a running job can be paused. This one is '.$job->getState().'.',
				reason: 'not-pausable',
				details: ['state' => $job->getState()]
			);
		}

		$job->setState(BulkJob::STATE_PAUSED);

		return $this->store->jobMapper->save($job);
	}//end pause()

	/**
	 * Set a paused job running again from the member it stopped at.
	 *
	 * The cursor is left alone on purpose: a resume continues, it does not
	 * restart, so an applied member is never walked twice. Re-enqueueing is
	 * the half that matters, because pausing took the job out of the queue by
	 * letting the runner fall through without adding itself back.
	 *
	 * @param BulkJob $job The paused job.
	 *
	 * @return BulkJob The running job.
	 *
	 * @throws BulkJobRefusedException When the job is not paused.
	 *
	 * @spec openspec/specs/operations-console/spec.md#requirement-a-run-is-started-again-from-the-console-once-req-aoc-002
	 */
	public function resume(BulkJob $job): BulkJob {
		if ($job->getState() !== BulkJob::STATE_PAUSED) {
			throw new BulkJobRefusedException(
				message: 'Only a paused job can be resumed. This one is '.$job->getState().'.',
				reason: 'not-resumable',
				details: ['state' => $job->getState()]
			);
		}

		$job->setState(BulkJob::STATE_RUNNING);
		$saved = $this->store->jobMapper->save($job);

		$this->store->jobList->add(JobHandles::BULK_JOB_RUNNER, ['job_id' => $saved->getId()]);

		return $saved;
	}//end resume()

	/**
	 * Retry a job that stopped part way, without repeating a member.
	 *
	 * @param BulkJob $job The stopped job.
	 *
	 * @return BulkJob The running job.
	 *
	 * @throws BulkJobRefusedException When the job has nothing to retry.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function retry(BulkJob $job): BulkJob {
		$retryable = [BulkJob::STATE_FAILED, BulkJob::STATE_CANCELLED, BulkJob::STATE_COMPLETED];

		if (in_array($job->getState(), $retryable, true) === false) {
			throw new BulkJobRefusedException(
				message: 'Only a job that has stopped can be retried. This one is '.$job->getState().'.',
				reason: 'not-retryable',
				details: ['state' => $job->getState()]
			);
		}

		$remaining = $this->store->memberMapper->findPendingBatch(jobId: (int)$job->getId(), cursor: new IdCursor(afterId: 0, limit: 1));

		if ($remaining === []) {
			throw new BulkJobRefusedException(
				message: 'Every member of this job has already been applied, so there is nothing to retry.',
				reason: 'nothing-to-retry',
				details: ['applied' => $job->getApplied()]
			);
		}

		$report = ($job->getReport() ?? []);
		$report['retries'] = ((int)($report['retries'] ?? 0) + 1);
		$report['skippedAsAlreadyApplied'] = $job->getApplied();

		$job->setReport($report);
		$job->setState(BulkJob::STATE_RUNNING);
		$job->setCursor(0);
		$saved = $this->store->jobMapper->save($job);

		$this->store->jobList->add(JobHandles::BULK_JOB_RUNNER, ['job_id' => $saved->getId()]);

		return $saved;
	}//end retry()

	/**
	 * Walk one batch of a running job, as the actor who created it.
	 *
	 * @param BulkJob $job The running job.
	 * @param int $batchSize How many members to walk.
	 *
	 * @return bool True when members remain.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function processBatch(BulkJob $job, int $batchSize): bool {
		$actor = $this->environment->userManager->get((string)$job->getStartedBy());

		if ($actor === null) {
			$this->store->logger->error(
				message: '[BulkJobService] The job\'s actor no longer exists',
				context: ['job' => $job->getUuid(), 'actor' => $job->getStartedBy()]
			);

			$job->setState(BulkJob::STATE_FAILED);
			$report = ($job->getReport() ?? []);
			$report['fatal'] = 'the account that created this job no longer exists';
			$job->setReport($report);
			$this->store->jobMapper->save($job);

			return false;
		}

		return $this->environment->objectService->runAs(
			$actor,
			fn (): bool => $this->actions->executor->processBatch(job: $job, batchSize: $batchSize)
		);
	}//end processBatch()

	/**
	 * A page of a job's per-object outcomes.
	 *
	 * @param BulkJob $job The job.
	 * @param OutcomeFilter|null $filter The outcome to keep (any when null) and the page; the first 100 when null.
	 *
	 * @return BulkJobMember[] The members.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function members(BulkJob $job, ?OutcomeFilter $filter=null): array {
		$filter ??= new OutcomeFilter(outcome: null, page: new QueryPage(limit: 100, offset: 0));
		return $this->store->memberMapper->findByJob(jobId: (int)$job->getId(), filter: $filter);
	}//end members()

	/**
	 * Re-resolve a query selection and report what changed since creation.
	 *
	 * @param BulkJob $job The job being committed.
	 * @param BulkActionInterface $action The action.
	 *
	 * @return void
	 */
	private function reconcileQuerySelection(BulkJob $job, BulkActionInterface $action): void {
		$selection = new BulkJobSelection(criteria: ($job->getSelection() ?? []), registerId: $job->getRegisterId(), schemaId: $job->getSchemaId());
		$current   = $this->actions->resolver->queryUuids(selection: $selection, ceiling: $this->getCeiling());

		$known = $this->store->memberMapper->findUuidsByJob(jobId: (int)$job->getId());
		$added = array_values(array_diff($current, $known));
		$gone = array_values(array_diff($known, $current));

		$report = ($job->getReport() ?? []);
		$report['delta'] = [
			'countAtCreation' => $job->getTotal(),
			'countAtCommit' => count($current),
			'added' => count($added),
			'removed' => count($gone),
		];
		$job->setReport($report);

		if ($added === []) {
			return;
		}

		$objects = $this->actions->resolver->hydrate(uuids: $added, scope: $selection);

		$this->actions->executor->writeAddedMembers(
			pass: new BulkJobPass(
				job: $job,
				action: $action,
				actor: $this->environment->userManager->get((string)$job->getStartedBy()),
				objects: $objects
			),
			uuids: $added
		);

		$job->setTotal(($job->getTotal() + count($added)));
	}//end reconcileQuerySelection()

	/**
	 * Which of the two kinds of selection the caller sent.
	 *
	 * @param array<string, mixed> $selection The selection.
	 *
	 * @return string One of the BulkJob::SELECTION_* constants.
	 *
	 * @throws InvalidArgumentException When it is neither.
	 */
	private function selectionTypeOf(array $selection): string {
		$hasIds = self::holdsList(selection: $selection, key: 'ids');
		$hasQuery = self::holdsList(selection: $selection, key: 'query');

		if ($hasIds === true && $hasQuery === true) {
			throw self::ambiguousSelection();
		}

		if ($hasIds === true) {
			return BulkJob::SELECTION_IDS;
		}

		if ($hasQuery === true) {
			return BulkJob::SELECTION_QUERY;
		}

		throw self::emptySelection();
	}//end selectionTypeOf()

	/**
	 * Whether the selection carries the key as an array.
	 *
	 * @param array<string, mixed> $selection The selection.
	 * @param string               $key       `ids` or `query`.
	 *
	 * @return bool
	 */
	private static function holdsList(array $selection, string $key): bool {
		return (isset($selection[$key]) === true && is_array($selection[$key]) === true);
	}//end holdsList()

	/**
	 * The refusal of a selection that is both a list of ids and a query.
	 *
	 * @return InvalidArgumentException
	 */
	private static function ambiguousSelection(): InvalidArgumentException {
		return new InvalidArgumentException(
			'A selection is either a list of ids or a query, never both. Send one of the two.'
		);
	}//end ambiguousSelection()

	/**
	 * The refusal of a selection that is neither a list of ids nor a query.
	 *
	 * @return InvalidArgumentException
	 */
	private static function emptySelection(): InvalidArgumentException {
		return new InvalidArgumentException(
			'A selection needs either an ids array or a query object, so the job can say which of the two it holds.'
		);
	}//end emptySelection()

	/**
	 * The refusals a job has to get past, built with this instance's ceiling.
	 *
	 * @return BulkJobGuards The guards.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	private function guards(): BulkJobGuards {
		return new BulkJobGuards(undoCeiling: $this->getUndoCeiling());
	}//end guards()

}//end class
