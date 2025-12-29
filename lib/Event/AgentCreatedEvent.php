<?php

/**
 * OpenRegister AgentCreatedEvent
 *
 * This file contains the event class dispatched when an agent is created
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

use OCA\OpenRegister\Db\Agent;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an agent is created.
 */
class AgentCreatedEvent extends Event
{

    /**
     * The newly created agent.
     *
     * @var Agent The agent that was created.
     */
    private Agent $agent;

    /**
     * Constructor for AgentCreatedEvent.
     *
     * @param Agent $agent The agent that was created.
     *
     * @return void
     */
    public function __construct(Agent $agent)
    {
        parent::__construct();
        $this->agent = $agent;
    }//end __construct()

    /**
     * Get the created agent.
     *
     * @return Agent The agent that was created.
     */
    public function getAgent(): Agent
    {
        return $this->agent;
    }//end getAgent()
}//end class
