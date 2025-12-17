<?php
/**
 * OpenRegister AgentDeletedEvent
 *
 * This file contains the event class dispatched when an agent is deleted
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
 * Event dispatched when an agent is deleted.
 */
class AgentDeletedEvent extends Event
{

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
    public function __construct(Agent $agent)
    {
        parent::__construct();
        $this->agent = $agent;

    }//end __construct()

    /**
     * Get the deleted agent.
     *
     * @return Agent The agent that was deleted.
     */
    public function getAgent(): Agent
    {
        return $this->agent;

    }//end getAgent()
}//end class
