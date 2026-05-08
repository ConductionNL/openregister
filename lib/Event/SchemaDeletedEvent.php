<?php

/**
 * OpenRegister SchemaDeletedEvent
 *
 * This file contains the event class dispatched when a schema is deleted
 * in the OpenRegister application.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * Event dispatched when a schema is deleted
 */
class SchemaDeletedEvent extends Event
{

    /**
     * The deleted schema
     *
     * @var Schema The schema that was deleted
     */
    private Schema $schema;

    /**
     * Constructor for SchemaDeletedEvent
     *
     * @param Schema $schema The schema that was deleted
     *
     * @return void
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
     *
     * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-27
     */
    public function getSchema(): Schema
    {
        return $this->schema;
    }//end getSchema()
}//end class
