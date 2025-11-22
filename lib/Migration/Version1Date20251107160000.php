<?php

/**
 * OpenRegister Migration - Add UUID Column to File Texts Table
 *
 * This migration adds a UUID column to oc_openregister_file_texts for
 * external referencing and API integrations.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add UUID column to file_texts table
 *
 * Adds a UUID column for external referencing while maintaining
 * backwards compatibility with existing records.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 */
class Version1Date20251107160000 extends SimpleMigrationStep
{


    /**
     * Add UUID column to file_texts table
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /*
         * @var ISchemaWrapper $schema
         */

        $schema  = $schemaClosure();
        $updated = false;

        $output->info(message: ('📄 Adding UUID column to file_texts table...');

        if ($schema->hasTable('openregister_file_texts') === true) {
            $table = $schema->getTable('openregister_file_texts');

            // Add UUID column if it doesn't exist.
            if ($table->hasColumn('uuid') === false) {
                $table->addColumn(
                        'uuid',
                        Types::STRING,
                        [
                            'notnull' => false,
                            'length'  => 36,
                            'comment' => 'Unique identifier for external referencing',
                        ]
                        );

                // Add index for UUID lookups.
                if ($table->hasIndex('file_texts_uuid_idx') === false) {
                    $table->addIndex(['uuid'], 'file_texts_uuid_idx');
                }

                $output->info(message: ('✅ Added UUID column to file_texts table');
                $updated = true;
            } else {
                $output->info(message: ('ℹ️  UUID column already exists in file_texts table');
            }//end if
        }//end if

        if ($updated === true) {
            return $schema;
        }

        return null;

    }//end changeSchema()


    /**
     * Post-migration actions
     *
     * Generate UUIDs for existing records that don't have one
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: ('Generating UUIDs for existing file_texts records...');

        // Note: UUID generation for existing records will be handled by the.
        // FileTextMapper when records are accessed/updated, to avoid.
        // potential timeout issues with large datasets.
        $output->info(message: ('✅ Migration complete - UUIDs will be generated on-demand');

    }//end postSchemaChange()


}//end class
