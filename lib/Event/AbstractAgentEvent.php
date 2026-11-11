<?php

/**
 * OpenRegister AbstractAgentEvent
 *
 * What the events about one agent entity carry.
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

use OCA\OpenRegister\Kernel\Contracts\AgentInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * An event about an agent: the entity it happened to.
 */
abstract class AbstractAgentEvent extends Event {
	/**
	 * Constructor for AbstractAgentEvent.
	 *
	 * @param AgentInterface&Entity $agent The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly AgentInterface $agent) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return AgentInterface&Entity The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getAgent(): AgentInterface {
		return $this->agent;
	}//end getAgent()
}//end class
