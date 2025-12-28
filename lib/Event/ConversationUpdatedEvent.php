<?php

/**
 * OpenRegister ConversationUpdatedEvent
 *
 * This file contains the event class dispatched when a conversation is updated
 * in the OpenRegister application.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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
 * Event dispatched when a conversation is updated.
 */
class ConversationUpdatedEvent extends Event
{
    /**
     * The updated conversation state.
     *
     * @var Conversation The conversation after update.
     *
     * @psalm-suppress UnusedProperty
     */
    private Conversation $newConversation;

    /**
     * The previous conversation state.
     *
     * @var Conversation The conversation before update.
     *
     * @psalm-suppress UnusedProperty
     */
    private Conversation $oldConversation;

    /**
     * Constructor for ConversationUpdatedEvent.
     *
     * @param Conversation $newConversation The conversation after update.
     * @param Conversation $oldConversation The conversation before update.
     *
     * @return void
     */
    public function __construct(Conversation $newConversation, Conversation $oldConversation)
    {
        parent::__construct();
        $this->newConversation = $newConversation;
        $this->oldConversation = $oldConversation;
    }//end __construct()

    /**
     * Get the conversation (returns new conversation for compatibility)
     *
     * @return Conversation The updated conversation entity
     */
    public function getConversation(): Conversation
    {
        return $this->newConversation;
    }//end getConversation()

    /**
     * Get the new conversation
     *
     * @return Conversation The conversation after update
     */
    public function getNewConversation(): Conversation
    {
        return $this->newConversation;
    }//end getNewConversation()

    /**
     * Get the old conversation
     *
     * @return Conversation The conversation before update
     */
    public function getOldConversation(): Conversation
    {
        return $this->oldConversation;
    }//end getOldConversation()
}//end class
