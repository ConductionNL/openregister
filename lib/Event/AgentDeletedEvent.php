<?php

/**
 * OpenRegister AgentDeletedEvent
 *
 * This file contains the event class dispatched when an agent is deleted
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

use OCA\OpenRegister\Db\Agent;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an agent is deleted.
 */
class AgentDeletedEvent extends Event {

	/**
	 * The deleted agent.
	 *
	 * @var Agent The agent that was deleted.
	 */
	private Agent $agent;

	/**
	 * Constructor for AgentDeletedEvent.
	 *
	 * @param Agent $agent The agent that was deleted.
	 *
	 * @return void
	 */
	public function __construct(Agent $agent) {
		parent::__construct();
		$this->agent = $agent;
	}//end __construct()

	/**
	 * Get the deleted agent.
	 *
	 * @return Agent The agent that was deleted.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getAgent(): Agent {
		return $this->agent;
	}//end getAgent()
}//end class
