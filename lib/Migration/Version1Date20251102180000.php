<?php

declare(strict_types=1);

/**
 * OpenRegister Organisation Groups Migration
 *
 * This migration renames the 'roles' column to 'groups' in the organisations table
 * to maintain naming consistency with applications.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author   Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version  GIT: <git_id>
 *
 * @link     https://www.OpenRegister.nl
 */

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
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return void
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $output->info('🔧 Preparing to rename roles to groups in organisations table...');

        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            // Only proceed if we have roles column and don't have groups column yet
            if ($table->hasColumn('roles') && !$table->hasColumn('groups')) {
                $output->info('   ✓ Ready to migrate roles column to groups');
            }
        }

    }//end preSchemaChange()


    /**
     * Rename roles column to groups in organisations table
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return ISchemaWrapper|null Updated schema
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('openregister_organisations')) {
            $table = $schema->getTable('openregister_organisations');
            
            // Check if we need to do the migration
            if ($table->hasColumn('roles') && !$table->hasColumn('groups')) {
                // Add new groups column
                $table->addColumn('groups', Types::JSON, [
                    'notnull' => false,
                    'default' => '[]',
                    'comment' => 'Array of Nextcloud group IDs that have access to this organisation'
                ]);
                
                $output->info('   ✓ Added groups column');
                
                return $schema;
                
            } elseif ($table->hasColumn('groups')) {
                $output->info('   ⚠️  Groups column already exists');
            }
        }

        return null;

    }//end changeSchema()


    /**
     * Perform post-schema change data migration and cleanup
     *
     * @param IOutput $output Migration output interface
     * @param Closure $schemaClosure Schema closure
     * @param array   $options Migration options
     *
     * @return void
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
    {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        
        if (!$schema->hasTable('openregister_organisations')) {
            return;
        }
        
        $table = $schema->getTable('openregister_organisations');
        
        // Only proceed if we have both columns
        if (!$table->hasColumn('roles') || !$table->hasColumn('groups')) {
            $output->info('   ℹ️  Migration already completed or not needed');
            return;
        }
        
        $output->info('📋 Migrating data from roles to groups...');
        
        // Get database connection
        $connection = \OC::$server->get(\OCP\IDBConnection::class);
        
        try {
            // Copy data from roles to groups (only where groups is empty)
            $connection->executeUpdate(
                'UPDATE `*PREFIX*openregister_organisations` SET `groups` = `roles` WHERE `groups` = \'[]\' OR `groups` IS NULL'
            );
            
            $output->info('   ✓ Copied data from roles to groups');
            
            // Now drop the roles column
            $table->dropColumn('roles');
            $output->info('   ✓ Dropped roles column');
            $output->info('✅ Migration completed successfully - organisations now use groups');
            
        } catch (\Exception $e) {
            $output->info('   ⚠️  Error during migration: ' . $e->getMessage());
            $output->info('   ℹ️  You may need to manually drop the roles column');
        }

    }//end postSchemaChange()


}//end class


