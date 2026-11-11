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
class OrganisationCreatedEvent extends AbstractOrganisationEvent {
}//end class
