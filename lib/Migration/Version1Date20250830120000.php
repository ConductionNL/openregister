<?php
/**
 * OpenRegister Configuration Table Updates Migration
 *
 * This migration updates the openregister_configurations table to:
 * 1. Rename the 'owner' column to 'app' for better semantics
 * 2. Add 'schemas' column to track schema IDs managed by configurations
 * 3. Add 'objects' column to track object IDs managed by configurations
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Configuration table structure updates migration
 */
class Version1Date20250830120000 extends SimpleMigrationStep
{


    /**
     * Change the database schema
     *
     * @param IOutput       $output        Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options       Migration options
     *
     * @return ISchemaWrapper|null The modified schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Check if the configurations table exists.
        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            // Rename 'owner' column to 'app' if it exists.
            if ($table->hasColumn('owner') === true) {
                // Add the new 'app' column.
                if ($table->hasColumn('app') === false) {
                    $table->addColumn(
                            'app',
                            Types::STRING,
                            [
                                'notnull' => false,
                                'length'  => 64,
                            ]
                            );
                }

                // Note: We'll copy data in postSchemaChange, then drop the old column.
            }

            // Add 'schemas' column if it doesn't exist.
            if ($table->hasColumn('schemas') === false) {
                $table->addColumn(
                        'schemas',
                        Types::JSON,
                        [
                            'notnull' => false,
                        ]
                        );
            }

            // Add 'objects' column if it doesn't exist.
            if ($table->hasColumn('objects') === false) {
                $table->addColumn(
                        'objects',
                        Types::JSON,
                        [
                            'notnull' => false,
                        ]
                        );
            }

            return $schema;
        }//end if

        return null;

    }//end changeSchema()


    /**
     * Perform post-schema change operations
     *
     * @param IOutput       $output        Output for the migration process
     * @param Closure       $schemaClosure The schema closure
     * @param array<string> $options       Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema = $schemaClosure();

        // Check if the configurations table exists.
        if ($schema->hasTable('openregister_configurations') === true) {
            $table = $schema->getTable('openregister_configurations');

            // If both 'owner' and 'app' columns exist, copy data and drop 'owner'.
            if ($table->hasColumn('owner') === true && $table->hasColumn('app') === true) {
                // Copy data from 'owner' to 'app' column using raw SQL.
                $connection = \OC::$server->getDatabaseConnection();

                // Copy the data.
                $connection->executeStatement(
                    'UPDATE `*PREFIX*openregister_configurations` SET `app` = `owner`'
                );

                // Drop the old 'owner' column.
                $schema = $schemaClosure();
                $table  = $schema->getTable('openregister_configurations');
                if ($table->hasColumn('owner') === true) {
                    $table->dropColumn('owner');
                }
            }
        }//end if

    }//end postSchemaChange()


}//end class
