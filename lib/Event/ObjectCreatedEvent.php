<?php
/**
 * OpenRegister ObjectCreatedEvent
 *
 * This file contains the event class dispatched when an object is created
 * in the OpenRegister application.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is created
 */
class ObjectCreatedEvent extends Event
{

    /**
     * The newly created object entity
     *
     * @var ObjectEntity The object entity that was created
     */
    private ObjectEntity $object;


    /**
     * Constructor for ObjectCreatedEvent
     *
     * @param ObjectEntity $object The object entity that was created
     *
     * @return void
     */
    public function __construct(ObjectEntity $object)
    {
        parent::__construct();
        $this->object = $object;

    }//end __construct()


    /**
     * Get the created object entity
     *
     * @return ObjectEntity The object entity that was created
     */
    public function getObject(): ObjectEntity
    {
        return $this->object;

    }//end getObject()


}//end class
