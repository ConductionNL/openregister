<?php
/**
 * OpenRegister SchemaCreatedEvent
 *
 * This file contains the event class dispatched when a schema is created
 * in the OpenRegister application.
 *
 * @category  Event
 * @package   OCA\OpenRegister\Event
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a schema is created
 */
class SchemaCreatedEvent extends Event
{
    /**
     * The newly created schema
     *
     * @var Schema The schema that was created
     */
    private Schema $schema;

    /**
     * Constructor for SchemaCreatedEvent
     *
     * @param Schema $schema The schema that was created
     *
     * @return void
     */
    public function __construct(Schema $schema)
    {
        parent::__construct();
        $this->schema = $schema;

    }//end __construct()

    /**
     * Get the created schema
     *
     * @return Schema The schema that was created
     */
    public function getSchema(): Schema
    {
        return $this->schema;

    }//end getSchema()

}//end class
