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
use OCA\OpenRegister\BackgroundJob\BulkJobRunner;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\ObjectEntity;
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
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection.
	 * Each of the ten is a collaborator the lifecycle genuinely uses, and
	 * bundling them behind a locator would hide the dependencies rather than
	 * remove them.
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
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function getBatchSize(): int {
		$size = $this->appConfig->getValueInt(self::APP_ID, self::BATCH_SIZE_KEY, self::BATCH_SIZE_DEFAULT);

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
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	public function getUndoCeiling(): int {
		$ceiling = $this->appConfig->getValueInt(self::APP_ID, self::UNDO_CEILING_KEY, self::UNDO_CEILING_DEFAULT);

		if ($ceiling < 1) {
			return self::UNDO_CEILING_DEFAULT;
		}

		return $ceiling;
	}//end getUndoCeiling()

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
		$this->assertUndoCeiling(action: $action, objects: $objects, parameters: $parameters);

		$window = null;
		$until = null;
		if ($action instanceof ReversibleBulkActionInterface) {
			$window = $action->getReversalWindow();
			// Provisional: the preview has to be able to NAME the window before
			// the job commits, and the executor re-stamps this the moment the
			// job stops writing.
			$until = (new DateTime())->modify('+'.$window.' seconds');
		}

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
				'reversalWindow' => $window,
				'reversibleUntil' => $until,
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
	 * Undo a job: a new job, over the same members, writing the prior values.
	 *
	 * Deliberately NOT a rollback. The inverse is an ordinary bulk job whose
	 * selection is the members the original actually wrote and whose
	 * per-member write is the recorded prior value, so there is one execution
	 * path, one ceiling, one preview, one audit shape, and the reversal is
	 * itself reversible (D-1).
	 *
	 * It is created, not committed. The caller previews it like any other job
	 * and commits it, which is what makes the members it will skip readable
	 * before anything is written.
	 *
	 * @param BulkJob     $original      The job to undo.
	 * @param string      $actorUid      The uid of the person undoing it.
	 * @param string|null $justification The reason they typed.
	 *
	 * @return BulkJob The previewed reversal.
	 *
	 * @throws BulkJobRefusedException When the job cannot be undone, naming the reason.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	public function reverse(BulkJob $original, string $actorUid, ?string $justification = null): BulkJob {
		$this->assertReversible(job: $original);

		$uuids = $this->memberMapper->findWrittenUuidsByJob(jobId: (int)$original->getId());

		if ($uuids === []) {
			throw new BulkJobRefusedException(
				message: 'This job wrote nothing, so there is nothing to undo.',
				reason: 'nothing-to-reverse',
				details: ['jobId' => $original->getId()]
			);
		}

		$reversal = $this->create(
			actionId: RestorePriorValuesAction::ID,
			parameters: [RestorePriorValuesAction::PARAM_JOB => (int)$original->getId()],
			selection: ['ids' => $uuids],
			justification: $justification,
			actorUid: $actorUid,
			registerId: $original->getRegisterId(),
			schemaId: $original->getSchemaId()
		);

		$report = ($reversal->getReport() ?? []);
		$report['reverses'] = [
			'jobId' => $original->getId(),
			'jobUuid' => $original->getUuid(),
			'action' => $original->getAction(),
			'written' => count($uuids),
		];

		$reversal->setReversesJobId((int)$original->getId());
		$reversal->setReport($report);
		$reversal = $this->jobMapper->save($reversal);

		// The original names its reversal too, so a job that has already been
		// undone says so rather than accepting a second one.
		$original->setReversedByJobId((int)$reversal->getId());
		$this->jobMapper->save($original);

		return $reversal;
	}//end reverse()

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
	 * Refuse a job that cannot be undone, naming which of the reasons it is.
	 *
	 * @param BulkJob $job The job to undo.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the job cannot be undone.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	private function assertReversible(BulkJob $job): void {
		$finished = [BulkJob::STATE_COMPLETED, BulkJob::STATE_CANCELLED, BulkJob::STATE_FAILED];

		if (in_array($job->getState(), $finished, true) === false) {
			throw new BulkJobRefusedException(
				message: 'Only a job that has stopped can be undone. This one is '.$job->getState().'. '
					.'Cancel it first, then undo what it managed to write.',
				reason: 'not-finished',
				details: ['state' => $job->getState()]
			);
		}

		if ($job->isReversible() === false) {
			throw new BulkJobRefusedException(
				message: 'The action '.$job->getAction().' is not reversible, so this job recorded nothing to go '
					.'back to. Nothing was undone.',
				reason: 'not-reversible',
				details: ['action' => $job->getAction()]
			);
		}

		$this->assertInsideWindow(job: $job);
		$this->assertNotAlreadyReversed(job: $job);
	}//end assertReversible()

	/**
	 * Refuse a reversal asked for after the window closed.
	 *
	 * @param BulkJob $job The job to undo.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the window has passed.
	 */
	private function assertInsideWindow(BulkJob $job): void {
		$until = $job->getReversibleUntil();

		if ($until === null || $until >= new DateTime()) {
			return;
		}

		throw new BulkJobRefusedException(
			message: 'The action '.$job->getAction().' can be undone for '.(int)$job->getReversalWindow()
				.' seconds after it runs, and that window closed on '.$until->format(DateTime::ATOM).'.',
			reason: 'window-expired',
			details: ['reversibleUntil' => $until->format(DateTime::ATOM), 'window' => $job->getReversalWindow()]
		);
	}//end assertInsideWindow()

	/**
	 * Refuse a second reversal of a job that already has a live one.
	 *
	 * A cancelled reversal does not count: it wrote nothing, and refusing
	 * because of it would strand the job it was meant to undo.
	 *
	 * @param BulkJob $job The job to undo.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When a live reversal already exists.
	 */
	private function assertNotAlreadyReversed(BulkJob $job): void {
		$reversalId = $job->getReversedByJobId();

		if ($reversalId === null) {
			return;
		}

		try {
			$existing = $this->jobMapper->find($reversalId);
		} catch (\Throwable $exception) {
			return;
		}

		if ($existing->getState() === BulkJob::STATE_CANCELLED) {
			return;
		}

		throw new BulkJobRefusedException(
			message: 'This job is already being undone by job '.$reversalId.'. Read that one rather than starting a '
				.'second reversal over the same objects.',
			reason: 'already-reversed',
			details: ['reversalJobId' => $reversalId, 'state' => $existing->getState()]
		);
	}//end assertNotAlreadyReversed()

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
	 * Refuse a job whose recorded prior values would outgrow the undo ceiling.
	 *
	 * Measured at CREATION, over the rehearsed selection, because that is the
	 * only moment at which refusing costs nobody anything. Half way through a
	 * commit the choice is between an unbounded buffer and a job that silently
	 * stops recording what it would take to go back, and the second is the
	 * failure this change exists to prevent.
	 *
	 * @param BulkActionInterface           $action     The action.
	 * @param array<string, ObjectEntity>   $objects    The hydrated selection.
	 * @param array<string, mixed>          $parameters The job's parameters.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the job would store too much.
	 *
	 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
	 */
	private function assertUndoCeiling(BulkActionInterface $action, array $objects, array $parameters): void {
		if (($action instanceof ReversibleBulkActionInterface) === false) {
			return;
		}

		$ceiling = $this->getUndoCeiling();
		$bytes = 0;

		foreach ($objects as $object) {
			$plan = $action->reversalPlanFor(object: $object, parameters: $parameters);
			$encoded = json_encode($plan);

			if ($encoded === false) {
				continue;
			}

			$bytes += strlen($encoded);

			if ($bytes <= $ceiling) {
				continue;
			}

			throw new BulkJobRefusedException(
				message: 'This instance stores at most '.$ceiling.' bytes of undo data per bulk job, and this one '
					.'would store more. Narrow the selection, write fewer properties, or ask an administrator to '
					.'raise the ceiling.',
				reason: 'undo-ceiling',
				details: ['ceiling' => $ceiling, 'objects' => count($objects)]
			);
		}//end foreach
	}//end assertUndoCeiling()

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
