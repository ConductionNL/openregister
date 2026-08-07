<?php

/**
 * Organisation Created Event
 *
 * This file contains the event class that is dispatched when an organisation entity is created.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Event
 * @package   OCA\OpenRegister\Event
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/OpenRegister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;
use OCA\OpenRegister\Db\Organisation;

/**
 * Event dispatched when an organisation entity is created
 *
 * This event is fired after an organisation entity has been successfully
 * created and committed to the database.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version  1.0.0
 * @link     https://github.com/ConductionNL/OpenRegister
 */
class OrganisationCreatedEvent extends Event
{

    /**
     * The organisation that was created
     *
     * @var Organisation
     */
    private Organisation $organisation;

    /**
     * OrganisationCreatedEvent constructor
     *
     * @param Organisation $organisation The organisation that was created
     */
    public function __construct(Organisation $organisation)
    {
        parent::__construct();
        $this->organisation = $organisation;
    }//end __construct()

    /**
     * Get the organisation that was created
     *
     * @return Organisation The organisation entity
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
     */
    public function getOrganisation(): Organisation
    {
        return $this->organisation;
    }//end getOrganisation()
}//end class
