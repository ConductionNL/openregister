<?php

/**
 * OpenRegister ViewUpdatedEvent
 *
 * This file contains the event class dispatched when a view is updated
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

use OCA\OpenRegister\Db\View;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a view is updated.
 */
class ViewUpdatedEvent extends Event
{

    /**
     * The updated view state.
     *
     * @var View The view after update.
     *
     * @psalm-suppress UnusedProperty
     */
    private View $newView;

    /**
     * The previous view state.
     *
     * @var View The view before update.
     *
     * @psalm-suppress UnusedProperty
     */
    private View $oldView;

    /**
     * Constructor for ViewUpdatedEvent.
     *
     * @param View $newView The view after update.
     * @param View $oldView The view before update.
     *
     * @return void
     */
    public function __construct(View $newView, View $oldView)
    {
        parent::__construct();
        $this->newView = $newView;
        $this->oldView = $oldView;
    }//end __construct()
}//end class
