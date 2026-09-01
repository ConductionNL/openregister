<?php

/**
 * One evidence row in a timer's history: armed, suspended, resumed, extended,
 * superseded, fired, breached or cancelled.
 *
 * Append-only: the mapper exposes insert and read, no update and no delete,
 * and a timer's cancellation does not cascade here. The suspension of a legal
 * term is a decision that has to stay evidenced, with the acting identity,
 * the moment, the reason and the legal basis (`Awb 4:15`).
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
 *
 * @method string|null getTimerUuid()
 * @method void setTimerUuid(?string $timerUuid)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method DateTime|null getPriorFireAt()
 * @method void setPriorFireAt(?DateTime $priorFireAt)
 * @method DateTime|null getNewFireAt()
 * @method void setNewFireAt(?DateTime $newFireAt)
 * @method float|null getDaysImpact()
 * @method void setDaysImpact(?float $daysImpact)
 * @method string|null getBasis()
 * @method void setBasis(?string $basis)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * The timer history row.
 */
class FlowTimerEvent extends Entity implements JsonSerializable {

	/**
	 * Event types.
	 */
	public const TYPE_ARMED = 'armed';

	public const TYPE_SUSPENDED = 'suspended';

	public const TYPE_RESUMED = 'resumed';

	public const TYPE_EXTENDED = 'extended';

	public const TYPE_SUPERSEDED = 'superseded';

	public const TYPE_FIRED = 'fired';

	public const TYPE_BREACHED = 'breached';

	public const TYPE_CANCELLED = 'cancelled';

	/**
	 * The timer this event belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $timerUuid = null;

	/**
	 * The event type.
	 *
	 * @var string|null
	 */
	protected ?string $type = null;

	/**
	 * The acting identity.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * The recorded reason.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * The fire moment before the event.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $priorFireAt = null;

	/**
	 * The fire moment after the event.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $newFireAt = null;

	/**
	 * The impact in the timer's budget unit.
	 *
	 * @var float|null
	 */
	protected ?float $daysImpact = null;

	/**
	 * The legal ground, e.g. `Awb 4:15`.
	 *
	 * @var string|null
	 */
	protected ?string $basis = null;

	/**
	 * Creation stamp: the moment of the event.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: declares the column types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'timerUuid', type: 'string');
		$this->addType(fieldName: 'type', type: 'string');
		$this->addType(fieldName: 'actor', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'priorFireAt', type: 'datetime');
		$this->addType(fieldName: 'newFireAt', type: 'datetime');
		$this->addType(fieldName: 'daysImpact', type: 'float');
		$this->addType(fieldName: 'basis', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The event.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-a-suspended-deadline-holds-elapsed-time-not-a-moment
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'timerUuid' => $this->timerUuid,
			'type' => $this->type,
			'actor' => $this->actor,
			'reason' => $this->reason,
			'priorFireAt' => $this->format($this->priorFireAt),
			'newFireAt' => $this->format($this->newFireAt),
			'daysImpact' => $this->daysImpact,
			'basis' => $this->basis,
			'created' => $this->format($this->created),
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
