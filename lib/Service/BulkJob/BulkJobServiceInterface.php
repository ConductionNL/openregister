<?php

/**
 * BulkJobServiceInterface: what callers outside the component use of BulkJobService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\BulkJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use InvalidArgumentException;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Db\OutcomeFilter;

/**
 * Create, run and steer bulk jobs over a selection of objects.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface BulkJobServiceInterface {

	/**
	 * The largest selection one job may carry on this instance.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getCeiling(): int;

	/**
	 * How many members one background batch walks.
	 *
	 * @return int The batch size.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getBatchSize(): int;

	/**
	 * How much undo data one job may store on this instance, in bytes.
	 *
	 * @return int The ceiling.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function getUndoCeiling(): int;

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
	public function actionCall(string $actionId, array $parameters): BulkJobActionCall;

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
	public function selection(array $criteria, ?int $registerId, ?int $schemaId): BulkJobSelection;

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
	public function createFromRequest(BulkJobRequest $request): BulkJob;

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
	public function commit(BulkJob $job, ?string $justification = null): BulkJob;

	/**
	 * Ask a running job to stop before its next object.
	 *
	 * @param BulkJob $job The job to cancel.
	 *
	 * @return BulkJob The job, cancelling or cancelled.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function cancel(BulkJob $job): BulkJob;

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
	public function pause(BulkJob $job): BulkJob;

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
	public function resume(BulkJob $job): BulkJob;

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
	public function retry(BulkJob $job): BulkJob;

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
	public function processBatch(BulkJob $job, int $batchSize): bool;

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
	public function members(BulkJob $job, ?OutcomeFilter $filter=null): array;
}//end interface
