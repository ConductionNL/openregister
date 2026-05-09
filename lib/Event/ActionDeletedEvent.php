<?php

/**
 * OpenRegister ActionDeletedEvent
 *
 * Event dispatched when an action is deleted in the OpenRegister application.
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

use OCA\OpenRegister\Db\Action;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an action is deleted
 */
class ActionDeletedEvent extends Event
{

    /**
     * The action
     *
     * @var Action The action entity
     */
    private Action $action;

    /**
     * Constructor for ActionDeletedEvent
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
     *
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
     */
    public function getAction(): Action
    {
        return $this->action;
    }//end getAction()
}//end class
