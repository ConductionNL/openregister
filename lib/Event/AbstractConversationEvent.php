<?php

/**
 * OpenRegister AbstractConversationEvent
 *
 * What the events about one conversation entity carry.
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

use OCA\OpenRegister\Mcp\Db\Conversation;
use OCP\EventDispatcher\Event;

/**
 * An event about a conversation: the entity it happened to.
 */
abstract class AbstractConversationEvent extends Event {
	/**
	 * Constructor for AbstractConversationEvent.
	 *
	 * @param Conversation $conversation The entity the event is about.
	 *
	 * @return void
	 */
	public function __construct(private readonly Conversation $conversation) {
		parent::__construct();
	}//end __construct()

	/**
	 * Get the entity the event is about.
	 *
	 * @return Conversation The entity.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getConversation(): Conversation {
		return $this->conversation;
	}//end getConversation()
}//end class
