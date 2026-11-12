<?php

/**
 * OpenRegister AbstractObjectEntityEvent
 *
 * What the events about one object entity carry.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
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
 * An event about an object: the entity it happened to.
 */
abstract class AbstractObjectEntityEvent extends Event {
	/**
	 * Constructor for AbstractObjectEntityEvent.
	 *
	 * @param ObjectEntity $object The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly ObjectEntity $object) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return ObjectEntity The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 * @spec openspec/changes/retrofit-2026-05-24-b-event-all/tasks.md#task-3
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()
}//end class
