<?php

/**
 * OpenRegister OrganisationDeletedEvent
 *
 * This file contains the event class dispatched when an organisation is deleted
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

use OCA\OpenRegister\Db\Organisation;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an organisation is deleted.
 */
class OrganisationDeletedEvent extends Event
{

    /**
     * The deleted organisation.
     *
     * @var Organisation The organisation that was deleted.
     */
    private Organisation $organisation;

    /**
     * Constructor for OrganisationDeletedEvent.
     *
     * @param Organisation $organisation The organisation that was deleted.
     *
     * @return void
     */
    public function __construct(Organisation $organisation)
    {
        parent::__construct();
        $this->organisation = $organisation;
    }//end __construct()

    /**
     * Get the deleted organisation.
     *
     * @return Organisation The organisation that was deleted.
     *
<<<<<<< HEAD
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
=======
     * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-27
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
     */
    public function getOrganisation(): Organisation
    {
        return $this->organisation;
    }//end getOrganisation()
}//end class
