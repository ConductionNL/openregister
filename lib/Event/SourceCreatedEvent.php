<?php
/**
 * OpenRegister SourceCreatedEvent
 *
 * This file contains the event class dispatched when a source is created
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
 * Event dispatched when a source is created.
 */
class SourceCreatedEvent extends Event
{

    /**
     * The newly created source.
     *
     * @var Source The source that was created.
     */
    private Source $source;

    /**
     * Constructor for SourceCreatedEvent.
     *
     * @param Source $source The source that was created.
     *
     * @return void
     */
    public function __construct(Source $source)
    {
        parent::__construct();
        $this->source = $source;

    }//end __construct()

    /**
     * Get the created source.
     *
     * @return Source The source that was created.
     */
    public function getSource(): Source
    {
        return $this->source;

    }//end getSource()
}//end class
