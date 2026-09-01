<?php

/**
 * One fired escalation rung: the dedup ledger row.
 *
 * Unique on `(timer_uuid, rung_key)`, so the INSERT is the claim: a second
 * sweep pass inserting the same key loses at the database, not at a
 * read-then-write over a document (design D-7). The row records the
 * TRANSITION RAISED and the roles it addressed, never "notified": this change
 * does not know whether anything was delivered.
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
 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * The rung-fire ledger row.
 *
 * @method string|null getTimerUuid()
 * @method void setTimerUuid(?string $timerUuid)
 * @method string|null getRungKey()
 * @method void setRungKey(?string $rungKey)
 * @method DateTime|null getFiredAt()
 * @method void setFiredAt(?DateTime $firedAt)
 * @method string|null getTransitionAction()
 * @method void setTransitionAction(?string $transitionAction)
 * @method array|null getRecipientRoles()
 * @method void setRecipientRoles(?array $recipientRoles)
 * @method string|null getPriority()
 * @method void setPriority(?string $priority)
 * @method bool|null getInherited()
 * @method void setInherited(?bool $inherited)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 */
class FlowTimerFire extends Entity implements JsonSerializable {

	/**
	 * The timer the rung belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $timerUuid = null;

	/**
	 * The rung's stable identity, e.g. `preBreach:14:calendarDays`.
	 *
	 * @var string|null
	 */
	protected ?string $rungKey = null;

	/**
	 * When the rung fired (or was inherited).
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $firedAt = null;

	/**
	 * The named transition raised.
	 *
	 * @var string|null
	 */
	protected ?string $transitionAction = null;

	/**
	 * The roles the rung addressed.
	 *
	 * @var array<int, string>|null
	 */
	protected ?array $recipientRoles = null;

	/**
	 * The rung's priority.
	 *
	 * @var string|null
	 */
	protected ?string $priority = null;

	/**
	 * True when copied forward from a superseded timer rather than fired.
	 *
	 * @var boolean|null
	 */
	protected ?bool $inherited = false;

	/**
	 * Creation stamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor: declares the column types.
	 */
	public function __construct() {
		$this->addType(fieldName: 'timerUuid', type: 'string');
		$this->addType(fieldName: 'rungKey', type: 'string');
		$this->addType(fieldName: 'firedAt', type: 'datetime');
		$this->addType(fieldName: 'transitionAction', type: 'string');
		$this->addType(fieldName: 'recipientRoles', type: 'json');
		$this->addType(fieldName: 'priority', type: 'string');
		$this->addType(fieldName: 'inherited', type: 'boolean');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The fire row.
	 *
	 * @spec openspec/changes/flow-business-timers/specs/flow-business-timers/spec.md#requirement-each-escalation-rung-fires-exactly-once
	 */
	public function jsonSerialize(): array {
		$firedAt = null;
		if ($this->firedAt !== null) {
			$firedAt = $this->firedAt->format('c');
		}

		return [
			'id' => $this->id,
			'timerUuid' => $this->timerUuid,
			'rungKey' => $this->rungKey,
			'firedAt' => $firedAt,
			'transitionAction' => $this->transitionAction,
			'recipientRoles' => $this->recipientRoles,
			'priority' => $this->priority,
			'inherited' => $this->inherited,
		];
	}//end jsonSerialize()
}//end class
