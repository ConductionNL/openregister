<?php
/**
 * OpenRegister ObjectUpdatedEvent
 *
 * This file contains the event class dispatched when an object is updated
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is updated
 */
class ObjectUpdatedEvent extends Event
{

    /**
     * The updated object entity state
     *
     * @var ObjectEntity The object entity after update
     */
    private ObjectEntity $newObject;

    /**
     * The previous object entity state
     *
     * @var ObjectEntity The object entity before update
     */
    private ObjectEntity $oldObject;


    /**
     * Constructor for ObjectUpdatedEvent
     *
     * @param ObjectEntity $newObject The object entity after update
     * @param ObjectEntity $oldObject The object entity before update
     *
     * @return void
     */
    public function __construct(ObjectEntity $newObject, ObjectEntity $oldObject)
    {
        parent::__construct();
        $this->newObject = $newObject;
        $this->oldObject = $oldObject;

    }//end __construct()


    /**
     * Get the updated object entity
     *
     * @return ObjectEntity The object entity after update
     */
    public function getNewObject(): ObjectEntity
    {
        return $this->newObject;

    }//end getNewObject()


    /**
     * Get the original object entity
     *
     * @return ObjectEntity The object entity before update
     */
    public function getOldObject(): ObjectEntity
    {
        return $this->oldObject;

    }//end getOldObject()


}//end class
