<?php

/**
 * BulkJobMemberMapperInterface: what callers outside the Db component use of BulkJobMemberMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

/**
 * The methods of BulkJobMemberMapper that callers outside the Db component use.
 *
 * Callers type against this interface and resolve it from the container; the
 * registrar binds it to BulkJobMemberMapper.
 */
interface BulkJobMemberMapperInterface {

	/**
	 * Count the members of a job per outcome.
	 *
	 * @param int $jobId The job id.
	 *
	 * @return array<string, int> Outcome name to count.
	 */
	public function countByOutcome(int $jobId): array;

	/**
	 * How many members the commit has walked so far.
	 *
	 * Members are walked in id order and the job's cursor holds the last one,
	 * so the count of members at or below it is the honest position: it does
	 * not move when a preview outcome changes, and it restarts when a retry
	 * resets the cursor, which is what that run actually did.
	 *
	 * @param int $jobId The job id.
	 * @param int $cursor The job's cursor.
	 *
	 * @return int The number of members walked.
	 */
	public function countWalked(int $jobId, int $cursor): int;

	/**
	 * Create a member from an array of values.
	 *
	 * @param array<string, mixed> $data The member values.
	 *
	 * @return BulkJobMember The persisted member.
	 */
	public function createFromArray(array $data): BulkJobMember;

	/**
	 * Find the members of a job, optionally filtered by outcome.
	 *
	 * @param int $jobId The job id.
	 * @param OutcomeFilter|null $filter Optional outcome and page.
	 *
	 * @return BulkJobMember[] The members.
	 */
	public function findByJob(int $jobId, ?OutcomeFilter $filter = null): array;

	/**
	 * Find one member of a job by the object it covers.
	 *
	 * The reversal reads the original job's member row to learn what that job
	 * wrote and what it wrote over.
	 *
	 * @param int    $jobId      The job id.
	 * @param string $objectUuid The object's uuid.
	 *
	 * @return BulkJobMember|null The member, or null when the job never held it.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function findByJobAndObject(int $jobId, string $objectUuid): ?BulkJobMember;

	/**
	 * Find the next batch of members a commit still has to walk.
	 *
	 * Members that were actually WRITTEN are excluded, which is what makes a
	 * retry idempotent: a job that failed at member 300 of 400 resumes at 301
	 * rather than acting on the first 300 a second time (D-5). The test is
	 * `applied_at`, never the outcome column: a member the preview reports as
	 * "would apply" has not been written yet, and excluding it here would
	 * make the commit skip exactly the members it exists to act on.
	 *
	 * @param int      $jobId  The job id.
	 * @param IdCursor $cursor The members after this id, and the batch size.
	 *
	 * @return BulkJobMember[] The members still to walk.
	 */
	public function findPendingBatch(int $jobId, IdCursor $cursor): array;

	/**
	 * The uuids already held by a job, for the commit-time delta.
	 *
	 * @param int $jobId The job id.
	 *
	 * @return array<int, string> The member uuids.
	 */
	public function findUuidsByJob(int $jobId): array;

	/**
	 * The uuids of the members a job actually WROTE.
	 *
	 * The selection of a reversal. A member the job skipped, refused or never
	 * reached has nothing to undo, and putting it in the reversal would report
	 * a hundred members where twelve were written (D-1).
	 *
	 * The test is `applied_at`, never the outcome column: a previewed member
	 * carries outcome `applied` and has not been written.
	 *
	 * @param int $jobId The job id.
	 *
	 * @return array<int, string> The written members' uuids, in id order.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function findWrittenUuidsByJob(int $jobId): array;

	/**
	 * Persist a member's outcome, refreshing the updated timestamp.
	 *
	 * @param BulkJobMember $member The member to persist.
	 *
	 * @return BulkJobMember The updated member.
	 */
	public function save(BulkJobMember $member): BulkJobMember;
}//end interface
