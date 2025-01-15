<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when an object is reverted to a previous state
 */
class ObjectRevertedEvent extends Event {
    private ObjectEntity $object;
    private $until;

    /**
     * @param ObjectEntity $object The reverted object
     * @param \DateTime|string|null $until The point in time or audit ID reverted to
     */
    public function __construct(ObjectEntity $object, $until = null) {
        parent::__construct();
        $this->object = $object;
        $this->until = $until;
    }

    public function getObject(): ObjectEntity {
        return $this->object;
    }

    public function getRevertPoint() {
        return $this->until;
    }
} 