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

use InvalidArgumentException;
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\ObjectService;
use OCP\BackgroundJob\IJobList;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Class BulkJobService
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The lifecycle is the seam
 * between the action catalogue, the selection, the queue and the job record.
 */
class BulkJobService {

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
	 * @param BulkJobMapper $jobMapper Job persistence.
	 * @param BulkJobMemberMapper $memberMapper Member persistence.
	 * @param BulkActionRegistry $registry The catalogue of registered actions.
	 * @param BulkSelectionResolver $resolver Selection resolution.
	 * @param BulkJobExecutor $executor The one executor.
	 * @param ObjectService $objectService For running as the job's actor.
	 * @param IUserManager $userManager Actor lookup.
	 * @param IJobList $jobList The background queue.
	 * @param IAppConfig $appConfig Instance settings.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly BulkJobMapper $jobMapper,
		private readonly BulkJobMemberMapper $memberMapper,
		private readonly BulkActionRegistry $registry,
		private readonly BulkSelectionResolver $resolver,
		private readonly BulkJobExecutor $executor,
		private readonly ObjectService $objectService,
		private readonly IUserManager $userManager,
		private readonly IJobList $jobList,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The largest selection one job may carry on this instance.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getCeiling(): int {
		$ceiling = $this->appConfig->getValueInt(self::APP_ID, self::CEILING_KEY, self::CEILING_DEFAULT);

		if ($ceiling < 1) {
			return self::CEILING_DEFAULT;
		}

		return $ceiling;
	}//end getCeiling()

	/**
	 * How many members one background batch walks.
	 *
	 * @return int The batch size.
	 */
	public function getBatchSize(): int {
		$size = $this->appConfig->getValueInt(self::APP_ID, self::BATCH_SIZE_KEY, self::BATCH_SIZE_DEFAULT);

		if ($size < 1) {
			return self::BATCH_SIZE_DEFAULT;
		}

		return $size;
	}//end getBatchSize()

	/**
	 * Create a job, rehearse it, and write nothing.
	 *
	 * @param string $actionId The action to run.
	 * @param array<string, mixed> $parameters The action's parameters.
	 * @param array<string, mixed> $selection Either `{"ids": [...]}` or `{"query": {...}}`.
	 * @param string|null $justification The reason the operator typed.
	 * @param string $actorUid The actor's uid.
	 * @param int|null $registerId The register the selection lives in.
	 * @param int|null $schemaId The schema the selection lives in.
	 *
	 * @return BulkJob The previewed job.
	 *
	 * @throws InvalidArgumentException When the action, its parameters or the
	 *                                  register and schema do not resolve.
	 * @throws BulkJobRefusedException When the ceiling or a guard refuses the selection.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function create(
		string $actionId,
		array $parameters,
		array $selection,
		?string $justification,
		string $actorUid,
		?int $registerId = null,
		?int $schemaId = null
	): BulkJob {
		$action = $this->registry->get(id: $actionId);
		$action->validateParameters(parameters: $parameters);
		$this->assertScope(registerId: $registerId, schemaId: $schemaId);

		$selectionType = $this->selectionTypeOf(selection: $selection);
		$ceiling = $this->getCeiling();

		$uuids = $this->resolver->resolveUuids(
			selectionType: $selectionType,
			selection: $selection,
			registerId: $registerId,
			schemaId: $schemaId,
			ceiling: $ceiling
		);

		$this->assertCeiling(count: count($uuids), ceiling: $ceiling);

		$objects = $this->resolver->hydrate(uuids: $uuids, registerId: $registerId, schemaId: $schemaId);
		$this->executor->assertGuards(action: $action, objects: $objects);

		$job = $this->jobMapper->createFromArray(
			[
				'action' => $actionId,
				'parameters' => $parameters,
				'selectionType' => $selectionType,
				'selection' => $this->normaliseSelection(selection: $selection, selectionType: $selectionType, uuids: $uuids),
				'registerId' => $registerId,
				'schemaId' => $schemaId,
				'justification' => $justification,
				'state' => BulkJob::STATE_PREVIEWED,
				'total' => count($uuids),
				'report' => ['selection' => ['kind' => $selectionType, 'countAtCreation' => count($uuids)]],
				'startedBy' => $actorUid,
			]
		);

		$this->executor->writePreviewMembers(
			job: $job,
			uuids: $uuids,
			objects: $objects,
			action: $action,
			actor: $this->userManager->get($actorUid)
		);

		// The cursor is still zero, so refreshCounts reports nothing walked
		// while reporting what the rehearsal found.
		$this->executor->refreshCounts(job: $job);

		return $this->jobMapper->save($job);
	}//end create()

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
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function commit(BulkJob $job, ?string $justification = null): BulkJob {
		if ($justification !== null && trim($justification) !== '') {
			$job->setJustification($justification);
		}

		$action = $this->registry->get(id: (string)$job->getAction());
		$this->assertJustification(action: $action, job: $job);

		if ($job->getSelectionType() === BulkJob::SELECTION_QUERY) {
			$this->reconcileQuerySelection(job: $job, action: $action);
		}

		$job->setState(BulkJob::STATE_RUNNING);
		$job->setCursor(0);
		$saved = $this->jobMapper->save($job);

		$this->jobList->add(BulkJobRunner::class, ['job_id' => $saved->getId()]);

		return $saved;
	}//end commit()

	/**
	 * Ask a running job to stop before its next object.
	 *
	 * @param BulkJob $job The job to cancel.
	 *
	 * @return BulkJob The job, cancelling or cancelled.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function cancel(BulkJob $job): BulkJob {
		if ($job->getState() === BulkJob::STATE_RUNNING) {
			$job->setState(BulkJob::STATE_CANCELLING);

			return $this->jobMapper->save($job);
		}

		if ($job->getState() === BulkJob::STATE_PREVIEWED) {
			$job->setState(BulkJob::STATE_CANCELLED);

			return $this->jobMapper->save($job);
		}

		return $job;
	}//end cancel()

	/**
	 * Retry a job that stopped part way, without repeating a member.
	 *
	 * @param BulkJob $job The stopped job.
	 *
	 * @return BulkJob The running job.
	 *
	 * @throws BulkJobRefusedException When the job has nothing to retry.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
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

		$remaining = $this->memberMapper->findPendingBatch(jobId: (int)$job->getId(), afterId: 0, limit: 1);

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
		$saved = $this->jobMapper->save($job);

		$this->jobList->add(BulkJobRunner::class, ['job_id' => $saved->getId()]);

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
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function processBatch(BulkJob $job, int $batchSize): bool {
		$actor = $this->userManager->get((string)$job->getStartedBy());

		if ($actor === null) {
			$this->logger->error(
				message: '[BulkJobService] The job\'s actor no longer exists',
				context: ['job' => $job->getUuid(), 'actor' => $job->getStartedBy()]
			);

			$job->setState(BulkJob::STATE_FAILED);
			$report = ($job->getReport() ?? []);
			$report['fatal'] = 'the account that created this job no longer exists';
			$job->setReport($report);
			$this->jobMapper->save($job);

			return false;
		}

		return $this->objectService->runAs(
			$actor,
			fn (): bool => $this->executor->processBatch(job: $job, batchSize: $batchSize)
		);
	}//end processBatch()

	/**
	 * A page of a job's per-object outcomes.
	 *
	 * @param BulkJob $job The job.
	 * @param string|null $outcome Optional outcome filter.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return BulkJobMember[] The members.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function members(BulkJob $job, ?string $outcome = null, int $limit = 100, int $offset = 0): array {
		return $this->memberMapper->findByJob(
			jobId: (int)$job->getId(),
			outcome: $outcome,
			limit: $limit,
			offset: $offset
		);
	}//end members()

	/**
	 * Refuse a job that does not say which register and schema it acts on.
	 *
	 * The object search resolves its table from the register and the schema,
	 * and answers an EMPTY LIST rather than an error when it has neither. A
	 * job without a scope would therefore hydrate nothing, report every
	 * member as not visible, and look like a working job over an unlucky
	 * selection. Refusing it here is the difference between an error and a
	 * confident wrong answer.
	 *
	 * @param int|null $registerId The register.
	 * @param int|null $schemaId The schema.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When either is missing.
	 */
	private function assertScope(?int $registerId, ?int $schemaId): void {
		if ($registerId !== null && $schemaId !== null) {
			return;
		}

		throw new InvalidArgumentException(
			'A bulk job needs both a register and a schema. The object search resolves its table from the two, '
				.'and without them it answers an empty selection rather than an error.'
		);
	}//end assertScope()

	/**
	 * Refuse a selection larger than the instance ceiling.
	 *
	 * @param int $count The selection size.
	 * @param int $ceiling The ceiling.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the selection is too large.
	 */
	private function assertCeiling(int $count, int $ceiling): void {
		if ($count <= $ceiling) {
			return;
		}

		throw new BulkJobRefusedException(
			message: 'This instance allows at most '.$ceiling.' objects in one bulk job, and this selection holds '
				.$count.'. Narrow the selection, or ask an administrator to raise the ceiling.',
			reason: 'ceiling',
			details: ['ceiling' => $ceiling, 'count' => $count]
		);
	}//end assertCeiling()

	/**
	 * Refuse a commit with no reason where the action requires one.
	 *
	 * @param BulkActionInterface $action The action.
	 * @param BulkJob $job The job.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the reason is missing.
	 */
	private function assertJustification(BulkActionInterface $action, BulkJob $job): void {
		if ($action->requiresJustification() === false) {
			return;
		}

		$justification = (string)($job->getJustification() ?? '');

		if (trim($justification) !== '') {
			return;
		}

		throw new BulkJobRefusedException(
			message: 'The action '.$action->getId().' cannot be committed without a written reason. '
				.'Nothing was modified.',
			reason: 'justification-required',
			details: ['action' => $action->getId()]
		);
	}//end assertJustification()

	/**
	 * Re-resolve a query selection and report what changed since creation.
	 *
	 * @param BulkJob $job The job being committed.
	 * @param BulkActionInterface $action The action.
	 *
	 * @return void
	 */
	private function reconcileQuerySelection(BulkJob $job, BulkActionInterface $action): void {
		$current = $this->resolver->resolveUuids(
			selectionType: BulkJob::SELECTION_QUERY,
			selection: ($job->getSelection() ?? []),
			registerId: $job->getRegisterId(),
			schemaId: $job->getSchemaId(),
			ceiling: $this->getCeiling()
		);

		$known = $this->memberMapper->findUuidsByJob(jobId: (int)$job->getId());
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

		$objects = $this->resolver->hydrate(
			uuids: $added,
			registerId: $job->getRegisterId(),
			schemaId: $job->getSchemaId()
		);

		$this->executor->writePreviewMembers(
			job: $job,
			uuids: $added,
			objects: $objects,
			action: $action,
			actor: $this->userManager->get((string)$job->getStartedBy()),
			addedAtCommit: true
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
		$hasIds = (isset($selection['ids']) === true && is_array($selection['ids']) === true);
		$hasQuery = (isset($selection['query']) === true && is_array($selection['query']) === true);

		if ($hasIds === true && $hasQuery === true) {
			throw new InvalidArgumentException(
				'A selection is either a list of ids or a query, never both. Send one of the two.'
			);
		}

		if ($hasIds === true) {
			return BulkJob::SELECTION_IDS;
		}

		if ($hasQuery === true) {
			return BulkJob::SELECTION_QUERY;
		}

		throw new InvalidArgumentException(
			'A selection needs either an ids array or a query object, so the job can say which of the two it holds.'
		);
	}//end selectionTypeOf()

	/**
	 * The selection as the job stores it.
	 *
	 * @param array<string, mixed> $selection The caller's selection.
	 * @param string $selectionType The selection kind.
	 * @param array<int, string> $uuids The resolved uuids.
	 *
	 * @return array<string, mixed> The stored selection.
	 */
	private function normaliseSelection(array $selection, string $selectionType, array $uuids): array {
		if ($selectionType === BulkJob::SELECTION_QUERY) {
			return ['query' => ($selection['query'] ?? [])];
		}

		return ['ids' => $uuids];
	}//end normaliseSelection()
}//end class
