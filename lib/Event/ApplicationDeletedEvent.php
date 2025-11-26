<?php
/**
 * OpenRegister ApplicationDeletedEvent
 *
 * This file contains the event class dispatched when an application is deleted
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

use OCA\OpenRegister\Db\Application;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an application is deleted.
 */
class ApplicationDeletedEvent extends Event
{

    /**
     * The deleted application.
     *
     * @var Application The application that was deleted.
     */
    private Application $application;


    /**
     * Constructor for ApplicationDeletedEvent.
     *
     * @param Application $application The application that was deleted.
     *
     * @return void
     */
    public function __construct(Application $application)
    {
        parent::__construct();
        $this->application = $application;

    }//end __construct()


    /**
     * Get the deleted application.
     *
     * @return Application The application that was deleted.
     */
    public function getApplication(): Application
    {
        return $this->application;

    }//end getApplication()


}//end class
