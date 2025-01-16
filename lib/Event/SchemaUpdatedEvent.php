<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a schema is updated
 */
class SchemaUpdatedEvent extends Event {
    
    /** @var Schema The schema after update */
    private Schema $newSchema;
    
    /** @var Schema The schema before update */
    private Schema $oldSchema;

    /**
     * Constructor for SchemaUpdatedEvent
     * 
     * @param Schema $newSchema The schema after update
     * @param Schema $oldSchema The schema before update
     */
    public function __construct(Schema $newSchema, Schema $oldSchema) {
        parent::__construct();
        $this->newSchema = $newSchema;
        $this->oldSchema = $oldSchema;
    }

    /**
     * Get the updated schema
     *
     * @return Schema The schema after update
     */
    public function getNewSchema(): Schema {
        return $this->newSchema;
    }

    /**
     * Get the original schema
     *
     * @return Schema The schema before update
     */
    public function getOldSchema(): Schema {
        return $this->oldSchema;
    }
} 