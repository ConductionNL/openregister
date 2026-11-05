<?php

/**
 * OpenRegister ObjectDeletingEvent
 *
 * This file contains the event class dispatched when an object is being deleted
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
 * Event dispatched when an object is being deleted.
 *
 * Implements StoppableEventInterface so hooks can reject deletion.
 */
class ObjectDeletingEvent extends Event implements StoppableEventInterface {
	use WriteVetoState;

	/**
	 * The object entity being deleted
	 *
	 * @var ObjectEntity The object entity that is being deleted
	 */
	private ObjectEntity $object;

	/**
	 * Constructor for ObjectDeletingEvent
	 *
	 * @param ObjectEntity $object The object entity that is being deleted
	 *
	 * @return void
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * Get the object entity being deleted
	 *
	 * @return ObjectEntity The object entity that is being deleted
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
