<?php

/**
 * One due moment of a calendar flow trigger for one record.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A row of `openregister_flow_moments`.
 *
 * @method string getFlowUuid()
 * @method void setFlowUuid(string $flowUuid)
 * @method string getNodeId()
 * @method void setNodeId(string $nodeId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string getDateValue()
 * @method void setDateValue(string $dateValue)
 * @method DateTime getDueAt()
 * @method void setDueAt(DateTime $dueAt)
 * @method DateTime|null getFiredAt()
 * @method void setFiredAt(?DateTime $firedAt)
 * @method string|null getRunUuid()
 * @method void setRunUuid(?string $runUuid)
 * @method bool|null getSkipped()
 * @method void setSkipped(?bool $skipped)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
 */
class FlowMoment extends Entity {

	/**
	 * The flow.
	 *
	 * @var string|null
	 */
	protected ?string $flowUuid = null;

	/**
	 * The calendar trigger node within the flow.
	 *
	 * @var string|null
	 */
	protected ?string $nodeId = null;

	/**
	 * The record whose date the moment is counted from.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The date value the moment was counted from, as stored on the record.
	 *
	 * @var string|null
	 */
	protected ?string $dateValue = null;

	/**
	 * When the moment comes due.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $dueAt = null;

	/**
	 * When the moment fired or was skipped; null while it waits.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $firedAt = null;

	/**
	 * The run it queued.
	 *
	 * @var string|null
	 */
	protected ?string $runUuid = null;

	/**
	 * Whether it was skipped because it lay before the trigger was enabled.
	 *
	 * @var boolean|null
	 */
	protected ?bool $skipped = false;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/flow-calendar-and-webhook-triggers/tasks.md#1-calendar-trigger
	 */
	public function __construct() {
		$this->addType(fieldName: 'flowUuid', type: Types::STRING);
		$this->addType(fieldName: 'nodeId', type: Types::STRING);
		$this->addType(fieldName: 'objectUuid', type: Types::STRING);
		$this->addType(fieldName: 'dateValue', type: Types::STRING);
		$this->addType(fieldName: 'dueAt', type: Types::DATETIME);
		$this->addType(fieldName: 'firedAt', type: Types::DATETIME);
		$this->addType(fieldName: 'runUuid', type: Types::STRING);
		$this->addType(fieldName: 'skipped', type: Types::BOOLEAN);
	}//end __construct()
}//end class
