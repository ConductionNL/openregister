<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is updated
 */
class ObjectUpdatedEvent extends Event {
    
    /** @var ObjectEntity The object entity after update */
    private ObjectEntity $newObject;
    
    /** @var ObjectEntity The object entity before update */
    private ObjectEntity $oldObject;

    /**
     * Constructor for ObjectUpdatedEvent
     * 
     * @param ObjectEntity $newObject The object entity after update
     * @param ObjectEntity $oldObject The object entity before update
     */
    public function __construct(ObjectEntity $newObject, ObjectEntity $oldObject) {
        parent::__construct();
        $this->newObject = $newObject;
        $this->oldObject = $oldObject;
    }

    /**
     * Get the updated object entity
     *
     * @return ObjectEntity The object entity after update
     */
    public function getNewObject(): ObjectEntity {
        return $this->newObject;
    }

    /**
     * Get the original object entity
     *
     * @return ObjectEntity The object entity before update
     */
    public function getOldObject(): ObjectEntity {
        return $this->oldObject;
    }
} 