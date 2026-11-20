<?php

/**
 * OpenRegister OrganisationUpdatedEvent
 *
 * This file contains the event class dispatched when an organisation is updated
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

use OCA\OpenRegister\Kernel\Contracts\OrganisationInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an organisation is updated.
 */
class OrganisationUpdatedEvent extends Event {

	/**
	 * The updated organisation state.
	 *
	 * @var OrganisationInterface&Entity The organisation after update.
	 */
	private OrganisationInterface $newOrganisation;

	/**
	 * The previous organisation state.
	 *
	 * @var OrganisationInterface&Entity The organisation before update.
	 */
	private OrganisationInterface $oldOrganisation;

	/**
	 * Constructor for OrganisationUpdatedEvent.
	 *
	 * @param OrganisationInterface&Entity $newOrganisation The organisation after update.
	 * @param OrganisationInterface&Entity $oldOrganisation The organisation before update.
	 *
	 * @return void
	 */
	public function __construct(OrganisationInterface $newOrganisation, OrganisationInterface $oldOrganisation) {
		parent::__construct();
		$this->newOrganisation = $newOrganisation;
		$this->oldOrganisation = $oldOrganisation;
	}//end __construct()

	/**
	 * Get the organisation (the updated state).
	 *
	 * @return OrganisationInterface&Entity The organisation after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOrganisation(): OrganisationInterface {
		return $this->newOrganisation;
	}//end getOrganisation()

	/**
	 * Get the new organisation state.
	 *
	 * @return OrganisationInterface&Entity The organisation after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getNewOrganisation(): OrganisationInterface {
		return $this->newOrganisation;
	}//end getNewOrganisation()

	/**
	 * Get the old organisation state.
	 *
	 * @return OrganisationInterface&Entity The organisation before update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOldOrganisation(): OrganisationInterface {
		return $this->oldOrganisation;
	}//end getOldOrganisation()
}//end class
