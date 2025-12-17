<?php
/**
 * OpenRegister ViewCreatedEvent
 *
 * This file contains the event class dispatched when a view is created
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
 * Event dispatched when a view is created.
 */
class ViewCreatedEvent extends Event
{

    /**
     * The newly created view.
     *
     * @var View The view that was created.
     */
    private View $view;

    /**
     * Constructor for ViewCreatedEvent.
     *
     * @param View $view The view that was created.
     *
     * @return void
     */
    public function __construct(View $view)
    {
        parent::__construct();
        $this->view = $view;

    }//end __construct()

    /**
     * Get the created view.
     *
     * @return View The view that was created.
     */
    public function getView(): View
    {
        return $this->view;

    }//end getView()
}//end class
