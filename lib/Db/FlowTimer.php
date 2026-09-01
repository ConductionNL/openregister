<?php

/**
 * A durable business timer: a budget and a suspension ledger, not a moment.
 *
 * One row per timer, bound to a subject (`subject_type` + `subject_uuid`)
 * with the run and node as optional provenance. The term is stored as what it
 * IS — `budget_value` in `budget_unit`, with `consumed_value` the sum of all
 * completed running segments and `running_since` the start of the current
 * one — and `fire_at`/`next_rung_at` are derived from those by the ONE
 * recomputation in {@see \OCA\OpenRegister\Service\Flow\Timer\FlowTimerService}.
 * Nothing here is overdue: that is `state = armed AND fire_at < now`, read
 * from the clock, never written.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Entity
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * The business-timer row.
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column, same as
 * {@see Task} and {@see FlowRun}: the column count IS the field count.
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The typed properties and the
 * field-by-field constructor scale linearly with the column count.
 * @SuppressWarnings(PHPMD.ExcessivePublicCount) Entity getters/setters are
 * the column surface, not an API design choice.
 * @SuppressWarnings(PHPMD.LongVariable) `suspendedTotalSeconds` IS the column
 * `suspended_total_seconds`; the entity property name is the column mapping.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method array|null getMetadata()
 * @method void setMetadata(?array $metadata)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getSubjectUuid()
 * @method void setSubjectUuid(?string $subjectUuid)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method string|null getNodeId()
 * @method void setNodeId(?string $nodeId)
 * @method string|null getAppId()
 * @method void setAppId(?string $appId)
 * @method string|null getPurpose()
 * @method void setPurpose(?string $purpose)
 * @method string|null getLegalEffect()
 * @method void setLegalEffect(?string $legalEffect)
 * @method string|null getOnExpiry()
 * @method void setOnExpiry(?string $onExpiry)
 * @method string|null getAnchorEvent()
 * @method void setAnchorEvent(?string $anchorEvent)
 * @method integer|null getAnchorOffset()
 * @method void setAnchorOffset(?int $anchorOffset)
 * @method string|null getAnchorOffsetUnit()
 * @method void setAnchorOffsetUnit(?string $anchorOffsetUnit)
 * @method DateTime|null getAnchorAt()
 * @method void setAnchorAt(?DateTime $anchorAt)
 * @method float|null getBudgetValue()
 * @method void setBudgetValue(?float $budgetValue)
 * @method string|null getBudgetUnit()
 * @method void setBudgetUnit(?string $budgetUnit)
 * @method float|null getConsumedValue()
 * @method void setConsumedValue(?float $consumedValue)
 * @method DateTime|null getRunningSince()
 * @method void setRunningSince(?DateTime $runningSince)
 * @method DateTime|null getFireAt()
 * @method void setFireAt(?DateTime $fireAt)
 * @method DateTime|null getNextRungAt()
 * @method void setNextRungAt(?DateTime $nextRungAt)
 * @method string|null getCalendarSlug()
 * @method void setCalendarSlug(?string $calendarSlug)
 * @method string|null getLadderSlug()
 * @method void setLadderSlug(?string $ladderSlug)
 * @method array|null getEscalationRules()
 * @method void setEscalationRules(?array $escalationRules)
 * @method DateTime|null getSuspendedSince()
 * @method void setSuspendedSince(?DateTime $suspendedSince)
 * @method string|null getSuspendReason()
 * @method void setSuspendReason(?string $suspendReason)
 * @method integer|null getSuspendedTotalSeconds()
 * @method void setSuspendedTotalSeconds(?int $suspendedTotalSeconds)
 * @method integer|null getExtensionCount()
 * @method void setExtensionCount(?int $extensionCount)
 * @method integer|null getExtensionMax()
 * @method void setExtensionMax(?int $extensionMax)
 * @method string|null getState()
 * @method void setState(?string $state)
 * @method string|null getSupersedesUuid()
 * @method void setSupersedesUuid(?string $supersedesUuid)
 * @method DateTime|null getFiredAt()
 * @method void setFiredAt(?DateTime $firedAt)
 * @method bool|null getBreached()
 * @method void setBreached(?bool $breached)
 * @method DateTime|null getCancelledAt()
 * @method void setCancelledAt(?DateTime $cancelledAt)
 * @method string|null getCancelReason()
 * @method void setCancelReason(?string $cancelReason)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 */
class FlowTimer extends Entity implements JsonSerializable {

	/**
	 * Lifecycle states. Nothing else is persistable; there is NO overdue state.
	 */
	public const STATE_ARMED = 'armed';

	public const STATE_SUSPENDED = 'suspended';

	public const STATE_FIRED = 'fired';

	public const STATE_CANCELLED = 'cancelled';

	public const STATE_SUPERSEDED = 'superseded';

	/**
	 * Every persistable state.
	 *
	 * @var array<int, string>
	 */
	public const STATES = [
		self::STATE_ARMED,
		self::STATE_SUSPENDED,
		self::STATE_FIRED,
		self::STATE_CANCELLED,
		self::STATE_SUPERSEDED,
	];

	/**
	 * States from which a timer never moves again.
	 *
	 * @var array<int, string>
	 */
	public const TERMINAL_STATES = [
		self::STATE_FIRED,
		self::STATE_CANCELLED,
		self::STATE_SUPERSEDED,
	];

	/**
	 * Purposes: `due` advises, `expiry` enforces.
	 */
	public const PURPOSE_DUE = 'due';

	public const PURPOSE_EXPIRY = 'expiry';

	/**
	 * The two purposes.
	 *
	 * @var array<int, string>
	 */
	public const PURPOSES = [self::PURPOSE_DUE, self::PURPOSE_EXPIRY];

	/**
	 * Legal effect. Only `wettelijk` may carry an enforcing outcome.
	 */
	public const LEGAL_NONE = 'none';

	public const LEGAL_SERVICENORM = 'servicenorm';

	public const LEGAL_WETTELIJK = 'wettelijk';

	/**
	 * The legal-effect vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const LEGAL_EFFECTS = [self::LEGAL_NONE, self::LEGAL_SERVICENORM, self::LEGAL_WETTELIJK];

	/**
	 * Subject types a timer can be bound to.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECT_TYPES = ['task', 'object', 'run'];

	/**
	 * The three reserved enforcing outcomes; `transition:<action>` is the fourth shape.
	 *
	 * @var array<int, string>
	 */
	public const RESERVED_OUTCOMES = ['skip', 'error', 'dead_letter'];

	/**
	 * Public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Human title.
	 *
	 * @var string|null
	 */
	protected ?string $title = null;

	/**
	 * Free-form metadata.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $metadata = null;

	/**
	 * Subject type: task, object or run.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * Subject uuid.
	 *
	 * @var string|null
	 */
	protected ?string $subjectUuid = null;

	/**
	 * Owning organisation.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * Originating run (provenance, optional).
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * Originating node (provenance, optional).
	 *
	 * @var string|null
	 */
	protected ?string $nodeId = null;

	/**
	 * Owning app id.
	 *
	 * @var string|null
	 */
	protected ?string $appId = null;

	/**
	 * Purpose: due or expiry.
	 *
	 * @var string|null
	 */
	protected ?string $purpose = null;

	/**
	 * Legal effect.
	 *
	 * @var string|null
	 */
	protected ?string $legalEffect = null;

	/**
	 * Enforcing outcome (expiry timers with legal effect wettelijk only).
	 *
	 * @var string|null
	 */
	protected ?string $onExpiry = null;

	/**
	 * Named anchoring event.
	 *
	 * @var string|null
	 */
	protected ?string $anchorEvent = null;

	/**
	 * Offset from the anchoring event.
	 *
	 * @var integer|null
	 */
	protected ?int $anchorOffset = null;

	/**
	 * Unit of the anchor offset.
	 *
	 * @var string|null
	 */
	protected ?string $anchorOffsetUnit = null;

	/**
	 * The resolved instant the term runs from.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $anchorAt = null;

	/**
	 * The term, in its own unit.
	 *
	 * @var float|null
	 */
	protected ?float $budgetValue = null;

	/**
	 * Unit of the budget.
	 *
	 * @var string|null
	 */
	protected ?string $budgetUnit = null;

	/**
	 * Completed running time, in the budget unit.
	 *
	 * @var float|null
	 */
	protected ?float $consumedValue = 0.0;

	/**
	 * Start of the current running segment; NULL while suspended.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $runningSince = null;

	/**
	 * Projected fire instant; NULL while suspended. Derived.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $fireAt = null;

	/**
	 * Instant of the next unfired rung; NULL while suspended. Derived.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $nextRungAt = null;

	/**
	 * Named working calendar.
	 *
	 * @var string|null
	 */
	protected ?string $calendarSlug = null;

	/**
	 * Named escalation ladder.
	 *
	 * @var string|null
	 */
	protected ?string $ladderSlug = null;

	/**
	 * Inline escalation rules.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	protected ?array $escalationRules = null;

	/**
	 * When the current suspension began.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $suspendedSince = null;

	/**
	 * Why the timer is suspended.
	 *
	 * @var string|null
	 */
	protected ?string $suspendReason = null;

	/**
	 * Total seconds spent suspended (reporting only).
	 *
	 * @var integer|null
	 */
	protected ?int $suspendedTotalSeconds = 0;

	/**
	 * Extensions granted.
	 *
	 * @var integer|null
	 */
	protected ?int $extensionCount = 0;

	/**
	 * Extension bound.
	 *
	 * @var integer|null
	 */
	protected ?int $extensionMax = 1;

	/**
	 * Lifecycle state.
	 *
	 * @var string|null
	 */
	protected ?string $state = null;

	/**
	 * The timer this one supersedes.
	 *
	 * @var string|null
	 */
	protected ?string $supersedesUuid = null;

	/**
	 * When the timer fired.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $firedAt = null;

	/**
	 * Whether the deadline was breached. Permanent once set.
	 *
	 * @var boolean|null
	 */
	protected ?bool $breached = false;

	/**
	 * When the timer was cancelled.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $cancelledAt = null;

	/**
	 * Why the timer was cancelled.
	 *
	 * @var string|null
	 */
	protected ?string $cancelReason = null;

	/**
	 * Creation stamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Update stamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Creating identity.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * Constructor: declares the column types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'title', type: 'string');
		$this->addType(fieldName: 'metadata', type: 'json');
		$this->addType(fieldName: 'subjectType', type: 'string');
		$this->addType(fieldName: 'subjectUuid', type: 'string');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'runUuid', type: 'string');
		$this->addType(fieldName: 'nodeId', type: 'string');
		$this->addType(fieldName: 'appId', type: 'string');
		$this->addType(fieldName: 'purpose', type: 'string');
		$this->addType(fieldName: 'legalEffect', type: 'string');
		$this->addType(fieldName: 'onExpiry', type: 'string');
		$this->addType(fieldName: 'anchorEvent', type: 'string');
		$this->addType(fieldName: 'anchorOffset', type: 'integer');
		$this->addType(fieldName: 'anchorOffsetUnit', type: 'string');
		$this->addType(fieldName: 'anchorAt', type: 'datetime');
		$this->addType(fieldName: 'budgetValue', type: 'float');
		$this->addType(fieldName: 'budgetUnit', type: 'string');
		$this->addType(fieldName: 'consumedValue', type: 'float');
		$this->addType(fieldName: 'runningSince', type: 'datetime');
		$this->addType(fieldName: 'fireAt', type: 'datetime');
		$this->addType(fieldName: 'nextRungAt', type: 'datetime');
		$this->addType(fieldName: 'calendarSlug', type: 'string');
		$this->addType(fieldName: 'ladderSlug', type: 'string');
		$this->addType(fieldName: 'escalationRules', type: 'json');
		$this->addType(fieldName: 'suspendedSince', type: 'datetime');
		$this->addType(fieldName: 'suspendReason', type: 'string');
		$this->addType(fieldName: 'suspendedTotalSeconds', type: 'integer');
		$this->addType(fieldName: 'extensionCount', type: 'integer');
		$this->addType(fieldName: 'extensionMax', type: 'integer');
		$this->addType(fieldName: 'state', type: 'string');
		$this->addType(fieldName: 'supersedesUuid', type: 'string');
		$this->addType(fieldName: 'firedAt', type: 'datetime');
		$this->addType(fieldName: 'breached', type: 'boolean');
		$this->addType(fieldName: 'cancelledAt', type: 'datetime');
		$this->addType(fieldName: 'cancelReason', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');
		$this->addType(fieldName: 'createdBy', type: 'string');

	}//end __construct()

	/**
	 * Whether the timer can still fire or be mutated.
	 *
	 * @return boolean True for armed and suspended.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function isOpen(): bool {
		return in_array((string)$this->state, self::TERMINAL_STATES, true) === false;
	}//end isOpen()

	/**
	 * Whether the timer enforces (applies an outcome) when it fires.
	 *
	 * @return boolean True for an expiry timer carrying an outcome.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
	 */
	public function isEnforcing(): bool {
		return $this->purpose === self::PURPOSE_EXPIRY && $this->onExpiry !== null && $this->onExpiry !== '';
	}//end isEnforcing()

	/**
	 * Serialise for the API. Carries NO overdue flag: that is derived by the reader.
	 *
	 * @return array<string, mixed> The timer.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-overdue-is-derived-from-the-clock-and-never-stored
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'title' => $this->title,
			'metadata' => $this->metadata,
			'subjectType' => $this->subjectType,
			'subjectUuid' => $this->subjectUuid,
			'organisation' => $this->organisation,
			'runUuid' => $this->runUuid,
			'nodeId' => $this->nodeId,
			'appId' => $this->appId,
			'purpose' => $this->purpose,
			'legalEffect' => $this->legalEffect,
			'onExpiry' => $this->onExpiry,
			'anchorEvent' => $this->anchorEvent,
			'anchorOffset' => $this->anchorOffset,
			'anchorOffsetUnit' => $this->anchorOffsetUnit,
			'anchorAt' => $this->format(value: $this->anchorAt),
			'budgetValue' => $this->budgetValue,
			'budgetUnit' => $this->budgetUnit,
			'consumedValue' => $this->consumedValue,
			'runningSince' => $this->format(value: $this->runningSince),
			'fireAt' => $this->format(value: $this->fireAt),
			'nextRungAt' => $this->format(value: $this->nextRungAt),
			'calendarSlug' => $this->calendarSlug,
			'ladderSlug' => $this->ladderSlug,
			'escalationRules' => $this->escalationRules,
			'suspendedSince' => $this->format(value: $this->suspendedSince),
			'suspendReason' => $this->suspendReason,
			'suspendedTotalSeconds' => $this->suspendedTotalSeconds,
			'extensionCount' => $this->extensionCount,
			'extensionMax' => $this->extensionMax,
			'state' => $this->state,
			'supersedesUuid' => $this->supersedesUuid,
			'firedAt' => $this->format(value: $this->firedAt),
			'breached' => $this->breached,
			'cancelledAt' => $this->format(value: $this->cancelledAt),
			'cancelReason' => $this->cancelReason,
			'created' => $this->format(value: $this->created),
			'updated' => $this->format(value: $this->updated),
			'createdBy' => $this->createdBy,
		];
	}//end jsonSerialize()

	/**
	 * ISO-8601 or null.
	 *
	 * @param DateTime|null $value The moment.
	 *
	 * @return string|null The formatted moment.
	 */
	private function format(?DateTime $value): ?string {
		if ($value === null) {
			return null;
		}

		return $value->format('c');
	}//end format()
}//end class
