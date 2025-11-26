<?php
/**
 * OpenRegister AgentUpdatedEvent
 *
 * This file contains the event class dispatched when an agent is updated
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
 * Event dispatched when an agent is updated.
 */
class AgentUpdatedEvent extends Event
{

    /**
     * The updated agent state.
     *
     * @var Agent The agent after update.
     */
    private Agent $newAgent;

    /**
     * The previous agent state.
     *
     * @var Agent The agent before update.
     */
    private Agent $oldAgent;


    /**
     * Constructor for AgentUpdatedEvent.
     *
     * @param Agent $newAgent The agent after update.
     * @param Agent $oldAgent The agent before update.
     *
     * @return void
     */
    public function __construct(Agent $newAgent, Agent $oldAgent)
    {
        parent::__construct();
        $this->newAgent = $newAgent;
        $this->oldAgent = $oldAgent;

    }//end __construct()


    /**
     * Get the updated agent.
     *
     * @return Agent The agent after update.
     */
    public function getNewAgent(): Agent
    {
        return $this->newAgent;

    }//end getNewAgent()


    /**
     * Get the original agent.
     *
     * @return Agent The agent before update.
     */
    public function getOldAgent(): Agent
    {
        return $this->oldAgent;

    }//end getOldAgent()


}//end class
