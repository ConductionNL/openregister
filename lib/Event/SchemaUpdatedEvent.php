<?php
/**
 * OpenRegister SchemaUpdatedEvent
 *
 * This file contains the event class dispatched when a schema is updated
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

use OCA\OpenRegister\Db\Schema;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a schema is updated
 */
class SchemaUpdatedEvent extends Event
{

    /**
     * The updated schema state
     *
     * @var Schema The schema after update
     */
    private Schema $newSchema;

    /**
     * The previous schema state
     *
     * @var Schema The schema before update
     */
    private Schema $oldSchema;


    /**
     * Constructor for SchemaUpdatedEvent
     *
     * @param Schema $newSchema The schema after update
     * @param Schema $oldSchema The schema before update
     *
     * @return void
     */
    public function __construct(Schema $newSchema, Schema $oldSchema)
    {
        parent::__construct();
        $this->newSchema = $newSchema;
        $this->oldSchema = $oldSchema;

    }//end __construct()


    /**
     * Get the updated schema
     *
     * @return Schema The schema after update
     */
    public function getNewSchema(): Schema
    {
        return $this->newSchema;

    }//end getNewSchema()


    /**
     * Get the original schema
     *
     * @return Schema The schema before update
     */
    public function getOldSchema(): Schema
    {
        return $this->oldSchema;

    }//end getOldSchema()


}//end class
