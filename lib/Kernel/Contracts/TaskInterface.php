<?php
/**
 * TaskInterface: what callers outside lib/Db need of a Task row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A task: one unit of work with a performer, a lifecycle state and a due date.
 *
 * Implemented by \OCA\OpenRegister\Db\Task. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTaskKey()
 * @method void setTaskKey(?string $taskKey)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getMetadata()
 * @method void setMetadata(?array $metadata)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getNodeId()
 * @method void setNodeId(?string $nodeId)
 * @method integer|null getDefinitionVersion()
 * @method void setDefinitionVersion(?int $definitionVersion)
 * @method string|null getAppId()
 * @method void setAppId(?string $appId)
 * @method string|null getWorkflowStepId()
 * @method void setWorkflowStepId(?string $workflowStepId)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method bool|null getIsTerminal()
 * @method void setIsTerminal(?bool $isTerminal)
 * @method string|null getLastAction()
 * @method void setLastAction(?string $lastAction)
 * @method string|null getOutcome()
 * @method void setOutcome(?string $outcome)
 * @method string|null getBlockedReason()
 * @method void setBlockedReason(?string $blockedReason)
 * @method string|null getBlockedBy()
 * @method void setBlockedBy(?string $blockedBy)
 * @method string|null getPerformerType()
 * @method void setPerformerType(?string $performerType)
 * @method string|null getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method array|null getCandidateUsers()
 * @method void setCandidateUsers(?array $candidateUsers)
 * @method array|null getCandidateGroups()
 * @method void setCandidateGroups(?array $candidateGroups)
 * @method string|null getCandidateRole()
 * @method void setCandidateRole(?string $candidateRole)
 * @method string|null getRoutingStrategy()
 * @method void setRoutingStrategy(?string $routingStrategy)
 * @method string|null getRoutingFallback()
 * @method void setRoutingFallback(?string $routingFallback)
 * @method string|null getOnBehalfOf()
 * @method void setOnBehalfOf(?string $onBehalfOf)
 * @method string|null getMandate()
 * @method void setMandate(?string $mandate)
 * @method string|null getRequester()
 * @method void setRequester(?string $requester)
 * @method array|null getWatchers()
 * @method void setWatchers(?array $watchers)
 * @method DateTime|null getStartAt()
 * @method void setStartAt(?DateTime $startAt)
 * @method DateTime|null getDueAt()
 * @method void setDueAt(?DateTime $dueAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method string|null getOnTimeout()
 * @method void setOnTimeout(?string $onTimeout)
 * @method string|null getOnReject()
 * @method void setOnReject(?string $onReject)
 * @method integer|null getSlaValue()
 * @method void setSlaValue(?int $slaValue)
 * @method string|null getSlaUnit()
 * @method void setSlaUnit(?string $slaUnit)
 * @method integer|null getCompliancePeriodDays()
 * @method void setCompliancePeriodDays(?int $compliancePeriodDays)
 * @method DateTime|null getSuspendedUntil()
 * @method void setSuspendedUntil(?DateTime $suspendedUntil)
 * @method string|null getRecurrence()
 * @method void setRecurrence(?string $recurrence)
 * @method string|null getPriority()
 * @method void setPriority(?string $priority)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method integer|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method integer|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getTemplateId()
 * @method void setTemplateId(?string $templateId)
 * @method integer|null getTemplateVersion()
 * @method void setTemplateVersion(?int $templateVersion)
 * @method array|null getTemplateSnapshot()
 * @method void setTemplateSnapshot(?array $templateSnapshot)
 * @method string|null getSequenceUuid()
 * @method void setSequenceUuid(?string $sequenceUuid)
 * @method integer|null getSequencePosition()
 * @method void setSequencePosition(?int $sequencePosition)
 * @method integer|null getLegacyStepId()
 * @method void setLegacyStepId(?int $legacyStepId)
 * @method array|null getChecklist()
 * @method void setChecklist(?array $checklist)
 * @method array|null getResponses()
 * @method void setResponses(?array $responses)
 * @method integer|null getPercentComplete()
 * @method void setPercentComplete(?int $percentComplete)
 * @method DateTime|null getCompletedAt()
 * @method void setCompletedAt(?DateTime $completedAt)
 * @method string|null getCompletedBy()
 * @method void setCompletedBy(?string $completedBy)
 * @method string|null getResultText()
 * @method void setResultText(?string $resultText)
 * @method string|null getComment()
 * @method void setComment(?string $comment)
 * @method array|null getEvidence()
 * @method void setEvidence(?array $evidence)
 * @method string|null getOverrideReason()
 * @method void setOverrideReason(?string $overrideReason)
 * @method integer|null getParentTaskId()
 * @method void setParentTaskId(?int $parentTaskId)
 * @method integer|null getEpicTaskId()
 * @method void setEpicTaskId(?int $epicTaskId)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 */
interface TaskInterface extends JsonSerializable {

	/**
	 * Performer types (ADR-098 D3).
	 *
	 * DELIBERATELY NOT A CLOSED SET at the storage level: the column is a
	 * plain string, and this list is the vocabulary the service validates
	 * against today. `external` (a portal party, ADR-098 D3 as amended
	 * 2026-08-31 for flow-portal-task) was admitted exactly that way: an
	 * append to this array, not a migration.
	 */
	public const PERFORMER_USER = 'user';

	public const PERFORMER_GROUP = 'group';

	public const PERFORMER_AGENT = 'agent';

	public const PERFORMER_WORKER = 'worker';

	/**
	 * A party outside the instance, reached through the portal seam. Its
	 * performer reference is a PARTY reference (see EXTERNAL_PARTY_PREFIX),
	 * never a Nextcloud uid, group or role; it is never pooled, claimed or
	 * delegated, and only the matched portal subject may complete it.
	 *
	 * @spec openspec/changes/flow-portal-task/specs/flow-tasks/spec.md#requirement-the-external-performer-type-is-portal-scoped-and-never-pooled
	 */
	public const PERFORMER_EXTERNAL = 'external';

	/**
	 * The prefix an external task's assignee carries. A Nextcloud uid cannot
	 * contain a colon, so a party reference can never collide with a uid and
	 * a uid can never be mistaken for the matched party.
	 *
	 * @var string
	 */
	public const EXTERNAL_PARTY_PREFIX = 'party:';

	/**
	 * The performer types known to this release.
	 *
	 * @var array<int, string>
	 */
	public const PERFORMER_TYPES = [
		self::PERFORMER_USER,
		self::PERFORMER_GROUP,
		self::PERFORMER_AGENT,
		self::PERFORMER_WORKER,
		self::PERFORMER_EXTERNAL,
	];

	/**
	 * Whether this task will never move again.
	 *
	 * Reads the state set membership, NOT the materialised column: the
	 * column exists for indexed queries, and a test asserts the two agree
	 * across every transition.
	 *
	 * @return boolean True when the state is terminal.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public function isInTerminalState(): bool;

	/**
	 * Hydrate entity from array.
	 *
	 * @param array<string, mixed> $object Data to hydrate from.
	 *
	 * @return self This task.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function hydrate(array $object): self;

	/**
	 * Serialise for the API.
	 *
	 * Carries the STORED row only. The derived temporal projection (overdue,
	 * daysUntilDue, daysOverdue) is attached by the read surface via
	 * {@see \OCA\OpenRegister\Service\Task\TaskTemporalProjection} — it is
	 * deliberately not computed here, so no code path can mistake it for a
	 * stored field.
	 *
	 * @return array<string, mixed> The task as plain data.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 * @spec openspec/changes/a-task-may-wait-on-another-task/specs/flow-tasks/spec.md#requirement-a-task-may-wait-on-another-task-and-waits-out-of-sight
	 */
	public function jsonSerialize(): array;
}//end interface
