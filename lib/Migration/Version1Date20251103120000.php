<?php

declare(strict_types=1);

/*
 * Rename views table to view (singular)
 *
 * This migration renames the openregister_views table to openregister_view
 * to follow naming conventions where entity names are singular.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://www.openregister.nl
 */

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename views table to view (singular)
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251103120000 extends SimpleMigrationStep
{


    /**
     * Rename the table from openregister_views to openregister_view
     *
     * @param IOutput $output        The migration output handler
     * @param Closure $schemaClosure The closure to get the schema
     * @param array   $options       Migration options
     *
     * @return null|ISchemaWrapper The updated schema or null
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        // Check if old table exists and new table doesn't
        if ($schema->hasTable('openregister_views') === true && $schema->hasTable('openregister_view') === false) {
            // Get the old table
            $oldTable = $schema->getTable('openregister_views');

            // Create new table with same structure
            $newTable = $schema->createTable('openregister_view');

            // Copy all columns from old table to new table
            foreach ($oldTable->getColumns() as $column) {
                $newColumn = $newTable->addColumn(
                    $column->getName(),
                    $column->getType()->getName(),
                    [
                        'notnull'       => $column->getNotnull(),
                        'length'        => $column->getLength(),
                        'default'       => $column->getDefault(),
                        'autoincrement' => $column->getAutoincrement(),
                        'unsigned'      => $column->getUnsigned(),
                    ]
                );

                if ($column->getComment() !== null) {
                    $newColumn->setComment($column->getComment());
                }
            }//end foreach

            // Copy primary key
            if ($oldTable->hasPrimaryKey() === true) {
                $newTable->setPrimaryKey($oldTable->getPrimaryKey()->getColumns());
            }

            // Copy indexes with renamed index names to avoid collisions
            // Replace 'views_' with 'view_' to reflect singular table name
            foreach ($oldTable->getIndexes() as $index) {
                if ($index->isPrimary() === false) {
                    // Rename index: views_* -> view_*
                    $oldIndexName = $index->getName();
                    $newIndexName = str_replace('views_', 'view_', $oldIndexName);

                    // Build options array only with available options
                    $options = [];
                    if ($index->hasOption('lengths')) {
                        $options['lengths'] = $index->getOption('lengths');
                    }

                    $newTable->addIndex(
                        $index->getColumns(),
                        $newIndexName,
                        $index->getFlags(),
                        $options
                    );
                }
            }//end foreach

            $output->info('Created new table openregister_view');

            return $schema;
        }//end if

        return null;

    }//end changeSchema()


    /**
     * Copy data from old table to new table and drop old table
     *
     * @param IOutput $output        The migration output handler
     * @param Closure $schemaClosure The closure to get the schema
     * @param array   $options       Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /*
         * @var ISchemaWrapper $schema
         */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_views') === true && $schema->hasTable('openregister_view') === true) {
            // Copy data
            $connection = \OC::$server->getDatabaseConnection();
            $connection->executeQuery('INSERT INTO `*PREFIX*openregister_view` SELECT * FROM `*PREFIX*openregister_views`');

            $output->info('Copied data from openregister_views to openregister_view');

            // Drop old table
            $schema->dropTable('openregister_views');

            $output->info('Dropped old table openregister_views');
        }//end if

    }//end postSchemaChange()


}//end class
