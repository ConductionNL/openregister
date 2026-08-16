<?php

/**
 * OpenRegister ConversationDeletedEvent
 *
 * This file contains the event class dispatched when a conversation is deleted
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

use OCA\OpenRegister\Db\Conversation;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a conversation is deleted.
 */
class ConversationDeletedEvent extends Event {

	/**
	 * The deleted conversation.
	 *
	 * @var Conversation The conversation that was deleted.
	 */
	private Conversation $conversation;

	/**
	 * Constructor for ConversationDeletedEvent.
	 *
	 * @param Conversation $conversation The conversation that was deleted.
	 *
	 * @return void
	 */
	public function __construct(Conversation $conversation) {
		parent::__construct();
		$this->conversation = $conversation;
	}//end __construct()

	/**
	 * Get the deleted conversation.
	 *
	 * @return Conversation The conversation that was deleted.
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getConversation(): Conversation {
		return $this->conversation;
	}//end getConversation()
}//end class
