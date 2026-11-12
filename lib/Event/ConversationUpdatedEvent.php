<?php

/**
 * OpenRegister ConversationUpdatedEvent
 *
 * This file contains the event class dispatched when a conversation is updated
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

use OCA\OpenRegister\Kernel\Contracts\ConversationInterface;
use OCP\AppFramework\Db\Entity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a conversation is updated.
 */
class ConversationUpdatedEvent extends Event {

	/**
	 * The updated conversation state.
	 *
	 * @var ConversationInterface&Entity The conversation after update.
	 */
	private ConversationInterface $newConversation;

	/**
	 * The previous conversation state.
	 *
	 * @var ConversationInterface&Entity The conversation before update.
	 */
	private ConversationInterface $oldConversation;

	/**
	 * Constructor for ConversationUpdatedEvent.
	 *
	 * @param ConversationInterface&Entity $newConversation The conversation after update.
	 * @param ConversationInterface&Entity $oldConversation The conversation before update.
	 *
	 * @return void
	 */
	public function __construct(ConversationInterface $newConversation, ConversationInterface $oldConversation) {
		parent::__construct();
		$this->newConversation = $newConversation;
		$this->oldConversation = $oldConversation;
	}//end __construct()

	/**
	 * Get the conversation (returns new conversation for compatibility)
	 *
	 * @return ConversationInterface&Entity The updated conversation entity
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getConversation(): ConversationInterface {
		return $this->newConversation;
	}//end getConversation()

	/**
	 * Get the new conversation
	 *
	 * @return ConversationInterface&Entity The conversation after update
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getNewConversation(): ConversationInterface {
		return $this->newConversation;
	}//end getNewConversation()

	/**
	 * Get the old conversation
	 *
	 * @return ConversationInterface&Entity The conversation before update
	 *
	 * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
	 */
	public function getOldConversation(): ConversationInterface {
		return $this->oldConversation;
	}//end getOldConversation()
}//end class
