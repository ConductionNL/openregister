<?php

/**
 * OpenRegister AbstractApplicationEvent
 *
 * What the events about one application entity carry.
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

use OCA\OpenRegister\Db\Application;
use OCP\EventDispatcher\Event;

/**
 * An event about an application: the entity it happened to.
 */
abstract class AbstractApplicationEvent extends Event {
	/**
	 * Constructor for AbstractApplicationEvent.
	 *
	 * @param Application $application The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly Application $application) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return Application The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getApplication(): Application {
		return $this->application;
	}//end getApplication()
}//end class
