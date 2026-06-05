<?php

/**
 * OpenRegister ApplicationDeletedEvent
 *
 * This file contains the event class dispatched when an application is deleted
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
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getApplication(): Application
    {
        return $this->application;
    }//end getApplication()
}//end class
