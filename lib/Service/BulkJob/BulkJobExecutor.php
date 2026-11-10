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

use DateTime;
use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\BulkAction\BulkActionOutcomeInterface;
use OCA\OpenRegister\BulkAction\BulkStep;
use OCA\OpenRegister\BulkAction\ReversibleBulkActionInterface;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\BulkJob;
use OCA\OpenRegister\Db\BulkJobMapperInterface;
use OCA\OpenRegister\Db\BulkJobMember;
use OCA\OpenRegister\Db\BulkJobMemberMapperInterface;
use OCA\OpenRegister\Db\IdCursor;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Exception\BulkJobRefusedException;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCA\OpenRegister\Service\Object\PermissionCheckInterface;
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
	 * @param BulkJobExecutorStores   $stores   The persistence collaborators of the bulk job executor.
	 * @param BulkJobExecutorServices $services The selection, action, access and actor collaborators of the bulk job executor.
	 * @param LoggerInterface         $logger   Logger.
	 */
	public function __construct(
		private readonly BulkJobExecutorStores $stores,
		private readonly BulkJobExecutorServices $services,
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
	 * @spec openspec/specs/bulk-action-jobs/spec.md
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
	 * @param BulkJobPass        $pass  The job being created, the action to rehearse, its actor and the hydrated selection.
	 * @param array<int, string> $uuids The selected uuids, in order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function writePreviewMembers(BulkJobPass $pass, array $uuids): void {
		foreach ($uuids as $uuid) {
			$this->stores->memberMapper->createFromArray($this->previewRow(pass: $pass, uuid: $uuid));
		}
	}//end writePreviewMembers()

	/**
	 * Write the member rows a grown query selection added at commit.
	 *
	 * The rows are rehearsed exactly as a preview's, and marked as added at
	 * commit, which the report has to name.
	 *
	 * @param BulkJobPass        $pass  The committing job, its action, its actor and the hydrated additions.
	 * @param array<int, string> $uuids The added uuids, in order.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function writeAddedMembers(BulkJobPass $pass, array $uuids): void {
		foreach ($uuids as $uuid) {
			$row = $this->previewRow(pass: $pass, uuid: $uuid);
			$row['addedAtCommit'] = true;
			$this->stores->memberMapper->createFromArray($row);
		}
	}//end writeAddedMembers()

	/**
	 * Rehearse one selected uuid and build its member row.
	 *
	 * @param BulkJobPass $pass The preview pass.
	 * @param string      $uuid The selected uuid.
	 *
	 * @return array<string, mixed> The member row; `addedAtCommit` is false until the caller sets it.
	 */
	private function previewRow(BulkJobPass $pass, string $uuid): array {
		$object = $pass->object(uuid: $uuid);
		$result = $this->rehearse(pass: $pass, object: $object);

		$version = null;
		if ($object !== null) {
			$version = $this->schemaVersionOf(object: $object);
		}

		$plan = $this->reversalPlanOf(pass: $pass, object: $object);

		return [
			'jobId' => $pass->job->getId(),
			'objectUuid' => $uuid,
			'outcome' => $result->getOutcome(),
			'reason' => $result->getReason(),
			'schemaVersion' => $version,
			'addedAtCommit' => false,
			'priorValues' => ($plan[ReversibleBulkActionInterface::PLAN_PRIOR] ?? null),
			'appliedValues' => ($plan[ReversibleBulkActionInterface::PLAN_APPLIED] ?? null),
		];
	}//end previewRow()

	/**
	 * Walk one batch of a committed job.
	 *
	 * @param BulkJob $job The running job.
	 * @param int $batchSize How many members to walk.
	 *
	 * @return bool True when members remain.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function processBatch(BulkJob $job, int $batchSize): bool {
		$pass = null;
		$members = $this->stores->memberMapper->findPendingBatch(
			jobId: (int)$job->getId(),
			cursor: new IdCursor(afterId: $job->getCursor(), limit: $batchSize)
		);

		if ($members === []) {
			$this->refreshCounts(job: $job);
			$job->setState(BulkJob::STATE_COMPLETED);
			$this->stampReversalDeadline(job: $job);
			$this->stores->jobMapper->save($job);

			return false;
		}

		$actor = $this->actorOf(job: $job);
		$objects = $this->services->resolver->hydrate(
			uuids: array_map(static fn (BulkJobMember $member): string => (string)$member->getObjectUuid(), $members),
			scope: new BulkJobSelection(criteria: ($job->getSelection() ?? []), registerId: $job->getRegisterId(), schemaId: $job->getSchemaId())
		);

		foreach ($members as $member) {
			if ($this->stores->jobMapper->readState((int)$job->getId()) === BulkJob::STATE_CANCELLING) {
				$this->stopAtBoundary(job: $job, member: $member);

				return false;
			}

			$pass ??= new BulkJobPass(job: $job, action: $this->actionOf(job: $job), actor: $actor, objects: $objects);
			$this->commitMember(pass: $pass, member: $member);
			$job->setCursor((int)$member->getId());
		}//end foreach

		$this->refreshCounts(job: $job);
		$this->stores->jobMapper->save($job);

		return true;
	}//end processBatch()

	/**
	 * Recompute the job's counters from its member rows.
	 *
	 * @param BulkJob $job The job.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	public function refreshCounts(BulkJob $job): void {
		$counts = $this->stores->memberMapper->countByOutcome(jobId: (int)$job->getId());

		$job->setApplied((int)($counts[BulkJobMember::OUTCOME_APPLIED] ?? 0));
		$job->setSkipped((int)($counts[BulkJobMember::OUTCOME_SKIPPED] ?? 0));
		$job->setRefused((int)($counts[BulkJobMember::OUTCOME_REFUSED] ?? 0));
		$job->setFailed((int)($counts[BulkJobMember::OUTCOME_FAILED] ?? 0));

		$job->setProcessed(
			$this->stores->memberMapper->countWalked(jobId: (int)$job->getId(), cursor: $job->getCursor())
		);
	}//end refreshCounts()

	/**
	 * The action a job runs.
	 *
	 * @param BulkJob $job The job.
	 *
	 * @return BulkActionInterface The action.
	 */
	private function actionOf(BulkJob $job): BulkActionInterface {
		return $this->services->registry->get(id: (string)$job->getAction());
	}//end actionOf()

	/**
	 * Rehearse the action against one object.
	 *
	 * @param BulkJobPass $pass The preview pass (job, action, actor).
	 * @param ObjectEntity|null $object The object, or null when unreadable.
	 *
	 * @return BulkActionOutcomeInterface What would happen.
	 */
	private function rehearse(BulkJobPass $pass, ?ObjectEntity $object): BulkActionOutcomeInterface {
		if ($object === null) {
			return new BulkJobRefusal(rule: self::RULE_NOT_VISIBLE);
		}

		$refusal = $this->refusalFor(object: $object, actorUid: (string)$pass->job->getStartedBy());
		if ($refusal !== null) {
			return $refusal;
		}

		return $pass->action->apply(
			step: new BulkStep(
				object: $object,
				parameters: ($pass->job->getParameters() ?? []),
				commit: false,
				actor: $pass->actor
			)
		);
	}//end rehearse()

	/**
	 * Apply the action to one member and record what happened.
	 *
	 * @param BulkJobPass $pass The batch pass (job, action, actor, hydrated batch).
	 * @param BulkJobMember $member The member.
	 *
	 * @return void
	 */
	private function commitMember(BulkJobPass $pass, BulkJobMember $member): void {
		$job = $pass->job;
		$object = $pass->object(uuid: (string)$member->getObjectUuid());

		if ($object === null) {
			$this->recordOutcome(member: $member, result: new BulkJobRefusal(rule: self::RULE_NOT_VISIBLE));

			return;
		}

		$refusal = $this->refusalFor(object: $object, actorUid: (string)$job->getStartedBy());
		if ($refusal !== null) {
			$this->recordOutcome(member: $member, result: $refusal);

			return;
		}

		// Re-read what the object holds NOW rather than trusting the preview's
		// copy. Minutes or hours may have passed since the rehearsal, and a
		// prior value captured then would restore the object to a state it
		// left before this job ever wrote (D-2).
		$plan = $this->reversalPlanOf(pass: $pass, object: $object);

		$result = $pass->action->apply(
			step: new BulkStep(
				object: $object,
				parameters: ($job->getParameters() ?? []),
				commit: true,
				actor: $pass->actor
			)
		);

		if ($result->isApplied() === true) {
			$this->recordAudit(job: $job, object: $object);

			if ($plan !== null) {
				$member->setPriorValues($plan[ReversibleBulkActionInterface::PLAN_PRIOR]);
				$member->setAppliedValues($plan[ReversibleBulkActionInterface::PLAN_APPLIED]);
			}
		}

		$this->recordCommitted(member: $member, result: $result);
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
	 * @return BulkActionOutcomeInterface|null The refusal, or null when the write is allowed.
	 */
	private function refusalFor(ObjectEntity $object, string $actorUid): ?BulkActionOutcomeInterface {
		try {
			$schema = $this->stores->schemaMapper->find($object->getSchema());
		} catch (\Throwable $exception) {
			return new BulkJobRefusal(rule: 'the schema of this object could not be resolved, so no write rule could be read');
		}

		$allowed = $this->services->permissionHandler->hasPermission(
			schema: $schema,
			action: 'update',
			userId: $actorUid,
			objectOwner: $object->getOwner(),
			object: $object
		);

		if ($allowed === true) {
			return null;
		}

		return new BulkJobRefusal(
			rule: "the update rule on schema '".$schema->getTitle()."' does not include ".$actorUid
		);
	}//end refusalFor()

	/**
	 * What it would take to undo this action on this object, or null.
	 *
	 * Null for an action that did not declare itself reversible, which is the
	 * whole of the "irreversible is declared" rule at the member level: no
	 * prior value is stored, so no reversal can pretend to have one (D-4).
	 *
	 * @param BulkJobPass       $pass   The pass (its action, and its job for the parameters).
	 * @param ObjectEntity|null $object The object, or null when unreadable.
	 *
	 * @return array{prior: array<string, mixed>, applied: array<string, mixed>}|null The plan.
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	private function reversalPlanOf(BulkJobPass $pass, ?ObjectEntity $object): ?array {
		$action = $pass->action;
		if ($object === null || ($action instanceof ReversibleBulkActionInterface) === false) {
			return null;
		}

		$plan = $action->reversalPlanFor(object: $object, parameters: ($pass->job->getParameters() ?? []));

		if (isset($plan[ReversibleBulkActionInterface::PLAN_PRIOR]) === false) {
			return null;
		}

		return $plan;
	}//end reversalPlanOf()

	/**
	 * Persist one member's outcome without a write stamp.
	 *
	 * @param BulkJobMember              $member The member.
	 * @param BulkActionOutcomeInterface $result The outcome.
	 *
	 * @return void
	 */
	private function recordOutcome(BulkJobMember $member, BulkActionOutcomeInterface $result): void {
		$member->setOutcome($result->getOutcome());
		$member->setReason($result->getReason());

		$this->stores->memberMapper->save($member);
	}//end recordOutcome()

	/**
	 * Persist a committed member's outcome, stamping it when a real write landed.
	 *
	 * The write stamp is the idempotence key. It is read off the commit's own
	 * outcome only: a preview records its outcome through recordOutcome() and
	 * never stamps.
	 *
	 * @param BulkJobMember              $member The member.
	 * @param BulkActionOutcomeInterface $result The commit outcome.
	 *
	 * @return void
	 */
	private function recordCommitted(BulkJobMember $member, BulkActionOutcomeInterface $result): void {
		if ($result->isApplied() === true) {
			$member->setAppliedAt(new DateTime());
		}

		$this->recordOutcome(member: $member, result: $result);
	}//end recordCommitted()

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
			$this->stores->auditTrailMapper->createAuditTrailEntry(
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
		$this->stampReversalDeadline(job: $job);
		$this->stores->jobMapper->save($job);
	}//end stopAtBoundary()

	/**
	 * Fix the reversal deadline at the moment the job stopped writing.
	 *
	 * The window runs from when the job actually wrote, not from when somebody
	 * opened the dialog. Creation stamps a provisional deadline so the preview
	 * can name one; this is the one a reversal is measured against.
	 *
	 * @param BulkJob $job The job that has just reached a terminal state.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bulk-action-jobs/spec.md
	 */
	private function stampReversalDeadline(BulkJob $job): void {
		if ($job->isReversible() === false) {
			return;
		}

		$job->setReversibleUntil((new DateTime())->modify('+'.(int)$job->getReversalWindow().' seconds'));
	}//end stampReversalDeadline()

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

		return $this->services->userManager->get($uid);
	}//end actorOf()

	/**
	 * The schema version an object carries, if it records one.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return string|null The schema version.
	 */
	private function schemaVersionOf(ObjectEntity $object): ?string {
		// The getter resolves through Entity::__call, and the @method
		// annotation on ObjectEntity is what makes it visible to static
		// analysis. Reading the version off jsonSerialize() does NOT work:
		// that method omits the field entirely, so every member came back
		// unversioned and the homogeneity guard never fired.
		$version = $object->getSchemaVersion();

		if (is_string($version) === false || $version === '') {
			return null;
		}

		return $version;
	}//end schemaVersionOf()
}//end class
