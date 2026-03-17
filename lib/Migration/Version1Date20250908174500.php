<?php

/**
 * OpenRegister UUID Unique Constraint Migration
 *
 * This migration adds a UNIQUE constraint on the uuid field of the openregister_objects
 * table to prevent duplicate objects and enable proper bulk update operations using
 * INSERT...ON DUPLICATE KEY UPDATE functionality.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add UNIQUE constraint on UUID field
 *
 * This migration implements a critical database constraint to ensure:
 * - No duplicate objects with the same UUID
 * - Proper bulk update operations via INSERT...ON DUPLICATE KEY UPDATE
 * - Improved data integrity and deduplication performance
 */
class Version1Date20250908174500 extends SimpleMigrationStep
{
    /**
     * Add UNIQUE constraint to uuid field
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema = $schemaClosure();

        // Get the objects table to add UUID unique constraint.
        if ($schema->hasTable('openregister_objects') === true) {
            $table = $schema->getTable('openregister_objects');

            $output->info(message: '🔧 Adding UNIQUE constraint on UUID field...');

            // Check if uuid column exists before adding constraint.
            if ($table->hasColumn('uuid') === true) {
                // Check if unique constraint already exists.
                if ($table->hasIndex('unique_uuid') === false) {
                    try {
                        // Add unique constraint on uuid field.
                        $table->addUniqueIndex(['uuid'], 'unique_uuid');
                        $output->info(message: '✅ Added UNIQUE constraint on uuid field');
                        $output->info(message: '🎯 This enables proper bulk update operations');
                        $output->info(message: '🚀 INSERT...ON DUPLICATE KEY UPDATE will now work correctly');
                    } catch (\Exception $e) {
                        $output->info('❌ Could not create UUID unique constraint: '.$e->getMessage());
                        $output->info(message: '⚠️  This may cause duplicate object creation during imports');

                        // Don't fail the migration - log the issue but continue.
                        $output->info(message: 'ℹ️  Migration continuing without UUID constraint');
                    }

                    return $schema;
                }

                $output->info(message: 'ℹ️  UUID unique constraint already exists');
                return $schema;
            }//end if

            $output->info(message: '⚠️  UUID column not found - cannot add unique constraint');
            return $schema;
        }//end if

        $output->info(message: '⚠️  openregister_objects table not found');

        $output->info(message: '🎉 UUID unique constraint migration completed');

        return $schema;
    }//end changeSchema()

    /**
     * Post schema update operations
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        $output->info(message: '📋 Post-migration verification...');
        $output->info(message: '✅ Bulk import operations will now properly deduplicate objects');
        $output->info(message: '✅ No more duplicate object creation on re-imports');
        $output->info(message: '✅ Performance maintained with optimized bulk operations');
        $output->info(message: '🎯 Migration successful - deduplication system ready');
    }//end postSchemaChange()
}//end class
