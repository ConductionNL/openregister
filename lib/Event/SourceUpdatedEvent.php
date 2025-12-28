<?php

/**
 * OpenRegister SourceUpdatedEvent
 *
 * This file contains the event class dispatched when a source is updated
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

use OCA\OpenRegister\Db\Source;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a source is updated.
 */
class SourceUpdatedEvent extends Event
{
    /**
     * The updated source state.
     *
     * @var Source The source after update.
     *
     * @psalm-suppress UnusedProperty
     */
    private Source $newSource;

    /**
     * The previous source state.
     *
     * @var Source The source before update.
     *
     * @psalm-suppress UnusedProperty
     */
    private Source $oldSource;

    /**
     * Constructor for SourceUpdatedEvent.
     *
     * @param Source $newSource The source after update.
     * @param Source $oldSource The source before update.
     *
     * @return void
     */
    public function __construct(Source $newSource, Source $oldSource)
    {
        parent::__construct();
        $this->newSource = $newSource;
        $this->oldSource = $oldSource;
    }//end __construct()

    /**
     * Get the updated source.
     *
     * @return Source The source after update.
     */
    public function getSource(): Source
    {
        return $this->newSource;
    }//end getSource()

    /**
     * Get the new source state.
     *
     * @return Source The source after update.
     */
    public function getNewSource(): Source
    {
        return $this->newSource;
    }//end getNewSource()

    /**
     * Get the old source state.
     *
     * @return Source The source before update.
     */
    public function getOldSource(): Source
    {
        return $this->oldSource;
    }//end getOldSource()
}//end class
