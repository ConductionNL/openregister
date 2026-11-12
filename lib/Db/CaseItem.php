<?php

/**
 * A plan item: one occurrence in a case plan, anchored to an OpenRegister
 * object.
 *
 * A plan item is NOT a task. The task is the work; the plan item is the
 * occurrence in the plan that decided the work should exist (design D-6).
 * A `humanTask` item is realised by a task row, a `stage` may be realised by
 * a flow run, and a `milestone` is realised by nothing at all. The link
 * always runs FROM this row TO the realisation (`realisation_uuid`), never
 * the other way: `openregister_tasks` gains no column for this.
 *
 * There is no case entity. `object_uuid` + `register_id` + `schema_id` is
 * the same triple a flow run carries as `subject_*` and a task carries as
 * `object_*`: the zaak, the bezwaar, the vergunning IS the case (design D-3).
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
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCA\OpenRegister\Db\CaseItem\CaseItemFieldTypes;
use OCP\AppFramework\Db\Entity;

/**
 * Class CaseItem
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getItemKey()
 * @method void setItemKey(?string $itemKey)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method integer|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method integer|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getFlowUuid()
 * @method void setFlowUuid(?string $flowUuid)
 * @method integer|null getFlowVersion()
 * @method void setFlowVersion(?int $flowVersion)
 * @method string|null getDefinitionItemKey()
 * @method void setDefinitionItemKey(?string $definitionItemKey)
 * @method string|null getOrigin()
 * @method void setOrigin(?string $origin)
 * @method integer|null getParentItemId()
 * @method void setParentItemId(?int $parentItemId)
 * @method string|null getPlanItemType()
 * @method void setPlanItemType(?string $planItemType)
 * @method integer|null getPosition()
 * @method void setPosition(?int $position)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method bool|null getIsTerminal()
 * @method void setIsTerminal(?bool $isTerminal)
 * @method DateTime|null getEnteredAt()
 * @method void setEnteredAt(?DateTime $enteredAt)
 * @method string|null getTerminatedReason()
 * @method void setTerminatedReason(?string $terminatedReason)
 * @method array|null getEntryCriteria()
 * @method void setEntryCriteria(?array $entryCriteria)
 * @method array|null getExitCriteria()
 * @method void setExitCriteria(?array $exitCriteria)
 * @method bool|null getRequired()
 * @method void setRequired(?bool $required)
 * @method bool|null getDiscretionary()
 * @method void setDiscretionary(?bool $discretionary)
 * @method array|null getRepetition()
 * @method void setRepetition(?array $repetition)
 * @method string|null getRealisationKind()
 * @method void setRealisationKind(?string $realisationKind)
 * @method string|null getRealisationUuid()
 * @method void setRealisationUuid(?string $realisationUuid)
 * @method integer|null getRealisationCount()
 * @method void setRealisationCount(?int $realisationCount)
 * @method array|null getAuthorizationRules()
 * @method void setAuthorizationRules(?array $authorizationRules)
 * @method array|null getCandidateUsers()
 * @method void setCandidateUsers(?array $candidateUsers)
 * @method array|null getCandidateGroups()
 * @method void setCandidateGroups(?array $candidateGroups)
 * @method string|null getCandidateRole()
 * @method void setCandidateRole(?string $candidateRole)
 * @method DateTime|null getDueAt()
 * @method void setDueAt(?DateTime $dueAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method string|null getDoorlooptijd()
 * @method void setDoorlooptijd(?string $doorlooptijd)
 * @method string|null getServicenorm()
 * @method void setServicenorm(?string $servicenorm)
 * @method array|null getPlanSettings()
 * @method void setPlanSettings(?array $planSettings)
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
 * `openregister_case_items` (design.md, Data model).
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The @method block, the typed
 * properties and the field-by-field constructor scale linearly with the
 * column count, same as {@see Task} and {@see FlowRun}.
 * @SuppressWarnings(PHPMD.ExcessivePublicCount) Entity getters/setters are
 * the column surface, not an API design choice.
 *
 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
 */
class CaseItem extends Entity implements JsonSerializable {
	use HydrateKnownFieldsTrait;

	/**
	 * The six CMMN plan-item states: the task's states, declared once on
	 * {@see Task} (one-engine-bpmn-and-cmmn).
	 */
	public const STATE_AVAILABLE = Task::STATE_AVAILABLE;

	public const STATE_ENABLED = Task::STATE_ENABLED;

	public const STATE_ACTIVE = Task::STATE_ACTIVE;

	public const STATE_COMPLETED = Task::STATE_COMPLETED;

	public const STATE_TERMINATED = Task::STATE_TERMINATED;

	public const STATE_DISABLED = Task::STATE_DISABLED;

	/**
	 * Every persistable state.
	 *
	 * @var array<int, string>
	 */
	public const STATES = Task::STATES;

	/**
	 * States out of which no plan item of any type ever moves.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_STATES = Task::TERMINAL_STATES;

	/**
	 * The plan-item types.
	 */
	public const TYPE_STAGE = 'stage';

	public const TYPE_HUMAN_TASK = 'humanTask';

	public const TYPE_MILESTONE = 'milestone';

	/**
	 * A work item realised by a flow run (CMMN ProcessTask): the case layer
	 * starting a BPMN process.
	 */
	public const TYPE_PROCESS_TASK = 'processTask';

	/**
	 * Every plan-item type.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = [
		self::TYPE_STAGE,
		self::TYPE_HUMAN_TASK,
		self::TYPE_PROCESS_TASK,
		self::TYPE_MILESTONE,
	];

	/**
	 * Where a plan item came from.
	 */
	public const ORIGIN_DEFINED = 'defined';

	public const ORIGIN_DISCRETIONARY = 'discretionary';

	public const ORIGIN_ADHOC = 'adhoc';

	/**
	 * Every origin.
	 *
	 * @var array<int, string>
	 */
	public const ORIGINS = [
		self::ORIGIN_DEFINED,
		self::ORIGIN_DISCRETIONARY,
		self::ORIGIN_ADHOC,
	];

	/**
	 * What realises an active item.
	 */
	public const REALISATION_TASK = 'task';

	public const REALISATION_RUN = 'run';

	public const REALISATION_NONE = 'none';

	/**
	 * Public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Stable id within the plan; what a sentry's on-part names. Shared by
	 * every realisation of a repeating item.
	 *
	 * @var string|null
	 */
	protected ?string $itemKey = null;

	/**
	 * Human name.
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * Longer description.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * The anchor: the object that IS the case.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The anchor's register.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The anchor's schema.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * Definition provenance: the flow whose case definition produced this
	 * item. Null for an ad-hoc item.
	 *
	 * @var string|null
	 */
	protected ?string $flowUuid = null;

	/**
	 * The pinned definition version.
	 *
	 * @var integer|null
	 */
	protected ?int $flowVersion = null;

	/**
	 * The key in the definition this row was created from. Null for ad-hoc.
	 *
	 * @var string|null
	 */
	protected ?string $definitionItemKey = null;

	/**
	 * defined | discretionary | adhoc.
	 *
	 * @var string|null
	 */
	protected ?string $origin = null;

	/**
	 * The containing stage, or null at the plan root.
	 *
	 * @var integer|null
	 */
	protected ?int $parentItemId = null;

	/**
	 * stage | humanTask | processTask | milestone.
	 *
	 * @var string|null
	 */
	protected ?string $planItemType = null;

	/**
	 * Display order among siblings.
	 *
	 * @var integer|null
	 */
	protected ?int $position = 0;

	/**
	 * One of the six states.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * Materialised terminality, written in the SAME statement as `state`.
	 *
	 * @var boolean|null
	 */
	protected ?bool $isTerminal = false;

	/**
	 * When the item first became active (or completed, for a milestone).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $enteredAt = null;

	/**
	 * Why the item was terminated, when it was.
	 *
	 * @var string|null
	 */
	protected ?string $terminatedReason = null;

	/**
	 * Entry sentries. Empty means: satisfied as soon as the parent is active.
	 *
	 * @var array|null
	 */
	protected ?array $entryCriteria = null;

	/**
	 * Exit sentries. Empty means: never satisfied.
	 *
	 * @var array|null
	 */
	protected ?array $exitCriteria = null;

	/**
	 * Whether the parent's completion rule waits for this item.
	 *
	 * @var boolean|null
	 */
	protected ?bool $required = true;

	/**
	 * Whether entering requires an explicit act.
	 *
	 * @var boolean|null
	 */
	protected ?bool $discretionary = false;

	/**
	 * The repetition rule, when any: `{"max": N}`.
	 *
	 * @var array|null
	 */
	protected ?array $repetition = null;

	/**
	 * task | run | none.
	 *
	 * @var string|null
	 */
	protected ?string $realisationKind = null;

	/**
	 * The task or run uuid realising this row.
	 *
	 * @var string|null
	 */
	protected ?string $realisationUuid = null;

	/**
	 * Which realisation of the item this row is (1 for a non-repeating item).
	 *
	 * @var integer|null
	 */
	protected ?int $realisationCount = 1;

	/**
	 * Who may enable or attach here: a list of group ids, `user:<uid>` and
	 * `role:<name>` entries. Serialised as `authorization`.
	 *
	 * @var array|null
	 */
	protected ?array $authorizationRules = null;

	/**
	 * Performer hint passed to the task on realisation.
	 *
	 * @var array|null
	 */
	protected ?array $candidateUsers = null;

	/**
	 * Performer hint passed to the task on realisation.
	 *
	 * @var array|null
	 */
	protected ?array $candidateGroups = null;

	/**
	 * Performer hint passed to the task on realisation.
	 *
	 * @var string|null
	 */
	protected ?string $candidateRole = null;

	/**
	 * Advisory deadline, carried onto the task.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $dueAt = null;

	/**
	 * Enforcing deadline, carried onto the task.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * The zaaktype's doorlooptijd, carried for flow-business-timers.
	 *
	 * @var string|null
	 */
	protected ?string $doorlooptijd = null;

	/**
	 * The zaaktype's servicenorm, carried for flow-business-timers.
	 *
	 * @var string|null
	 */
	protected ?string $servicenorm = null;

	/**
	 * Plan-level settings, frozen at creation: `authorization` (root),
	 * `results` (the allowed end states), `writeThrough` (field mapping).
	 *
	 * @var array|null
	 */
	protected ?array $planSettings = null;

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
	 * Who created the item.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * Constructor: declare field types so the mapper hydrates them correctly.
	 */
	public function __construct() {
		foreach (CaseItemFieldTypes::TYPES as $fieldName => $type) {
			$this->addType(fieldName: $fieldName, type: $type);
		}
	}//end __construct()

	/**
	 * Whether this row will never move again.
	 *
	 * Reads the state set, NOT the materialised column: the column exists for
	 * indexed queries, and a test asserts the two agree.
	 *
	 * @return boolean True when the state is terminal.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-one-lifecycle-table-governs-every-plan-item
	 */
	public function isInTerminalState(): bool {
		return in_array($this->state, self::TERMINAL_STATES, true);
	}//end isInTerminalState()

	/**
	 * Whether this row has been entered (is or was past `available`).
	 *
	 * @return boolean True for enabled, active or a terminal state other than disabled.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-stages-nest-and-complete-by-a-written-rule
	 */
	public function isEntered(): bool {
		return $this->state !== null
			&& $this->state !== self::STATE_AVAILABLE
			&& $this->state !== self::STATE_DISABLED;
	}//end isEntered()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The plan item as plain data.
	 *
	 * @spec openspec/specs/flow-cases/spec.md#requirement-the-case-is-the-openregister-object
	 */
	public function jsonSerialize(): array {
		return array_merge(
			(new TypedFieldsJson())->renamed(json: (new TypedFieldsJson())->serialise(fieldTypes: $this->getFieldTypes(), values: get_object_vars($this)), names: ['itemKey' => 'key', 'planItemType' => 'type', 'authorizationRules' => 'authorization']),
			[
				'entryCriteria' => ($this->entryCriteria ?? []),
				'exitCriteria' => ($this->exitCriteria ?? []),
			]
		);
	}//end jsonSerialize()
}//end class
