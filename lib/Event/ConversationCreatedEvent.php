<?php

/**
 * OpenRegister ConversationCreatedEvent
 *
 * This file contains the event class dispatched when a conversation is created
 * in the OpenRegister application.
 *
<<<<<<< HEAD
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
=======
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conductio.nl>
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
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
 * Event dispatched when a conversation is created.
 */
class ConversationCreatedEvent extends Event
{

    /**
     * The newly created conversation.
     *
     * @var Conversation The conversation that was created.
     */
    private Conversation $conversation;

    /**
     * Constructor for ConversationCreatedEvent.
     *
     * @param Conversation $conversation The conversation that was created.
     *
     * @return void
     */
    public function __construct(Conversation $conversation)
    {
        parent::__construct();
        $this->conversation = $conversation;
    }//end __construct()

    /**
     * Get the created conversation.
     *
     * @return Conversation The conversation that was created.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getConversation(): Conversation
    {
        return $this->conversation;
    }//end getConversation()
}//end class
