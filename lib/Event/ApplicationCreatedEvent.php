<?php

/**
 * OpenRegister ApplicationCreatedEvent
 *
 * This file contains the event class dispatched when an application is created
 * in the OpenRegister application.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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

use OCA\OpenRegister\Db\Application;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an application is created.
 */
class ApplicationCreatedEvent extends Event
{

    /**
     * The newly created application.
     *
     * @var Application The application that was created.
     */
    private Application $application;

    /**
     * Constructor for ApplicationCreatedEvent.
     *
     * @param Application $application The application that was created.
     *
     * @return void
     */
    public function __construct(Application $application)
    {
        parent::__construct();
        $this->application = $application;
    }//end __construct()

    /**
     * Get the created application.
     *
     * @return Application The application that was created.
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
     */
    public function getApplication(): Application
    {
        return $this->application;
    }//end getApplication()
}//end class
