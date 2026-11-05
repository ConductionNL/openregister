<?php

/**
 * OpenRegister ObjectUpdatingEvent
 *
 * This file contains the event class dispatched when an object is being updated
 * in the OpenRegister application. Supports hook-based rejection via StoppableEventInterface.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Event dispatched when an object is being updated.
 *
 * Implements StoppableEventInterface so hooks can reject updates.
 */
class ObjectUpdatingEvent extends Event implements StoppableEventInterface {
	use WriteVetoState;

	/**
	 * Constructor for ObjectUpdatingEvent
	 *
	 * @param ObjectEntity $newObject The object entity after update
	 * @param ObjectEntity|null $oldObject The object entity before update (null if not available)
	 *
	 * @return void
	 */
	public function __construct(private readonly ObjectEntity $newObject, private readonly ?ObjectEntity $oldObject = null) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the original object entity
	 *
	 * @return ObjectEntity|null The object entity before update (null if not available)
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOldObject(): ?ObjectEntity {
		return $this->oldObject;
	}//end getOldObject()

	/**
	 * Get the updated object entity
	 *
	 * @return ObjectEntity The object entity after update
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getNewObject(): ObjectEntity {
		return $this->newObject;
	}//end getNewObject()
}//end class
