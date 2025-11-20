<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add individual indexes for search optimization
 *
 * This migration adds individual indexes on name, description, and summary columns
 * for improved search performance. Uses prefix indexes for TEXT columns to avoid
 * MySQL key length limits.
 */
class Version1Date20250902130000 extends SimpleMigrationStep
{


    /**
     * Apply database schema changes for search performance
     *
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return null;
        }

        $table = $schema->getTable('openregister_objects');

        // Skip name index creation for now to avoid MySQL key length issues
        // TODO: Add name index after app is enabled with proper length prefix
        $output->info('Skipping name index creation to avoid MySQL key length issues');

        return $schema;

    }//end changeSchema()


    /**
     * Execute raw SQL for TEXT column prefix indexes
     *
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array   $options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_objects') === false) {
            return;
        }

        // Get database connection for raw SQL
        $connection = \OC::$server->getDatabaseConnection();

        // Skip complex index creation for now to avoid MySQL key length issues
        // TODO: Add indexes after app is enabled
        $output->info('Skipping complex index creation to avoid MySQL key length issues');

    }//end postSchemaChange()


}//end class
