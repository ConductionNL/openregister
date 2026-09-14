<?php

/**
 * The one executor behind both the rehearsal and the commit.
 *
 * A preview produced by a second implementation is a promise, not a
 * rehearsal. This class walks the selection, resolves access per object,
 * runs the action's guards and asks the action what it would do. The only
 * difference between the preview and the commit is whether the write is
 * made (D-1).
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

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionResult;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapper;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\Object\PermissionHandler;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Class BulkJobExecutor
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The executor is the seam
 * where the selection, the action, access control and the audit trail meet.
 */
class BulkJobExecutor {

	/**
	 * The reason recorded for a member the actor cannot even read.
	 *
	 * @var string
	 */
	public const RULE_NOT_VISIBLE = 'the object does not exist, or the read rule on its schema does not include you';

	/**
	 * Constructor.
	 *
	 * @param BulkJobMapper $jobMapper Job persistence.
	 * @param BulkJobMemberMapper $memberMapper Member persistence.
	 * @param BulkSelectionResolver $resolver Selection resolution.
	 * @param BulkActionRegistry $registry The catalogue of registered actions.
	 * @param PermissionHandler $permissionHandler Per-object access checks.
	 * @param SchemaMapper $schemaMapper Schema lookup for the access check.
	 * @param AuditTrailMapper $auditTrailMapper Audit entries per member.
	 * @param IUserManager $userManager Actor lookup.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly BulkJobMapper $jobMapper,
		private readonly BulkJobMemberMapper $memberMapper,
		private readonly BulkSelectionResolver $resolver,
		private readonly BulkActionRegistry $registry,
		private readonly PermissionHandler $permissionHandler,
		private readonly SchemaMapper $schemaMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a selection that spans more than one schema version.
	 *
	 * @param BulkActionInterface $action The action, which declares its guards.
	 * @param array<string, ObjectEntity> $objects The hydrated selection.
	 *
	 * @return void
	 *
	 * @throws BulkJobRefusedException When the guard refuses the selection.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function assertGuards(BulkActionInterface $action, array $objects): void {
		if (in_array(BulkActionInterface::GUARD_HOMOGENEITY, $action->getGuards(), true) === false) {
			return;
		}

		$versions = [];
		foreach ($objects as $object) {
			$version = ($this->schemaVersionOf(object: $object) ?? 'unversioned');
			$versions[$version] = (($versions[$version] ?? 0) + 1);
		}

		if (count($versions) < 2) {
			return;
		}

		ksort($versions);
		$named = [];
		foreach ($versions as $version => $count) {
			$named[] = $version.' ('.$count.')';
		}

		throw new BulkJobRefusedException(
			message: 'The action '.$action->getId().' refuses a selection spanning more than one schema version. '
				.'This selection holds '.implode(', ', $named).'.',
			reason: 'homogeneity',
			details: ['versions' => $versions]
		);
	}//end assertGuards()

	/**
	 * Write the member rows of a preview, and the counts they add up to.
	 *
	 * @param BulkJob $job The job being created.
	 * @param array<int, string> $uuids The selected uuids, in order.
	 * @param array<string, ObjectEntity> $objects The hydrated selection.
	 * @param BulkActionInterface $action The action to rehearse.
	 * @param IUser|null $actor The user the job runs as.
	 * @param bool $addedAtCommit True when these members appeared at commit.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The flag marks the member
	 * rows a grown query selection added, which the report has to name.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function writePreviewMembers(
		BulkJob $job,
		array $uuids,
		array $objects,
		BulkActionInterface $action,
		?IUser $actor,
		bool $addedAtCommit = false
	): void {
		foreach ($uuids as $uuid) {
			$object = ($objects[$uuid] ?? null);
			$result = $this->rehearse(object: $object, action: $action, job: $job, actor: $actor);

			$version = null;
			if ($object !== null) {
				$version = $this->schemaVersionOf(object: $object);
			}

			$this->memberMapper->createFromArray(
				[
					'jobId' => $job->getId(),
					'objectUuid' => $uuid,
					'outcome' => $result->getOutcome(),
					'reason' => $result->getReason(),
					'schemaVersion' => $version,
					'addedAtCommit' => $addedAtCommit,
				]
			);
		}
	}//end writePreviewMembers()

	/**
	 * Walk one batch of a committed job.
	 *
	 * @param BulkJob $job The running job.
	 * @param int $batchSize How many members to walk.
	 *
	 * @return bool True when members remain.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function processBatch(BulkJob $job, int $batchSize): bool {
		$action = null;
		$members = $this->memberMapper->findPendingBatch(
			jobId: (int)$job->getId(),
			afterId: $job->getCursor(),
			limit: $batchSize
		);

		if ($members === []) {
			$this->refreshCounts(job: $job);
			$job->setState(BulkJob::STATE_COMPLETED);
			$this->jobMapper->save($job);

			return false;
		}

		$actor = $this->actorOf(job: $job);
		$objects = $this->resolver->hydrate(
			uuids: array_map(static fn (BulkJobMember $member): string => (string)$member->getObjectUuid(), $members),
			registerId: $job->getRegisterId(),
			schemaId: $job->getSchemaId()
		);

		foreach ($members as $member) {
			if ($this->jobMapper->readState((int)$job->getId()) === BulkJob::STATE_CANCELLING) {
				$this->stopAtBoundary(job: $job, member: $member);

				return false;
			}

			$action ??= $this->actionOf(job: $job);
			$this->commitMember(job: $job, member: $member, objects: $objects, action: $action, actor: $actor);
			$job->setCursor((int)$member->getId());
		}//end foreach

		$this->refreshCounts(job: $job);
		$this->jobMapper->save($job);

		return true;
	}//end processBatch()

	/**
	 * Recompute the job's counters from its member rows.
	 *
	 * @param BulkJob $job The job.
	 *
	 * @return void
	 */
	public function refreshCounts(BulkJob $job): void {
		$counts = $this->memberMapper->countByOutcome(jobId: (int)$job->getId());

		$job->setApplied((int)($counts[BulkJobMember::OUTCOME_APPLIED] ?? 0));
		$job->setSkipped((int)($counts[BulkJobMember::OUTCOME_SKIPPED] ?? 0));
		$job->setRefused((int)($counts[BulkJobMember::OUTCOME_REFUSED] ?? 0));
		$job->setFailed((int)($counts[BulkJobMember::OUTCOME_FAILED] ?? 0));

		$pending = (int)($counts[BulkJobMember::OUTCOME_PENDING] ?? 0);
		$job->setProcessed((int)max(0, (array_sum($counts) - $pending)));
	}//end refreshCounts()

	/**
	 * The action a job runs.
	 *
	 * @param BulkJob $job The job.
	 *
	 * @return BulkActionInterface The action.
	 */
	private function actionOf(BulkJob $job): BulkActionInterface {
		return $this->registry->get(id: (string)$job->getAction());
	}//end actionOf()

	/**
	 * Rehearse the action against one object.
	 *
	 * @param ObjectEntity|null $object The object, or null when unreadable.
	 * @param BulkActionInterface $action The action.
	 * @param BulkJob $job The job.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return BulkActionResult What would happen.
	 */
	private function rehearse(?ObjectEntity $object, BulkActionInterface $action, BulkJob $job, ?IUser $actor): BulkActionResult {
		if ($object === null) {
			return BulkActionResult::refused(rule: self::RULE_NOT_VISIBLE);
		}

		$refusal = $this->refusalFor(object: $object, actorUid: (string)$job->getStartedBy());
		if ($refusal !== null) {
			return $refusal;
		}

		return $action->apply(
			object: $object,
			parameters: ($job->getParameters() ?? []),
			commit: false,
			actor: $actor
		);
	}//end rehearse()

	/**
	 * Apply the action to one member and record what happened.
	 *
	 * @param BulkJob $job The job.
	 * @param BulkJobMember $member The member.
	 * @param array<string, ObjectEntity> $objects The hydrated batch.
	 * @param BulkActionInterface $action The action.
	 * @param IUser|null $actor The user the job runs as.
	 *
	 * @return void
	 */
	private function commitMember(
		BulkJob $job,
		BulkJobMember $member,
		array $objects,
		BulkActionInterface $action,
		?IUser $actor
	): void {
		$object = ($objects[(string)$member->getObjectUuid()] ?? null);

		if ($object === null) {
			$this->recordOutcome(member: $member, result: BulkActionResult::refused(rule: self::RULE_NOT_VISIBLE));

			return;
		}

		$refusal = $this->refusalFor(object: $object, actorUid: (string)$job->getStartedBy());
		if ($refusal !== null) {
			$this->recordOutcome(member: $member, result: $refusal);

			return;
		}

		$result = $action->apply(
			object: $object,
			parameters: ($job->getParameters() ?? []),
			commit: true,
			actor: $actor
		);

		if ($result->isApplied() === true) {
			$this->recordAudit(job: $job, object: $object);
		}

		$this->recordOutcome(member: $member, result: $result);
	}//end commitMember()

	/**
	 * The refusal for an object the actor may not write, or null.
	 *
	 * An object the actor may not write is reported as refused, with the rule
	 * that refused it, never as a skip (D-3).
	 *
	 * @param ObjectEntity $object The object.
	 * @param string $actorUid The actor's uid.
	 *
	 * @return BulkActionResult|null The refusal, or null when the write is allowed.
	 */
	private function refusalFor(ObjectEntity $object, string $actorUid): ?BulkActionResult {
		try {
			$schema = $this->schemaMapper->find($object->getSchema());
		} catch (\Throwable $exception) {
			return BulkActionResult::refused(rule: 'the schema of this object could not be resolved, so no write rule could be read');
		}

		$allowed = $this->permissionHandler->hasPermission(
			schema: $schema,
			action: 'update',
			userId: $actorUid,
			objectOwner: $object->getOwner(),
			object: $object
		);

		if ($allowed === true) {
			return null;
		}

		return BulkActionResult::refused(
			rule: "the update rule on schema '".$schema->getTitle()."' does not include ".$actorUid
		);
	}//end refusalFor()

	/**
	 * Persist one member's outcome.
	 *
	 * @param BulkJobMember $member The member.
	 * @param BulkActionResult $result The outcome.
	 *
	 * @return void
	 */
	private function recordOutcome(BulkJobMember $member, BulkActionResult $result): void {
		$member->setOutcome($result->getOutcome());
		$member->setReason($result->getReason());
		$this->memberMapper->save($member);
	}//end recordOutcome()

	/**
	 * Write the member's own audit entry, referencing the job.
	 *
	 * The job is one audited act and every member carries its own entry, so
	 * the reason the operator typed is readable from the object as well as
	 * from the job (D-7).
	 *
	 * @param BulkJob $job The job.
	 * @param ObjectEntity $object The object that was written.
	 *
	 * @return void
	 */
	private function recordAudit(BulkJob $job, ObjectEntity $object): void {
		try {
			$this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: 'bulk.applied',
				context: [
					'bulkJob' => $job->getUuid(),
					'bulkAction' => $job->getAction(),
					'reason' => $job->getJustification(),
				],
				actorId: $job->getStartedBy()
			);
		} catch (\Throwable $exception) {
			// The write already happened. Losing the extra entry is bad;
			// pretending the write did not happen would be worse.
			$this->logger->error(
				message: '[BulkJobExecutor] Could not write the member audit entry',
				context: [
					'job' => $job->getUuid(),
					'object' => $object->getUuid(),
					'error' => $exception->getMessage(),
				]
			);
		}//end try
	}//end recordAudit()

	/**
	 * Stop the job before the member it was about to act on.
	 *
	 * Cancelling leaves what is already committed committed, stops before the
	 * next object and reports the boundary. A rollback across four hundred
	 * objects would hold a transaction open for minutes, and that is the
	 * wrong trade for this act (D-4).
	 *
	 * @param BulkJob $job The job.
	 * @param BulkJobMember $member The member it stopped before.
	 *
	 * @return void
	 */
	private function stopAtBoundary(BulkJob $job, BulkJobMember $member): void {
		$this->refreshCounts(job: $job);

		$report = ($job->getReport() ?? []);
		$report['cancelledBefore'] = [
			'objectUuid' => $member->getObjectUuid(),
			'position' => ($job->getProcessed() + 1),
			'applied' => $job->getApplied(),
		];

		$job->setReport($report);
		$job->setState(BulkJob::STATE_CANCELLED);
		$this->jobMapper->save($job);
	}//end stopAtBoundary()

	/**
	 * The actor a job runs as.
	 *
	 * @param BulkJob $job The job.
	 *
	 * @return IUser|null The actor, or null when the account is gone.
	 */
	private function actorOf(BulkJob $job): ?IUser {
		$uid = $job->getStartedBy();

		if ($uid === null) {
			return null;
		}

		return $this->userManager->get($uid);
	}//end actorOf()

	/**
	 * The schema version an object carries, if it records one.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null The schema version.
	 */
	private function schemaVersionOf(ObjectEntity $object): ?string {
		$version = ($object->jsonSerialize()['schemaVersion'] ?? null);

		if (is_string($version) === false || $version === '') {
			return null;
		}

		return $version;
	}//end schemaVersionOf()
}//end class
