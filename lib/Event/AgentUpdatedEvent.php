<?php

/**
 * OpenRegister AgentUpdatedEvent
 *
 * This file contains the event class dispatched when an agent is updated
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

use OCA\OpenRegister\Kernel\Contracts\AgentInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an agent is updated.
 */
class AgentUpdatedEvent extends Event {

	/**
	 * The updated agent state.
	 *
	 * @var AgentInterface&Entity The agent after update.
	 */
	private AgentInterface $newAgent;

	/**
	 * The previous agent state.
	 *
	 * @var AgentInterface&Entity The agent before update.
	 */
	private AgentInterface $oldAgent;

	/**
	 * Constructor for AgentUpdatedEvent.
	 *
	 * @param AgentInterface&Entity $newAgent The agent after update.
	 * @param AgentInterface&Entity $oldAgent The agent before update.
	 *
	 * @return void
	 */
	public function __construct(AgentInterface $newAgent, AgentInterface $oldAgent) {
		parent::__construct();
		$this->newAgent = $newAgent;
		$this->oldAgent = $oldAgent;
	}//end __construct()

	/**
	 * Get the updated agent.
	 *
	 * @return AgentInterface&Entity The agent after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getAgent(): AgentInterface {
		return $this->newAgent;
	}//end getAgent()

	/**
	 * Get the new agent state.
	 *
	 * @return AgentInterface&Entity The agent after update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getNewAgent(): AgentInterface {
		return $this->newAgent;
	}//end getNewAgent()

	/**
	 * Get the old agent state.
	 *
	 * @return AgentInterface&Entity The agent before update.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOldAgent(): AgentInterface {
		return $this->oldAgent;
	}//end getOldAgent()
}//end class
