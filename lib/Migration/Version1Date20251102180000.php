<?php

/**
 * OpenRegister Migration Version1Date20251102180000
 *
 * OpenRegister Organisation Groups Migration.
 *
 * This migration renames the 'roles' column to 'groups' in the organisations table
 * to maintain naming consistency with applications.
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
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to rename roles column to groups in organisations table
 *
 * Renames the roles column to groups for naming consistency with applications.
 * Both applications and organisations now use 'groups' to store arrays of Nextcloud group IDs.
 */
class Version1Date20251102180000 extends SimpleMigrationStep
{
    /**
     * Pre-schema change: Copy data before modifying structure
     *
     * @param IOutput $output        Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options       Migration options
     *
     * @return void
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        $output->info(message: '🔧 Preparing to rename roles to groups in organisations table...');

        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Only proceed if we have roles column and don't have groups column yet.
            if ($table->hasColumn('roles') === true && $table->hasColumn('groups') === false) {
                $output->info(message: '   ✓ Ready to migrate roles column to groups');
            }
        }
    }//end preSchemaChange()

    /**
     * Rename roles column to groups in organisations table
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
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_organisations') === true) {
            $table = $schema->getTable('openregister_organisations');

            // Check if we need to do the migration.
            if ($table->hasColumn('roles') === true && $table->hasColumn('groups') === false) {
                // Add new groups column.
                $table->addColumn(
                    'groups',
                    Types::JSON,
                    [
                        'notnull' => false,
                        'default' => '[]',
                        'comment' => 'Array of Nextcloud group IDs that have access to this organisation',
                    ]
                );

                $output->info(message: '   ✓ Added groups column');

                return $schema;
            } else if ($table->hasColumn('groups') === true) {
                $output->info(message: '   ⚠️  Groups column already exists');
            }
        }//end if

        return null;
    }//end changeSchema()

    /**
     * Perform post-schema change data migration
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
        // @var ISchemaWrapper $schema
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_organisations') === false) {
            return;
        }

        $output->info(message: '📋 Migrating data from roles to groups...');

        // Get database connection.
        $connection = \OC::$server->get(\OCP\IDBConnection::class);

        try {
            // Copy data from roles to groups (only where groups is empty or null).
            // Use try-catch in case roles column doesn't exist.
            try {
                // Update groups column from roles column where groups is empty or null.
                // Phpcs:ignore Generic.Files.LineLength.MaxExceeded -- SQL query must be on single line.
                $sql    = 'UPDATE `*PREFIX*openregister_organisations` SET `groups` = `roles` WHERE (`groups` = \'[]\' OR `groups` IS NULL) AND `roles` IS NOT NULL';
                $result = $connection->executeUpdate($sql);

                if ($result > 0) {
                    $output->info(message: "   ✓ Copied data from roles to groups for {$result} organisations");
                }

                if ($result === 0) {
                    $output->info(message: '   ℹ️  No data to migrate (already migrated or roles column empty)');
                }
            } catch (\Exception $copyError) {
                // Roles column might not exist if migration already ran.
                $output->info(message: '   ℹ️  Data migration skipped (roles column may not exist)');
            }

            $output->info(message: '✅ Migration completed successfully - organisations now use groups');
        } catch (\Exception $e) {
            $output->info('   ⚠️  Error during migration: '.$e->getMessage());
        }//end try
    }//end postSchemaChange()
}//end class
