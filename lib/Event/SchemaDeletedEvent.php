<?php

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a schema is deleted
 */
class SchemaDeletedEvent extends Event
{

    /**
     * @var Schema The schema that was deleted
     */
    private Schema $schema;


    /**
     * Constructor for SchemaDeletedEvent
     *
     * @param Schema $schema The schema that was deleted
     */
    public function __construct(Schema $schema)
    {
        parent::__construct();
        $this->schema = $schema;

    }//end __construct()


    /**
     * Get the deleted schema
     *
     * @return Schema The schema that was deleted
     */
    public function getSchema(): Schema
    {
        return $this->schema;

    }//end getSchema()


}//end class
