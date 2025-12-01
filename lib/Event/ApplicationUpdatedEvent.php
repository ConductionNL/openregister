<?php
/**
 * OpenRegister ApplicationUpdatedEvent
 *
 * This file contains the event class dispatched when an application is updated
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
 * Event dispatched when an application is updated.
 */
class ApplicationUpdatedEvent extends Event
{

    /**
     * The updated application state.
     *
     * @var Application The application after update.
     *
     * @psalm-suppress UnusedProperty
     */
    private Application $newApplication;

    /**
     * The previous application state.
     *
     * @var Application The application before update.
     *
     * @psalm-suppress UnusedProperty
     */
    private Application $oldApplication;


    /**
     * Constructor for ApplicationUpdatedEvent.
     *
     * @param Application $newApplication The application after update.
     * @param Application $oldApplication The application before update.
     *
     * @return void
     */
    public function __construct(Application $newApplication, Application $oldApplication)
    {
        parent::__construct();
        $this->newApplication = $newApplication;
        $this->oldApplication = $oldApplication;

    }//end __construct()




}//end class
