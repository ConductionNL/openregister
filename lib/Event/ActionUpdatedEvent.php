<?php

/**
 * OpenRegister ActionUpdatedEvent
 *
 * Event dispatched when an action is updated in the OpenRegister application.
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

use OCA\OpenRegister\Db\Action;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an action is updated
 */
class ActionUpdatedEvent extends Event
{

    /**
     * The action
     *
     * @var Action The action entity
     */
    private Action $action;

    /**
     * Constructor for ActionUpdatedEvent
     *
     * @param Action $action The action entity
     *
     * @return void
     */
    public function __construct(Action $action)
    {
        parent::__construct();
        $this->action = $action;
    }//end __construct()

    /**
     * Get the action
     *
     * @return Action The action entity
     */
    public function getAction(): Action
    {
        return $this->action;
    }//end getAction()
}//end class
