<?php

/**
 * OpenRegister ConversationDeletedEvent
 *
 * This file contains the event class dispatched when a conversation is deleted
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
 * Event dispatched when a conversation is deleted.
 */
class ConversationDeletedEvent extends Event
{
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
    public function __construct(Conversation $conversation)
    {
        parent::__construct();
        $this->conversation = $conversation;
    }//end __construct()

    /**
     * Get the deleted conversation.
     *
     * @return Conversation The conversation that was deleted.
     */
    public function getConversation(): Conversation
    {
        return $this->conversation;
    }//end getConversation()
}//end class
