<?php

/**
 * OpenRegister SourceDeletedEvent
 *
 * This file contains the event class dispatched when a source is deleted
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

use OCA\OpenRegister\Db\Source;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a source is deleted.
 */
class SourceDeletedEvent extends Event
{

    /**
     * The deleted source.
     *
     * @var Source The source that was deleted.
     */
    private Source $source;

    /**
     * Constructor for SourceDeletedEvent.
     *
     * @param Source $source The source that was deleted.
     *
     * @return void
     */
    public function __construct(Source $source)
    {
        parent::__construct();
        $this->source = $source;
    }//end __construct()

    /**
     * Get the deleted source.
     *
     * @return Source The source that was deleted.
     *
     * @spec openspec/specs/event-driven-architecture/spec.md#requirement-event-payloads-for-webhook-delivery-must-include-register-and-schema-context-for-object-events
     */
    public function getSource(): Source
    {
        return $this->source;
    }//end getSource()
}//end class
