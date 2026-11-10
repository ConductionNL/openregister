<?php

/**
 * OpenRegister AbstractRegisterEvent
 *
 * What the events about one register entity carry.
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

use OCA\OpenRegister\Db\Register;
use OCP\EventDispatcher\Event;

/**
 * An event about a register: the entity it happened to.
 */
abstract class AbstractRegisterEvent extends Event {
	/**
	 * Constructor for AbstractRegisterEvent.
	 *
	 * @param Register $register The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly Register $register) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return Register The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getRegister(): Register {
		return $this->register;
	}//end getRegister()
}//end class
