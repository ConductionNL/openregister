<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a schema is created
 */
class SchemaCreatedEvent extends Event {
    
    /** @var Schema The schema that was created */
    private Schema $schema;

    /**
     * Constructor for SchemaCreatedEvent
     * 
     * @param Schema $schema The schema that was created
     */
    public function __construct(Schema $schema) {
        parent::__construct();
        $this->schema = $schema;
    }

    /**
     * Get the created schema
     *
     * @return Schema The schema that was created
     */
    public function getSchema(): Schema {
        return $this->schema;
    }
} 