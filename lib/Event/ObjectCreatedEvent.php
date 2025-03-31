<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is created
 */
class ObjectCreatedEvent extends Event
{

    /**
     * @var ObjectEntity The object entity that was created
     */
    private ObjectEntity $object;


    /**
     * Constructor for ObjectCreatedEvent
     *
     * @param ObjectEntity $object The object entity that was created
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
