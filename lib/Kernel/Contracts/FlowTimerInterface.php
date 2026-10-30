<?php
/**
 * FlowTimerInterface: what callers outside lib/Db need of a FlowTimer row.
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
 * A flow timer: a due or expiry deadline on a task, object, run or case item.
 *
 * Implemented by \OCA\OpenRegister\Db\FlowTimer. The column accessors are
 * served by Nextcloud's Entity::__call, so they are listed as tags here.
 *
 * @method int getId()
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
 * @method string|null getRollToWorkingDay()
 * @method void setRollToWorkingDay(?string $rollToWorkingDay)
 * @method DateTime|null getUnrolledAt()
 * @method void setUnrolledAt(?DateTime $unrolledAt)
 * @method string|null getRolledBy()
 * @method void setRolledBy(?string $rolledBy)
 * @method string|null getRollToWorkingDay()
 * @method void setRollToWorkingDay(?string $rollToWorkingDay)
 * @method DateTime|null getUnrolledAt()
 * @method void setUnrolledAt(?DateTime $unrolledAt)
 * @method string|null getRolledBy()
 * @method void setRolledBy(?string $rolledBy)
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
interface FlowTimerInterface extends JsonSerializable {

	/**
	 * Whether the timer can still fire or be mutated.
	 *
	 * @return boolean True for armed and suspended.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-business-timer-is-durable-subject-bound-and-cancelled-by-completion
	 */
	public function isOpen(): bool;

	/**
	 * Whether the timer enforces (applies an outcome) when it fires.
	 *
	 * @return boolean True for an expiry timer carrying an outcome.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-an-advisory-due-date-notifies-an-enforcing-expiry-transitions
	 */
	public function isEnforcing(): bool;

	/**
	 * Serialise for the API. Carries NO overdue flag: that is derived by the reader.
	 *
	 * @return array<string, mixed> The timer.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-overdue-is-derived-from-the-clock-and-never-stored
	 */
	public function jsonSerialize(): array;
}//end interface
