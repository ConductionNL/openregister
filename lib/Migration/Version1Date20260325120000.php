<?php

/**
 * Database migration to add file action columns to the openregister_files table.
 *
 * Adds columns for file description, category, locking, and download tracking
 * to support the file-actions feature set.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * Adds file action columns to the openregister_files table.
 *
 * New columns:
 * - description (TEXT) - File description for metadata enrichment
 * - category (VARCHAR 255) - File category for filtering
 * - locked_by (VARCHAR 64) - User ID who locked the file
 * - locked_at (DATETIME) - When the lock was acquired
 * - lock_expires (DATETIME) - When the lock expires (TTL)
 * - download_count (INT) - Cached download count for audit
 *
 * @package OCA\OpenRegister\Migration
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
class Version1Date20260325120000 extends SimpleMigrationStep
{
    /**
     * Change the database schema.
     *
     * @param IOutput $output        Migration output
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return ISchemaWrapper|null The updated schema or null if no changes
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        $schema    = $schemaClosure();
        $tableName = 'openregister_files';

        if ($schema->hasTable($tableName) === false) {
            $output->info("Table {$tableName} does not exist, skipping migration");
            return null;
        }

        $table   = $schema->getTable($tableName);
        $changed = false;

        // Add description column for metadata enrichment.
        if ($table->hasColumn('description') === false) {
            $table->addColumn(
                'description',
                Types::TEXT,
                [
                    'notnull' => false,
                    'default' => null,
                    'comment' => 'File description for metadata enrichment',
                ]
            );
            $output->info("Added 'description' column to {$tableName}");
            $changed = true;
        }

        // Add category column for file classification.
        if ($table->hasColumn('category') === false) {
            $table->addColumn(
                'category',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 255,
                    'default' => null,
                    'comment' => 'File category for classification and filtering',
                ]
            );
            $output->info("Added 'category' column to {$tableName}");
            $changed = true;
        }

        // Add locked_by column for file locking.
        if ($table->hasColumn('locked_by') === false) {
            $table->addColumn(
                'locked_by',
                Types::STRING,
                [
                    'notnull' => false,
                    'length'  => 64,
                    'default' => null,
                    'comment' => 'User ID who locked the file',
                ]
            );
            $output->info("Added 'locked_by' column to {$tableName}");
            $changed = true;
        }

        // Add locked_at column for lock timestamp.
        if ($table->hasColumn('locked_at') === false) {
            $table->addColumn(
                'locked_at',
                Types::DATETIME_MUTABLE,
                [
                    'notnull' => false,
                    'default' => null,
                    'comment' => 'Timestamp when the file lock was acquired',
                ]
            );
            $output->info("Added 'locked_at' column to {$tableName}");
            $changed = true;
        }

        // Add lock_expires column for TTL-based lock expiry.
        if ($table->hasColumn('lock_expires') === false) {
            $table->addColumn(
                'lock_expires',
                Types::DATETIME_MUTABLE,
                [
                    'notnull' => false,
                    'default' => null,
                    'comment' => 'Timestamp when the file lock expires (TTL)',
                ]
            );
            $output->info("Added 'lock_expires' column to {$tableName}");
            $changed = true;
        }

        // Add download_count column for download tracking.
        if ($table->hasColumn('download_count') === false) {
            $table->addColumn(
                'download_count',
                Types::INTEGER,
                [
                    'notnull' => true,
                    'default' => 0,
                    'comment' => 'Cached download count for audit and analytics',
                ]
            );
            $output->info("Added 'download_count' column to {$tableName}");
            $changed = true;
        }

        if ($changed === false) {
            $output->info("All file action columns already exist on {$tableName}, skipping");
            return null;
        }

        return $schema;
    }//end changeSchema()
}//end class
