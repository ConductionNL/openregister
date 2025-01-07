<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is deleted
 */
class ObjectDeletedEvent extends Event {
    
    /** @var ObjectEntity The object entity that was deleted */
    private ObjectEntity $object;

    /**
     * Constructor for ObjectDeletedEvent
     * 
     * @param ObjectEntity $object The object entity that was deleted
     */
    public function __construct(ObjectEntity $object) {
        parent::__construct();
        $this->object = $object;
    }

    /**
     * Get the deleted object entity
     *
     * @return ObjectEntity The object entity that was deleted
     */
    public function getObject(): ObjectEntity {
        return $this->object;
    }
} 