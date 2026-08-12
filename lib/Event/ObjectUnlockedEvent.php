<?php

/**
 * OpenRegister ObjectUnlockedEvent
 *
 * This file contains the event class dispatched when an object is unlocked
 * in the OpenRegister application.
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

/**
 * Event dispatched when an object is unlocked
 */
class ObjectUnlockedEvent extends Event {

	/**
	 * The unlocked object entity
	 *
	 * @var ObjectEntity The object that has been unlocked
	 */
	private ObjectEntity $object;

	/**
	 * Constructor for ObjectUnlockedEvent
	 *
	 * @param ObjectEntity $object The object that has been unlocked
	 *
	 * @return void
	 */
	public function __construct(ObjectEntity $object) {
		parent::__construct();
		$this->object = $object;
	}//end __construct()

	/**
	 * Get the unlocked object entity
	 *
	 * @return ObjectEntity The object that has been unlocked
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-3
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
