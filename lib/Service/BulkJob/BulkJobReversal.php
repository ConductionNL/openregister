<?php

/**
 * The inverse of a bulk job: a new job that writes its prior values back.
 *
 * It lives beside {@see BulkJobService} rather than inside it because
 * undoing is its own lifecycle. It reuses the create path whole, so the
 * reversal is previewed, bounded by the same ceilings, walked by the same
 * executor and authorised for the person asking, and it adds only the five
 * questions that are peculiar to going back: has the job stopped, was its
 * action ever reversible, is the window still open, is somebody already
 * undoing it, and did it write anything at all (D-1).
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
 *
 * @spec openspec/changes/undo-a-bulk-action/specs/bulk-action-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\BulkJob;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\BulkAction\RestorePriorValuesAction;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;

/**
 * Class BulkJobReversal
 */
class BulkJobReversal {

	/**
	 * Constructor.
	 *
	 * @param BulkJobService      $service      The lifecycle the reversal reuses.
	 * @param BulkJobMapper       $jobMapper    Job persistence.
	 * @param BulkJobMemberMapper $memberMapper Member persistence.
	 */
	public function __construct(
		private readonly BulkJobService $service,
		private readonly BulkJobMapper $jobMapper,
		private readonly BulkJobMemberMapper $memberMapper,
	) {
	}//end __construct()

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
	 * @throws InvalidArgumentException When the job names no register or schema, which the create path refuses.
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

		$reversal = $this->service->create(
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
}//end class
