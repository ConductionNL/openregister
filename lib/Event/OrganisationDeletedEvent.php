<?php

/**
 * OpenRegister OrganisationDeletedEvent
 *
 * This file contains the event class dispatched when an organisation is deleted
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

use OCA\OpenRegister\Db\Organisation;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an organisation is deleted.
 */
class OrganisationDeletedEvent extends Event {

	/**
	 * The deleted organisation.
	 *
	 * @var Organisation The organisation that was deleted.
	 */
	private Organisation $organisation;

	/**
	 * Constructor for OrganisationDeletedEvent.
	 *
	 * @param Organisation $organisation The organisation that was deleted.
	 *
	 * @return void
	 */
	public function __construct(Organisation $organisation) {
		parent::__construct();
		$this->organisation = $organisation;
	}//end __construct()

	/**
	 * Get the deleted organisation.
	 *
	 * @return Organisation The organisation that was deleted.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOrganisation(): Organisation {
		return $this->organisation;
	}//end getOrganisation()
}//end class
