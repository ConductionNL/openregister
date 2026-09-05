<?php

/**
 * The fleet-generic task: a durable record of work owed by a performer.
 *
 * ONE task instead of the 23 conflicting shapes the 2026-08-22 inventory
 * found. A task may carry `run_uuid`/`node_id` as PROVENANCE — a pointer to
 * the flow suspension that raised it — but a standalone task with neither is
 * first-class by design (design D-3): nothing derived from a run may be
 * load-bearing for a task without one.
 *
 * Deliberate absences: no `overdue` field of any kind (derived on read,
 * always), and no app-named field (extra subjects live in the typed relation
 * table).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class Task
 *
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
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column of
 * `openregister_tasks`. The width is the resolved union of 23 fleet task
 * shapes (design.md — Data model); dropping fields here would push them back
 * into per-app columns, which is the defect this entity removes.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The @method block, the typed
 * properties and the field-by-field constructor scale linearly with the
 * column count, same as {@see FlowRun}.
 * @SuppressWarnings(PHPMD.ExcessivePublicCount) Entity getters/setters are
 * the column surface, not an API design choice.
 *
 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
 */
class Task extends Entity implements JsonSerializable {

	/**
	 * The six CMMN plan-item states (ADR-098 D4). Nothing else is persistable.
	 */
	public const STATE_AVAILABLE = 'available';

	public const STATE_ENABLED = 'enabled';

	public const STATE_ACTIVE = 'active';

	public const STATE_COMPLETED = 'completed';

	public const STATE_TERMINATED = 'terminated';

	public const STATE_DISABLED = 'disabled';

	/**
	 * Every persistable state.
	 *
	 * @var array<int, string>
	 */
	public const STATES = [
		self::STATE_AVAILABLE,
		self::STATE_ENABLED,
		self::STATE_ACTIVE,
		self::STATE_COMPLETED,
		self::STATE_TERMINATED,
		self::STATE_DISABLED,
	];

	/**
	 * States from which a task never moves again.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_STATES = [
		self::STATE_COMPLETED,
		self::STATE_TERMINATED,
		self::STATE_DISABLED,
	];

	/**
	 * The reserved behaviour vocabulary `on_timeout` and `on_reject` accept —
	 * the same words `TaskService::applyTimerOutcome()` resolves, so one
	 * mapping serves the timer path, the sweep and the reject routing.
	 *
	 * @var array<int, string>
	 */
	public const OUTCOME_BEHAVIOURS = ['skip', 'error', 'dead_letter'];

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
	 * The one normalised priority scale.
	 *
	 * @var array<int, string>
	 */
	public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

	/**
	 * The routing strategies a candidate pool supports.
	 *
	 * @var array<int, string>
	 */
	public const ROUTING_STRATEGIES = [
		'single-role',
		'or-set',
		'hierarchical',
		'round-robin',
		'least-loaded',
	];

	/**
	 * Public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * External reference key. Stored as `task_key` (KEY is a reserved word);
	 * serialised as `key` on the API.
	 *
	 * @var string|null
	 */
	protected ?string $taskKey = null;

	/**
	 * Human title. Nullable: four fleet shapes carry none, and a display
	 * title is then SYNTHESIZED on read, never persisted.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * Longer description.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * Carried, never interpreted: no lifecycle, authorization or inbox rule
	 * may read this (design — Risks).
	 *
	 * @var array|null
	 */
	protected ?array $metadata = null;

	/**
	 * Provenance: the run whose suspension raised this task. OPTIONAL.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * Provenance: the node within that run. Task identity is (runUuid,
	 * nodeId), never runUuid alone — a run accumulates one resume slot per
	 * node ({@see \OCA\OpenRegister\Service\Flow\FlowResumeState}).
	 *
	 * @var string|null
	 */
	protected ?string $nodeId = null;

	/**
	 * The flow definition version the run was pinned to at task creation.
	 * Copied from {@see FlowRun::getFlowVersion()}; unwritten until the
	 * user-task node exists to write it (flow-user-task-node).
	 *
	 * @var integer|null
	 */
	protected ?int $definitionVersion = null;

	/**
	 * The app that created the task.
	 *
	 * @var string|null
	 */
	protected ?string $appId = null;

	/**
	 * Legacy workflow step reference, for migrating shapes.
	 *
	 * @var string|null
	 */
	protected ?string $workflowStepId = null;

	/**
	 * Owning organisation.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * One of the six CMMN states.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * Materialised terminality. Written in the SAME statement as `state`,
	 * only ever by the lifecycle write path.
	 *
	 * @var boolean|null
	 */
	protected ?bool $isTerminal = false;

	/**
	 * The named transition action that produced the current state. Recorded
	 * because ADR-031 `transition(action)` notification triggers address the
	 * ACTION, not the resulting state.
	 *
	 * @var string|null
	 */
	protected ?string $lastAction = null;

	/**
	 * The outcome distinction that state collapsing preserved
	 * (`approved` vs `done`, `cancelled` vs `terminated`).
	 *
	 * @var string|null
	 */
	protected ?string $outcome = null;

	/**
	 * Why the task is blocked, when it is.
	 *
	 * @var string|null
	 */
	protected ?string $blockedReason = null;

	/**
	 * user|group|agent|worker (extensible — see PERFORMER_TYPES).
	 *
	 * @var string|null
	 */
	protected ?string $performerType = null;

	/**
	 * The resolved current holder; empty while pooled.
	 *
	 * @var string|null
	 */
	protected ?string $assignee = null;

	/**
	 * Candidate uids — the readable half of the pool; the index half lives in
	 * `openregister_task_candidates` and one write path maintains both.
	 *
	 * @var array|null
	 */
	protected ?array $candidateUsers = null;

	/**
	 * Candidate group ids.
	 *
	 * @var array|null
	 */
	protected ?array $candidateGroups = null;

	/**
	 * A role name resolved to people at authorization time.
	 *
	 * @var string|null
	 */
	protected ?string $candidateRole = null;

	/**
	 * single-role|or-set|hierarchical|round-robin|least-loaded.
	 *
	 * @var string|null
	 */
	protected ?string $routingStrategy = null;

	/**
	 * Performer used when the strategy resolves to nobody. Absent one, the
	 * task stays POOLED — never implicitly assigned.
	 *
	 * @var string|null
	 */
	protected ?string $routingFallback = null;

	/**
	 * The original performer a delegate acts for.
	 *
	 * @var string|null
	 */
	protected ?string $onBehalfOf = null;

	/**
	 * The authority a delegation relies on.
	 *
	 * @var string|null
	 */
	protected ?string $mandate = null;

	/**
	 * Who asked for this work. Distinct from the performer.
	 *
	 * @var string|null
	 */
	protected ?string $requester = null;

	/**
	 * Read visibility only; no lifecycle rights whatsoever.
	 *
	 * @var array|null
	 */
	protected ?array $watchers = null;

	/**
	 * Earliest sensible start. Stored here, interpreted by
	 * flow-business-timers.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $startAt = null;

	/**
	 * ADVISORY deadline: passing it changes reporting, never state.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $dueAt = null;

	/**
	 * ENFORCING deadline: passing it makes the task eligible for automatic
	 * termination (the sweep itself belongs to flow-business-timers).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * Declared behaviour when the enforcing deadline passes: one value of
	 * the reserved timer-outcome vocabulary (skip|error|dead_letter). Null
	 * means no declared behaviour — the bare deadline enforces nothing.
	 *
	 * @var string|null
	 */
	protected ?string $onTimeout = null;

	/**
	 * Declared behaviour on a rejecting completion: one value of the
	 * reserved timer-outcome vocabulary. Only `dead_letter` reroutes the
	 * record; `skip` and `error` are the resuming consumer's contract.
	 *
	 * @var string|null
	 */
	protected ?string $onReject = null;

	/**
	 * SLA magnitude. Stored, not interpreted here.
	 *
	 * @var integer|null
	 */
	protected ?int $slaValue = null;

	/**
	 * SLA unit. Stored, not interpreted here.
	 *
	 * @var string|null
	 */
	protected ?string $slaUnit = null;

	/**
	 * Compliance period in days. Stored, not interpreted here.
	 *
	 * @var integer|null
	 */
	protected ?int $compliancePeriodDays = null;

	/**
	 * Until when the task is suspended. Stored, not interpreted here.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $suspendedUntil = null;

	/**
	 * Recurrence rule. Stored, not interpreted here.
	 *
	 * @var string|null
	 */
	protected ?string $recurrence = null;

	/**
	 * low|normal|high|urgent — the one normalised scale.
	 *
	 * @var string|null
	 */
	protected ?string $priority = 'normal';

	/**
	 * The ONE generic anchor: the subject object's uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The subject object's register.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The subject object's schema.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The template this task was created from, when any.
	 *
	 * @var string|null
	 */
	protected ?string $templateId = null;

	/**
	 * The template version at creation.
	 *
	 * @var integer|null
	 */
	protected ?int $templateVersion = null;

	/**
	 * The template content FROZEN at creation. All later evaluation reads
	 * this, never the live template.
	 *
	 * @var array|null
	 */
	protected ?array $templateSnapshot = null;

	/**
	 * The sequence this task is a position of, when any
	 * (flow-approval-consolidation). Null for every task outside an ordered
	 * approval.
	 *
	 * @var string|null
	 */
	protected ?string $sequenceUuid = null;

	/**
	 * This task's ordinal within its sequence. Stable and unique per
	 * sequence, enforced by a unique index.
	 *
	 * @var integer|null
	 */
	protected ?int $sequencePosition = null;

	/**
	 * The legacy approval step this task was migrated from, when any. The
	 * reconciliation pair with `migrated_task_uuid` on the kept step row.
	 *
	 * @var integer|null
	 */
	protected ?int $legacyStepId = null;

	/**
	 * Typed checklist: a list of {id, label, description, checked} entries.
	 * Never a string containing JSON.
	 *
	 * @var array|null
	 */
	protected ?array $checklist = null;

	/**
	 * Append-only response log.
	 *
	 * @var array|null
	 */
	protected ?array $responses = null;

	/**
	 * Progress percentage.
	 *
	 * @var integer|null
	 */
	protected ?int $percentComplete = null;

	/**
	 * When the task completed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $completedAt = null;

	/**
	 * Who completed it (the ACTING identity; delegation detail is in the audit).
	 *
	 * @var string|null
	 */
	protected ?string $completedBy = null;

	/**
	 * Free-text result.
	 *
	 * @var string|null
	 */
	protected ?string $resultText = null;

	/**
	 * Completion comment. MANDATORY on a rejecting or returning outcome.
	 *
	 * @var string|null
	 */
	protected ?string $comment = null;

	/**
	 * File references supporting the completion.
	 *
	 * @var array|null
	 */
	protected ?array $evidence = null;

	/**
	 * Why a supervisor overrode the normal path, when one did.
	 *
	 * @var string|null
	 */
	protected ?string $overrideReason = null;

	/**
	 * Subtask parent.
	 *
	 * @var integer|null
	 */
	protected ?int $parentTaskId = null;

	/**
	 * Epic parent — a second hierarchy on purpose: planix has both, and
	 * overloading one `parent` is what makes its two indistinguishable today.
	 *
	 * @var integer|null
	 */
	protected ?int $epicTaskId = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last-modified timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Who created the task.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'taskKey', type: 'string');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'metadata', type: 'json');
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'nodeId', type: 'string');
		$this->addType(fieldName: 'definitionVersion', type: 'integer');
		$this->addType(fieldName: 'appId', type: 'string');
		$this->addType(fieldName: 'workflowStepId', type: 'string');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'isTerminal', type: 'boolean');
		$this->addType(fieldName: 'lastAction', type: 'string');
		$this->addType(fieldName: 'outcome', type: 'string');
		$this->addType(fieldName: 'blockedReason', type: 'string');
		$this->addType(fieldName: 'performerType', type: 'string');
		$this->addType(fieldName: 'assignee', type: 'string');
		$this->addType(fieldName: 'candidateUsers', type: 'json');
		$this->addType(fieldName: 'candidateGroups', type: 'json');
		$this->addType(fieldName: 'candidateRole', type: 'string');
		$this->addType(fieldName: 'routingStrategy', type: 'string');
		$this->addType(fieldName: 'routingFallback', type: 'string');
		$this->addType(fieldName: 'onBehalfOf', type: 'string');
		$this->addType(fieldName: 'mandate', type: 'string');
		$this->addType(fieldName: 'requester', type: 'string');
		$this->addType(fieldName: 'watchers', type: 'json');
		$this->addType(fieldName: 'startAt', type: 'datetime');
		$this->addType(fieldName: 'dueAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'onTimeout', type: 'string');
		$this->addType(fieldName: 'onReject', type: 'string');
		$this->addType(fieldName: 'slaValue', type: 'integer');
		$this->addType(fieldName: 'slaUnit', type: 'string');
		$this->addType(fieldName: 'compliancePeriodDays', type: 'integer');
		$this->addType(fieldName: 'suspendedUntil', type: 'datetime');
		$this->addType(fieldName: 'recurrence', type: 'string');
		$this->addType(fieldName: 'priority', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'templateId', type: 'string');
		$this->addType(fieldName: 'templateVersion', type: 'integer');
		$this->addType(fieldName: 'templateSnapshot', type: 'json');
		$this->addType(fieldName: 'sequenceUuid', type: 'string');
		$this->addType(fieldName: 'sequencePosition', type: 'integer');
		$this->addType(fieldName: 'legacyStepId', type: 'integer');
		$this->addType(fieldName: 'checklist', type: 'json');
		$this->addType(fieldName: 'responses', type: 'json');
		$this->addType(fieldName: 'percentComplete', type: 'integer');
		$this->addType(fieldName: 'completedAt', type: 'datetime');
		$this->addType(fieldName: 'completedBy', type: 'string');
		$this->addType(fieldName: 'resultText', type: 'string');
		$this->addType(fieldName: 'comment', type: 'string');
		$this->addType(fieldName: 'evidence', type: 'json');
		$this->addType(fieldName: 'overrideReason', type: 'string');
		$this->addType(fieldName: 'parentTaskId', type: 'integer');
		$this->addType(fieldName: 'epicTaskId', type: 'integer');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'createdBy', type: 'string');

	}//end __construct()

	/**
	 * Whether this task will never move again.
	 *
	 * Reads the state set membership, NOT the materialised column: the
	 * column exists for indexed queries, and a test asserts the two agree
	 * across every transition.
	 *
	 * @return boolean True when the state is terminal.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-one-lifecycle-with-every-legacy-value-mapped-onto-it
	 */
	public function isInTerminalState(): bool {
		return in_array($this->state, self::TERMINAL_STATES, true);
	}//end isInTerminalState()

	/**
	 * Hydrate entity from array.
	 *
	 * @param array<string, mixed> $object Data to hydrate from.
	 *
	 * @return self This task.
	 *
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function hydrate(array $object): self {
		foreach ($object as $fieldName => $value) {
			if (array_key_exists($fieldName, $this->getFieldTypes()) === false || $fieldName === 'id') {
				continue;
			}

			$setter = 'set' . ucfirst($fieldName);
			$this->$setter($value);
		}

		return $this;
	}//end hydrate()

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
	 * @spec openspec/changes/flow-task-entity/specs/flow-tasks/spec.md#requirement-a-task-is-a-first-class-record-not-a-flow-artefact
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'key' => $this->taskKey,
			'title' => $this->title,
			'description' => $this->description,
			'metadata' => $this->metadata,
			'runUuid' => $this->runUuid,
			'nodeId' => $this->nodeId,
			'definitionVersion' => $this->definitionVersion,
			'appId' => $this->appId,
			'workflowStepId' => $this->workflowStepId,
			'organisation' => $this->organisation,
			'state' => $this->state,
			'isTerminal' => $this->isTerminal,
			'lastAction' => $this->lastAction,
			'outcome' => $this->outcome,
			'blockedReason' => $this->blockedReason,
			'performerType' => $this->performerType,
			'assignee' => $this->assignee,
			'candidateUsers' => $this->candidateUsers,
			'candidateGroups' => $this->candidateGroups,
			'candidateRole' => $this->candidateRole,
			'routingStrategy' => $this->routingStrategy,
			'routingFallback' => $this->routingFallback,
			'onBehalfOf' => $this->onBehalfOf,
			'mandate' => $this->mandate,
			'requester' => $this->requester,
			'watchers' => $this->watchers,
			'startAt' => $this->startAt?->format('c'),
			'dueAt' => $this->dueAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
			'onTimeout' => $this->onTimeout,
			'onReject' => $this->onReject,
			'slaValue' => $this->slaValue,
			'slaUnit' => $this->slaUnit,
			'compliancePeriodDays' => $this->compliancePeriodDays,
			'suspendedUntil' => $this->suspendedUntil?->format('c'),
			'recurrence' => $this->recurrence,
			'priority' => $this->priority,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'templateId' => $this->templateId,
			'templateVersion' => $this->templateVersion,
			'templateSnapshot' => $this->templateSnapshot,
			'sequenceUuid' => $this->sequenceUuid,
			'sequencePosition' => $this->sequencePosition,
			'legacyStepId' => $this->legacyStepId,
			'checklist' => $this->checklist,
			'responses' => $this->responses,
			'percentComplete' => $this->percentComplete,
			'completedAt' => $this->completedAt?->format('c'),
			'completedBy' => $this->completedBy,
			'resultText' => $this->resultText,
			'comment' => $this->comment,
			'evidence' => $this->evidence,
			'overrideReason' => $this->overrideReason,
			'parentTaskId' => $this->parentTaskId,
			'epicTaskId' => $this->epicTaskId,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
			'createdBy' => $this->createdBy,
		];
	}//end jsonSerialize()
}//end class
