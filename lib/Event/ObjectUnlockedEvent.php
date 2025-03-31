<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is unlocked
 */
class ObjectUnlockedEvent extends Event
{

    private ObjectEntity $object;


    public function __construct(ObjectEntity $object)
    {
        parent::__construct();
        $this->object = $object;

    }//end __construct()


    public function getObject(): ObjectEntity
    {
        return $this->object;

    }//end getObject()


}//end class
